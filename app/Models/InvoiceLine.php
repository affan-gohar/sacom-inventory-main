<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'qty' => 'integer',
            'qty_sold' => 'integer',
            'qty_paid' => 'integer',
            'qty_returned' => 'integer',
        ];
    }

    /** Units from this line still physically at the branch. */
    public function qtyInShop(): int
    {
        return $this->qty - $this->qty_sold - $this->qty_returned;
    }

    /** Units sold but not yet paid. */
    public function qtySoldUnpaid(): int
    {
        return $this->qty_sold - $this->qty_paid;
    }

    /** Units still owed (in shop + sold-unpaid). */
    public function qtyUnpaid(): int
    {
        return $this->qty - $this->qty_returned - $this->qty_paid;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<LineAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(LineAllocation::class);
    }
}
