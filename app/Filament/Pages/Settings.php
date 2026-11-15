<?php

namespace App\Filament\Pages;

use App\Domain\AuditLogger;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Business profile and payment QR shown on every invoice. */
class Settings extends Page
{
    /** Keys stored in the settings table and edited here. */
    public const KEYS = ['business_name', 'business_address', 'business_email', 'business_phone', 'invoice_footer', 'payment_qr_path', 'payment_qr_caption'];

    public const QR_DISK = 'public';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Business Settings';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::get($key)])->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Business profile')
                    ->description('Printed in the FROM section of every invoice.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('business_name')->label('Business name')->required()->maxLength(255),
                        TextInput::make('business_phone')->label('Phone')->tel()->maxLength(32),
                        TextInput::make('business_email')->label('Email')->email()->maxLength(255),
                        TextInput::make('business_address')->label('Address')->maxLength(255),
                        Textarea::make('invoice_footer')->label('Invoice footer')->rows(2)->maxLength(500)->columnSpanFull(),
                    ]),
                Section::make('Payment QR code')
                    ->description('Printed on every invoice so shops can scan and pay (e.g. DuitNow / bank QR).')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('payment_qr_path')
                            ->label('QR image')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                            ->maxSize(2048)
                            ->disk(self::QR_DISK)
                            ->directory('settings')
                            ->visibility('public')
                            ->imagePreviewHeight('220')
                            ->helperText('PNG or JPG, up to 2 MB. Upload a new one to replace it; clear it to remove the QR from invoices.'),
                        TextInput::make('payment_qr_caption')->label('Caption under the QR')
                            ->placeholder('Scan to pay — DuitNow')->maxLength(80),
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
                    Actions::make([Action::make('save')->label('Save settings')->submit('save')])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $before = collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::get($key)])->all();

        foreach (self::KEYS as $key) {
            Setting::put($key, filled($data[$key] ?? null) ? (string) $data[$key] : null);
        }

        // A replaced or removed QR leaves no orphan file behind.
        $oldQr = $before['payment_qr_path'];
        if ($oldQr && $oldQr !== ($data['payment_qr_path'] ?? null)) {
            Storage::disk(self::QR_DISK)->delete($oldQr);
        }

        $changed = array_keys(array_diff_assoc(array_map('strval', $data + array_fill_keys(self::KEYS, null)), array_map('strval', $before)));
        if ($changed !== []) {
            AuditLogger::record('settings_updated', after: ['changed' => $changed]);
        }

        Notification::make()->title('Settings saved')->success()->send();
    }
}
