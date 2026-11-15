<?php

namespace App\Enums;

enum AllocationKind: string
{
    case Sold = 'sold';
    case Paid = 'paid';
    case Returned = 'returned';
}
