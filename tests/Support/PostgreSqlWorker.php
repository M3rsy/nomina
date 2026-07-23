<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class PostgreSqlWorker
{
    private string $output = '';

    private bool $inputClosed = false;

    private bool $inputDrained = true;

    private function __construct(
        private Process $process,
        private InputStream $input,
    ) {
        $this->input->onEmpty(function (): void {
            $this->inputDrained = true;
        });
    }

    public static function start(string $mode, array $payload = []): self
    {
        $input = new InputStream;
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/postgresql-worker.php'),
            $mode,
            json_encode($payload, JSON_THROW_ON_ERROR),
        ], base_path(), timeout: 15);
        $process->setInput($input);
        $process->start();

        return new self($process, $input);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** @return array<string, mixed> */
    public function waitFor(string $checkpoint, float $timeout = 5): array
    {
        $deadline = microtime(true) + $timeout;

        do {
            $this->pump();

            while (($newline = strpos($this->output, "\n")) !== false) {
                $line = substr($this->output, 0, $newline);
                $this->output = substr($this->output, $newline + 1);
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (($event['checkpoint'] ?? null) !== $checkpoint) {
                    throw new RuntimeException("Unexpected worker checkpoint: {$line}");
                }

                return $event;
            }

            if (! $this->process->isRunning()) {
                throw new RuntimeException('Worker exited early: '.$this->process->getErrorOutput());
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Timed out waiting for worker checkpoint [{$checkpoint}].");
    }

    public function release(string $command): void
    {
        $this->inputDrained = false;
        $this->input->write(json_encode(['release' => $command], JSON_THROW_ON_ERROR)."\n");
        $deadline = microtime(true) + 1;

        while (! $this->inputDrained && microtime(true) < $deadline) {
            $this->pump();
            usleep(1_000);
        }

        if (! $this->inputDrained) {
            throw new RuntimeException("Timed out delivering worker release [{$command}].");
        }
    }

    public function stop(): void
    {
        if (! $this->inputClosed) {
            $this->input->close();
            $this->inputClosed = true;
        }

        if ($this->process->isRunning()) {
            $this->process->stop(0.2);
        }
    }

    private function pump(): void
    {
        $this->output .= $this->process->getIncrementalOutput();
    }
}
