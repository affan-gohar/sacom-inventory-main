<?php

namespace App\Filament\Shop\Resources\OtherShopsStock\Pages;

use App\Filament\Shop\Resources\OtherShopsStock\OtherShopsStockResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListOtherShopsStock extends ListRecords
{
    protected static string $resource = OtherShopsStockResource::class;

    /** Narrow to one allowed shop, e.g. when opened from a dashboard card (?shop=ID). */
    #[Url]
    public ?string $shop = null;

    public function mount(): void
    {
        parent::mount();

        if ($this->shop !== null && ! in_array((int) $this->shop, OtherShopsStockResource::allowedShopIds(), true)) {
            $this->shop = null;
        }
    }

    public function getTitle(): string
    {
        $name = $this->shop ? auth()->user()->visibleOtherShops()->firstWhere('id', (int) $this->shop)?->name : null;

        return $name ? "Stock at {$name}" : 'Other Shops Stock';
    }

    public function getSubheading(): ?string
    {
        return 'Read-only. Search a product to see which branch has it available for pickup.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('allShops')->label('Show all shops')->color('gray')
                ->visible(fn () => $this->shop !== null)
                ->url(fn () => OtherShopsStockResource::getUrl('index')),
        ];
    }
}
