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

/** Direct over-the-counter sale at the Main Shop. */
class RecordCounterSale
{
    use Support;

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $lines = LineInput::fromRows($rows);

        return DB::transaction(function () use ($lines, $note, $occurredAt) {
            $main = Shop::main();
            $txn = $this->newTransaction(TransactionType::CounterSale, $main, $note, $occurredAt);
            $total = 0;

            foreach ($lines as $line) {
                $product = $this->activeProduct($line->productId);
                $price = $line->unitPrice ?? $product->selling_price;
                Ledger::move($txn, $main->id, $product->id, StockSource::Main, -$line->qty, $price, $product->purchase_cost);
                $total += $line->qty * Money::toSen($price);

                AuditLogger::record('counter_sale', $txn, $main->id, $product->id, $line->qty,
                    after: ['unit_price' => $price], note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($total)]);

            return $txn;
        });
    }
}
