<?php

namespace App\Domain;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Sequential invoice numbers per month: SAC-YYMM-00001. Never reused, even after delete. */
final class InvoiceNumber
{
    public static function next(CarbonInterface $at): string
    {
        $period = $at->format('ym');

        DB::table('invoice_sequences')->insertOrIgnore(['period' => $period, 'last_number' => 0]);
        $row = DB::table('invoice_sequences')->where('period', $period)->lockForUpdate()->first();
        $next = ((int) $row->last_number) + 1;
        DB::table('invoice_sequences')->where('period', $period)->update(['last_number' => $next]);

        return sprintf('SAC-%s-%05d', $period, $next);
    }
}
