<?php

use App\Domain\Financial\Support\AdditionalCostSideStatus;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * forwarder_status e supplier_payable_status só eram gravados pelo
     * reconcile, na primeira alocação de pagamento; até lá o lado ficava
     * NULL e a tabela de custos mostrava a linha sem status (SH-2026-00041,
     * perna de 78,69 da Shenzhen TAS). O sync passou a semear o status ao
     * criar a parcela; aqui preenche o legado a partir da parcela existente,
     * só onde está NULL. Regra em AdditionalCostSideStatus.
     */
    public function up(): void
    {
        AdditionalCostSideStatus::backfillMissing();
    }

    public function down(): void
    {
        // Backfill idempotente, derivado das parcelas; não é revertido.
    }
};
