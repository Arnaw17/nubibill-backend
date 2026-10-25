<?php

// Script to generate Eloquent Models and Filament Resources for all 57 Firestore collections

require __DIR__ . '/vendor/autoload.php';

$summaryPath = 'D:/firestore_full_backup/summary.json';
if (!file_exists($summaryPath)) {
    die("summary.json not found at $summaryPath\n");
}

$summary = json_decode(file_get_contents($summaryPath), true);
$collections = array_keys($summary['documentsByRootCollection']);

$groupMapping = [
    // Billing & Sales
    'invoices' => 'Billing & Sales',
    'invoice_items' => 'Billing & Sales',
    'payments' => 'Billing & Sales',
    'payment_allocations' => 'Billing & Sales',

    // Customers & Accounts
    'customers' => 'Customers & Accounts',
    'customer_balances' => 'Customers & Accounts',
    'customer_ledger_entries' => 'Customers & Accounts',

    // Products & Catalog
    'products' => 'Products & Catalog',
    'product_variants' => 'Products & Catalog',
    'product_categories' => 'Products & Catalog',
    'product_subcategories' => 'Products & Catalog',
    'central_product_master_verified' => 'Products & Catalog',
    'brands' => 'Products & Catalog',
    'units' => 'Products & Catalog',
    'hsn_codes' => 'Products & Catalog',
    'pack_sizes' => 'Products & Catalog',
    'shades' => 'Products & Catalog',
    'product_costs' => 'Products & Catalog',
    'product_attributes' => 'Products & Catalog',
    'product_attribute_options' => 'Products & Catalog',
    'product_classification_options' => 'Products & Catalog',
    'subcategory_attribute_templates' => 'Products & Catalog',
    'product_unique_keys' => 'Products & Catalog',
    'product_catalogue_releases' => 'Products & Catalog',

    // Purchases & Suppliers
    'suppliers' => 'Purchases & Suppliers',
    'supplier_businesses' => 'Purchases & Suppliers',
    'supplier_products' => 'Purchases & Suppliers',
    'supplier_balances' => 'Purchases & Suppliers',
    'supplier_ledger_entries' => 'Purchases & Suppliers',
    'supplier_payments' => 'Purchases & Suppliers',
    'purchase_bills' => 'Purchases & Suppliers',
    'purchase_bill_items' => 'Purchases & Suppliers',
    'purchase_corrections' => 'Purchases & Suppliers',
    'goods_receipts' => 'Purchases & Suppliers',
    'goods_receipt_items' => 'Purchases & Suppliers',

    // Inventory & Outlets
    'outlets' => 'Inventory & Outlets',
    'outlet_products' => 'Inventory & Outlets',
    'stock_balances' => 'Inventory & Outlets',
    'stock_movements' => 'Inventory & Outlets',
    'inventory_batches' => 'Inventory & Outlets',
    'inventory_batch_movements' => 'Inventory & Outlets',
    'inventory_transactions' => 'Inventory & Outlets',
    'storage_locations' => 'Inventory & Outlets',
    'godown_code_reservations' => 'Inventory & Outlets',

    // Organization & Staff
    'businesses' => 'Organization & Staff',
    'business_types' => 'Organization & Staff',
    'business_sub_types' => 'Organization & Staff',
    'business_name_reservations' => 'Organization & Staff',
    'employees' => 'Organization & Staff',
    'staff_attendance' => 'Organization & Staff',
    'roles' => 'Organization & Staff',
    'permissions' => 'Organization & Staff',

    // System & Logs
    'app_config' => 'System & Logs',
    'audit_logs' => 'System & Logs',
    'document_sequences' => 'System & Logs',
    'system_error_analytics' => 'System & Logs',
    'user' => 'System & Logs',
];

function tableNameToClass($table) {
    if ($table === 'user') {
        return 'FirestoreUser';
    }
    // Remove plural suffix for simple words or handle irregulars
    $parts = explode('_', $table);
    $last = array_pop($parts);

    if (str_ends_with($last, 'ies')) {
        $last = substr($last, 0, -3) . 'y';
    } elseif (str_ends_with($last, 'ses')) {
        $last = substr($last, 0, -2);
    } elseif (str_ends_with($last, 's') && !str_ends_with($last, 'ss')) {
        $last = substr($last, 0, -1);
    }
    $parts[] = $last;
    return implode('', array_map('ucfirst', $parts));
}

function tableNameToPluralClass($table) {
    if ($table === 'user') {
        return 'FirestoreUsers';
    }
    $parts = explode('_', $table);
    return implode('', array_map('ucfirst', $parts));
}

function tableNameToLabel($table) {
    if ($table === 'user') {
        return 'Firestore Users';
    }
    return ucwords(str_replace('_', ' ', $table));
}

$modelsDir = __DIR__ . '/app/Models';
$resourcesBaseDir = __DIR__ . '/app/Filament/Resources';

$createdCount = 0;

foreach ($collections as $collection) {
    $className = tableNameToClass($collection);
    $pluralClass = tableNameToPluralClass($collection);
    $label = tableNameToLabel($collection);
    $group = $groupMapping[$collection] ?? 'Other Firestore Collections';

    // 1. Generate Model (if not already existing like User.php)
    if ($className === 'User') {
        $className = 'FirestoreUser';
    }

    $modelFile = "$modelsDir/{$className}.php";
    if (!file_exists($modelFile)) {
        $modelContent = <<<PHP
<?php

namespace App\Models;

class {$className} extends FirestoreModel
{
    protected \$table = '{$collection}';
}

PHP;
        file_put_contents($modelFile, $modelContent);
    }

    // 2. Generate Filament Resource directory
    $resourceDir = "$resourcesBaseDir/{$pluralClass}";
    $pagesDir = "$resourceDir/Pages";
    if (!is_dir($pagesDir)) {
        mkdir($pagesDir, 0777, true);
    }

    $resourceFile = "$resourceDir/{$className}Resource.php";
    $pageFile = "$pagesDir/Manage{$pluralClass}.php";

    // Manage Page
    $pageContent = <<<PHP
<?php

namespace App\Filament\Resources\\{$pluralClass}\Pages;

use App\Filament\Resources\\{$pluralClass}\\{$className}Resource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class Manage{$pluralClass} extends ManageRecords
{
    protected static string \$resource = {$className}Resource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

PHP;
    file_put_contents($pageFile, $pageContent);

    // Resource Class
    $resourceContent = <<<PHP
<?php

namespace App\Filament\Resources\\{$pluralClass};

use App\Filament\Resources\\{$pluralClass}\Pages\Manage{$pluralClass};
use App\Models\\{$className};
use BackedEnum;
use UnitEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;

class {$className}Resource extends Resource
{
    protected static ?string \$model = {$className}::class;

    protected static string|BackedEnum|null \$navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null \$navigationGroup = '{$group}';

    protected static ?string \$navigationLabel = '{$label}';

    public static function form(Schema \$schema): Schema
    {
        return \$schema
            ->components([
                TextInput::make('id')->disabled(),
                TextInput::make('business_id'),
                TextInput::make('outlet_id'),
                TextInput::make('status'),
                Toggle::make('is_deleted')->required(),
                Textarea::make('payload')
                    ->formatStateUsing(fn (\$state) => is_array(\$state) ? json_encode(\$state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : \$state)
                    ->columnSpanFull()
                    ->rows(8),
            ]);
    }

    public static function table(Table \$table): Table
    {
        return \$table
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
                    ->color(fn (?string \$state): string => match (\$state) {
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
            'index' => Manage{$pluralClass}::route('/'),
        ];
    }
}

PHP;
    file_put_contents($resourceFile, $resourceContent);

    $createdCount++;
}

echo "Successfully generated models and Filament resources for {$createdCount} collections!\n";
