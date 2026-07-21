<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DatabaseSessionRevoker
{
    public function revokeUser(int $userId): int
    {
        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        return ($connection === null
            ? DB::table($table)
            : DB::connection($connection)->table($table)
        )->where('user_id', $userId)->delete();
    }

    public function revokeCompanyUsers(int $companyId): int
    {
        $userIds = User::query()->where('company_id', $companyId)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->pluck('id')
            ->toArray();

        return empty($userIds) ? 0 : $this->revokeUsers($userIds);
    }

    public function revokeUsers(array $userIds): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        return ($connection === null
            ? DB::table($table)
            : DB::connection($connection)->table($table)
        )->whereIn('user_id', $userIds)->delete();
    }
}
