<?php

use App\Domain\Actions\RecordLocalStockIn;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Pages\ChangePassword;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Shops\Pages\ViewShop;
use App\Filament\Resources\Shops\RelationManagers\UsersRelationManager;
use App\Filament\Support\InventoryForm;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->main = asAdmin();
    Filament::setCurrentPanel('admin');
    $this->zain = Shop::factory()->tier2()->create(['name' => 'Zain Shop']);
    $this->om = Shop::factory()->tier2()->create(['name' => 'Om Shop']);
    $this->mouse = Product::factory()->create(['name' => 'Wireless Mouse', 'selling_price' => '35.00']);
});

// Issue 1 — local stock-in accepts any product name, unlinked from the Main catalogue.

it('creates a branch-local product from a typed name and adds it as local stock', function () {
    app(RecordLocalStockIn::class)->handle($this->zain, [['product_name' => '  Phone  Holder X ', 'qty' => 3, 'unit_cost' => '2.50']]);

    $product = Product::query()->where('name', 'Phone Holder X')->firstOrFail();
    expect($product->shop_id)->toBe($this->zain->id)
        ->and($product->category->name)->toBe(RecordLocalStockIn::LOCAL_CATEGORY)
        ->and(stockOf($this->zain, $product))->toBe([0, 3]);

    // Same name again (any case) reuses the product; a Main catalogue name reuses that product.
    app(RecordLocalStockIn::class)->handle($this->zain, [['product_name' => 'phone holder x', 'qty' => 2], ['product_name' => 'wireless mouse', 'qty' => 1]]);
    expect(stockOf($this->zain, $product))->toBe([0, 5])
        ->and(stockOf($this->zain, $this->mouse))->toBe([0, 1])
        ->and(Product::query()->count())->toBe(2);
});

it('keeps branch-local products out of the Main catalogue and other branches', function () {
    app(RecordLocalStockIn::class)->handle($this->zain, [['product_name' => 'Phone Holder X', 'qty' => 3]]);
    $local = Product::query()->where('name', 'Phone Holder X')->firstOrFail();

    expect(InventoryForm::productOptions($this->main->id)->keys()->all())->toBe([$this->mouse->id])
        ->and(InventoryForm::productOptions($this->om->id)->keys()->all())->toBe([$this->mouse->id])
        ->and(InventoryForm::productOptions($this->zain->id)->keys()->all())->toContain($local->id);

    Livewire::test(ManageProducts::class)->assertCanSeeTableRecords([$this->mouse])->assertCanNotSeeTableRecords([$local]);
});

it('records a free-text local stock-in from the shop page', function () {
    Livewire::test(ViewShop::class, ['record' => $this->zain->getRouteKey()])
        ->callAction('localStockIn', data: [
            'lines' => [['product_name' => 'USB-C Cable 2m', 'qty' => 4, 'unit_cost' => '3']],
            'note' => 'Dealer Ali',
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(stockOf($this->zain, Product::query()->where('name', 'USB-C Cable 2m')->firstOrFail()))->toBe([0, 4]);
});

// Issue 3 — custom passwords.

it('lets the admin set a custom password for a shop login and choose which shops it can view', function () {
    $user = User::factory()->viewerOf($this->zain)->create();

    Livewire::test(UsersRelationManager::class, ['ownerRecord' => $this->zain, 'pageClass' => ViewShop::class])
        ->callAction(TestAction::make('setPassword')->table($user), data: ['password' => 'zain1234', 'password_confirmation' => 'zain1234'])
        ->assertHasNoActionErrors();

    expect(Hash::check('zain1234', $user->fresh()->password))->toBeTrue();
});

it('lets the admin change their own password', function () {
    Livewire::test(ChangePassword::class)
        ->fillForm(['current_password' => 'wrong', 'password' => 'admin123', 'password_confirmation' => 'admin123'])
        ->call('save')
        ->assertHasErrors(['data.current_password']);

    Livewire::test(ChangePassword::class)
        ->fillForm(['current_password' => 'password', 'password' => 'admin123', 'password_confirmation' => 'admin123'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Password changed');

    expect(Hash::check('admin123', auth()->user()->fresh()->password))->toBeTrue();
    $this->get('/admin/change-password')->assertOk();
});

// Issue 4 — no invoice reference under the QR.

it('prints the payment QR without the invoice reference line', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 5]]);
    $invoice = app(TransferStock::class)->handle($this->zain, [['product_id' => $this->mouse->id, 'qty' => 2]]);

    $html = view('pdf.invoice', [
        'invoice' => $invoice->load('shop', 'lines.product'),
        'lines' => $invoice->lines,
        'status' => $invoice->status(),
        'totals' => ['units' => 2, 'goods' => '70.00', 'returned' => '0.00', 'paid' => '0.00', 'due' => '70.00'],
        'business' => ['name' => 'SACOM', 'address' => null, 'email' => null, 'phone' => null, 'footer' => null],
        'printedAt' => now(),
        'qr' => 'data:image/png;base64,iVBORw0KGgo=',
        'qrCaption' => 'MayBank',
    ])->render();

    expect($html)->toContain('MayBank')->not->toContain('Ref: '.$invoice->number);
});

// Issue 6 — export the filtered product history.

it('exports the product history to an Excel file', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 5]]);
    app(TransferStock::class)->handle($this->zain, [['product_id' => $this->mouse->id, 'qty' => 2]]);

    Livewire::test(MovementsRelationManager::class, ['ownerRecord' => $this->mouse, 'pageClass' => ViewProduct::class])
        ->callAction(TestAction::make('exportExcel')->table())
        ->assertFileDownloaded();
});
