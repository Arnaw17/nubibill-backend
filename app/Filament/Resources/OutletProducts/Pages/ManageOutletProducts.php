<?php

namespace App\Filament\Resources\OutletProducts\Pages;

use App\Filament\Resources\OutletProducts\OutletProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOutletProducts extends ManageRecords
{
    protected static string $resource = OutletProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
