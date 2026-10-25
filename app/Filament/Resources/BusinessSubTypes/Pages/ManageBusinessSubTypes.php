<?php

namespace App\Filament\Resources\BusinessSubTypes\Pages;

use App\Filament\Resources\BusinessSubTypes\BusinessSubTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinessSubTypes extends ManageRecords
{
    protected static string $resource = BusinessSubTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
