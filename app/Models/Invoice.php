<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    /** Paid / Partially paid / Unpaid, from the lines (lines must be loaded or will be queried). */
    public function status(): string
    {
        $unpaid = $this->lines->sum(fn (InvoiceLine $l) => $l->qtyUnpaid());
        $paid = $this->lines->sum(fn (InvoiceLine $l) => $l->qty_paid);

        return match (true) {
            $unpaid === 0 => 'Paid',
            $paid > 0 => 'Partially paid',
            default => 'Unpaid',
        };
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<StockTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
