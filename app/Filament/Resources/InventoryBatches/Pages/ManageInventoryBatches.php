<?php

namespace App\Filament\Resources\InventoryBatches\Pages;

use App\Filament\Resources\InventoryBatches\InventoryBatcheResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryBatches extends ManageRecords
{
    protected static string $resource = InventoryBatcheResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
