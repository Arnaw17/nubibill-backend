<?php

namespace App\Filament\Resources\BusinessTypes\Pages;

use App\Filament\Resources\BusinessTypes\BusinessTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinessTypes extends ManageRecords
{
    protected static string $resource = BusinessTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
