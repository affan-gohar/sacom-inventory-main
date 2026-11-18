<?php

namespace App\Filament\Resources\Shops\RelationManagers;

use App\Domain\AuditLogger;
use App\Enums\UserRole;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Read-only logins for this shop's owner and staff. */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Logins';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Person\'s name')->required()->maxLength(255),
            TextInput::make('username')->required()->alphaDash()->maxLength(64)->unique('users', 'username', ignoreRecord: true),
            TextInput::make('new_password')->label('Password (optional)')
                ->password()->revealable()->minLength(6)->maxLength(64)
                ->helperText('Leave empty to generate one.')
                ->visibleOn('create'),
            CheckboxList::make('viewableShops')
                ->label('Can view stock of these shops (read-only)')
                ->helperText('Shown on this login\'s dashboard and in "Other Shops Stock". New shops appear here automatically; leave all unticked to allow none.')
                ->relationship('viewableShops', 'name',
                    modifyQueryUsing: fn ($query) => $query->where('shops.id', '!=', $this->getOwnerRecord()->getKey())
                        ->orderByRaw("CASE WHEN shops.type = 'main' THEN 0 ELSE 1 END")->orderBy('shops.name'))
                ->getOptionLabelFromRecordUsing(fn (Shop $record) => "{$record->name} ({$record->type->label()})")
                ->bulkToggleable()
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('username')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('username')->searchable()->copyable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('last_login_at')->label('Last login')->since()->placeholder('Never'),
                TextColumn::make('viewableShops.name')->label('Can view')->badge()->color('gray')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('New login')
                    ->mutateDataUsing(function (array $data): array {
                        $chosen = $data['new_password'] ?? null;
                        unset($data['new_password']);
                        $data['password'] = $this->generatedPassword = filled($chosen) ? $chosen : Str::password(10, symbols: false);
                        $data['role'] = UserRole::Viewer;

                        return $data;
                    })
                    ->after(fn (User $record) => $this->announcePassword($record, 'login_created')),
            ])
            ->recordActions([
                Action::make('setPassword')->label('Change password')->icon(Heroicon::OutlinedLockClosed)
                    ->modalHeading(fn (User $record) => "Change password — {$record->username}")
                    ->schema([
                        TextInput::make('password')->label('New password')->password()->revealable()
                            ->required()->minLength(6)->maxLength(64)->confirmed(),
                        TextInput::make('password_confirmation')->label('Repeat new password')->password()->revealable()
                            ->required()->dehydrated(false),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(['password' => $data['password']]);
                        AuditLogger::record('password_changed', $record, $record->shop_id, after: ['username' => $record->username]);
                        Notification::make()->title("Password changed for {$record->username}")->success()->send();
                    }),
                Action::make('resetPassword')->label('Reset password')->icon(Heroicon::OutlinedKey)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $this->generatedPassword = Str::password(10, symbols: false);
                        $record->update(['password' => $this->generatedPassword]);
                        $this->announcePassword($record, 'password_reset');
                    }),
                Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $record) => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheck)
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['is_active' => ! $record->is_active]);
                        AuditLogger::record($record->is_active ? 'login_activated' : 'login_deactivated', $record, $record->shop_id,
                            after: ['username' => $record->username]);
                    }),
                EditAction::make(),
            ]);
    }

    public ?string $generatedPassword = null;

    private function announcePassword(User $user, string $action): void
    {
        AuditLogger::record($action, $user, $user->shop_id, after: ['username' => $user->username]);
        Notification::make()->title('Copy the password now')
            ->body("Username: {$user->username}\nPassword: {$this->generatedPassword}\nIt will not be shown again.")
            ->success()->persistent()->send();
        $this->generatedPassword = null;
    }
}
