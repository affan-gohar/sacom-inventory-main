<?php

use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Pages\Settings;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    asAdmin();
    Filament::setCurrentPanel('admin');
    Storage::fake('public');
});

it('saves the business profile', function () {
    Livewire::test(Settings::class)
        ->fillForm(['business_name' => 'SACOM Central Hub', 'business_address' => 'Kuala Lumpur', 'business_phone' => '+60 12-345 6789'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Settings saved');

    expect(Setting::get('business_address'))->toBe('Kuala Lumpur')
        ->and(AuditLog::query()->where('action', 'settings_updated')->exists())->toBeTrue();
});

it('stores an uploaded payment QR on disk and its path in the database', function () {
    Livewire::test(Settings::class)
        ->fillForm(['business_name' => 'SACOM', 'payment_qr_path' => UploadedFile::fake()->image('duitnow.png', 300, 300), 'payment_qr_caption' => 'Scan to pay — DuitNow'])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = Setting::get('payment_qr_path');
    expect($path)->toStartWith('settings/');
    Storage::disk('public')->assertExists($path);
});

it('deletes the old QR file when it is replaced', function () {
    Storage::disk('public')->put('settings/old.png', 'x');
    Setting::put('payment_qr_path', 'settings/old.png');

    Livewire::test(Settings::class)
        ->fillForm(['business_name' => 'SACOM', 'payment_qr_path' => [UploadedFile::fake()->image('new.png')]])
        ->call('save')
        ->assertHasNoFormErrors();

    Storage::disk('public')->assertMissing('settings/old.png');
    Storage::disk('public')->assertExists(Setting::get('payment_qr_path'));
});

it('rejects files that are not images', function () {
    Livewire::test(Settings::class)
        ->fillForm(['business_name' => 'SACOM', 'payment_qr_path' => UploadedFile::fake()->create('qr.pdf', 10, 'application/pdf')])
        ->call('save')
        ->assertHasFormErrors(['payment_qr_path']);
});

it('embeds the QR image in the invoice HTML', function () {
    Storage::disk('public')->put('settings/qr.png', UploadedFile::fake()->image('qr.png', 200, 200)->getContent());
    Setting::put('payment_qr_path', 'settings/qr.png');
    Setting::put('payment_qr_caption', 'Scan to pay — DuitNow');
    Setting::put('business_address', 'Jalan Ampang, Kuala Lumpur');

    $shop = Shop::factory()->tier3()->create();
    $p = Product::factory()->create();
    app(RecordStockIn::class)->handle([['product_id' => $p->id, 'qty' => 2]]);
    $invoice = app(TransferStock::class)->handle($shop, [['product_id' => $p->id, 'qty' => 1]]);

    Pdf::shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) {
        $html = view($view, $data)->render();
        expect($html)->toContain('data:image/png;base64,')->toContain('Scan to pay — DuitNow')->toContain('Jalan Ampang');

        return true;
    })->andReturnSelf();
    Pdf::shouldReceive('setPaper')->andReturnSelf();
    Pdf::shouldReceive('stream')->andReturn(response('pdf'));

    $this->get(route('invoices.pdf', $invoice))->assertOk();
});
