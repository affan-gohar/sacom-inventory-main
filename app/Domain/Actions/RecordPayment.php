<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\InventoryException;
use App\Domain\Ledger;
use App\Domain\Money;
use App\Enums\AllocationKind;
use App\Enums\ShopType;
use App\Enums\StockSource;
use App\Enums\TransactionType;
use App\Models\InvoiceLine;
use App\Models\Shop;
use App\Models\StockTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Item-level clearance. Tier 2: pay up to the sold-unpaid quantity of a line.
 * Tier 3: pay up to everything unpaid on the line; sold-unpaid units are settled first, then
 * in-shop units, which are recorded as sold at the same time (paying = selling).
 */
class RecordPayment
{
    use Support;

    /** @param array<int|string, int|string|null> $qtyByLine invoice_line_id => qty to pay */
    public function handle(Shop $shop, array $qtyByLine, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $this->requireBranch($shop);

        $requested = [];
        foreach ($qtyByLine as $lineId => $qty) {
            $qty = (int) $qty;
            if ($qty < 0) {
                throw new InventoryException('Quantities cannot be negative.');
            }
            if ($qty > 0) {
                $requested[(int) $lineId] = $qty;
            }
        }
        if ($requested === []) {
            throw new InventoryException('Enter a quantity to pay on at least one line.');
        }
        ksort($requested);

        return DB::transaction(function () use ($shop, $requested, $note, $occurredAt) {
            $txn = $this->newTransaction(TransactionType::Payment, $shop, $note, $occurredAt);
            $total = 0;

            $lines = InvoiceLine::query()
                ->select('invoice_lines.*')
                ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                ->whereNull('invoices.deleted_at')
                ->where('invoices.shop_id', $shop->id)
                ->whereIn('invoice_lines.id', array_keys($requested))
                ->orderBy('invoice_lines.id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($requested as $lineId => $qty) {
                $line = $lines->get($lineId);
                if ($line === null) {
                    throw new InventoryException('One of the lines does not belong to this shop.');
                }

                if ($shop->type === ShopType::Tier2) {
                    $max = $line->qtySoldUnpaid();
                    if ($qty > $max) {
                        throw new InventoryException("Only {$max} sold unit(s) can be paid on {$line->product->name} (invoice {$line->invoice->number}).");
                    }
                } else {
                    $max = $line->qtyUnpaid();
                    if ($qty > $max) {
                        throw new InventoryException("Only {$max} unit(s) are unpaid on {$line->product->name} (invoice {$line->invoice->number}).");
                    }
                    $toSell = max(0, $qty - $line->qtySoldUnpaid());
                    if ($toSell > 0) {
                        Ledger::move($txn, $shop->id, $line->product_id, StockSource::Main, -$toSell, $line->unit_price);
                        $line->increment('qty_sold', $toSell);
                        $txn->allocations()->create(['invoice_line_id' => $line->id, 'kind' => AllocationKind::Sold, 'qty' => $toSell]);
                    }
                }

                $line->increment('qty_paid', $qty);
                $txn->allocations()->create(['invoice_line_id' => $line->id, 'kind' => AllocationKind::Paid, 'qty' => $qty]);
                $total += $qty * Money::toSen($line->unit_price);

                AuditLogger::record('payment', $txn, $shop->id, $line->product_id, $qty,
                    after: ['invoice_line_id' => $line->id, 'unit_price' => $line->unit_price], note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($total)]);

            return $txn;
        });
    }
}
