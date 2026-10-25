<?php

namespace App\Filament\Resources\SubcategoryAttributeTemplates\Pages;

use App\Filament\Resources\SubcategoryAttributeTemplates\SubcategoryAttributeTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSubcategoryAttributeTemplates extends ManageRecords
{
    protected static string $resource = SubcategoryAttributeTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
