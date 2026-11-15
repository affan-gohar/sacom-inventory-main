<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('type', 8); // main | tier2 | tier3
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('type');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('shop_id')->references('id')->on('shops')->restrictOnDelete();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name')->unique();
            $table->decimal('selling_price', 12, 2);
            $table->decimal('purchase_cost', 12, 2)->nullable(); // admin-only
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shop_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_main')->default(0);  // supplied by Main Shop (owed)
            $table->unsignedInteger('qty_local')->default(0); // bought locally (branch's own)
            $table->timestamps();
            $table->unique(['shop_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('shop_stock');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['shop_id']));
        Schema::dropIfExists('shops');
    }
};
