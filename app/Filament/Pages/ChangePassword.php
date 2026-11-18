<?php

namespace App\Filament\Pages;

use App\Domain\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** The signed-in admin changes their own login password. */
class ChangePassword extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Change Admin Password';

    protected static ?string $navigationLabel = 'Change Password';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Admin login')
                    ->description(fn () => 'Username: '.auth()->user()?->username)
                    ->schema([
                        TextInput::make('current_password')->label('Current password')->password()->revealable()->required(),
                        TextInput::make('password')->label('New password')->password()->revealable()
                            ->required()->minLength(6)->maxLength(64)->confirmed(),
                        TextInput::make('password_confirmation')->label('Repeat new password')->password()->revealable()->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Change password')->submit('save')])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['data.current_password' => 'The current password is not correct.']);
        }

        $user->update(['password' => $data['password']]);
        AuditLogger::record('admin_password_changed', $user, after: ['username' => $user->username]);

        // Keep this browser signed in: the session's stored password hash is re-recorded on the next request.
        session()->forget(['password_hash_web', 'password_hash_'.auth()->getDefaultDriver()]);

        $this->form->fill();
        Notification::make()->title('Password changed')->success()->send();
    }
}
