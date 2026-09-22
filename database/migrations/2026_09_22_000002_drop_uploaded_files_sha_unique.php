<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'uploaded_files_company_id_sha256_unique';

    private const LOOKUP_INDEX = 'uploaded_files_company_id_sha256_index';

    public function up(): void
    {
        if (Schema::hasIndex('uploaded_files', self::UNIQUE_INDEX)) {
            Schema::table('uploaded_files', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (! Schema::hasIndex('uploaded_files', self::LOOKUP_INDEX)) {
            Schema::table('uploaded_files', function (Blueprint $table): void {
                $table->index(['company_id', 'sha256'], self::LOOKUP_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('uploaded_files', self::LOOKUP_INDEX)) {
            Schema::table('uploaded_files', function (Blueprint $table): void {
                $table->dropIndex(self::LOOKUP_INDEX);
            });
        }

        if (! Schema::hasIndex('uploaded_files', self::UNIQUE_INDEX)) {
            Schema::table('uploaded_files', function (Blueprint $table): void {
                $table->unique(['company_id', 'sha256'], self::UNIQUE_INDEX);
            });
        }
    }
};
