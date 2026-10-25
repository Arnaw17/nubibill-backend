<?php

namespace App\Filament\Resources\CentralProductMasterVerified\Pages;

use App\Filament\Resources\CentralProductMasterVerified\CentralProductMasterVerifiedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCentralProductMasterVerified extends ManageRecords
{
    protected static string $resource = CentralProductMasterVerifiedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
