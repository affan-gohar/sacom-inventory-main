<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Actions\RecordStockIn;
use App\Domain\InventoryException;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\DB;

class ManageProducts extends ManageRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New product')
                // Product and its opening stock-in are saved together, or not at all.
                ->using(function (array $data, CreateAction $action): Product {
                    $qty = (int) ($data['opening_qty'] ?? 0);
                    $note = filled($data['opening_note'] ?? null) ? $data['opening_note'] : 'Opening stock';
                    unset($data['opening_qty'], $data['opening_note']);

                    try {
                        return DB::transaction(function () use ($data, $qty, $note) {
                            $product = Product::query()->create($data);
                            if ($qty > 0) {
                                app(RecordStockIn::class)->handle(
                                    [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => $data['purchase_cost'] ?? null]],
                                    $note,
                                );
                            }

                            return $product;
                        });
                    } catch (InventoryException $e) {
                        Notification::make()->title('Not saved')->body($e->getMessage())->danger()->persistent()->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
