<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\InvoiceNumber;
use App\Domain\Ledger;
use App\Domain\LineInput;
use App\Domain\Money;
use App\Enums\StockSource;
use App\Enums\TransactionType;
use App\Models\Invoice;
use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Main Shop → branch. Creates one invoice with the prices frozen on its lines. */
class TransferStock
{
    use Support;

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(Shop $shop, array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): Invoice
    {
        $this->requireBranch($shop);
        $lines = LineInput::fromRows($rows);

        return DB::transaction(function () use ($shop, $lines, $note, $occurredAt) {
            $main = Shop::main();
            $txn = $this->newTransaction(TransactionType::Transfer, $shop, $note, $occurredAt);

            $invoice = Invoice::query()->create([
                'number' => InvoiceNumber::next($txn->occurred_at),
                'shop_id' => $shop->id,
                'stock_transaction_id' => $txn->id,
                'issued_at' => $txn->occurred_at,
            ]);

            $total = 0;
            foreach ($lines as $line) {
                $product = $this->activeProduct($line->productId);
                $price = $line->unitPrice ?? $product->selling_price;

                Ledger::move($txn, $main->id, $product->id, StockSource::Main, -$line->qty, $price);
                Ledger::move($txn, $shop->id, $product->id, StockSource::Main, $line->qty, $price);

                $invoice->lines()->create([
                    'product_id' => $product->id,
                    'unit_price' => $price,
                    'qty' => $line->qty,
                ]);
                $total += $line->qty * Money::toSen($price);

                AuditLogger::record('transfer', $txn, $shop->id, $product->id, $line->qty,
                    after: ['invoice' => $invoice->number, 'unit_price' => $price], note: $txn->note);
            }

            $txn->update(['amount' => Money::fromSen($total)]);

            return $invoice;
        });
    }
}
