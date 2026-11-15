<?php

use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Resources\Shops\Pages\ViewShop;
use App\Filament\Support\PaymentForm;
use App\Models\Product;
use App\Models\Shop;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    asAdmin();
    Filament::setCurrentPanel('admin');
    $this->zen = Shop::factory()->tier3()->create(['name' => 'Zen Shop']);
    $this->mouse = Product::factory()->create(['name' => 'Wireless Mouse', 'selling_price' => '35.00']);
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 20]]);
    $this->invoice = app(TransferStock::class)->handle($this->zen, [['product_id' => $this->mouse->id, 'qty' => 5]]);
    $this->line = $this->invoice->lines->first();
});

it('lists every payable line for the shop', function () {
    expect(PaymentForm::lines($this->zen)->pluck('id')->all())->toBe([$this->line->id]);
});

it('records Zen paying for 2 of 5 pieces', function () {
    Livewire::test(ViewShop::class, ['record' => $this->zen->id])
        ->callAction('payment', data: [
            'payments' => [['line_id' => $this->line->id, 'max' => 5, 'pay_qty' => 2]],
            'note' => 'Cash',
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Payment recorded — RM 70.00');

    $this->line->refresh();
    expect([$this->line->qty_paid, $this->line->qtyUnpaid()])->toBe([2, 3])
        ->and($this->invoice->fresh()->load('lines')->status())->toBe('Partially paid')
        ->and(stockOf($this->zen, $this->mouse))->toBe([3, 0]);
});

it('refuses to pay more than is unpaid', function () {
    Livewire::test(ViewShop::class, ['record' => $this->zen->id])
        ->callAction('payment', data: [
            'payments' => [['line_id' => $this->line->id, 'max' => 5, 'pay_qty' => 6]],
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertHasActionErrors();

    expect($this->line->fresh()->qty_paid)->toBe(0);
});
