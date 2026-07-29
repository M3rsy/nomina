<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_decisions', function (Blueprint $table): void {
            $table->foreignId('batch_item_id')->nullable()->unique()
                ->constrained('overtime_decision_batch_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('overtime_decisions', function (Blueprint $table): void {
            $table->dropUnique(['batch_item_id']);
            $table->dropConstrainedForeignId('batch_item_id');
        });
    }
};
