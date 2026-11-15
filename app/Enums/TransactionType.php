<?php

namespace App\Enums;

enum TransactionType: string
{
    case StockIn = 'stock_in';
    case LocalStockIn = 'local_stock_in';
    case Transfer = 'transfer';
    case BranchSale = 'branch_sale';
    case CounterSale = 'counter_sale';
    case Return = 'return';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::StockIn => 'Stock In (Supplier)',
            self::LocalStockIn => 'Local Stock-In',
            self::Transfer => 'Transfer',
            self::BranchSale => 'Branch Sale',
            self::CounterSale => 'Counter Sale',
            self::Return => 'Return to Main',
            self::Payment => 'Payment',
        };
    }
}
