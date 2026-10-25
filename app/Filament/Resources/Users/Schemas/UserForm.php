<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('phone')
                    ->tel()
                    ->required(),
                Textarea::make('address')
                    ->columnSpanFull(),
                TextInput::make('photo_path'),
                TextInput::make('password')
                    ->password()
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('INACTIVE'),
                TextInput::make('role')
                    ->required()
                    ->default('USER'),
                DateTimePicker::make('email_verified_at'),
                DateTimePicker::make('activated_at'),
                TextInput::make('activated_by'),
                TextInput::make('business_id'),
                TextInput::make('version')
                    ->required()
                    ->numeric()
                    ->default(1),
                Toggle::make('is_deleted')
                    ->required(),
            ]);
    }
}
