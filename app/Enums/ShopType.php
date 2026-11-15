<?php

namespace App\Enums;

enum ShopType: string
{
    case Main = 'main';
    case Tier2 = 'tier2';
    case Tier3 = 'tier3';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main Shop (Tier 1)',
            self::Tier2 => 'My Shop (Tier 2)',
            self::Tier3 => 'Other Shop (Tier 3)',
        };
    }

    public function isBranch(): bool
    {
        return $this !== self::Main;
    }

    /** Low-stock alerts apply to the Main Shop and Tier 2 only. */
    public function hasStockAlerts(): bool
    {
        return $this !== self::Tier3;
    }
}
