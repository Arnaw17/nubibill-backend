<?php

namespace App\Filament\Resources\ProductSubcategories\Pages;

use App\Filament\Resources\ProductSubcategories\ProductSubcategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductSubcategories extends ManageRecords
{
    protected static string $resource = ProductSubcategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
