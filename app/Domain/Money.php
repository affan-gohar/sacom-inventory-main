<?php

namespace App\Domain;

/** Money is handled as integer sen internally, and as "12.34" strings at the DB boundary. */
final class Money
{
    public static function toSen(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    public static function fromSen(int $sen): string
    {
        return number_format($sen / 100, 2, '.', '');
    }

    public static function format(string|int|float|null $amount): string
    {
        return 'RM '.number_format((float) $amount, 2);
    }

    /** Line total = qty × price, at 2 dp. */
    public static function lineTotal(int $qty, string|float|null $unitPrice): string
    {
        return self::fromSen($qty * self::toSen($unitPrice));
    }
}
