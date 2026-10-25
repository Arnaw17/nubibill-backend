<?php

namespace App\Filament\Resources\PaymentAllocations\Pages;

use App\Filament\Resources\PaymentAllocations\PaymentAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentAllocations extends ManageRecords
{
    protected static string $resource = PaymentAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
