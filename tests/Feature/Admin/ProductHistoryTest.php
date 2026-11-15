<?php

use App\Domain\Actions\RecordPayment;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Products\RelationManagers\StockByShopRelationManager;
use App\Filament\Widgets\AdminStats;
use App\Filament\Widgets\LowStockAlerts;
use App\Filament\Widgets\ShopsAtAGlance;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStock;
use App\Models\StockMovement;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->main = asAdmin();
    Filament::setCurrentPanel('admin');
    $this->zen = Shop::factory()->tier3()->create(['name' => 'Zen Shop']);
    $this->home = Shop::factory()->tier2()->create(['name' => 'Home Shop']);
    $this->mouse = Product::factory()->create(['name' => 'Wireless Mouse', 'selling_price' => '35.00']);
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 20]], occurredAt: now()->subDays(10));
    $this->invoice = app(TransferStock::class)->handle($this->zen, [['product_id' => $this->mouse->id, 'qty' => 5]], occurredAt: now()->subDays(5));
    app(TransferStock::class)->handle($this->home, [['product_id' => $this->mouse->id, 'qty' => 8]], occurredAt: now()->subDays(4));
    app(RecordPayment::class)->handle($this->zen, [$this->invoice->lines->first()->id => 2], occurredAt: now()->subDay());
});

it('shows where the product is now, shop by shop', function () {
    Livewire::test(StockByShopRelationManager::class, ['ownerRecord' => $this->mouse, 'pageClass' => ViewProduct::class])
        ->assertCanSeeTableRecords(ShopStock::query()->where('product_id', $this->mouse->id)->get())
        ->assertSee('Zen Shop')->assertSee('Home Shop');
});

it('shows each movement with the stock left at that shop afterwards', function () {
    $component = Livewire::test(MovementsRelationManager::class, ['ownerRecord' => $this->mouse, 'pageClass' => ViewProduct::class]);

    $zenMoves = StockMovement::query()->where('shop_id', $this->zen->id)->orderBy('occurred_at')->get();
    $component->assertCanSeeTableRecords($zenMoves)
        ->assertTableColumnStateSet('stock_after', 5, $zenMoves[0])   // received 5
        ->assertTableColumnStateSet('stock_after', 3, $zenMoves[1])   // paid & sold 2
        ->assertSee('Received from Main')->assertSee('Paid & sold (Tier 3)');

    $mainMoves = StockMovement::query()->where('shop_id', $this->main->id)->orderBy('occurred_at')->get();
    $component->assertTableColumnStateSet('stock_after', 7, $mainMoves[2]); // 20 - 5 - 8
});

it('keeps "stock after" correct when the history is filtered by shop', function () {
    $last = StockMovement::query()->where('shop_id', $this->zen->id)->orderByDesc('occurred_at')->first();

    Livewire::test(MovementsRelationManager::class, ['ownerRecord' => $this->mouse, 'pageClass' => ViewProduct::class])
        ->filterTable('shop', $this->zen->id)
        ->assertCanSeeTableRecords([$last])
        ->assertTableColumnStateSet('stock_after', 3, $last);
});

it('renders the dashboard widgets with live figures', function () {
    Livewire::test(AdminStats::class)->assertSee('RM 105.00')->assertSee('Main Shop stock');
    Livewire::test(ShopsAtAGlance::class)->assertCanSeeTableRecords([$this->zen, $this->home]);
    Livewire::test(LowStockAlerts::class)->assertSee('Wireless Mouse'); // Home Shop has 8 ≤ 10
});
