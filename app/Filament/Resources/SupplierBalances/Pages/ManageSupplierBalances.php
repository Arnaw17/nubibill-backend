<?php

namespace App\Filament\Resources\SupplierBalances\Pages;

use App\Filament\Resources\SupplierBalances\SupplierBalanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierBalances extends ManageRecords
{
    protected static string $resource = SupplierBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
