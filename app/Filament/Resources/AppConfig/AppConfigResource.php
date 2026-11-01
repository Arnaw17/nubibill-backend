<?php

namespace App\Filament\Resources\AppConfig;

use App\Filament\Resources\AppConfig\Pages\ManageAppConfig;
use App\Models\AppConfig;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AppConfigResource extends Resource
{
    protected static ?string $model = AppConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'System & Logs';

    protected static ?string $navigationLabel = 'App Config';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')->disabled(),
                TextInput::make('business_id'),
                TextInput::make('outlet_id'),
                TextInput::make('status'),
                Toggle::make('is_deleted')->required(),
                Textarea::make('payload')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $state)
                    ->columnSpanFull()
                    ->rows(8),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('app_status')
                    ->label('System Mode')
                    ->state(function (AppConfig $record): string {
                        if ($record->id !== 'system') {
                            return 'N/A';
                        }
                        $isActive = (bool) ($record->payload['fields']['isActive'] ?? true);

                        return $isActive ? 'ONLINE (Active)' : 'MAINTENANCE';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ONLINE (Active)' => 'success',
                        'MAINTENANCE' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('business_id')
                    ->label('Business')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_deleted')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('maintenance')
                    ->label('Maintenance Mode')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->color(fn (AppConfig $record): string => ($record->payload['fields']['isActive'] ?? true) ? 'warning' : 'success')
                    ->visible(fn (AppConfig $record): bool => $record->id === 'system')
                    ->form([
                        Toggle::make('isActive')
                            ->label('Application Active (Uncheck to put Flutter App into Maintenance Mode)')
                            ->default(true),
                        TextInput::make('maintenanceTitle')
                            ->label('Maintenance Title')
                            ->default('System Maintenance')
                            ->required(),
                        Textarea::make('maintenanceMessage')
                            ->label('Maintenance Message')
                            ->rows(3),
                        TextInput::make('maintenanceImageUrl')
                            ->label('Maintenance Image URL')
                            ->url(),
                    ])
                    ->fillForm(function (AppConfig $record): array {
                        $fields = $record->payload['fields'] ?? [];

                        return [
                            'isActive' => (bool) ($fields['isActive'] ?? true),
                            'maintenanceTitle' => $fields['maintenanceTitle'] ?? 'System Maintenance',
                            'maintenanceMessage' => $fields['maintenanceMessage'] ?? '',
                            'maintenanceImageUrl' => $fields['maintenanceImageUrl'] ?? '',
                        ];
                    })
                    ->action(function (AppConfig $record, array $data): void {
                        $payload = $record->payload ?? [];
                        $fields = $payload['fields'] ?? [];

                        $fields['isActive'] = (bool) $data['isActive'];
                        $fields['maintenanceTitle'] = $data['maintenanceTitle'];
                        $fields['maintenanceMessage'] = $data['maintenanceMessage'];
                        $fields['maintenanceImageUrl'] = $data['maintenanceImageUrl'];
                        $fields['updatedAt'] = now()->toIso8601String();

                        $payload['fields'] = $fields;
                        $record->payload = $payload;
                        $record->save();
                    }),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAppConfig::route('/'),
        ];
    }
}
