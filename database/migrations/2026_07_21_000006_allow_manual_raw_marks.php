<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_marks', function (Blueprint $table) {
            $table->foreignId('uploaded_file_id')->nullable()->change();
            $table->text('raw_line')->nullable()->change();
            $table->integer('row_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('raw_marks')
            ->where('source', 'manual')
            ->delete();

        Schema::table('raw_marks', function (Blueprint $table) {
            $table->foreignId('uploaded_file_id')->nullable(false)->change();
            $table->text('raw_line')->nullable(false)->change();
            $table->integer('row_number')->nullable(false)->change();
        });
    }
};
