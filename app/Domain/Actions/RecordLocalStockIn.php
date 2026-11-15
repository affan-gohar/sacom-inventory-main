<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\Ledger;
use App\Domain\LineInput;
use App\Domain\Money;
use App\Enums\StockSource;
use App\Enums\TransactionType;
use App\Models\Shop;
use App\Models\StockTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Local supplier → branch (skips Main). The branch's own stock: never invoiced or owed. */
class RecordLocalStockIn
{
    use Support;

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(Shop $shop, array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $this->requireBranch($shop);
        $lines = LineInput::fromRows($rows);

        return DB::transaction(function () use ($shop, $lines, $note, $occurredAt) {
            $txn = $this->newTransaction(TransactionType::LocalStockIn, $shop, $note, $occurredAt);
            $total = 0;

            foreach ($lines as $line) {
                $product = $this->activeProduct($line->productId);
                Ledger::move($txn, $shop->id, $product->id, StockSource::Local, $line->qty, $product->selling_price, $line->unitCost);
                $total += $line->qty * Money::toSen($line->unitCost);

                AuditLogger::record('local_stock_in', $txn, $shop->id, $product->id, $line->qty,
                    after: ['unit_cost' => $line->unitCost], note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($total)]);

            return $txn;
        });
    }
}
