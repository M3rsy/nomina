<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('employees', 'employees_company_payment_code_unique')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_payment_code_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('employees', 'employees_company_payment_code_unique')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->unique(['company_id', 'payment_code'], 'employees_company_payment_code_unique');
        });
    }
};
