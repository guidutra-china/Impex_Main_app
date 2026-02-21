<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mapping = [
            'ceo'           => 'management',
            'cto'           => 'management',
            'cfo'           => 'finance',
            'director'      => 'management',
            'manager'       => 'management',
            'sales_manager' => 'sales',
            'supervisor'    => 'operations',
            'coordinator'   => 'operations',
            'analyst'       => 'finance',
            'specialist'    => 'other',
            'consultant'    => 'other',
        ];

        foreach ($mapping as $old => $new) {
            DB::table('contacts')
                ->where('function', $old)
                ->update(['function' => $new]);
        }
    }

    public function down(): void
    {
        // Cannot reverse — old specific values are lost
    }
};
