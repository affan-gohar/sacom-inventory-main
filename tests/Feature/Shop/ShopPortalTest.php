<?php

use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Shop\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Shop\Resources\Stock\Pages\ListStock;
use App\Filament\Shop\Widgets\ShopOverview;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    asAdmin();
    $this->zen = Shop::factory()->tier3()->create(['name' => 'Zen Shop']);
    $this->other = Shop::factory()->tier3()->create(['name' => 'Other Shop']);
    $this->mouse = Product::factory()->create(['name' => 'Wireless Mouse', 'selling_price' => '15.00', 'purchase_cost' => '777.31']);
    $this->glass = Product::factory()->create(['name' => 'Privacy Glass']);
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 20, 'unit_cost' => '777.31'], ['product_id' => $this->glass->id, 'qty' => 20]]);
    $this->zenInvoice = app(TransferStock::class)->handle($this->zen, [['product_id' => $this->mouse->id, 'qty' => 5]]);
    $this->otherInvoice = app(TransferStock::class)->handle($this->other, [['product_id' => $this->glass->id, 'qty' => 3]]);

    $this->actingAs(User::factory()->viewerOf($this->zen)->create());
    Filament::setCurrentPanel('shop');
});

it('renders every portal page for a shop login', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/shop', '/shop/stock', '/shop/invoices', '/shop/history']);

it('shows only the viewer\'s own stock and invoices', function () {
    Livewire::test(ListStock::class)->assertSee('Wireless Mouse')->assertDontSee('Privacy Glass');
    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$this->zenInvoice])
        ->assertCanNotSeeTableRecords([$this->otherInvoice]);
});

it('never shows purchase cost in the portal', function (string $path) {
    $this->get($path)->assertDontSee('777.31');
})->with(['/shop', '/shop/stock', '/shop/history']);

it('shows the pending balance on the dashboard', function () {
    Livewire::test(ShopOverview::class)->assertSee('RM 75.00')->assertDontSee('777.31');
});

it('has no way to change anything', function () {
    Livewire::test(ListStock::class)->assertActionDoesNotExist('create');
    Livewire::test(ListInvoices::class)->assertActionDoesNotExist('create');
});

it('sends the admin who opens the portal back to the admin panel', function () {
    $this->actingAs(User::query()->where('role', 'admin')->first());
    $this->get('/shop')->assertRedirect('/admin');
});

it('locks out deactivated logins and logins of deactivated shops', function () {
    $user = User::factory()->viewerOf($this->zen)->create(['is_active' => false]);
    $this->actingAs($user)->get('/shop')->assertRedirect('/shop/login');
    $this->assertGuest();

    $this->other->update(['is_active' => false]);
    $this->actingAs(User::factory()->viewerOf($this->other)->create())->get('/shop')->assertRedirect('/shop/login');
    $this->assertGuest();
});
