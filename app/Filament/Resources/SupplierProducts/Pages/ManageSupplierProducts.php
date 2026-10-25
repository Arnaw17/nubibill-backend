<?php

namespace App\Filament\Resources\SupplierProducts\Pages;

use App\Filament\Resources\SupplierProducts\SupplierProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierProducts extends ManageRecords
{
    protected static string $resource = SupplierProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
