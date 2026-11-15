<?php

namespace App\Filament\Shop;

use App\Models\Shop;
use Illuminate\Auth\Access\AuthorizationException;

/** The signed-in viewer's own shop. Every portal query is scoped through this. */
final class ShopScope
{
    public static function shop(): Shop
    {
        $shop = auth()->user()?->shop;
        if ($shop === null) {
            throw new AuthorizationException;
        }

        return $shop;
    }

    public static function id(): int
    {
        return self::shop()->id;
    }
}
