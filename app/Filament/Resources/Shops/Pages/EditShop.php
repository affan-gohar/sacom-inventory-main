<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Domain\AuditLogger;
use App\Filament\Resources\Shops\ShopResource;
use Filament\Resources\Pages\EditRecord;

class EditShop extends EditRecord
{
    protected static string $resource = ShopResource::class;

    protected function afterSave(): void
    {
        $changes = $this->getRecord()->getChanges();
        unset($changes['updated_at']);
        if ($changes !== []) {
            AuditLogger::record('shop_updated', $this->getRecord(), $this->getRecord()->id, after: $changes);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
