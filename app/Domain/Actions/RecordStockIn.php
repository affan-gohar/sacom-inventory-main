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

/** Supplier → Main Shop. Updates each product's purchase cost when one is given. */
class RecordStockIn
{
    use Support;

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $lines = LineInput::fromRows($rows);

        return DB::transaction(function () use ($lines, $note, $occurredAt) {
            $main = Shop::main();
            $txn = $this->newTransaction(TransactionType::StockIn, $main, $note, $occurredAt);
            $total = 0;

            foreach ($lines as $line) {
                $product = $this->activeProduct($line->productId);
                Ledger::move($txn, $main->id, $product->id, StockSource::Main, $line->qty, $product->selling_price, $line->unitCost);

                if ($line->unitCost !== null) {
                    $product->update(['purchase_cost' => $line->unitCost]);
                    $total += $line->qty * Money::toSen($line->unitCost);
                }

                AuditLogger::record('stock_in', $txn, $main->id, $product->id, $line->qty,
                    after: ['unit_cost' => $line->unitCost], note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($total)]);

            return $txn;
        });
    }
}
