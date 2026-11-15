<?php

namespace App\Domain;

use App\Enums\AllocationKind;
use App\Models\InvoiceLine;
use App\Models\StockTransaction;
use Illuminate\Database\Eloquent\Collection;

/** Finds and allocates against a shop's invoice lines, oldest first. */
final class OpenLines
{
    /**
     * Lines for this shop/product that still have units in the shop, oldest first, locked.
     *
     * @return Collection<int, InvoiceLine>
     */
    public static function inShop(int $shopId, int $productId): Collection
    {
        return InvoiceLine::query()
            ->select('invoice_lines.*')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.shop_id', $shopId)
            ->where('invoice_lines.product_id', $productId)
            ->whereRaw('invoice_lines.qty > invoice_lines.qty_sold + invoice_lines.qty_returned')
            ->orderBy('invoices.issued_at')
            ->orderBy('invoice_lines.id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Take $qty units out of the shop's oldest lines, recording $kind (sold or returned).
     *
     * @return int value of the allocated units, in sen
     */
    public static function consume(StockTransaction $txn, int $shopId, int $productId, int $qty, AllocationKind $kind): int
    {
        $column = $kind === AllocationKind::Sold ? 'qty_sold' : 'qty_returned';
        $remaining = $qty;
        $value = 0;

        foreach (self::inShop($shopId, $productId) as $line) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $line->qtyInShop());
            $line->increment($column, $take);
            $txn->allocations()->create(['invoice_line_id' => $line->id, 'kind' => $kind, 'qty' => $take]);
            $remaining -= $take;
            $value += $take * Money::toSen($line->unit_price);
        }

        if ($remaining > 0) {
            // shop_stock.qty_main and the open lines disagree — refuse rather than guess.
            throw new InventoryException('Stock records for this shop are out of step with its invoices. Nothing was saved.');
        }

        return $value;
    }
}
