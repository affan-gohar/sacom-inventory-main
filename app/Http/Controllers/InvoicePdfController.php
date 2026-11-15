<?php

namespace App\Http\Controllers;

use App\Domain\Money;
use App\Filament\Pages\Settings;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Branded invoice PDF. Admin: any invoice. Shop login: only its own shop's invoices (else 404). */
class InvoicePdfController
{
    public function __invoke(Invoice $invoice): Response
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return redirect('/shop/login');
        }
        $allowed = $user->isAdmin()
            ? $user->canAccessPanel(filament()->getPanel('admin'))
            : $user->canAccessPanel(filament()->getPanel('shop')) && $invoice->shop_id === $user->shop_id;
        abort_unless($allowed, 404);

        $invoice->load(['shop', 'lines.product:id,name']);
        $lines = $invoice->lines->sortBy('id')->values();

        $sum = fn (callable $qty) => Money::fromSen($lines->sum(fn (InvoiceLine $l) => $qty($l) * Money::toSen($l->unit_price)));

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'lines' => $lines,
            'status' => $invoice->status(),
            'totals' => [
                'units' => $lines->sum('qty'),
                'goods' => $sum(fn ($l) => $l->qty),
                'returned' => $sum(fn ($l) => $l->qty_returned),
                'paid' => $sum(fn ($l) => $l->qty_paid),
                'due' => $sum(fn ($l) => $l->qtyUnpaid()),
            ],
            'business' => [
                'name' => Setting::get('business_name', 'SACOM Inventory'),
                'address' => Setting::get('business_address'),
                'email' => Setting::get('business_email'),
                'phone' => Setting::get('business_phone'),
                'footer' => Setting::get('invoice_footer'),
            ],
            'qr' => self::paymentQr(),
            'qrCaption' => Setting::get('payment_qr_caption'),
            'printedAt' => now(),
        ])->setPaper('a4');

        $file = "{$invoice->number}.pdf";

        return request()->boolean('download') ? $pdf->download($file) : $pdf->stream($file);
    }

    /** The admin's payment QR as a data URI, embedded so the PDF never depends on a public URL. */
    private static function paymentQr(): ?string
    {
        $path = Setting::get('payment_qr_path');
        $disk = Storage::disk(Settings::QR_DISK);
        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        return 'data:'.$disk->mimeType($path).';base64,'.base64_encode($disk->get($path));
    }
}
