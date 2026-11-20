<?php

use App\Domain\Actions\RecordLocalStockIn;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Shop\Resources\OtherShopsStock\Pages\ListOtherShopsStock;
use App\Filament\Shop\Widgets\OtherShopsOverview;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStock;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->main = asAdmin();
    $this->zain = Shop::factory()->tier2()->create(['name' => 'Zain Shop']);
    $this->om = Shop::factory()->tier2()->create(['name' => 'Om Shop']);
    $this->secret = Shop::factory()->tier3()->create(['name' => 'Secret Shop']);
    $this->mouse = Product::factory()->create(['name' => 'Wireless Mouse', 'selling_price' => '35.00', 'purchase_cost' => '777.31']);
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 20, 'unit_cost' => '777.31']]);
    app(TransferStock::class)->handle($this->om, [['product_id' => $this->mouse->id, 'qty' => 1]]);
    app(TransferStock::class)->handle($this->secret, [['product_id' => $this->mouse->id, 'qty' => 4]]);
    app(RecordLocalStockIn::class)->handle($this->om, [['product_name' => 'Om Only Charger', 'qty' => 2]]);

    $this->viewer = User::factory()->viewerOf($this->zain)->create();
    $this->viewer->viewableShops()->sync([$this->main->id, $this->om->id]);
    $this->actingAs($this->viewer);
    Filament::setCurrentPanel('shop');
});

it('shows live stock of the allowed shops only, read-only', function () {
    $allowed = ShopStock::query()->whereIn('shop_id', [$this->main->id, $this->om->id])->get();
    $hidden = ShopStock::query()->where('shop_id', $this->secret->id)->get();

    $this->get('/shop/other-shops-stock')->assertOk()->assertSee('Other Shops Stock')->assertDontSee('777.31');

    Livewire::test(ListOtherShopsStock::class)
        ->assertCanSeeTableRecords($allowed)
        ->assertCanNotSeeTableRecords($hidden)
        ->searchTable('Charger')
        ->assertCanSeeTableRecords(ShopStock::query()->where('shop_id', $this->om->id)->whereHas('product', fn ($q) => $q->where('name', 'Om Only Charger'))->get())
        ->assertActionDoesNotExist('create');
});

it('narrows to one shop from a dashboard card and ignores shops it may not view', function () {
    Livewire::withQueryParams(['shop' => $this->om->id])->test(ListOtherShopsStock::class)
        ->assertCanSeeTableRecords(ShopStock::query()->where('shop_id', $this->om->id)->get())
        ->assertCanNotSeeTableRecords(ShopStock::query()->where('shop_id', $this->main->id)->get());

    Livewire::withQueryParams(['shop' => $this->secret->id])->test(ListOtherShopsStock::class)
        ->assertCanNotSeeTableRecords(ShopStock::query()->where('shop_id', $this->secret->id)->get());
});

it('shows one dashboard card per allowed shop', function () {
    Livewire::test(OtherShopsOverview::class)
        ->assertSee($this->main->name.' — Total units')->assertSee('15')
        ->assertSee('Om Shop — Total units')->assertSee('3')
        ->assertDontSee('Secret Shop');
    $this->get('/shop')->assertOk();
});

it('keeps the menu item but shows nothing, and no cards, when the admin allows no shops', function () {
    $this->viewer->viewableShops()->sync([]);

    $this->get('/shop/other-shops-stock')->assertOk()->assertSee('No shops shared with you yet');
    Livewire::test(ListOtherShopsStock::class)->assertCanNotSeeTableRecords(ShopStock::all());
    $this->get('/shop')->assertOk();
    expect(OtherShopsOverview::canView())->toBeFalse();
});
