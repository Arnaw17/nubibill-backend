<?php

namespace App\Filament\Resources\BusinessNameReservations\Pages;

use App\Filament\Resources\BusinessNameReservations\BusinessNameReservationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinessNameReservations extends ManageRecords
{
    protected static string $resource = BusinessNameReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
