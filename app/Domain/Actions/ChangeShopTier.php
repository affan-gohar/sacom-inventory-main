<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\InventoryException;
use App\Enums\ShopType;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Switch a branch between Tier 2 and Tier 3. Invoice line quantities keep their state; only the
 * rules applied from now on change (Tier 2: due when sold; Tier 3: month-end credit, no alerts).
 */
class ChangeShopTier
{
    public function handle(Shop $shop, ShopType $to): Shop
    {
        if (! $shop->type->isBranch() || ! $to->isBranch()) {
            throw new InventoryException('Only Tier 2 and Tier 3 shops can change tier.');
        }
        if ($shop->type === $to) {
            throw new InventoryException("{$shop->name} is already {$to->label()}.");
        }

        return DB::transaction(function () use ($shop, $to) {
            $from = $shop->type;
            $shop->update(['type' => $to]);
            AuditLogger::record('tier_changed', $shop, $shop->id,
                before: ['type' => $from->value], after: ['type' => $to->value]);

            return $shop;
        });
    }
}
