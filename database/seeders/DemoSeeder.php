<?php

namespace Database\Seeders;

use App\Domain\Actions\RecordBranchSale;
use App\Domain\Actions\RecordCounterSale;
use App\Domain\Actions\RecordLocalStockIn;
use App\Domain\Actions\RecordPayment;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Enums\ShopType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/** Local demo data based on the mockups. Never run in production. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        $this->call(DatabaseSeeder::class);
        Auth::setUser(User::query()->where('role', UserRole::Admin)->firstOrFail());

        $acc = Category::query()->firstOrCreate(['name' => 'Accessories'], ['low_stock_threshold' => 10]);
        $lap = Category::query()->firstOrCreate(['name' => 'Laptops'], ['low_stock_threshold' => 2]);

        $mouse = Product::query()->firstOrCreate(['name' => 'Wireless Mouse'], ['category_id' => $acc->id, 'selling_price' => '35.00']);
        $glass = Product::query()->firstOrCreate(['name' => 'Privacy Glass Y20'], ['category_id' => $acc->id, 'selling_price' => '4.00']);
        $laptop = Product::query()->firstOrCreate(['name' => 'Dell Laptop i7'], ['category_id' => $lap->id, 'selling_price' => '850.00']);

        $home = Shop::query()->firstOrCreate(['code' => 'HOME-T2-01'], ['name' => 'Home Shop', 'type' => ShopType::Tier2]);
        $zen = Shop::query()->firstOrCreate(['code' => 'ZEN-T3-08'], ['name' => 'Zen Shop', 'type' => ShopType::Tier3, 'address' => 'Zen Shop Branch Plaza']);

        foreach ([['home_staff', $home], ['zen_owner', $zen]] as [$username, $shop]) {
            User::query()->firstOrCreate(['username' => $username], [
                'name' => ucfirst(str_replace('_', ' ', $username)), 'password' => 'password',
                'role' => UserRole::Viewer, 'shop_id' => $shop->id,
            ]);
        }

        $at = now()->subDays(40);
        app(RecordStockIn::class)->handle([
            ['product_id' => $mouse->id, 'qty' => 150, 'unit_cost' => '12.00'],
            ['product_id' => $glass->id, 'qty' => 480, 'unit_cost' => '1.50'],
            ['product_id' => $laptop->id, 'qty' => 15, 'unit_cost' => '700.00'],
        ], 'Apex Wholesalers Guangzhou, Batch #882', $at);

        $oct = app(TransferStock::class)->handle($zen, [
            ['product_id' => $mouse->id, 'qty' => 5],
            ['product_id' => $glass->id, 'qty' => 40],
            ['product_id' => $laptop->id, 'qty' => 1],
        ], null, $at->copy()->addDays(2));
        app(TransferStock::class)->handle($home, [
            ['product_id' => $mouse->id, 'qty' => 40],
            ['product_id' => $laptop->id, 'qty' => 2],
        ], null, $at->copy()->addDays(3));
        app(RecordLocalStockIn::class)->handle($home, [['product_id' => $glass->id, 'qty' => 30, 'unit_cost' => '1.20']], 'Direct local purchase', $at->copy()->addDays(5));

        // Zen Shop month-end: mice and glass sold & paid, laptop still unpaid (as on the sample invoice).
        $lines = $oct->lines->keyBy('product_id');
        app(RecordPayment::class)->handle($zen, [$lines[$mouse->id]->id => 5, $lines[$glass->id]->id => 40], 'Month-end clearance', now()->subDays(5));

        app(RecordBranchSale::class)->handle($home, [['product_id' => $mouse->id, 'qty' => 32]], null, now()->subDays(3));
        app(RecordCounterSale::class)->handle([['product_id' => $glass->id, 'qty' => 10]], null, now()->subDay());

        app(TransferStock::class)->handle($zen, [['product_id' => $mouse->id, 'qty' => 2], ['product_id' => $glass->id, 'qty' => 3]]);

        $this->command?->info('Demo data loaded. Shop logins: home_staff / password, zen_owner / password');
    }
}
