<?php

namespace App\Filament\Resources\PurchaseBillItems\Pages;

use App\Filament\Resources\PurchaseBillItems\PurchaseBillItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePurchaseBillItems extends ManageRecords
{
    protected static string $resource = PurchaseBillItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
