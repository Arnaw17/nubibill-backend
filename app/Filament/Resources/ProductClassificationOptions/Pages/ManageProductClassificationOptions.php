<?php

namespace App\Filament\Resources\ProductClassificationOptions\Pages;

use App\Filament\Resources\ProductClassificationOptions\ProductClassificationOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductClassificationOptions extends ManageRecords
{
    protected static string $resource = ProductClassificationOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
