<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryConfigurationService
{
    public const CAT_GRAVELS = 'SYS_CAT_CONST_GRAVELS';

    public const CAT_ACCESSORIES = 'SYS_CAT_CONST_ACCESSORIES';

    public const GRAVEL_SIZE_KEY = 'aggregateSize';

    public const GRAVEL_QUALITY_KEY = 'gradeQuality';

    public const SUPPORTED_FIELD_TYPES = [
        'TEXT',
        'NUMBER',
        'DROPDOWN',
        'MEASUREMENT',
        'BOOLEAN',
    ];

    public const DEFAULT_GRAVEL_SIZES = ['10mm', '20mm', '40mm', '60mm'];

    public const DEFAULT_GRAVEL_QUALITIES = ['Premium', 'Standard', 'Normal', 'Custom'];

    public const DEFAULT_ACCESSORY_FIELDS = [
        [
            'key' => 'size_dimension',
            'name' => 'Size / Dimension',
            'fieldType' => 'MEASUREMENT',
            'required' => false,
            'defaultValue' => '',
            'options' => [],
            'measurementUnitId' => 'SYS_UNIT_MM',
            'measurementUnitSymbol' => 'mm',
            'sortOrder' => 10,
            'level' => 'VARIANT',
        ],
        [
            'key' => 'specification',
            'name' => 'Specification',
            'fieldType' => 'TEXT',
            'required' => false,
            'defaultValue' => '',
            'options' => [],
            'measurementUnitId' => null,
            'measurementUnitSymbol' => null,
            'sortOrder' => 20,
            'level' => 'VARIANT',
        ],
        [
            'key' => 'material',
            'name' => 'Material / Grade',
            'fieldType' => 'TEXT',
            'required' => false,
            'defaultValue' => '',
            'options' => [],
            'measurementUnitId' => null,
            'measurementUnitSymbol' => null,
            'sortOrder' => 30,
            'level' => 'PRODUCT',
        ],
    ];

    public function __construct(private ?UnitConversionService $unitService = null)
    {
        $this->unitService ??= new UnitConversionService;
    }

    /**
     * Fetch complete dynamic category configuration (Gravels options, Accessories custom fields, and Units)
     * scoped to a single business, seeding starter defaults once per business if uninitialized.
     */
    public function getConfiguration(string $businessId, ?string $tenantId = null, ?string $actorUid = null): array
    {
        $this->ensureDefaultsInitialized($businessId, $tenantId, $actorUid);

        $usage = $this->collectCategoryUsage($businessId);

        // 1. Gravels Options (Size & Quality)
        $gravelOptions = DB::table('product_attribute_options')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload ?? '{}', true) ?: [];
                $fields = $payload['fields'] ?? [];
                $catId = (string) ($fields['categoryId'] ?? ($row->category_id ?? ''));
                $attrKey = (string) ($fields['attributeKey'] ?? ($row->attribute_key ?? ''));

                return [
                    'id' => (string) $row->id,
                    'attributeId' => (string) ($fields['attributeId'] ?? ''),
                    'attributeKey' => $attrKey,
                    'categoryId' => $catId,
                    'name' => (string) ($fields['name'] ?? ''),
                    'normalizedName' => (string) ($fields['normalizedName'] ?? strtolower((string) ($fields['name'] ?? ''))),
                    'sortOrder' => (int) ($fields['sortOrder'] ?? ($row->sort_order ?? 100)),
                    'status' => (string) ($fields['status'] ?? $row->status ?? 'ACTIVE'),
                ];
            })
            ->filter(fn ($opt) => $opt['categoryId'] === self::CAT_GRAVELS && strtoupper($opt['status']) === 'ACTIVE' && $opt['name'] !== '')
            ->values();

        $sizes = $gravelOptions
            ->filter(fn ($o) => $o['attributeKey'] === self::GRAVEL_SIZE_KEY)
            ->sortBy([['sortOrder', 'asc'], ['name', 'asc']])
            ->map(function ($o) use ($usage) {
                $o['attributeType'] = 'size';
                $o['inUse'] = isset($usage['gravelSizes'][strtolower($o['name'])]);

                return $o;
            })
            ->values()
            ->all();

        $qualities = $gravelOptions
            ->filter(fn ($o) => $o['attributeKey'] === self::GRAVEL_QUALITY_KEY)
            ->sortBy([['sortOrder', 'asc'], ['name', 'asc']])
            ->map(function ($o) use ($usage) {
                $o['attributeType'] = 'quality';
                $o['inUse'] = isset($usage['gravelQualities'][strtolower($o['name'])]);

                return $o;
            })
            ->values()
            ->all();

        // 2. Accessories Customizable Fields
        $accessoryFields = DB::table('product_attributes')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->get()
            ->map(function ($row) use ($usage) {
                $payload = json_decode($row->payload ?? '{}', true) ?: [];
                $fields = $payload['fields'] ?? [];
                $catId = (string) ($fields['categoryId'] ?? ($row->category_id ?? ''));
                $isMarker = ! empty($fields['isConfigMarker']);
                if ($isMarker || $catId !== self::CAT_ACCESSORIES) {
                    return null;
                }
                if (strtoupper((string) ($fields['status'] ?? $row->status ?? 'ACTIVE')) !== 'ACTIVE') {
                    return null;
                }
                $key = (string) ($fields['attributeKey'] ?? ($row->attribute_key ?? $fields['code'] ?? $row->id));
                $fieldType = $this->normalizeFieldType((string) ($fields['fieldType'] ?? $fields['dataType'] ?? 'TEXT'));

                return [
                    'id' => (string) $row->id,
                    'attributeId' => (string) ($fields['attributeId'] ?? $row->id),
                    'key' => $key,
                    'name' => (string) ($fields['name'] ?? $key),
                    'fieldType' => $fieldType,
                    'required' => (bool) ($fields['required'] ?? false),
                    'defaultValue' => $fields['defaultValue'] ?? null,
                    'options' => is_array($fields['options'] ?? null) ? array_values($fields['options']) : [],
                    'measurementUnitId' => $fields['measurementUnitId'] ?? null,
                    'measurementUnitSymbol' => $fields['measurementUnitSymbol'] ?? null,
                    'sortOrder' => (int) ($fields['sortOrder'] ?? ($row->sort_order ?? 100)),
                    'level' => (string) ($fields['attributeLevel'] ?? 'PRODUCT'),
                    'categoryId' => self::CAT_ACCESSORIES,
                    'inUse' => isset($usage['accessoryKeys'][strtolower($key)]),
                ];
            })
            ->filter()
            ->sortBy([['sortOrder', 'asc'], ['name', 'asc']])
            ->values()
            ->all();

        // 3. Units (System + Business-specific custom units with inUse guard)
        $units = $this->listUnits($businessId, $usage['unitRefs']);

        return [
            'businessId' => $businessId,
            'gravels' => [
                'categoryId' => self::CAT_GRAVELS,
                'sizes' => $sizes,
                'qualities' => $qualities,
            ],
            'accessories' => [
                'categoryId' => self::CAT_ACCESSORIES,
                'fields' => $accessoryFields,
                'supportedFieldTypes' => self::SUPPORTED_FIELD_TYPES,
            ],
            'units' => $units,
        ];
    }

    /**
     * Lazy-seed starter Gravels options and Accessories fields once per business if not yet initialized.
     */
    public function ensureDefaultsInitialized(string $businessId, ?string $tenantId = null, ?string $actorUid = null): void
    {
        $now = now()->toIso8601String();
        $dbNow = now();
        $resolvedTenant = $tenantId ?: 'DEFAULT_TENANT';
        $actor = $actorUid ?: 'SYSTEM';

        $gravelMarkerId = "BUSCFG_GRAVELS_{$businessId}";
        $hasGravelMarker = DB::table('product_attributes')->where('id', $gravelMarkerId)->exists();
        $existingGravelOptions = DB::table('product_attribute_options')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->get()
            ->filter(function ($r) {
                $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

                return ($f['categoryId'] ?? '') === self::CAT_GRAVELS;
            })
            ->count();

        if (! $hasGravelMarker && $existingGravelOptions === 0) {
            foreach (self::DEFAULT_GRAVEL_SIZES as $i => $sizeName) {
                $this->insertOptionRow($businessId, $resolvedTenant, self::GRAVEL_SIZE_KEY, $sizeName, ($i + 1) * 10, $actor, $now, $dbNow);
            }
            foreach (self::DEFAULT_GRAVEL_QUALITIES as $i => $qualityName) {
                $this->insertOptionRow($businessId, $resolvedTenant, self::GRAVEL_QUALITY_KEY, $qualityName, ($i + 1) * 10, $actor, $now, $dbNow);
            }
        }
        if (! $hasGravelMarker) {
            $this->insertMarkerRow($gravelMarkerId, $businessId, $resolvedTenant, self::CAT_GRAVELS, $now, $dbNow);
        }

        $accMarkerId = "BUSCFG_ACCESSORIES_{$businessId}";
        $hasAccMarker = DB::table('product_attributes')->where('id', $accMarkerId)->exists();
        $existingAccFields = DB::table('product_attributes')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->get()
            ->filter(function ($r) {
                $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

                return ($f['categoryId'] ?? '') === self::CAT_ACCESSORIES && empty($f['isConfigMarker']);
            })
            ->count();

        if (! $hasAccMarker && $existingAccFields === 0) {
            foreach (self::DEFAULT_ACCESSORY_FIELDS as $def) {
                $this->insertFieldRow($businessId, $resolvedTenant, $def, $actor, $now, $dbNow);
            }
        }
        if (! $hasAccMarker) {
            $this->insertMarkerRow($accMarkerId, $businessId, $resolvedTenant, self::CAT_ACCESSORIES, $now, $dbNow);
        }
    }

    public function addGravelOption(string $businessId, string $attributeType, string $name, ?int $sortOrder = null, ?string $tenantId = null, ?string $actorUid = null): array
    {
        $this->ensureDefaultsInitialized($businessId, $tenantId, $actorUid);
        $attrKey = $this->resolveGravelAttributeKey($attributeType);
        $cleanName = $this->cleanLabel($name);
        if ($cleanName === '') {
            throw ValidationException::withMessages(['name' => 'Option name is required.']);
        }

        $duplicate = DB::table('product_attribute_options')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->get()
            ->first(function ($r) use ($attrKey, $cleanName) {
                $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

                return ($f['categoryId'] ?? '') === self::CAT_GRAVELS
                    && ($f['attributeKey'] ?? '') === $attrKey
                    && strtoupper((string) ($f['status'] ?? 'ACTIVE')) === 'ACTIVE'
                    && strtolower(trim((string) ($f['name'] ?? ''))) === strtolower($cleanName);
            });

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "Option '{$cleanName}' already exists."]);
        }

        $now = now()->toIso8601String();
        $dbNow = now();
        $id = $this->insertOptionRow(
            $businessId,
            $tenantId ?: 'DEFAULT_TENANT',
            $attrKey,
            $cleanName,
            $sortOrder ?? 100,
            $actorUid ?: 'SYSTEM',
            $now,
            $dbNow
        );

        return [
            'id' => $id,
            'attributeType' => $attrKey === self::GRAVEL_SIZE_KEY ? 'size' : 'quality',
            'attributeKey' => $attrKey,
            'categoryId' => self::CAT_GRAVELS,
            'name' => $cleanName,
            'sortOrder' => $sortOrder ?? 100,
            'inUse' => false,
        ];
    }

    public function updateGravelOption(string $businessId, string $optionId, string $name, ?int $sortOrder = null, ?string $actorUid = null): array
    {
        $row = DB::table('product_attribute_options')
            ->where('id', $optionId)
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            abort(404, 'Gravel option not found.');
        }

        $cleanName = $this->cleanLabel($name);
        if ($cleanName === '') {
            throw ValidationException::withMessages(['name' => 'Option name is required.']);
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        $attrKey = (string) ($fields['attributeKey'] ?? self::GRAVEL_SIZE_KEY);

        $duplicate = DB::table('product_attribute_options')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->where('id', '!=', $optionId)
            ->get()
            ->first(function ($r) use ($attrKey, $cleanName) {
                $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

                return ($f['categoryId'] ?? '') === self::CAT_GRAVELS
                    && ($f['attributeKey'] ?? '') === $attrKey
                    && strtoupper((string) ($f['status'] ?? 'ACTIVE')) === 'ACTIVE'
                    && strtolower(trim((string) ($f['name'] ?? ''))) === strtolower($cleanName);
            });

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "Option '{$cleanName}' already exists."]);
        }

        $now = now()->toIso8601String();
        $fields['name'] = $cleanName;
        $fields['normalizedName'] = strtolower($cleanName);
        if ($sortOrder !== null) {
            $fields['sortOrder'] = $sortOrder;
        }
        $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
        $fields['updatedAt'] = $now;
        $payload['fields'] = $fields;

        $update = [
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('product_attribute_options', 'sort_order') && $sortOrder !== null) {
            $update['sort_order'] = $sortOrder;
        }
        DB::table('product_attribute_options')->where('id', $optionId)->update($update);

        return [
            'id' => $optionId,
            'attributeType' => $attrKey === self::GRAVEL_SIZE_KEY ? 'size' : 'quality',
            'attributeKey' => $attrKey,
            'categoryId' => self::CAT_GRAVELS,
            'name' => $cleanName,
            'sortOrder' => (int) ($fields['sortOrder'] ?? 100),
        ];
    }

    public function deleteGravelOption(string $businessId, string $optionId, ?string $actorUid = null): void
    {
        $row = DB::table('product_attribute_options')
            ->where('id', $optionId)
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            abort(404, 'Gravel option not found.');
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        $name = trim((string) ($fields['name'] ?? ''));
        $attrKey = (string) ($fields['attributeKey'] ?? self::GRAVEL_SIZE_KEY);

        $usage = $this->collectCategoryUsage($businessId);
        $inUse = $attrKey === self::GRAVEL_SIZE_KEY
            ? isset($usage['gravelSizes'][strtolower($name)])
            : isset($usage['gravelQualities'][strtolower($name)]);

        if ($inUse) {
            throw ValidationException::withMessages([
                'option' => "'{$name}' is currently used by existing Gravels products and cannot be deleted.",
            ]);
        }

        $now = now()->toIso8601String();
        $fields['status'] = 'DELETED';
        $fields['isDeleted'] = true;
        $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
        $fields['updatedAt'] = $now;
        $payload['fields'] = $fields;

        DB::table('product_attribute_options')->where('id', $optionId)->update([
            'status' => 'DELETED',
            'is_deleted' => 1,
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);
    }

    public function addAccessoryField(string $businessId, array $input, ?string $tenantId = null, ?string $actorUid = null): array
    {
        $this->ensureDefaultsInitialized($businessId, $tenantId, $actorUid);
        $cleanName = $this->cleanLabel((string) ($input['name'] ?? ''));
        if ($cleanName === '') {
            throw ValidationException::withMessages(['name' => 'Field name is required.']);
        }
        $fieldType = $this->normalizeFieldType((string) ($input['field_type'] ?? $input['fieldType'] ?? 'TEXT'));
        if (! in_array($fieldType, self::SUPPORTED_FIELD_TYPES, true)) {
            throw ValidationException::withMessages(['field_type' => 'Unsupported field type.']);
        }

        $options = $this->normalizeDropdownOptions($input['options'] ?? []);
        if ($fieldType === 'DROPDOWN' && empty($options)) {
            throw ValidationException::withMessages(['options' => 'Dropdown fields require at least one option.']);
        }

        $existingFields = DB::table('product_attributes')
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->get()
            ->filter(function ($r) {
                $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

                return ($f['categoryId'] ?? '') === self::CAT_ACCESSORIES
                    && empty($f['isConfigMarker'])
                    && strtoupper((string) ($f['status'] ?? 'ACTIVE')) === 'ACTIVE';
            });

        if ($existingFields->contains(function ($r) use ($cleanName) {
            $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

            return strtolower(trim((string) ($f['name'] ?? ''))) === strtolower($cleanName);
        })) {
            throw ValidationException::withMessages(['name' => "Field '{$cleanName}' already exists."]);
        }

        $keyBase = Str::slug($cleanName, '_');
        if ($keyBase === '') {
            $keyBase = 'field_'.Str::lower(Str::random(4));
        }
        $key = $keyBase;
        $suffix = 2;
        while ($existingFields->contains(function ($r) use ($key) {
            $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

            return strtolower((string) ($f['attributeKey'] ?? '')) === strtolower($key);
        })) {
            $key = "{$keyBase}_{$suffix}";
            $suffix++;
        }

        $maxSort = (int) ($existingFields->max(function ($r) {
            $f = (json_decode($r->payload ?? '{}', true) ?: [])['fields'] ?? [];

            return (int) ($f['sortOrder'] ?? 0);
        }) ?? 0);

        $unitSymbol = trim((string) ($input['measurement_unit_symbol'] ?? $input['measurementUnitSymbol'] ?? ''));
        $unitId = trim((string) ($input['measurement_unit_id'] ?? $input['measurementUnitId'] ?? ''));

        $def = [
            'key' => $key,
            'name' => $cleanName,
            'fieldType' => $fieldType,
            'required' => (bool) ($input['required'] ?? false),
            'defaultValue' => $input['default_value'] ?? $input['defaultValue'] ?? null,
            'options' => $options,
            'measurementUnitId' => $unitId !== '' ? $unitId : null,
            'measurementUnitSymbol' => $unitSymbol !== '' ? $unitSymbol : null,
            'sortOrder' => (int) ($input['sort_order'] ?? $input['sortOrder'] ?? ($maxSort + 10)),
            'level' => strtoupper((string) ($input['level'] ?? 'PRODUCT')) === 'VARIANT' ? 'VARIANT' : 'PRODUCT',
        ];

        $now = now()->toIso8601String();
        $id = $this->insertFieldRow($businessId, $tenantId ?: 'DEFAULT_TENANT', $def, $actorUid ?: 'SYSTEM', $now, now());

        return array_merge($def, [
            'id' => $id,
            'attributeId' => $id,
            'categoryId' => self::CAT_ACCESSORIES,
            'inUse' => false,
        ]);
    }

    public function updateAccessoryField(string $businessId, string $fieldId, array $input, ?string $actorUid = null): array
    {
        $row = DB::table('product_attributes')
            ->where('id', $fieldId)
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            abort(404, 'Accessory field not found.');
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (($fields['categoryId'] ?? '') !== self::CAT_ACCESSORIES || ! empty($fields['isConfigMarker'])) {
            abort(404, 'Accessory field not found.');
        }

        $cleanName = $this->cleanLabel((string) ($input['name'] ?? ($fields['name'] ?? '')));
        if ($cleanName === '') {
            throw ValidationException::withMessages(['name' => 'Field name is required.']);
        }

        $fieldType = $this->normalizeFieldType((string) ($input['field_type'] ?? $input['fieldType'] ?? ($fields['fieldType'] ?? 'TEXT')));
        if (! in_array($fieldType, self::SUPPORTED_FIELD_TYPES, true)) {
            throw ValidationException::withMessages(['field_type' => 'Unsupported field type.']);
        }

        $options = array_key_exists('options', $input)
            ? $this->normalizeDropdownOptions($input['options'])
            : ($fields['options'] ?? []);
        if ($fieldType === 'DROPDOWN' && empty($options)) {
            throw ValidationException::withMessages(['options' => 'Dropdown fields require at least one option.']);
        }

        $now = now()->toIso8601String();
        $fields['name'] = $cleanName;
        $fields['normalizedName'] = strtolower($cleanName);
        $fields['fieldType'] = $fieldType;
        $fields['dataType'] = $fieldType;
        if (array_key_exists('required', $input)) {
            $fields['required'] = (bool) $input['required'];
        }
        if (array_key_exists('default_value', $input) || array_key_exists('defaultValue', $input)) {
            $fields['defaultValue'] = $input['default_value'] ?? $input['defaultValue'];
        }
        $fields['options'] = $options;
        if (array_key_exists('measurement_unit_id', $input) || array_key_exists('measurementUnitId', $input)) {
            $fields['measurementUnitId'] = $input['measurement_unit_id'] ?? $input['measurementUnitId'];
        }
        if (array_key_exists('measurement_unit_symbol', $input) || array_key_exists('measurementUnitSymbol', $input)) {
            $fields['measurementUnitSymbol'] = $input['measurement_unit_symbol'] ?? $input['measurementUnitSymbol'];
        }
        if (array_key_exists('sort_order', $input) || array_key_exists('sortOrder', $input)) {
            $fields['sortOrder'] = (int) ($input['sort_order'] ?? $input['sortOrder']);
        }
        if (array_key_exists('level', $input)) {
            $fields['attributeLevel'] = strtoupper((string) $input['level']) === 'VARIANT' ? 'VARIANT' : 'PRODUCT';
        }
        $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
        $fields['updatedAt'] = $now;
        $payload['fields'] = $fields;

        $update = [
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('product_attributes', 'sort_order')) {
            $update['sort_order'] = (int) ($fields['sortOrder'] ?? 100);
        }
        DB::table('product_attributes')->where('id', $fieldId)->update($update);

        return [
            'id' => $fieldId,
            'attributeId' => $fieldId,
            'key' => (string) ($fields['attributeKey'] ?? $fieldId),
            'name' => $fields['name'],
            'fieldType' => $fieldType,
            'required' => (bool) ($fields['required'] ?? false),
            'defaultValue' => $fields['defaultValue'] ?? null,
            'options' => $options,
            'measurementUnitId' => $fields['measurementUnitId'] ?? null,
            'measurementUnitSymbol' => $fields['measurementUnitSymbol'] ?? null,
            'sortOrder' => (int) ($fields['sortOrder'] ?? 100),
            'level' => (string) ($fields['attributeLevel'] ?? 'PRODUCT'),
            'categoryId' => self::CAT_ACCESSORIES,
        ];
    }

    public function deleteAccessoryField(string $businessId, string $fieldId, ?string $actorUid = null): void
    {
        $row = DB::table('product_attributes')
            ->where('id', $fieldId)
            ->where('business_id', $businessId)
            ->where('scope_type', 'BUSINESS')
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            abort(404, 'Accessory field not found.');
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (($fields['categoryId'] ?? '') !== self::CAT_ACCESSORIES || ! empty($fields['isConfigMarker'])) {
            abort(404, 'Accessory field not found.');
        }

        $key = (string) ($fields['attributeKey'] ?? $fieldId);
        $name = (string) ($fields['name'] ?? $key);
        $usage = $this->collectCategoryUsage($businessId);

        if (isset($usage['accessoryKeys'][strtolower($key)])) {
            throw ValidationException::withMessages([
                'field' => "Field '{$name}' is used by existing Construction & Accessories products and cannot be deleted.",
            ]);
        }

        $now = now()->toIso8601String();
        $fields['status'] = 'DELETED';
        $fields['isDeleted'] = true;
        $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
        $fields['updatedAt'] = $now;
        $payload['fields'] = $fields;

        DB::table('product_attributes')->where('id', $fieldId)->update([
            'status' => 'DELETED',
            'is_deleted' => 1,
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);
    }

    public function reorderAccessoryFields(string $businessId, array $orderedIds, ?string $actorUid = null): void
    {
        $now = now()->toIso8601String();
        $dbNow = now();
        $sort = 10;

        foreach ($orderedIds as $fid) {
            $row = DB::table('product_attributes')
                ->where('id', $fid)
                ->where('business_id', $businessId)
                ->where('scope_type', 'BUSINESS')
                ->where('is_deleted', 0)
                ->first();
            if (! $row) {
                continue;
            }
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = $payload['fields'] ?? [];
            if (($fields['categoryId'] ?? '') !== self::CAT_ACCESSORIES || ! empty($fields['isConfigMarker'])) {
                continue;
            }
            $fields['sortOrder'] = $sort;
            $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
            $fields['updatedAt'] = $now;
            $payload['fields'] = $fields;

            $upd = ['payload' => json_encode($payload), 'updated_at' => $dbNow];
            if (Schema::hasColumn('product_attributes', 'sort_order')) {
                $upd['sort_order'] = $sort;
            }
            DB::table('product_attributes')->where('id', $fid)->update($upd);
            $sort += 10;
        }
    }

    public function listUnits(string $businessId, ?array $inUseRefs = null): array
    {
        if ($inUseRefs === null) {
            $inUseRefs = $this->collectCategoryUsage($businessId)['unitRefs'];
        }

        $rows = DB::table('units')
            ->where('is_deleted', 0)
            ->where(function ($q) use ($businessId) {
                $q->whereNull('business_id')
                    ->orWhere('business_id', '')
                    ->orWhere('business_id', 'SYSTEM')
                    ->orWhere('business_id', $businessId);
            })
            ->get();

        $merged = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = $payload['fields'] ?? [];
            $status = strtoupper((string) ($fields['status'] ?? $row->status ?? 'ACTIVE'));
            if ($status !== 'ACTIVE') {
                continue;
            }
            $id = (string) ($fields['unitId'] ?? $row->id);
            $name = (string) ($fields['name'] ?? ($row->name ?? $id));
            $symbol = (string) ($fields['symbol'] ?? ($row->symbol ?? $fields['shortName'] ?? $name));
            $displayName = (string) ($fields['displayName'] ?? ($row->display_name ?? $name));
            $merged[$id] = [
                'id' => $id,
                'name' => $name,
                'displayName' => $displayName,
                'symbol' => $symbol,
                'shortName' => $symbol,
                'allowFractional' => (bool) ($fields['allowFractional'] ?? ($row->allow_fractional ?? false)),
                'decimalPrecision' => (int) ($fields['decimalPrecision'] ?? ($row->decimal_precision ?? 0)),
                'measurementDimension' => (string) ($fields['measurementDimension'] ?? ($row->measurement_dimension ?? 'COUNT')),
                'scopeType' => (string) ($fields['scopeType'] ?? ($row->scope_type ?? 'SYSTEM')),
                'status' => $status,
                'inUse' => isset($inUseRefs[strtolower($id)]) || isset($inUseRefs[strtolower($symbol)]),
            ];
        }

        usort($merged, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return array_values($merged);
    }

    public function addCustomUnit(string $businessId, array $input, ?string $tenantId = null, ?string $actorUid = null): array
    {
        $cleanName = $this->cleanLabel((string) ($input['name'] ?? ''));
        $cleanSymbol = $this->cleanLabel((string) ($input['symbol'] ?? $input['short_name'] ?? $cleanName));
        if ($cleanName === '' || $cleanSymbol === '') {
            throw ValidationException::withMessages(['name' => 'Unit name and symbol are required.']);
        }

        $existingUnits = $this->listUnits($businessId, []);
        foreach ($existingUnits as $u) {
            if (strtolower($u['name']) === strtolower($cleanName) && strtolower($u['symbol']) === strtolower($cleanSymbol)) {
                return $u;
            }
        }

        $allowFractional = (bool) ($input['allow_fractional'] ?? $input['allowFractional'] ?? false);
        $decimalPrecision = (int) ($input['decimal_precision'] ?? $input['decimalPrecision'] ?? ($allowFractional ? 3 : 0));
        $dimension = strtoupper(trim((string) ($input['measurement_dimension'] ?? $input['measurementDimension'] ?? 'COUNT')));
        $displayName = $this->cleanLabel((string) ($input['display_name'] ?? $input['displayName'] ?? $cleanName));
        $id = 'BUS_UNIT_'.strtoupper(substr(md5(strtolower($businessId.'_'.$cleanName.'_'.$cleanSymbol.'_'.Str::random(6))), 0, 12));
        $now = now()->toIso8601String();
        $dbNow = now();

        $fields = [
            'unitId' => $id,
            'code' => strtoupper(Str::slug($cleanSymbol, '_')),
            'name' => $cleanName,
            'displayName' => $displayName,
            'symbol' => $cleanSymbol,
            'shortName' => $cleanSymbol,
            'normalizedName' => strtolower($cleanName),
            'allowFractional' => $allowFractional,
            'decimalPrecision' => $decimalPrecision,
            'measurementDimension' => $dimension,
            'scopeType' => 'BUSINESS',
            'tenantId' => $tenantId ?: 'DEFAULT_TENANT',
            'businessId' => $businessId,
            'status' => 'ACTIVE',
            'createdBy' => $actorUid ?: 'SYSTEM',
            'createdAt' => $now,
            'updatedBy' => $actorUid ?: 'SYSTEM',
            'updatedAt' => $now,
        ];

        $row = [
            'id' => $id,
            'document_path' => "units/{$id}",
            'business_id' => $businessId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['_documentId' => $id, '_documentPath' => "units/{$id}", 'fields' => $fields]),
            'created_at' => $dbNow,
            'updated_at' => $dbNow,
        ];
        if (Schema::hasColumn('units', 'tenant_id')) {
            $row['tenant_id'] = $tenantId ?: 'DEFAULT_TENANT';
        }
        if (Schema::hasColumn('units', 'scope_type')) {
            $row['scope_type'] = 'BUSINESS';
        }
        if (Schema::hasColumn('units', 'code')) {
            $row['code'] = $fields['code'];
        }
        if (Schema::hasColumn('units', 'name')) {
            $row['name'] = $cleanName;
        }
        if (Schema::hasColumn('units', 'display_name')) {
            $row['display_name'] = $displayName;
        }
        if (Schema::hasColumn('units', 'symbol')) {
            $row['symbol'] = $cleanSymbol;
        }
        if (Schema::hasColumn('units', 'allow_fractional')) {
            $row['allow_fractional'] = $allowFractional ? 1 : 0;
        }
        if (Schema::hasColumn('units', 'decimal_precision')) {
            $row['decimal_precision'] = $decimalPrecision;
        }
        if (Schema::hasColumn('units', 'measurement_dimension')) {
            $row['measurement_dimension'] = $dimension;
        }

        DB::table('units')->insert($row);

        return array_merge($fields, ['id' => $id, 'inUse' => false]);
    }

    public function updateCustomUnit(string $businessId, string $unitId, array $input, ?string $actorUid = null): array
    {
        $row = DB::table('units')
            ->where('id', $unitId)
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            abort(404, 'Custom measurement unit not found (or system unit cannot be edited).');
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (strtoupper((string) ($fields['scopeType'] ?? 'SYSTEM')) !== 'BUSINESS') {
            abort(404, 'Custom measurement unit not found (or system unit cannot be edited).');
        }
        $cleanName = $this->cleanLabel((string) ($input['name'] ?? ($fields['name'] ?? '')));
        $cleanSymbol = $this->cleanLabel((string) ($input['symbol'] ?? $input['short_name'] ?? ($fields['symbol'] ?? '')));
        if ($cleanName === '' || $cleanSymbol === '') {
            throw ValidationException::withMessages(['name' => 'Unit name and symbol are required.']);
        }

        $allowFractional = array_key_exists('allow_fractional', $input) || array_key_exists('allowFractional', $input)
            ? (bool) ($input['allow_fractional'] ?? $input['allowFractional'])
            : (bool) ($fields['allowFractional'] ?? false);
        $decimalPrecision = (int) ($input['decimal_precision'] ?? $input['decimalPrecision'] ?? ($allowFractional ? 3 : 0));
        $dimension = strtoupper(trim((string) ($input['measurement_dimension'] ?? $input['measurementDimension'] ?? ($fields['measurementDimension'] ?? 'COUNT'))));
        $displayName = $this->cleanLabel((string) ($input['display_name'] ?? $input['displayName'] ?? $cleanName));

        $fields['name'] = $cleanName;
        $fields['displayName'] = $displayName;
        $fields['symbol'] = $cleanSymbol;
        $fields['shortName'] = $cleanSymbol;
        $fields['normalizedName'] = strtolower($cleanName);
        $fields['allowFractional'] = $allowFractional;
        $fields['decimalPrecision'] = $decimalPrecision;
        $fields['measurementDimension'] = $dimension;
        $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
        $fields['updatedAt'] = now()->toIso8601String();
        $payload['fields'] = $fields;

        $update = [
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('units', 'name')) {
            $update['name'] = $cleanName;
        }
        if (Schema::hasColumn('units', 'display_name')) {
            $update['display_name'] = $displayName;
        }
        if (Schema::hasColumn('units', 'symbol')) {
            $update['symbol'] = $cleanSymbol;
        }
        if (Schema::hasColumn('units', 'allow_fractional')) {
            $update['allow_fractional'] = $allowFractional ? 1 : 0;
        }
        if (Schema::hasColumn('units', 'decimal_precision')) {
            $update['decimal_precision'] = $decimalPrecision;
        }
        if (Schema::hasColumn('units', 'measurement_dimension')) {
            $update['measurement_dimension'] = $dimension;
        }

        DB::table('units')->where('id', $unitId)->update($update);

        return array_merge($fields, ['id' => $unitId]);
    }

    public function deleteCustomUnit(string $businessId, string $unitId, ?string $actorUid = null): void
    {
        $row = DB::table('units')
            ->where('id', $unitId)
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            abort(404, 'Custom measurement unit not found.');
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (strtoupper((string) ($fields['scopeType'] ?? 'SYSTEM')) !== 'BUSINESS') {
            abort(404, 'System measurement unit cannot be deleted.');
        }
        $symbol = (string) ($fields['symbol'] ?? ($row->symbol ?? ''));

        $usage = $this->collectCategoryUsage($businessId);
        if (isset($usage['unitRefs'][strtolower($unitId)]) || ($symbol !== '' && isset($usage['unitRefs'][strtolower($symbol)]))) {
            throw ValidationException::withMessages([
                'unit' => "Measurement unit '{$symbol}' is used by existing products and cannot be deleted.",
            ]);
        }

        $fields['status'] = 'DELETED';
        $fields['isDeleted'] = true;
        $fields['updatedBy'] = $actorUid ?: 'SYSTEM';
        $fields['updatedAt'] = now()->toIso8601String();
        $payload['fields'] = $fields;

        DB::table('units')->where('id', $unitId)->update([
            'status' => 'DELETED',
            'is_deleted' => 1,
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);
    }

    /**
     * Collect currently active product/variant references for Gravel options, Accessory custom field keys, and Units.
     *
     * @return array{gravelSizes:array<string,bool>,gravelQualities:array<string,bool>,accessoryKeys:array<string,bool>,unitRefs:array<string,bool>}
     */
    private function collectCategoryUsage(string $businessId): array
    {
        $gravelSizes = [];
        $gravelQualities = [];
        $accessoryKeys = [];
        $unitRefs = [];

        $products = DB::table('products')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $prodCatMap = [];
        foreach ($products as $pr) {
            $fields = (json_decode($pr->payload ?? '{}', true) ?: [])['fields'] ?? [];
            if (strtoupper((string) ($fields['status'] ?? $pr->status ?? 'ACTIVE')) === 'DELETED') {
                continue;
            }
            $catId = (string) ($fields['categoryId'] ?? ($pr->category ?? ''));
            $pid = (string) ($fields['productId'] ?? $pr->id);
            $prodCatMap[$pid] = $catId;

            foreach (['baseUnitId', 'purchaseUnitId', 'defaultSalesUnitId', 'unitId', 'unitSymbol'] as $uKey) {
                $val = trim((string) ($fields[$uKey] ?? ''));
                if ($val !== '') {
                    $unitRefs[strtolower($val)] = true;
                }
            }

            $attrs = is_array($fields['attributeValues'] ?? null) ? $fields['attributeValues'] : [];
            $this->recordAttrUsage($catId, $attrs, $gravelSizes, $gravelQualities, $accessoryKeys);
        }

        $variants = DB::table('product_variants')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        foreach ($variants as $vr) {
            $fields = (json_decode($vr->payload ?? '{}', true) ?: [])['fields'] ?? [];
            if (strtoupper((string) ($fields['status'] ?? $vr->status ?? 'ACTIVE')) === 'DELETED') {
                continue;
            }
            $pid = (string) ($fields['productId'] ?? ($vr->product_id ?? ''));
            $catId = $prodCatMap[$pid] ?? '';

            foreach (['baseUnitId', 'purchaseUnitId', 'defaultSalesUnitId', 'unitId', 'commercialUnitId', 'unitSymbol'] as $uKey) {
                $val = trim((string) ($fields[$uKey] ?? ''));
                if ($val !== '') {
                    $unitRefs[strtolower($val)] = true;
                }
            }

            $attrs = is_array($fields['attributeValues'] ?? null) ? $fields['attributeValues'] : [];
            $this->recordAttrUsage($catId, $attrs, $gravelSizes, $gravelQualities, $accessoryKeys);
        }

        return [
            'gravelSizes' => $gravelSizes,
            'gravelQualities' => $gravelQualities,
            'accessoryKeys' => $accessoryKeys,
            'unitRefs' => $unitRefs,
        ];
    }

    private function recordAttrUsage(string $catId, array $attrs, array &$gravelSizes, array &$gravelQualities, array &$accessoryKeys): void
    {
        if ($catId === self::CAT_GRAVELS || stripos($catId, 'gravel') !== false) {
            foreach (['aggregateSize', 'size'] as $k) {
                $v = trim((string) ($attrs[$k] ?? ''));
                if ($v !== '') {
                    $gravelSizes[strtolower($v)] = true;
                }
            }
            foreach (['gradeQuality', 'quality'] as $k) {
                $v = trim((string) ($attrs[$k] ?? ''));
                if ($v !== '') {
                    $gravelQualities[strtolower($v)] = true;
                }
            }
        }

        if ($catId === self::CAT_ACCESSORIES || stripos($catId, 'accessor') !== false) {
            $custom = is_array($attrs['customAttributes'] ?? null) ? $attrs['customAttributes'] : [];
            foreach (array_merge($attrs, $custom) as $k => $v) {
                if ($k === 'customAttributes' || $v === null || $v === '' || $v === []) {
                    continue;
                }
                $accessoryKeys[strtolower((string) $k)] = true;
            }
        }
    }

    private function insertMarkerRow(string $markerId, string $businessId, string $tenantId, string $categoryId, string $now, \DateTimeInterface $dbNow): void
    {
        $fields = [
            'attributeId' => $markerId,
            'categoryId' => $categoryId,
            'isConfigMarker' => true,
            'scopeType' => 'BUSINESS',
            'tenantId' => $tenantId,
            'businessId' => $businessId,
            'status' => 'ACTIVE',
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
        $row = [
            'id' => $markerId,
            'document_path' => "product_attributes/{$markerId}",
            'business_id' => $businessId,
            'tenant_id' => $tenantId,
            'scope_type' => 'BUSINESS',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['_documentId' => $markerId, '_documentPath' => "product_attributes/{$markerId}", 'fields' => $fields]),
            'created_at' => $dbNow,
            'updated_at' => $dbNow,
        ];
        if (Schema::hasColumn('product_attributes', 'category_id')) {
            $row['category_id'] = $categoryId;
        }
        DB::table('product_attributes')->insert($row);
    }

    private function insertOptionRow(
        string $businessId,
        string $tenantId,
        string $attrKey,
        string $name,
        int $sortOrder,
        string $actor,
        string $now,
        \DateTimeInterface $dbNow
    ): string {
        $attrDocId = "BUSATTR_GRAVELS_{$businessId}_{$attrKey}";
        $id = 'BUSOPT_'.strtoupper(substr(md5(strtolower("{$businessId}_{$attrKey}_{$name}_".Str::random(4))), 0, 14));
        $fields = [
            'optionId' => $id,
            'attributeId' => $attrDocId,
            'attributeKey' => $attrKey,
            'categoryId' => self::CAT_GRAVELS,
            'name' => $name,
            'normalizedName' => strtolower($name),
            'sortOrder' => $sortOrder,
            'scopeType' => 'BUSINESS',
            'tenantId' => $tenantId,
            'businessId' => $businessId,
            'status' => 'ACTIVE',
            'isDeleted' => false,
            'createdBy' => $actor,
            'createdAt' => $now,
            'updatedBy' => $actor,
            'updatedAt' => $now,
        ];
        $row = [
            'id' => $id,
            'document_path' => "product_attribute_options/{$id}",
            'business_id' => $businessId,
            'tenant_id' => $tenantId,
            'scope_type' => 'BUSINESS',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['_documentId' => $id, '_documentPath' => "product_attribute_options/{$id}", 'fields' => $fields]),
            'created_at' => $dbNow,
            'updated_at' => $dbNow,
        ];
        if (Schema::hasColumn('product_attribute_options', 'category_id')) {
            $row['category_id'] = self::CAT_GRAVELS;
        }
        if (Schema::hasColumn('product_attribute_options', 'attribute_key')) {
            $row['attribute_key'] = $attrKey;
        }
        if (Schema::hasColumn('product_attribute_options', 'sort_order')) {
            $row['sort_order'] = $sortOrder;
        }
        DB::table('product_attribute_options')->insert($row);

        return $id;
    }

    private function insertFieldRow(
        string $businessId,
        string $tenantId,
        array $def,
        string $actor,
        string $now,
        \DateTimeInterface $dbNow
    ): string {
        $id = 'BUSFLD_'.strtoupper(substr(md5(strtolower("{$businessId}_".($def['key'] ?? Str::random(6)).'_'.Str::random(4))), 0, 14));
        $fields = [
            'attributeId' => $id,
            'attributeKey' => $def['key'],
            'code' => $def['key'],
            'name' => $def['name'],
            'normalizedName' => strtolower($def['name']),
            'categoryId' => self::CAT_ACCESSORIES,
            'fieldType' => $def['fieldType'],
            'dataType' => $def['fieldType'],
            'attributeLevel' => $def['level'] ?? 'PRODUCT',
            'required' => (bool) ($def['required'] ?? false),
            'defaultValue' => $def['defaultValue'] ?? null,
            'options' => $def['options'] ?? [],
            'measurementUnitId' => $def['measurementUnitId'] ?? null,
            'measurementUnitSymbol' => $def['measurementUnitSymbol'] ?? null,
            'sortOrder' => (int) ($def['sortOrder'] ?? 100),
            'allowMultiple' => false,
            'allowAddNewOption' => true,
            'scopeType' => 'BUSINESS',
            'tenantId' => $tenantId,
            'businessId' => $businessId,
            'status' => 'ACTIVE',
            'isDeleted' => false,
            'createdBy' => $actor,
            'createdAt' => $now,
            'updatedBy' => $actor,
            'updatedAt' => $now,
        ];
        $row = [
            'id' => $id,
            'document_path' => "product_attributes/{$id}",
            'business_id' => $businessId,
            'tenant_id' => $tenantId,
            'scope_type' => 'BUSINESS',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['_documentId' => $id, '_documentPath' => "product_attributes/{$id}", 'fields' => $fields]),
            'created_at' => $dbNow,
            'updated_at' => $dbNow,
        ];
        if (Schema::hasColumn('product_attributes', 'category_id')) {
            $row['category_id'] = self::CAT_ACCESSORIES;
        }
        if (Schema::hasColumn('product_attributes', 'attribute_key')) {
            $row['attribute_key'] = $def['key'];
        }
        if (Schema::hasColumn('product_attributes', 'sort_order')) {
            $row['sort_order'] = (int) ($def['sortOrder'] ?? 100);
        }
        DB::table('product_attributes')->insert($row);

        return $id;
    }

    private function resolveGravelAttributeKey(string $attributeType): string
    {
        $t = strtolower(trim($attributeType));
        if (in_array($t, ['size', 'aggregatesize', 'aggregate_size'], true)) {
            return self::GRAVEL_SIZE_KEY;
        }
        if (in_array($t, ['quality', 'grade', 'gradequality', 'grade_quality'], true)) {
            return self::GRAVEL_QUALITY_KEY;
        }
        throw ValidationException::withMessages(['attribute_type' => 'attribute_type must be size or quality.']);
    }

    private function normalizeFieldType(string $type): string
    {
        $t = strtoupper(trim($type));
        if ($t === 'SELECT') {
            return 'DROPDOWN';
        }
        if (in_array($t, ['BOOL', 'YES_NO', 'YESNO'], true)) {
            return 'BOOLEAN';
        }

        return $t;
    }

    private function normalizeDropdownOptions(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = array_map('trim', explode(',', $raw));

            return array_values(array_unique(array_filter($parts, fn ($v) => $v !== '')));
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $raw), fn ($v) => $v !== '')));
    }

    private function cleanLabel(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
