<?php

declare(strict_types=1);

namespace App\Domain\SupplierQuotations\Actions;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Aplica a resposta revisada do fornecedor numa SQ existente, numa transação:
 * guarda o arquivo em Documents (tipo supplier_response; mesmo nome = nova
 * versão), grava custo/MOQ/prazo item a item (o hook recalcula total_cost),
 * preenche só os campos VAZIOS do cabeçalho e leva Requested → Received pela
 * máquina de estados. Linhas sem preço ficam como estão.
 */
class ApplySupplierQuotationResponseAction
{
    /** Status em que a resposta ainda pode entrar (re-import de proposta revisada incluso). */
    public const ALLOWED_STATUSES = [
        SupplierQuotationStatus::REQUESTED,
        SupplierQuotationStatus::RECEIVED,
        SupplierQuotationStatus::UNDER_ANALYSIS,
    ];

    /**
     * @param  list<array<string,mixed>>  $linhas  sq_item_id + unit_cost (decimal) / moq / lead_time_days / notes
     * @param  array<string,mixed>  $cabecalho
     * @return array{updated:int}
     */
    public function __invoke(SupplierQuotation $sq, array $linhas, array $cabecalho, User $user, string $filePath, string $fileName): array
    {
        if (! $user->can('edit-supplier-quotations')) {
            throw new AuthorizationException;
        }

        if (! in_array($sq->status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(__('messages.supplier_response_status_not_allowed', ['status' => $sq->status->getLabel()]));
        }

        $currency = filled($cabecalho['currency_code'] ?? null) ? strtoupper((string) $cabecalho['currency_code']) : null;
        if ($currency !== null && $currency !== strtoupper((string) $sq->currency_code)) {
            throw new \InvalidArgumentException(__('messages.supplier_response_currency_mismatch', [
                'document' => $currency,
                'quotation' => $sq->currency_code,
            ]));
        }

        $storedPath = null;

        try {
            return DB::transaction(function () use ($sq, $linhas, $cabecalho, $user, $filePath, $fileName, &$storedPath) {
                $sq = SupplierQuotation::lockForUpdate()->findOrFail($sq->id);
                $items = $sq->items()->get()->keyBy('id');

                $updated = 0;
                foreach ($linhas as $linha) {
                    $item = $items->get((int) ($linha['sq_item_id'] ?? 0));
                    if ($item === null) {
                        throw new \InvalidArgumentException(__('messages.supplier_response_foreign_item'));
                    }

                    if (! filled($linha['unit_cost'] ?? null)) {
                        continue; // não cotado: mantém o que a linha já tinha
                    }

                    $item->unit_cost = Money::toMinor($linha['unit_cost']);
                    foreach (['moq', 'lead_time_days'] as $field) {
                        if (filled($linha[$field] ?? null)) {
                            $item->{$field} = (int) $linha[$field];
                        }
                    }
                    if (filled($linha['notes'] ?? null)) {
                        $item->notes = (string) $linha['notes'];
                    }
                    $item->save();
                    $updated++;
                }

                foreach (['supplier_reference', 'valid_until', 'lead_time_days', 'moq', 'incoterm'] as $field) {
                    if (blank($sq->{$field}) && filled($cabecalho[$field] ?? null)) {
                        $sq->{$field} = $cabecalho[$field];
                    }
                }
                if (blank($sq->received_at)) {
                    $sq->received_at = now()->toDateString();
                }
                $sq->save();

                $storedPath = $this->attachDocument($sq, $filePath, $fileName, $user);

                if ($sq->status === SupplierQuotationStatus::REQUESTED) {
                    app(TransitionStatusAction::class)->execute(
                        $sq,
                        SupplierQuotationStatus::RECEIVED,
                        __('messages.supplier_response_imported_note', ['file' => $fileName]),
                    );
                }

                return ['updated' => $updated];
            });
        } catch (\Throwable $e) {
            // O rollback desfaz a linha em documents, não o arquivo copiado.
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $e;
        }
    }

    /**
     * Mesmo comportamento do upload manual em Documents: mesmo tipo + nome vira
     * nova versão do documento existente. Retorna o caminho gravado.
     */
    private function attachDocument(SupplierQuotation $sq, string $filePath, string $fileName, User $user): string
    {
        $disk = 'local';
        $stored = "supplier-quotations/{$sq->id}/responses/".Str::random(8).'_'.basename($fileName);
        Storage::disk($disk)->put($stored, (string) file_get_contents($filePath));

        $attributes = [
            'path' => $stored,
            'source' => DocumentSourceType::UPLOADED,
            'mime_type' => Storage::disk($disk)->mimeType($stored),
            'size' => Storage::disk($disk)->size($stored),
            'checksum' => hash_file('sha256', Storage::disk($disk)->path($stored)),
            'created_by' => $user->id,
        ];

        $existing = $sq->documents()
            ->where('type', 'supplier_response')
            ->where('name', $fileName)
            ->first();

        if ($existing !== null) {
            $existing->versions()->create([
                'path' => $existing->path,
                'version' => $existing->version,
                'size' => $existing->size,
                'checksum' => $existing->checksum,
                'created_by' => $existing->created_by,
            ]);
            $existing->update($attributes + ['version' => $existing->version + 1]);

            return $stored;
        }

        $sq->documents()->create($attributes + [
            'type' => 'supplier_response',
            'name' => $fileName,
            'disk' => $disk,
            'version' => 1,
        ]);

        return $stored;
    }
}
