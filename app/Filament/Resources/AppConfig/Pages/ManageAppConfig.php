<?php

namespace App\Filament\Resources\AppConfig\Pages;

use App\Filament\Resources\AppConfig\AppConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAppConfig extends ManageRecords
{
    protected static string $resource = AppConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
