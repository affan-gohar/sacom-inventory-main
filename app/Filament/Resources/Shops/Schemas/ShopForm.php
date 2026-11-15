<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Enums\ShopType;
use App\Models\Shop;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shop')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->label('Store ID')->required()->maxLength(32)->unique(ignoreRecord: true)
                    ->placeholder('ZEN-T3-08'),
                Radio::make('type')->label('Tier')->required()->inline()
                    ->options([ShopType::Tier2->value => ShopType::Tier2->label(), ShopType::Tier3->value => ShopType::Tier3->label()])
                    ->descriptions([
                        ShopType::Tier2->value => 'Your own branch. Items are due once sold. Low-stock alerts on.',
                        ShopType::Tier3->value => 'Independent shop on monthly credit. No stock alerts.',
                    ])
                    ->hiddenOn('edit')
                    ->columnSpanFull(),
                TextInput::make('address')->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(32),
                Toggle::make('is_active')->label('Active')->default(true)->hiddenOn('create')
                    ->helperText('Deactivating stops all of this shop\'s logins.')
                    ->rules([fn (?Shop $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record) {
                        if ($record && ! $value && $record->is_active && ! $record->canBeDeactivated()) {
                            $fail('This shop still has stock or an unpaid balance.');
                        }
                    }]),
            ]),
            Section::make('First login (optional)')->columns(2)->visibleOn('create')
                ->description('Creates a read-only login for this shop. The password is shown once after saving.')
                ->schema([
                    TextInput::make('login_username')->label('Username')->alphaDash()->maxLength(64)
                        ->unique('users', 'username'),
                    TextInput::make('login_name')->label('Person\'s name')->maxLength(255),
                ]),
        ]);
    }
}
