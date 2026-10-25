<?php

namespace App\Filament\Resources\StaffAttendance\Pages;

use App\Filament\Resources\StaffAttendance\StaffAttendanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStaffAttendance extends ManageRecords
{
    protected static string $resource = StaffAttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
