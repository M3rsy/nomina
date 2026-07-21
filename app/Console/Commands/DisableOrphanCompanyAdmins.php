<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\DatabaseSessionRevoker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisableOrphanCompanyAdmins extends Command
{
    protected $signature = 'users:disable-orphan-company-admins';

    protected $description = 'Disable active company admins without a valid company assignment.';

    public function handle(DatabaseSessionRevoker $sessions): int
    {
        $disabled = 0;
        foreach (User::role('company_admin')
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->where(fn ($query) => $query->whereNull('company_id')->orWhereDoesntHave('company'))
            ->get() as $user) {
            DB::transaction(function () use ($sessions, $user): void {
                $user->update(['is_active' => false]);
                $sessions->revokeUser($user->id);
            });
            Log::warning('Orphan company admin disabled', [
                'event' => 'orphan_company_admin_disabled', 'reason' => AccountAccess::COMPANY_ASSIGNMENT_MISSING,
                'user_id' => $user->id,
            ]);
            $disabled++;
        }
        $this->info("Disabled {$disabled} orphan company admin(s).");

        return self::SUCCESS;
    }
}
