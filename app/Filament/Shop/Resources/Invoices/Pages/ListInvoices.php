<?php

namespace App\Filament\Shop\Resources\Invoices\Pages;

use App\Filament\Shop\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
