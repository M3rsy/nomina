<?php

namespace App\Services\Attendance;

final readonly class OvertimeDecisionBatchRequest
{
    public string $reason;

    /** @var array{search:string,status:string,date:string,rate:string} */
    public array $filters;

    /** @var list<string> */
    public array $selectedTokens;

    /**
     * @param  array{search:string,status:string,date:string,rate:string}  $filters
     * @param  list<string>  $selectedTokens
     */
    public function __construct(
        public string $decision,
        string $reason,
        public string $requestKey,
        public ?int $uploadedFileId,
        array $filters,
        public bool $all,
        array $selectedTokens,
        public string $expectedSelectionHash,
    ) {
        $this->reason = trim($reason);
        $this->filters = [
            'search' => $filters['search'],
            'status' => $filters['status'],
            'date' => $filters['date'],
            'rate' => $filters['rate'],
        ];
        $selectedTokens = array_values(array_unique($selectedTokens));
        sort($selectedTokens, SORT_STRING);
        $this->selectedTokens = $selectedTokens;
    }
}
