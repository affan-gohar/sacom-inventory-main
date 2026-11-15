<?php

namespace Database\Seeders;

use App\Enums\ShopType;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Production-safe: creates the Main Shop, the admin account and the default business profile. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Shop::query()->firstOrCreate(
            ['type' => ShopType::Main],
            ['name' => 'SACOM Central Hub', 'code' => 'MAIN', 'is_active' => true],
        );

        if (! User::query()->where('role', UserRole::Admin)->exists()) {
            $password = env('ADMIN_INITIAL_PASSWORD') ?: Str::password(16, symbols: false);
            User::query()->create([
                'name' => 'Admin',
                'username' => env('ADMIN_USERNAME', 'admin'),
                'password' => $password,
                'role' => UserRole::Admin,
            ]);
            $this->command?->warn('Admin created. Username: '.env('ADMIN_USERNAME', 'admin')."  Password: {$password}  (change it after first login)");
        }

        foreach ([
            'business_name' => 'SACOM Inventory',
            'business_address' => '',
            'business_email' => '',
            'business_phone' => '',
            'invoice_footer' => 'For questions or adjustments, contact Central Admin. Thank you for your business.',
        ] as $key => $value) {
            if (Setting::get($key) === null) {
                Setting::put($key, $value);
            }
        }
    }
}
