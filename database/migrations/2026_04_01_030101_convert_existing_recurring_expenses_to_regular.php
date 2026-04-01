<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing records with is_recurring=true were actual paid expenses,
        // not templates. Convert them so they appear in listings again.
        DB::table('company_expenses')
            ->where('is_recurring', true)
            ->update(['is_recurring' => false]);
    }

    public function down(): void
    {
        // Cannot reliably reverse — we don't know which were originally recurring.
    }
};
