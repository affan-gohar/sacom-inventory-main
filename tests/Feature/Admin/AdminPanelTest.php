<?php

use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Shops\Pages\CreateShop;
use App\Filament\Resources\Shops\Pages\ViewShop;
use App\Filament\Resources\Shops\RelationManagers\StockRelationManager;
use App\Filament\Resources\StockTransactions\Pages\ManageStockTransactions;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockTransaction;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->main = asAdmin();
    Filament::setCurrentPanel('admin');
});

it('renders every admin page', function (string $path) {
    $shop = Shop::factory()->tier3()->create();
    $product = Product::factory()->create();
    app(RecordStockIn::class)->handle([['product_id' => $product->id, 'qty' => 5]]);
    app(TransferStock::class)->handle($shop, [['product_id' => $product->id, 'qty' => 2]]);

    $this->get(str_replace(['{shop}', '{product}'], [(string) $shop->id, (string) $product->id], $path))->assertOk();
})->with([
    '/admin', '/admin/products', '/admin/categories', '/admin/shops', '/admin/shops/create',
    '/admin/shops/{shop}', '/admin/shops/{shop}/edit', '/admin/stock-transactions', '/admin/audit-logs', '/admin/settings', '/admin/products/{product}',
]);

it('sends a shop login that opens the admin panel to its own portal', function () {
    $shop = Shop::factory()->tier2()->create();
    $this->actingAs(User::factory()->viewerOf($shop)->create());

    $this->get('/admin/products')->assertRedirect('/shop');
});

it('records a multi-product transfer from the Stock Activity page', function () {
    $shop = Shop::factory()->tier3()->create();
    [$a, $b] = Product::factory()->count(2)->create();
    app(RecordStockIn::class)->handle([['product_id' => $a->id, 'qty' => 10], ['product_id' => $b->id, 'qty' => 10]]);

    Livewire::test(ManageStockTransactions::class)
        ->callAction('transfer', data: [
            'shop_id' => $shop->id,
            'lines' => [
                ['product_id' => $a->id, 'qty' => 3, 'unit_price' => '15'],
                ['product_id' => $b->id, 'qty' => 4, 'unit_price' => '5'],
            ],
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(stockOf($shop, $a))->toBe([3, 0])->and(stockOf($shop, $b))->toBe([4, 0]);
});

it('shows a refusal and saves nothing when a transfer exceeds Main stock', function () {
    $shop = Shop::factory()->tier3()->create();
    $a = Product::factory()->create(['name' => 'Dell Laptop i7']);

    Livewire::test(ManageStockTransactions::class)
        ->callAction('transfer', data: [
            'shop_id' => $shop->id,
            'lines' => [['product_id' => $a->id, 'qty' => 1, 'unit_price' => '850']],
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertNotified('Not saved');

    expect(stockOf($shop, $a))->toBe([0, 0]);
});

it('creates a shop with a read-only login', function () {
    Livewire::test(CreateShop::class)
        ->fillForm(['name' => 'Zen Shop', 'code' => 'ZEN-T3-08', 'type' => 'tier3', 'login_username' => 'zen_owner'])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('username', 'zen_owner')->firstOrFail();
    expect($user->role->value)->toBe('viewer')
        ->and($user->shop->name)->toBe('Zen Shop');
});

it('records a Tier 2 sale from the shop page', function () {
    $shop = Shop::factory()->tier2()->create();
    $p = Product::factory()->create();
    app(RecordStockIn::class)->handle([['product_id' => $p->id, 'qty' => 10]]);
    app(TransferStock::class)->handle($shop, [['product_id' => $p->id, 'qty' => 6]]);

    Livewire::test(ViewShop::class, ['record' => $shop->id])
        ->callAction('branchSale', data: [
            'lines' => [['product_id' => $p->id, 'qty' => 2]],
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors();

    expect(stockOf($shop, $p))->toBe([4, 0]);
    Livewire::test(StockRelationManager::class, ['ownerRecord' => $shop, 'pageClass' => ViewShop::class])->assertOk();
});

it('creates a product with opening stock recorded as a Main Shop stock-in', function () {
    $category = Category::factory()->create();

    Livewire::test(ManageProducts::class)
        ->callAction('create', data: [
            'name' => 'USB-C Cable', 'category_id' => $category->id, 'selling_price' => '12',
            'purchase_cost' => '4', 'opening_qty' => 25, 'opening_note' => 'Apex batch #9', 'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $product = Product::query()->where('name', 'USB-C Cable')->firstOrFail();
    $txn = StockTransaction::query()->where('type', 'stock_in')->latest('id')->firstOrFail();
    expect(stockOf($this->main, $product))->toBe([25, 0])
        ->and($txn->note)->toBe('Apex batch #9')
        ->and($txn->amount)->toBe('100.00');
});

it('creates a product without stock when no opening quantity is given', function () {
    $category = Category::factory()->create();

    Livewire::test(ManageProducts::class)
        ->callAction('create', data: ['name' => 'Earbuds', 'category_id' => $category->id, 'selling_price' => '30', 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(StockTransaction::query()->where('type', 'stock_in')->count())->toBe(0)
        ->and(Product::query()->where('name', 'Earbuds')->exists())->toBeTrue();
});
