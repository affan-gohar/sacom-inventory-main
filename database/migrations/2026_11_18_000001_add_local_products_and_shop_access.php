<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A product typed in during a branch's local stock-in belongs to that branch, not the Main catalogue.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('category_id')->constrained()->restrictOnDelete();
        });

        // Which other shops' stock a branch login may view (read-only).
        Schema::create('user_shop_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_shop_access');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });
    }
};
