<?php

namespace App\Filament\Resources\HsnCodes\Pages;

use App\Filament\Resources\HsnCodes\HsnCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHsnCodes extends ManageRecords
{
    protected static string $resource = HsnCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
