<?php

namespace App\Filament\Resources\PurchaseCorrections\Pages;

use App\Filament\Resources\PurchaseCorrections\PurchaseCorrectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePurchaseCorrections extends ManageRecords
{
    protected static string $resource = PurchaseCorrectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
