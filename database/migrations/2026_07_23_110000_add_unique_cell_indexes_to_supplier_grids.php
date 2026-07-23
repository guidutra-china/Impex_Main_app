<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os grids do Supplier Portal (e o import de cronograma) gravam células via
 * updateOrCreate sem índice unique de apoio — dois saves concorrentes na mesma
 * célula podiam inserir duas linhas. Estes índices são a barreira no banco.
 * Duplicatas pré-existentes são resolvidas mantendo a linha mais recente
 * (mesma semântica do updateOrCreate, onde o último save vence).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dedupe('production_schedule_entries', ['production_schedule_id', 'proforma_invoice_item_id', 'production_date']);
        $this->dedupe('component_deliveries', ['production_schedule_component_id', 'expected_date']);

        Schema::table('production_schedule_entries', function (Blueprint $table) {
            $table->unique(
                ['production_schedule_id', 'proforma_invoice_item_id', 'production_date'],
                'ps_entries_schedule_item_date_unique',
            );
        });

        Schema::table('component_deliveries', function (Blueprint $table) {
            $table->unique(
                ['production_schedule_component_id', 'expected_date'],
                'component_deliveries_component_date_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('production_schedule_entries', function (Blueprint $table) {
            $table->dropUnique('ps_entries_schedule_item_date_unique');
        });

        Schema::table('component_deliveries', function (Blueprint $table) {
            $table->dropUnique('component_deliveries_component_date_unique');
        });
    }

    /**
     * @param  list<string>  $keyColumns
     */
    private function dedupe(string $table, array $keyColumns): void
    {
        $groups = DB::table($table)
            ->select(array_merge($keyColumns, [DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as total')]))
            ->groupBy($keyColumns)
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $query = DB::table($table)->where('id', '!=', $group->keep_id);

            foreach ($keyColumns as $column) {
                $query->where($column, $group->{$column});
            }

            $query->delete();
        }
    }
};
