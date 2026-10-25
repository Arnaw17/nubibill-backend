<?php

namespace App\Filament\Resources\CustomerLedgerEntries\Pages;

use App\Filament\Resources\CustomerLedgerEntries\CustomerLedgerEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCustomerLedgerEntries extends ManageRecords
{
    protected static string $resource = CustomerLedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
