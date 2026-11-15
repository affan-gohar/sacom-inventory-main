<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\InventoryException;
use App\Domain\Ledger;
use App\Domain\LineInput;
use App\Domain\Money;
use App\Domain\OpenLines;
use App\Enums\AllocationKind;
use App\Enums\StockSource;
use App\Enums\TransactionType;
use App\Models\Shop;
use App\Models\StockTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Branch → Main Shop. Only Main-supplied, unsold stock; credited at each line's price, oldest first. */
class ReturnToMain
{
    use Support;

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(Shop $shop, array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $this->requireBranch($shop);
        $lines = LineInput::fromRows($rows);

        return DB::transaction(function () use ($shop, $lines, $note, $occurredAt) {
            $main = Shop::main();
            $txn = $this->newTransaction(TransactionType::Return, $shop, $note, $occurredAt);
            $credit = 0;

            foreach ($lines as $line) {
                $product = $this->activeProduct($line->productId);
                $stock = Ledger::lock($shop->id, $product->id);
                if ($line->qty > $stock->qty_main) {
                    throw new InventoryException(
                        "Only {$stock->qty_main} {$product->name} from the Main Shop can be returned by {$shop->name}."
                        .($stock->qty_local > 0 ? ' Locally bought stock cannot be returned.' : '')
                    );
                }

                // Lock order: main shop row is locked inside move(); branch row already locked.
                Ledger::move($txn, $shop->id, $product->id, StockSource::Main, -$line->qty, $product->selling_price);
                Ledger::move($txn, $main->id, $product->id, StockSource::Main, $line->qty, $product->selling_price);

                $credit += OpenLines::consume($txn, $shop->id, $product->id, $line->qty, AllocationKind::Returned);

                AuditLogger::record('return', $txn, $shop->id, $product->id, $line->qty, note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($credit)]);

            return $txn;
        });
    }
}
