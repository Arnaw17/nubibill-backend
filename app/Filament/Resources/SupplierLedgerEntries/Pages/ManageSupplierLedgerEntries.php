<?php

namespace App\Filament\Resources\SupplierLedgerEntries\Pages;

use App\Filament\Resources\SupplierLedgerEntries\SupplierLedgerEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierLedgerEntries extends ManageRecords
{
    protected static string $resource = SupplierLedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
