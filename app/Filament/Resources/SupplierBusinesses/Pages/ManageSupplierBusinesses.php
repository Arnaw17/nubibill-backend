<?php

namespace App\Filament\Resources\SupplierBusinesses\Pages;

use App\Filament\Resources\SupplierBusinesses\SupplierBusinessResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierBusinesses extends ManageRecords
{
    protected static string $resource = SupplierBusinessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
