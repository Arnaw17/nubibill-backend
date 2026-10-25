<?php

namespace App\Filament\Resources\PackSizes\Pages;

use App\Filament\Resources\PackSizes\PackSizeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePackSizes extends ManageRecords
{
    protected static string $resource = PackSizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
