<?php

use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** Sign in as the admin and return the Main Shop. */
function asAdmin(): Shop
{
    test()->actingAs(User::factory()->admin()->create());

    return Shop::query()->where('type', 'main')->first() ?? Shop::factory()->main()->create();
}

/** @return array{0: int, 1: int} [qty_main, qty_local] */
function stockOf(Shop $shop, Product $product): array
{
    $row = ShopStock::query()->where('shop_id', $shop->id)->where('product_id', $product->id)->first();

    return [$row->qty_main ?? 0, $row->qty_local ?? 0];
}
