<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartonContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'carton_id',
        'shipment_item_id',
        'pieces',
        'part_label',
        'multi_box_set_id',
        'weight_share',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pieces' => 'integer',
            'weight_share' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class);
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }

    /**
     * Peças que contam como equipamento nos documentos (EQUIP QTY).
     *
     * Num produto dividido em partes (packing_split), cada parte carrega as N
     * peças do item para o controle de empacotamento fechar, mas todas são a
     * mesma máquina. Só a primeira parte conta; as demais (acessórios, small
     * parts) saem com zero, senão o packing list declara o dobro da CI.
     */
    public function equipmentPieces(): int
    {
        $split = $this->shipmentItem?->packing_split;

        $isSecondaryPart = $this->multi_box_set_id !== null
            && is_array($split)
            && ($split['set_id'] ?? null) === $this->multi_box_set_id
            && $this->part_label !== ($split['part_labels'][0] ?? null);

        return $isSecondaryPart ? 0 : (int) $this->pieces;
    }
}
