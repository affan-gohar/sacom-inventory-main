<?php

namespace App\Domain\Actions;

use App\Domain\AuditLogger;
use App\Domain\InventoryException;
use App\Domain\Ledger;
use App\Enums\AllocationKind;
use App\Enums\TransactionType;
use App\Models\InvoiceLine;
use App\Models\LineAllocation;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Delete an action and undo exactly what it did. Refused while a later action depends on it;
 * the message names those actions. The audit log keeps a full record of what was deleted.
 */
class DeleteTransaction
{
    public function handle(StockTransaction $txn, ?string $reason = null): void
    {
        DB::transaction(function () use ($txn, $reason) {
            $txn = StockTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            $txn->load(['movements', 'allocations', 'invoice.lines']);

            $snapshot = [
                'type' => $txn->type->value,
                'shop_id' => $txn->shop_id,
                'occurred_at' => $txn->occurred_at->toDateTimeString(),
                'amount' => $txn->amount,
                'note' => $txn->note,
                'invoice' => $txn->invoice?->number,
                'movements' => $txn->movements->map->only(['shop_id', 'product_id', 'qty', 'source', 'unit_price', 'unit_cost'])->all(),
                'allocations' => $txn->allocations->map->only(['invoice_line_id', 'kind', 'qty'])->all(),
            ];

            if ($txn->type === TransactionType::Transfer && $txn->invoice !== null) {
                $this->guardTransfer($txn);
            }

            // Undo allocations (paid before sold, so paid ≤ sold holds at every step).
            $order = [AllocationKind::Paid->value => 0, AllocationKind::Returned->value => 1, AllocationKind::Sold->value => 2];
            $allocations = $txn->allocations->sortBy(fn (LineAllocation $a) => [$order[$a->kind->value], $a->invoice_line_id]);
            foreach ($allocations as $allocation) {
                $this->undoAllocation($txn, $allocation);
            }

            // Undo stock movements, refusing if the stock has already moved on.
            foreach ($txn->movements->sortBy(['product_id', 'shop_id']) as $movement) {
                $stock = Ledger::lock($movement->shop_id, $movement->product_id);
                $column = $movement->source->value === 'main' ? 'qty_main' : 'qty_local';
                $new = $stock->{$column} - $movement->qty;
                if ($new < 0) {
                    throw new InventoryException(
                        "Can't delete: {$movement->product->name} from this action has already been sold, transferred or returned at {$movement->shop->name}. Delete those actions first."
                    );
                }
                $stock->{$column} = $new;
                $stock->save();
            }

            $txn->movements()->delete();
            $txn->allocations()->delete();
            $txn->invoice?->delete();
            $txn->delete();

            AuditLogger::record('deleted_'.$txn->type->value, $txn, $txn->shop_id,
                before: $snapshot, note: $reason);
        });
    }

    private function guardTransfer(StockTransaction $txn): void
    {
        $lineIds = $txn->invoice->lines->pluck('id');
        $blockers = LineAllocation::query()
            ->whereIn('invoice_line_id', $lineIds)
            ->with('transaction')
            ->get()
            ->map(fn (LineAllocation $a) => $a->transaction)
            ->filter()
            ->unique('id')
            ->map(fn (StockTransaction $t) => "{$t->type->label()} #{$t->id}")
            ->values();

        if ($blockers->isNotEmpty()) {
            throw new InventoryException(
                "Can't delete invoice {$txn->invoice->number}: ".$blockers->implode(', ').' depend on it. Delete those first.'
            );
        }
    }

    private function undoAllocation(StockTransaction $txn, LineAllocation $allocation): void
    {
        $line = InvoiceLine::query()->lockForUpdate()->findOrFail($allocation->invoice_line_id);

        if ($allocation->kind === AllocationKind::Sold && $line->qty_paid > $line->qty_sold - $allocation->qty) {
            $payments = LineAllocation::query()
                ->where('invoice_line_id', $line->id)
                ->where('kind', AllocationKind::Paid)
                ->where('stock_transaction_id', '!=', $txn->id)
                ->pluck('stock_transaction_id')
                ->unique()
                ->map(fn (int $id) => "Payment #{$id}")
                ->implode(', ');

            throw new InventoryException("Can't delete: {$payments} already paid for these sold items. Delete the payment first.");
        }

        $column = match ($allocation->kind) {
            AllocationKind::Sold => 'qty_sold',
            AllocationKind::Paid => 'qty_paid',
            AllocationKind::Returned => 'qty_returned',
        };
        $line->decrement($column, $allocation->qty);
    }
}
