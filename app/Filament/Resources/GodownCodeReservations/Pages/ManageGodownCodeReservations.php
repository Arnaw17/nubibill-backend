<?php

namespace App\Filament\Resources\GodownCodeReservations\Pages;

use App\Filament\Resources\GodownCodeReservations\GodownCodeReservationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGodownCodeReservations extends ManageRecords
{
    protected static string $resource = GodownCodeReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
