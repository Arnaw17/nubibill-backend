<?php

namespace App\Filament\Resources\CustomerBalances\Pages;

use App\Filament\Resources\CustomerBalances\CustomerBalanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCustomerBalances extends ManageRecords
{
    protected static string $resource = CustomerBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
