<?php

namespace App\Filament\Resources\PurchaseBills\Pages;

use App\Filament\Resources\PurchaseBills\PurchaseBillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePurchaseBills extends ManageRecords
{
    protected static string $resource = PurchaseBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
