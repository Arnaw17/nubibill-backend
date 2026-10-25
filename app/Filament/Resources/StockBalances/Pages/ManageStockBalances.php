<?php

namespace App\Filament\Resources\StockBalances\Pages;

use App\Filament\Resources\StockBalances\StockBalanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStockBalances extends ManageRecords
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
