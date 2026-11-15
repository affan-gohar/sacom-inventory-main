<?php

use App\Http\Controllers\InvoicePdfController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
