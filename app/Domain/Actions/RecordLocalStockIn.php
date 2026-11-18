<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\InventoryException;
use App\Domain\Ledger;
use App\Domain\LineInput;
use App\Domain\Money;
use App\Enums\StockSource;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Local supplier → branch (skips Main). The branch's own stock: never invoiced or owed.
 *
 * Lines may name any product as free text ('product_name'): an existing product with that name is
 * reused, otherwise a new product local to this branch is created — it never enters the Main Shop
 * catalogue. Lines may still pass 'product_id'.
 */
class RecordLocalStockIn
{
    use Support;

    public const LOCAL_CATEGORY = 'Local purchases';

    /** @param array<int, array<string, mixed>> $rows */
    public function handle(Shop $shop, array $rows, ?string $note = null, ?CarbonInterface $occurredAt = null): StockTransaction
    {
        $this->requireBranch($shop);

        return DB::transaction(function () use ($shop, $rows, $note, $occurredAt) {
            $lines = LineInput::fromRows($this->resolveProducts($shop, $rows));
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

    /**
     * Turn free-text product names into product ids, creating branch-local products as needed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function resolveProducts(Shop $shop, array $rows): array
    {
        return array_map(function (array $row) use ($shop) {
            if (! empty($row['product_id'])) {
                return $row;
            }

            $name = trim(preg_replace('/\s+/', ' ', (string) ($row['product_name'] ?? '')));
            if ($name === '') {
                throw new InventoryException('Every line needs a product name.');
            }
            if (mb_strlen($name) > 255) {
                throw new InventoryException('Product names can be at most 255 characters.');
            }

            $product = Product::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if ($product === null) {
                $cost = isset($row['unit_cost']) && $row['unit_cost'] !== '' ? Money::fromSen(Money::toSen($row['unit_cost'])) : null;
                $product = Product::query()->create([
                    'name' => $name,
                    'shop_id' => $shop->id,
                    'category_id' => Category::query()->firstOrCreate(['name' => self::LOCAL_CATEGORY], ['low_stock_threshold' => 0])->id,
                    'selling_price' => $cost ?? '0.00',
                    'purchase_cost' => $cost,
                    'is_active' => true,
                ]);
                AuditLogger::record('local_product_created', $product, $shop->id, $product->id, after: ['name' => $name]);
            } elseif (! $product->is_active) {
                throw new InventoryException("{$product->name} is deactivated. Reactivate it in Products first.");
            }

            unset($row['product_name']);
            $row['product_id'] = $product->id;

            return $row;
        }, $rows);
    }
}
