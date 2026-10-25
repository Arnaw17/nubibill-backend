<?php

namespace App\Filament\Resources\ProductUniqueKeys\Pages;

use App\Filament\Resources\ProductUniqueKeys\ProductUniqueKeyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductUniqueKeys extends ManageRecords
{
    protected static string $resource = ProductUniqueKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
