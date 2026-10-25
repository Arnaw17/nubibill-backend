<?php

namespace App\Filament\Resources\InventoryBatchMovements\Pages;

use App\Filament\Resources\InventoryBatchMovements\InventoryBatchMovementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryBatchMovements extends ManageRecords
{
    protected static string $resource = InventoryBatchMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
