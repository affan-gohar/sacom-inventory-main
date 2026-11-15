<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Domain\AuditLogger;
use App\Enums\UserRole;
use App\Filament\Resources\Shops\ShopResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $login = ['username' => $data['login_username'] ?? null, 'name' => $data['login_name'] ?? null];
            unset($data['login_username'], $data['login_name']);

            $shop = static::getModel()::query()->create($data + ['is_active' => true]);
            AuditLogger::record('shop_created', $shop, $shop->id, after: ['name' => $shop->name, 'type' => $shop->type->value]);

            if (filled($login['username'])) {
                $password = Str::password(10, symbols: false);
                $user = User::query()->create([
                    'name' => $login['name'] ?: $shop->name,
                    'username' => $login['username'],
                    'password' => $password,
                    'role' => UserRole::Viewer,
                    'shop_id' => $shop->id,
                ]);
                AuditLogger::record('login_created', $user, $shop->id, after: ['username' => $user->username]);

                Notification::make()->title('Login created — copy the password now')
                    ->body("Username: {$user->username}\nPassword: {$password}\nIt will not be shown again.")
                    ->success()->persistent()->send();
            }

            return $shop;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
