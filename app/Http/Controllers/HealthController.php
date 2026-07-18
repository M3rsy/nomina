<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [
            'database' => false,
            'storage' => false,
            'cache' => false,
        ];

        try {
            DB::connection()->getPdo();
            $checks['database'] = true;
        } catch (\Throwable $e) {
            $checks['database'] = false;
        }

        $checks['storage'] = is_writable(storage_path('framework'));
        $checks['cache'] = is_writable(base_path('bootstrap/cache'));

        $ok = $checks['database'] && $checks['storage'] && $checks['cache'];

        $status = $ok ? 200 : 503;

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'checks' => $checks,
        ], $status);
    }
}
