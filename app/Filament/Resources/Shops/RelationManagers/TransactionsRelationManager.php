<?php

namespace App\Filament\Resources\Shops\RelationManagers;

use App\Filament\Resources\StockTransactions\StockTransactionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Activity';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return StockTransactionResource::table($table);
    }
}
