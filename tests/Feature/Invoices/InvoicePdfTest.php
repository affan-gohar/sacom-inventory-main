<?php

use App\Domain\Actions\RecordPayment;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Shop::factory()->main()->create();
    $this->zen = Shop::factory()->tier3()->create(['name' => 'Zen Shop']);
    $p = Product::factory()->create(['selling_price' => '35.00', 'purchase_cost' => '777.31']);
    app(RecordStockIn::class)->handle([['product_id' => $p->id, 'qty' => 10]]);
    $this->invoice = app(TransferStock::class)->handle($this->zen, [['product_id' => $p->id, 'qty' => 5]]);
    app(RecordPayment::class)->handle($this->zen, [$this->invoice->lines->first()->id => 2]);
});

it('lets the admin view and download any invoice as a PDF', function () {
    $this->get(route('invoices.pdf', $this->invoice))
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->get(route('invoices.pdf', [$this->invoice, 'download' => 1]))
        ->assertOk()->assertDownload("{$this->invoice->number}.pdf");
});

it('lets a shop download its own invoice', function () {
    $this->actingAs(User::factory()->viewerOf($this->zen)->create());

    $this->get(route('invoices.pdf', $this->invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('hides other shops\' invoices from a shop login', function () {
    $other = Shop::factory()->tier3()->create();
    $this->actingAs(User::factory()->viewerOf($other)->create());

    $this->get(route('invoices.pdf', $this->invoice))->assertNotFound();
});

it('sends signed-out visitors to the login page', function () {
    auth()->logout();

    $this->get(route('invoices.pdf', $this->invoice))->assertRedirect('/shop/login');
});

it('shows line status and balance, never the purchase cost', function () {
    $html = view('pdf.invoice', [
        'invoice' => $this->invoice->load('shop', 'lines.product'),
        'lines' => $this->invoice->lines,
        'status' => $this->invoice->status(),
        'totals' => ['units' => 5, 'goods' => '175.00', 'returned' => '0.00', 'paid' => '70.00', 'due' => '105.00'],
        'business' => ['name' => 'SACOM Inventory', 'address' => null, 'email' => null, 'phone' => null, 'footer' => null],
        'printedAt' => now(),
        'qr' => null,
        'qrCaption' => null,
    ])->render();

    expect($html)->toContain('2 PAID')->toContain('3 UNPAID')->toContain('PARTIALLY PAID')
        ->toContain('105.00')->not->toContain('777.31');
});
