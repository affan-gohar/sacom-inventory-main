<?php

namespace App\Models;

use App\Enums\AllocationKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => AllocationKind::class, 'qty' => 'integer'];
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
    }

    /** @return BelongsTo<StockTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
