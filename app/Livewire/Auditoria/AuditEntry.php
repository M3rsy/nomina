<?php

namespace App\Livewire\Auditoria;

use Carbon\Carbon;

readonly class AuditEntry
{
    public function __construct(
        public string $type,
        public string $typeLabel,
        public Carbon $createdAt,
        public ?int $companyId,
        public ?string $companyName,
        public ?int $userId,
        public ?string $userEmail,
        public string $description,
        public array $metadata = []
    ) {
    }
}
