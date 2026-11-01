<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Barryvdh\DomPDF\ServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ServiceProvider::class,
];
