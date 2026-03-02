<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['inquiry_id', 'user_id', 'role']);
        });

        $tables = [
            'inquiries',
            'quotations',
            'supplier_quotations',
            'proforma_invoices',
            'purchase_orders',
            'shipments',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('responsible_user_id')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'inquiries',
            'quotations',
            'supplier_quotations',
            'proforma_invoices',
            'purchase_orders',
            'shipments',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['responsible_user_id']);
                $table->dropColumn('responsible_user_id');
            });
        }

        Schema::dropIfExists('project_team_members');
    }
};
