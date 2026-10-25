<?php

namespace App\Filament\Resources\GoodsReceiptItems\Pages;

use App\Filament\Resources\GoodsReceiptItems\GoodsReceiptItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGoodsReceiptItems extends ManageRecords
{
    protected static string $resource = GoodsReceiptItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
