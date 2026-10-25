<?php

namespace App\Filament\Resources\ProductCatalogueReleases\Pages;

use App\Filament\Resources\ProductCatalogueReleases\ProductCatalogueReleasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductCatalogueReleases extends ManageRecords
{
    protected static string $resource = ProductCatalogueReleasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
