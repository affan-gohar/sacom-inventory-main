<?php

use App\Domain\Actions\ChangeShopTier;
use App\Domain\Actions\RecordBranchSale;
use App\Domain\Actions\RecordPayment;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Domain\InventoryException;
use App\Enums\ShopType;
use App\Models\Product;
use App\Models\Shop;

beforeEach(function () {
    $this->main = asAdmin();
    $this->mouse = Product::factory()->create(['selling_price' => '15.00']);
    $this->tier2 = Shop::factory()->tier2()->create();
    $this->tier3 = Shop::factory()->tier3()->create();
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 100]]);
});

it('lets Tier 3 pay part of a line, which records those units as sold', function () {
    $line = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();

    $payment = app(RecordPayment::class)->handle($this->tier3, [$line->id => 3]);

    $line->refresh();
    expect([$line->qty_sold, $line->qty_paid, $line->qtyInShop(), $line->qtyUnpaid()])->toBe([3, 3, 2, 2])
        ->and(stockOf($this->tier3, $this->mouse))->toBe([2, 0])
        ->and($payment->amount)->toBe('45.00');
});

it('refuses to overpay a Tier 3 line', function () {
    $line = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();

    expect(fn () => app(RecordPayment::class)->handle($this->tier3, [$line->id => 6]))
        ->toThrow(InventoryException::class, 'Only 5 unit(s) are unpaid');
});

it('lets Tier 2 pay only what has been sold', function () {
    $line = app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();
    app(RecordBranchSale::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 2]]);

    expect(fn () => app(RecordPayment::class)->handle($this->tier2, [$line->id => 3]))
        ->toThrow(InventoryException::class, 'Only 2 sold unit(s)');

    app(RecordPayment::class)->handle($this->tier2, [$line->id => 2]);
    expect($line->fresh()->qty_paid)->toBe(2)
        ->and(stockOf($this->tier2, $this->mouse))->toBe([3, 0]);
});

it('refuses to pay a line belonging to another shop', function () {
    $line = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();
    $otherTier3 = Shop::factory()->tier3()->create();

    expect(fn () => app(RecordPayment::class)->handle($otherTier3, [$line->id => 1]))
        ->toThrow(InventoryException::class, 'does not belong to this shop');
});

it('keeps sold-unpaid units due when a Tier 2 shop becomes Tier 3', function () {
    $line = app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();
    app(RecordBranchSale::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 2]]);

    app(ChangeShopTier::class)->handle($this->tier2, ShopType::Tier3);

    // Paying 3 on Tier 3 settles the 2 sold units first, then sells 1 from the shelf.
    app(RecordPayment::class)->handle($this->tier2->fresh(), [$line->id => 3]);
    $line->refresh();
    expect([$line->qty_sold, $line->qty_paid])->toBe([3, 3])
        ->and(stockOf($this->tier2, $this->mouse))->toBe([2, 0]);
});

it('does not allow the Main Shop to change tier', function () {
    expect(fn () => app(ChangeShopTier::class)->handle($this->main, ShopType::Tier2))
        ->toThrow(InventoryException::class);
});
