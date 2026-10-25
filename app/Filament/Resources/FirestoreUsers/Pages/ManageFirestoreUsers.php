<?php

namespace App\Filament\Resources\FirestoreUsers\Pages;

use App\Filament\Resources\FirestoreUsers\FirestoreUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFirestoreUsers extends ManageRecords
{
    protected static string $resource = FirestoreUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
