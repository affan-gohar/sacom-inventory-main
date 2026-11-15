<?php

use App\Domain\Actions\RecordBranchSale;
use App\Domain\Actions\RecordCounterSale;
use App\Domain\Actions\RecordLocalStockIn;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\ReturnToMain;
use App\Domain\Actions\TransferStock;
use App\Domain\InventoryException;
use App\Models\AuditLog;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;

beforeEach(function () {
    $this->main = asAdmin();
    $this->mouse = Product::factory()->create(['name' => 'Wireless Mouse', 'selling_price' => '15.00']);
    $this->tier2 = Shop::factory()->tier2()->create(['name' => 'Home Shop']);
    $this->tier3 = Shop::factory()->tier3()->create(['name' => 'Zen Shop']);
});

it('adds supplier stock to the Main Shop and records the purchase cost', function () {
    $txn = app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 100, 'unit_cost' => '4.50']], 'Apex batch #882');

    expect(stockOf($this->main, $this->mouse))->toBe([100, 0])
        ->and($this->mouse->fresh()->purchase_cost)->toBe('4.50')
        ->and($txn->amount)->toBe('450.00')
        ->and(AuditLog::query()->where('action', 'stock_in')->first()->note)->toBe('Apex batch #882');
});

it('transfers stock, creates an invoice and freezes the price on its lines', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 50]]);

    $invoice = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 10]]);
    $this->mouse->update(['selling_price' => '99.00']);

    expect(stockOf($this->main, $this->mouse))->toBe([40, 0])
        ->and(stockOf($this->tier3, $this->mouse))->toBe([10, 0])
        ->and($invoice->number)->toMatch('/^SAC-\d{4}-00001$/')
        ->and($invoice->lines->first()->unit_price)->toBe('15.00')
        ->and($invoice->transaction->amount)->toBe('150.00');
});

it('numbers invoices sequentially', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 50]]);
    $a = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 1]]);
    $b = app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 1]]);

    expect(substr($b->number, -5))->toBe('00002')
        ->and(substr($a->number, 0, 8))->toBe(substr($b->number, 0, 8));
});

it('refuses to transfer more than the Main Shop holds and saves nothing', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 5]]);
    $other = Product::factory()->create();
    app(RecordStockIn::class)->handle([['product_id' => $other->id, 'qty' => 5]]);

    expect(fn () => app(TransferStock::class)->handle($this->tier3, [
        ['product_id' => $other->id, 'qty' => 2],
        ['product_id' => $this->mouse->id, 'qty' => 6],
    ]))->toThrow(InventoryException::class, 'Only 5 of Wireless Mouse available');

    expect(stockOf($this->main, $other))->toBe([5, 0])
        ->and(InvoiceLine::query()->count())->toBe(0);
});

it('rejects zero and negative quantities', function (int $qty) {
    expect(fn () => app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => $qty]]))
        ->toThrow(InventoryException::class, 'at least 1');
})->with([0, -3]);

it('records a counter sale with an editable price', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 10]]);
    $txn = app(RecordCounterSale::class)->handle([['product_id' => $this->mouse->id, 'qty' => 2, 'unit_price' => '12.50']]);

    expect(stockOf($this->main, $this->mouse))->toBe([8, 0])
        ->and($txn->amount)->toBe('25.00');
});

it('keeps local stock separate and never invoices it', function () {
    app(RecordLocalStockIn::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 7, 'unit_cost' => '3.00']]);

    expect(stockOf($this->tier2, $this->mouse))->toBe([0, 7])
        ->and(InvoiceLine::query()->count())->toBe(0);
});

it('uses Main stock first on a Tier 2 sale, oldest invoice first, and only Main stock becomes due', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 10]]);
    $old = app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 2]], occurredAt: now()->subMonth());
    $new = app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 2, 'unit_price' => '20.00']]);
    app(RecordLocalStockIn::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 5]]);

    $sale = app(RecordBranchSale::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 6]]);

    expect(stockOf($this->tier2, $this->mouse))->toBe([0, 3])
        ->and($old->lines->first()->fresh()->qty_sold)->toBe(2)
        ->and($new->lines->first()->fresh()->qty_sold)->toBe(2)
        ->and($sale->amount)->toBe('70.00'); // 2 × 15 + 2 × 20
});

it('only allows branch sales at Tier 2 shops', function () {
    expect(fn () => app(RecordBranchSale::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 1]]))
        ->toThrow(InventoryException::class, 'only for My Shop (Tier 2)');
});

it('returns oldest in-shop stock first and credits the billed price', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 10]]);
    $sep = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 2]], occurredAt: now()->subMonth());
    $oct = app(TransferStock::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 2, 'unit_price' => '20.00']]);

    $return = app(ReturnToMain::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 3]]);

    expect($sep->lines->first()->fresh()->qty_returned)->toBe(2)
        ->and($oct->lines->first()->fresh()->qty_returned)->toBe(1)
        ->and($return->amount)->toBe('50.00')
        ->and(stockOf($this->main, $this->mouse))->toBe([9, 0])
        ->and(stockOf($this->tier3, $this->mouse))->toBe([1, 0]);
});

it('refuses to return locally bought stock', function () {
    app(RecordLocalStockIn::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 4]]);

    expect(fn () => app(ReturnToMain::class)->handle($this->tier3, [['product_id' => $this->mouse->id, 'qty' => 1]]))
        ->toThrow(InventoryException::class, 'Locally bought stock cannot be returned');
});

it('keeps the ledger equal to the running totals', function () {
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 30]]);
    app(TransferStock::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 10]]);
    app(RecordBranchSale::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 4]]);
    app(ReturnToMain::class)->handle($this->tier2, [['product_id' => $this->mouse->id, 'qty' => 2]]);
    app(RecordCounterSale::class)->handle([['product_id' => $this->mouse->id, 'qty' => 3]]);

    foreach ([$this->main, $this->tier2] as $shop) {
        $ledger = (int) StockMovement::query()->where('shop_id', $shop->id)->where('product_id', $this->mouse->id)->sum('qty');
        expect($ledger)->toBe(array_sum(stockOf($shop, $this->mouse)));
    }
    // Branch Main-supplied stock equals the in-shop quantity on its invoice lines.
    $inShop = InvoiceLine::query()->get()->sum(fn ($l) => $l->qtyInShop());
    expect($inShop)->toBe(stockOf($this->tier2, $this->mouse)[0]);
});

it('treats a newly created shop as active without a reload', function () {
    $shop = Shop::query()->create(['name' => 'New', 'code' => 'NEW-1', 'type' => 'tier3']);
    app(RecordStockIn::class)->handle([['product_id' => $this->mouse->id, 'qty' => 1]]);

    expect(app(TransferStock::class)->handle($shop, [['product_id' => $this->mouse->id, 'qty' => 1]])->number)->toStartWith('SAC-');
});
