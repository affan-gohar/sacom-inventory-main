<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per admin action. Deleting an action soft-deletes this row and removes its
        // movements/allocations, so the ledger always sums to shop_stock.
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            // stock_in | local_stock_in | transfer | branch_sale | counter_sale | return | payment
            $table->string('type', 16);
            $table->foreignId('shop_id')->constrained()->restrictOnDelete(); // shop acted on (Main for stock_in/counter_sale)
            $table->timestamp('occurred_at');
            $table->decimal('amount', 12, 2)->default(0); // money value of the action
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['shop_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('qty'); // signed
            $table->string('source', 8); // main | local
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['product_id', 'occurred_at']);
            $table->index(['shop_id', 'occurred_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_transaction_id')->constrained()->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['shop_id', 'issued_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('qty');
            $table->unsignedInteger('qty_sold')->default(0);
            $table->unsignedInteger('qty_paid')->default(0);
            $table->unsignedInteger('qty_returned')->default(0);
            $table->timestamps();
            $table->index('product_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT chk_lines_paid_le_sold CHECK (qty_paid <= qty_sold)');
            DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT chk_lines_sold_le_available CHECK (qty_sold + qty_returned <= qty)');
        }

        // Which invoice lines each action changed, so delete can reverse exactly.
        Schema::create('line_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->constrained()->restrictOnDelete();
            $table->string('kind', 8); // sold | paid | returned
            $table->unsignedInteger('qty');
            $table->timestamps();
            $table->index(['invoice_line_id', 'kind']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->string('period', 4)->primary(); // YYMM
            $table->unsignedInteger('last_number')->default(0);
        });

        // Append-only business history. Nothing in the app updates or deletes these rows.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 48);
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('qty')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('note', 1000)->nullable();
            $table->index('occurred_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['shop_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('line_allocations');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_transactions');
    }
};
