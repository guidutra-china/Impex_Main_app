<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;

class DiagnoseOperationalAlertsCommand extends Command
{
    protected $signature = 'diagnose:operational-alerts {--with-trashed}';

    protected $description = 'Print exact rows the OperationalAlertsWidget is counting';

    public function handle(): int
    {
        $withTrashed = $this->option('with-trashed');

        $this->section('Pending approval — Payables (OUTBOUND)', function () use ($withTrashed) {
            $q = Payment::query()
                ->where('status', PaymentStatus::PENDING_APPROVAL)
                ->where('direction', PaymentDirection::OUTBOUND);
            if ($withTrashed) {
                $q->withTrashed();
            }
            $rows = $q->get(['id', 'reference', 'company_id', 'amount', 'currency_code', 'status', 'direction', 'payment_date', 'deleted_at', 'created_at']);
            $this->info('count = '.$rows->count());
            $this->table(
                ['id', 'reference', 'company_id', 'amount', 'currency', 'status', 'direction', 'payment_date', 'deleted_at'],
                $rows->map(fn ($p) => [$p->id, $p->reference, $p->company_id, $p->amount, $p->currency_code, $p->status?->value, $p->direction?->value, (string) $p->payment_date, (string) $p->deleted_at])->toArray(),
            );
        });

        $this->section('Pending approval — Receivables (INBOUND)', function () use ($withTrashed) {
            $q = Payment::query()
                ->where('status', PaymentStatus::PENDING_APPROVAL)
                ->where('direction', PaymentDirection::INBOUND);
            if ($withTrashed) {
                $q->withTrashed();
            }
            $rows = $q->get(['id', 'reference', 'company_id', 'amount', 'currency_code', 'status', 'direction', 'payment_date', 'deleted_at']);
            $this->info('count = '.$rows->count());
            $this->table(
                ['id', 'reference', 'company_id', 'amount', 'currency', 'status', 'direction', 'payment_date', 'deleted_at'],
                $rows->map(fn ($p) => [$p->id, $p->reference, $p->company_id, $p->amount, $p->currency_code, $p->status?->value, $p->direction?->value, (string) $p->payment_date, (string) $p->deleted_at])->toArray(),
            );
        });

        $this->section('Overdue payables (PSI on PurchaseOrder)', function () {
            $rows = PaymentScheduleItem::query()
                ->where('status', PaymentScheduleStatus::OVERDUE)
                ->where('is_credit', false)
                ->where('payable_type', PurchaseOrder::class)
                ->get(['id', 'payable_id', 'amount', 'due_date', 'status']);
            $this->info('count = '.$rows->count());
        });

        $this->section('Overdue receivables (PSI on ProformaInvoice)', function () {
            $rows = PaymentScheduleItem::query()
                ->where('status', PaymentScheduleStatus::OVERDUE)
                ->where('is_credit', false)
                ->where('payable_type', ProformaInvoice::class)
                ->get(['id', 'payable_id', 'amount', 'due_date', 'status']);
            $this->info('count = '.$rows->count());
        });

        $this->section('Due this week — payables', function () {
            $count = PaymentScheduleItem::query()
                ->where('status', PaymentScheduleStatus::DUE)
                ->where('is_credit', false)
                ->where('payable_type', PurchaseOrder::class)
                ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->count();
            $this->info('count = '.$count);
        });

        $this->section('Due this week — receivables', function () {
            $count = PaymentScheduleItem::query()
                ->where('status', PaymentScheduleStatus::DUE)
                ->where('is_credit', false)
                ->where('payable_type', ProformaInvoice::class)
                ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->count();
            $this->info('count = '.$count);
        });

        $this->section('All payments grouped by status × direction (sanity check)', function () use ($withTrashed) {
            $q = Payment::query();
            if ($withTrashed) {
                $q->withTrashed();
            }
            $grouped = $q->selectRaw('status, direction, COUNT(*) as c')
                ->groupBy('status', 'direction')
                ->orderBy('status')
                ->orderBy('direction')
                ->get();
            $this->table(
                ['status', 'direction', 'count'],
                $grouped->map(fn ($r) => [is_object($r->status) ? $r->status->value : $r->status, is_object($r->direction) ? $r->direction->value : $r->direction, $r->c])->toArray(),
            );
        });

        return self::SUCCESS;
    }

    protected function section(string $title, \Closure $body): void
    {
        $this->newLine();
        $this->line('===== '.$title.' =====');
        $body();
    }
}
