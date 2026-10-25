<?php

namespace App\Filament\Resources\DocumentSequences\Pages;

use App\Filament\Resources\DocumentSequences\DocumentSequenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentSequences extends ManageRecords
{
    protected static string $resource = DocumentSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
