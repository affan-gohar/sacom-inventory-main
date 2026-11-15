<?php

namespace App\Domain\Actions;

use App\Domain\InventoryException;
use App\Enums\ShopType;
use App\Enums\TransactionType;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockTransaction;
use Carbon\CarbonInterface;

/** Shared steps for the action classes. */
trait Support
{
    protected function newTransaction(
        TransactionType $type,
        Shop $shop,
        ?string $note,
        ?CarbonInterface $occurredAt,
    ): StockTransaction {
        $userId = auth()->id();
        if ($userId === null) {
            throw new InventoryException('You must be signed in.');
        }

        return StockTransaction::query()->create([
            'type' => $type,
            'shop_id' => $shop->id,
            'occurred_at' => $occurredAt ?? now(),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'created_by' => $userId,
            'amount' => '0.00',
        ]);
    }

    protected function activeProduct(int $productId): Product
    {
        $product = Product::query()->find($productId);
        if ($product === null || ! $product->is_active) {
            throw new InventoryException('That product is not available.');
        }

        return $product;
    }

    protected function requireBranch(Shop $shop, ?ShopType $only = null): void
    {
        if (! $shop->type->isBranch()) {
            throw new InventoryException('Choose a Tier 2 or Tier 3 shop.');
        }
        if ($only !== null && $shop->type !== $only) {
            throw new InventoryException("This action is only for {$only->label()} shops.");
        }
        if (! $shop->is_active) {
            throw new InventoryException("{$shop->name} is deactivated.");
        }
    }
}
