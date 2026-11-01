<?php

namespace App\Filament\Resources\SubcategoryAttributeTemplates;

use App\Filament\Resources\SubcategoryAttributeTemplates\Pages\ManageSubcategoryAttributeTemplates;
use App\Models\SubcategoryAttributeTemplate;
use BackedEnum;
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

class SubcategoryAttributeTemplateResource extends Resource
{
    protected static ?string $model = SubcategoryAttributeTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Products & Catalog';

    protected static ?string $navigationLabel = 'Subcategory Attribute Templates';

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
                TextColumn::make('business_id')
                    ->label('Business')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'ACTIVE' => 'success',
                        'INACTIVE' => 'warning',
                        'DELETED' => 'danger',
                        default => 'gray',
                    }),
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
            'index' => ManageSubcategoryAttributeTemplates::route('/'),
        ];
    }
}
