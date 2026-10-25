<?php

namespace App\Filament\Resources\SystemErrorAnalytics\Pages;

use App\Filament\Resources\SystemErrorAnalytics\SystemErrorAnalyticResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSystemErrorAnalytics extends ManageRecords
{
    protected static string $resource = SystemErrorAnalyticResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
