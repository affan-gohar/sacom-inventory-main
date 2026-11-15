<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\Ledger;
use App\Domain\LineInput;
use App\Domain\Money;
use App\Domain\OpenLines;
use App\Enums\AllocationKind;
use App\Enums\ShopType;
use App\Enums\StockSource;
use App\Enums\TransactionType;
use App\Models\Shop;
use App\Models\StockTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Items sold at a Tier 2 shop. Main-supplied stock is used first (oldest invoice line first) and
 * becomes due; anything beyond that comes from the branch's local stock and is not owed.
 */
class RecordBranchSale
{
    use Support;

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(Shop $shop, array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $this->requireBranch($shop, ShopType::Tier2);
        $lines = LineInput::fromRows($rows);

        return DB::transaction(function () use ($shop, $lines, $note, $occurredAt) {
            $txn = $this->newTransaction(TransactionType::BranchSale, $shop, $note, $occurredAt);
            $due = 0;

            foreach ($lines as $line) {
                $product = $this->activeProduct($line->productId);
                $stock = Ledger::lock($shop->id, $product->id);
                $fromMain = min($line->qty, $stock->qty_main);
                $fromLocal = $line->qty - $fromMain;

                if ($fromMain > 0) {
                    Ledger::move($txn, $shop->id, $product->id, StockSource::Main, -$fromMain, $product->selling_price);
                    $due += OpenLines::consume($txn, $shop->id, $product->id, $fromMain, AllocationKind::Sold);
                }
                if ($fromLocal > 0) {
                    Ledger::move($txn, $shop->id, $product->id, StockSource::Local, -$fromLocal, $product->selling_price);
                }

                AuditLogger::record('branch_sale', $txn, $shop->id, $product->id, $line->qty,
                    after: ['from_main' => $fromMain, 'from_local' => $fromLocal], note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($due)]);

            return $txn;
        });
    }
}
