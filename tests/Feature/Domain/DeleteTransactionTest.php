<?php

use App\Domain\Actions\DeleteTransaction;
use App\Domain\Actions\RecordBranchSale;
use App\Domain\Actions\RecordPayment;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\ReturnToMain;
use App\Domain\Actions\TransferStock;
use App\Domain\InventoryException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Shop;

beforeEach(function () {
    $this->main = asAdmin();
    $this->mouse = Product::factory()->create(['selling_price' => '15.00']);
    $this->tier2 = Shop::factory()->tier2()->create();
    $this->tier3 = Shop::factory()->tier3()->create();
    $this->stockIn = app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 20]]);
});

it('deletes a transfer, restores stock and removes its invoice', function () {
    $invoice = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 8]]);

    app(DeleteTransaction::class)->handle($invoice->transaction, 'Typed 8 instead of 2');

    expect(stockOf($this->main, $this->mouse))->toBe([20, 0])
        ->and(stockOf($this->tier3, $this->mouse))->toBe([0, 0])
        ->and(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'deleted_transfer')->first())
        ->before->invoice->toBe($invoice->number)
        ->note->toBe('Typed 8 instead of 2');
});

it('blocks deleting a transfer that has payments or returns against it', function () {
    $invoice = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 8]]);
    $payment = app(RecordPayment::class)->handle($this->tier3, [$invoice->lines->first()->id => 1]);

    expect(fn () => app(DeleteTransaction::class)->handle($invoice->transaction))
        ->toThrow(InventoryException::class, "Payment #{$payment->id}");

    app(DeleteTransaction::class)->handle($payment);
    app(DeleteTransaction::class)->handle($invoice->transaction);
    expect(stockOf($this->main, $this->mouse))->toBe([20, 0]);
});

it('blocks deleting a stock-in whose stock has moved on', function () {
    app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 15]]);

    expect(fn () => app(DeleteTransaction::class)->handle($this->stockIn))
        ->toThrow(InventoryException::class, 'already been sold, transferred or returned');
    expect(stockOf($this->main, $this->mouse))->toBe([5, 0]);
});

it('blocks deleting a Tier 2 sale that has been paid, until the payment is deleted', function () {
    $line = app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();
    $sale = app(RecordBranchSale::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 3]]);
    $payment = app(RecordPayment::class)->handle($this->tier2, [$line->id => 3]);

    expect(fn () => app(DeleteTransaction::class)->handle($sale))->toThrow(InventoryException::class, 'Delete the payment first');

    app(DeleteTransaction::class)->handle($payment);
    app(DeleteTransaction::class)->handle($sale);
    $line->refresh();
    expect([$line->qty_sold, $line->qty_paid])->toBe([0, 0])
        ->and(stockOf($this->tier2, $this->mouse))->toBe([5, 0]);
});

it('reverses a Tier 3 payment, putting the units back on the shelf', function () {
    $line = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();
    $payment = app(RecordPayment::class)->handle($this->tier3, [$line->id => 4]);

    app(DeleteTransaction::class)->handle($payment);

    $line->refresh();
    expect([$line->qty_sold, $line->qty_paid])->toBe([0, 0])
        ->and(stockOf($this->tier3, $this->mouse))->toBe([5, 0]);
});

it('reverses a return', function () {
    $line = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 5]])->lines->first();
    $return = app(ReturnToMain::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 2]]);

    app(DeleteTransaction::class)->handle($return);

    expect($line->fresh()->qty_returned)->toBe(0)
        ->and(stockOf($this->tier3, $this->mouse))->toBe([5, 0])
        ->and(stockOf($this->main, $this->mouse))->toBe([15, 0]);
});

it('never reuses a deleted invoice number', function () {
    $first = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 1]]);
    app(DeleteTransaction::class)->handle($first->transaction);
    $second = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 1]]);

    expect(substr($second->number, -5))->toBe('00002');
});

it('refuses to edit or delete audit log entries', function () {
    $entry = AuditLog::query()->first();

    expect(fn () => $entry->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
