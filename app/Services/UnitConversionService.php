<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UnitConversionService
{
    /**
     * Canonical system units mapped by unit ID.
     */
    public const STANDARD_UNITS = [
        'SYS_UNIT_CFT' => [
            'id' => 'SYS_UNIT_CFT',
            'code' => 'cft',
            'name' => 'CFT',
            'displayName' => 'Cubic Feet (CFT)',
            'symbol' => 'CFT',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'VOLUME',
        ],
        'SYS_UNIT_KG' => [
            'id' => 'SYS_UNIT_KG',
            'code' => 'kg',
            'name' => 'KG',
            'displayName' => 'Kilogram (KG)',
            'symbol' => 'KG',
            'allowFractional' => true,
            'precision' => 3,
            'dimension' => 'WEIGHT',
        ],
        'SYS_UNIT_GRAM' => [
            'id' => 'SYS_UNIT_GRAM',
            'code' => 'g',
            'name' => 'Gram',
            'displayName' => 'Gram',
            'symbol' => 'g',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'WEIGHT',
        ],
        'SYS_UNIT_METRIC_TON' => [
            'id' => 'SYS_UNIT_METRIC_TON',
            'code' => 'MT',
            'name' => 'Metric Tonne',
            'displayName' => 'Metric Tonne (MT)',
            'symbol' => 'MT',
            'allowFractional' => true,
            'precision' => 3,
            'dimension' => 'WEIGHT',
        ],
        'SYS_UNIT_PIECE' => [
            'id' => 'SYS_UNIT_PIECE',
            'code' => 'pcs',
            'name' => 'Piece (PCS)',
            'displayName' => 'Piece (PCS)',
            'symbol' => 'PCS',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_BAG' => [
            'id' => 'SYS_UNIT_BAG',
            'code' => 'bag',
            'name' => 'Bag',
            'displayName' => 'Bag',
            'symbol' => 'Bag',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_LITRE' => [
            'id' => 'SYS_UNIT_LITRE',
            'code' => 'L',
            'name' => 'Litre',
            'displayName' => 'Litre',
            'symbol' => 'L',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'VOLUME',
        ],
        'SYS_UNIT_ML' => [
            'id' => 'SYS_UNIT_ML',
            'code' => 'ML',
            'name' => 'ML',
            'displayName' => 'Millilitre (ML)',
            'symbol' => 'ML',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'VOLUME',
        ],
        'SYS_UNIT_BOX' => [
            'id' => 'SYS_UNIT_BOX',
            'code' => 'box',
            'name' => 'Box',
            'displayName' => 'Box',
            'symbol' => 'Box',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_PACKET' => [
            'id' => 'SYS_UNIT_PACKET',
            'code' => 'pkt',
            'name' => 'Packet',
            'displayName' => 'Packet',
            'symbol' => 'Pkt',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_BUNDLE' => [
            'id' => 'SYS_UNIT_BUNDLE',
            'code' => 'bdl',
            'name' => 'Bundle',
            'displayName' => 'Bundle',
            'symbol' => 'Bdl',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_METRE' => [
            'id' => 'SYS_UNIT_METRE',
            'code' => 'm',
            'name' => 'Metre',
            'displayName' => 'Metre',
            'symbol' => 'm',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'LENGTH',
        ],
        'SYS_UNIT_SQFT' => [
            'id' => 'SYS_UNIT_SQFT',
            'code' => 'sqft',
            'name' => 'Square Feet',
            'displayName' => 'Square Feet',
            'symbol' => 'SqFt',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'AREA',
        ],
        'SYS_UNIT_CUBIC_FEET' => [
            'id' => 'SYS_UNIT_CUBIC_FEET',
            'code' => 'cuft',
            'name' => 'Cubic Feet',
            'displayName' => 'Cubic Feet',
            'symbol' => 'CuFt',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'VOLUME',
        ],
        'SYS_UNIT_DOZEN' => [
            'id' => 'SYS_UNIT_DOZEN',
            'code' => 'doz',
            'name' => 'Dozen',
            'displayName' => 'Dozen',
            'symbol' => 'Dozen',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_BRASS' => [
            'id' => 'SYS_UNIT_BRASS',
            'code' => 'brass',
            'name' => 'Brass (100 cft)',
            'displayName' => 'Brass (100 CFT)',
            'symbol' => 'Brass',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'VOLUME',
        ],
        'SYS_UNIT_THOUSAND_NOS' => [
            'id' => 'SYS_UNIT_THOUSAND_NOS',
            'code' => '1000 nos',
            'name' => '1000 Pieces (Nos)',
            'displayName' => '1000 Pieces',
            'symbol' => '1000 PCS',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_CM' => [
            'id' => 'SYS_UNIT_CM',
            'code' => 'cm',
            'name' => 'Centimetre',
            'displayName' => 'Centimetre (cm)',
            'symbol' => 'cm',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'LENGTH',
        ],
        'SYS_UNIT_FOOT' => [
            'id' => 'SYS_UNIT_FOOT',
            'code' => 'ft',
            'name' => 'Foot',
            'displayName' => 'Foot (ft)',
            'symbol' => 'ft',
            'allowFractional' => true,
            'precision' => 2,
            'dimension' => 'LENGTH',
        ],
        'SYS_UNIT_ROLL' => [
            'id' => 'SYS_UNIT_ROLL',
            'code' => 'roll',
            'name' => 'Roll',
            'displayName' => 'Roll',
            'symbol' => 'Roll',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_PAIR' => [
            'id' => 'SYS_UNIT_PAIR',
            'code' => 'pair',
            'name' => 'Pair',
            'displayName' => 'Pair',
            'symbol' => 'Pair',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_SET' => [
            'id' => 'SYS_UNIT_SET',
            'code' => 'set',
            'name' => 'Set',
            'displayName' => 'Set',
            'symbol' => 'Set',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
        'SYS_UNIT_CARTON' => [
            'id' => 'SYS_UNIT_CARTON',
            'code' => 'carton',
            'name' => 'Carton',
            'displayName' => 'Carton',
            'symbol' => 'Carton',
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ],
    ];

    /**
     * Common aliases / legacy unit identifiers mapped to canonical IDs.
     */
    private const ALIASES = [
        'cft' => 'SYS_UNIT_CFT',
        'sys_unit_cft' => 'SYS_UNIT_CFT',
        'cubic feet (cft)' => 'SYS_UNIT_CFT',
        'cubic feet' => 'SYS_UNIT_CUBIC_FEET',
        'cuft' => 'SYS_UNIT_CUBIC_FEET',
        'sys_unit_cubic_feet' => 'SYS_UNIT_CUBIC_FEET',
        'kg' => 'SYS_UNIT_KG',
        'kilogram' => 'SYS_UNIT_KG',
        'kilogram (kg)' => 'SYS_UNIT_KG',
        'sys_unit_kg' => 'SYS_UNIT_KG',
        'g' => 'SYS_UNIT_GRAM',
        'gm' => 'SYS_UNIT_GRAM',
        'gram' => 'SYS_UNIT_GRAM',
        'grams' => 'SYS_UNIT_GRAM',
        'sys_unit_gram' => 'SYS_UNIT_GRAM',
        'mt' => 'SYS_UNIT_METRIC_TON',
        'ton' => 'SYS_UNIT_METRIC_TON',
        'tonne' => 'SYS_UNIT_METRIC_TON',
        'tonnes' => 'SYS_UNIT_METRIC_TON',
        'metric ton' => 'SYS_UNIT_METRIC_TON',
        'metric tonne' => 'SYS_UNIT_METRIC_TON',
        'metric ton (mt)' => 'SYS_UNIT_METRIC_TON',
        'metric tonne (mt)' => 'SYS_UNIT_METRIC_TON',
        'sys_unit_ton' => 'SYS_UNIT_METRIC_TON',
        'sys_unit_metric_ton' => 'SYS_UNIT_METRIC_TON',
        'pc' => 'SYS_UNIT_PIECE',
        'pcs' => 'SYS_UNIT_PIECE',
        'piece' => 'SYS_UNIT_PIECE',
        'pieces' => 'SYS_UNIT_PIECE',
        'piece (pcs)' => 'SYS_UNIT_PIECE',
        'piece / rod' => 'SYS_UNIT_PIECE',
        'nos' => 'SYS_UNIT_PIECE',
        'unit' => 'SYS_UNIT_PIECE',
        'sys_unit_pcs' => 'SYS_UNIT_PIECE',
        'sys_unit_piece' => 'SYS_UNIT_PIECE',
        'bag' => 'SYS_UNIT_BAG',
        'bags' => 'SYS_UNIT_BAG',
        'sys_unit_bag' => 'SYS_UNIT_BAG',
        'l' => 'SYS_UNIT_LITRE',
        'ltr' => 'SYS_UNIT_LITRE',
        'litre' => 'SYS_UNIT_LITRE',
        'litres' => 'SYS_UNIT_LITRE',
        'liter' => 'SYS_UNIT_LITRE',
        'liters' => 'SYS_UNIT_LITRE',
        'sys_unit_l' => 'SYS_UNIT_LITRE',
        'sys_unit_ltr' => 'SYS_UNIT_LITRE',
        'sys_unit_litre' => 'SYS_UNIT_LITRE',
        'ml' => 'SYS_UNIT_ML',
        'millilitre' => 'SYS_UNIT_ML',
        'milliliter' => 'SYS_UNIT_ML',
        'millilitre (ml)' => 'SYS_UNIT_ML',
        'sys_unit_ml' => 'SYS_UNIT_ML',
        'box' => 'SYS_UNIT_BOX',
        'boxes' => 'SYS_UNIT_BOX',
        'sys_unit_box' => 'SYS_UNIT_BOX',
        'pkt' => 'SYS_UNIT_PACKET',
        'packet' => 'SYS_UNIT_PACKET',
        'packets' => 'SYS_UNIT_PACKET',
        'pack' => 'SYS_UNIT_PACKET',
        'sys_unit_packet' => 'SYS_UNIT_PACKET',
        'bdl' => 'SYS_UNIT_BUNDLE',
        'bundle' => 'SYS_UNIT_BUNDLE',
        'bundles' => 'SYS_UNIT_BUNDLE',
        'sys_unit_bundle' => 'SYS_UNIT_BUNDLE',
        'm' => 'SYS_UNIT_METRE',
        'mtr' => 'SYS_UNIT_METRE',
        'metre' => 'SYS_UNIT_METRE',
        'meter' => 'SYS_UNIT_METRE',
        'sys_unit_metre' => 'SYS_UNIT_METRE',
        'sqft' => 'SYS_UNIT_SQFT',
        'sq.ft' => 'SYS_UNIT_SQFT',
        'sq ft' => 'SYS_UNIT_SQFT',
        'square feet' => 'SYS_UNIT_SQFT',
        'sys_unit_sqft' => 'SYS_UNIT_SQFT',
        'doz' => 'SYS_UNIT_DOZEN',
        'dz' => 'SYS_UNIT_DOZEN',
        'dozen' => 'SYS_UNIT_DOZEN',
        'sys_unit_dozen' => 'SYS_UNIT_DOZEN',
        'brass' => 'SYS_UNIT_BRASS',
        'brass (100 cft)' => 'SYS_UNIT_BRASS',
        'sys_unit_brass' => 'SYS_UNIT_BRASS',
        '1000 nos' => 'SYS_UNIT_THOUSAND_NOS',
        '1000 pcs' => 'SYS_UNIT_THOUSAND_NOS',
        'sys_unit_thousand_nos' => 'SYS_UNIT_THOUSAND_NOS',
        'cm' => 'SYS_UNIT_CM',
        'centimetre' => 'SYS_UNIT_CM',
        'centimeter' => 'SYS_UNIT_CM',
        'centimetres' => 'SYS_UNIT_CM',
        'centimeters' => 'SYS_UNIT_CM',
        'sys_unit_cm' => 'SYS_UNIT_CM',
        'ft' => 'SYS_UNIT_FOOT',
        'foot' => 'SYS_UNIT_FOOT',
        'feet' => 'SYS_UNIT_FOOT',
        'sys_unit_foot' => 'SYS_UNIT_FOOT',
        'sys_unit_ft' => 'SYS_UNIT_FOOT',
        'roll' => 'SYS_UNIT_ROLL',
        'rolls' => 'SYS_UNIT_ROLL',
        'sys_unit_roll' => 'SYS_UNIT_ROLL',
        'pair' => 'SYS_UNIT_PAIR',
        'pairs' => 'SYS_UNIT_PAIR',
        'sys_unit_pair' => 'SYS_UNIT_PAIR',
        'set' => 'SYS_UNIT_SET',
        'sets' => 'SYS_UNIT_SET',
        'sys_unit_set' => 'SYS_UNIT_SET',
        'carton' => 'SYS_UNIT_CARTON',
        'cartons' => 'SYS_UNIT_CARTON',
        'ctn' => 'SYS_UNIT_CARTON',
        'sys_unit_carton' => 'SYS_UNIT_CARTON',
    ];

    /**
     * Implicit cross-unit base conversion factors when two known units share a dimension.
     */
    private const KNOWN_PAIR_FACTORS = [
        'SYS_UNIT_METRIC_TON->SYS_UNIT_KG' => 1000.0,
        'SYS_UNIT_KG->SYS_UNIT_METRIC_TON' => 0.001,
        'SYS_UNIT_KG->SYS_UNIT_GRAM' => 1000.0,
        'SYS_UNIT_GRAM->SYS_UNIT_KG' => 0.001,
        'SYS_UNIT_LITRE->SYS_UNIT_ML' => 1000.0,
        'SYS_UNIT_ML->SYS_UNIT_LITRE' => 0.001,
        'SYS_UNIT_METRE->SYS_UNIT_CM' => 100.0,
        'SYS_UNIT_CM->SYS_UNIT_METRE' => 0.01,
        'SYS_UNIT_FOOT->SYS_UNIT_CM' => 30.48,
        'SYS_UNIT_BRASS->SYS_UNIT_CFT' => 100.0,
        'SYS_UNIT_CFT->SYS_UNIT_BRASS' => 0.01,
        'SYS_UNIT_CFT->SYS_UNIT_CUBIC_FEET' => 1.0,
        'SYS_UNIT_CUBIC_FEET->SYS_UNIT_CFT' => 1.0,
        'SYS_UNIT_THOUSAND_NOS->SYS_UNIT_PIECE' => 1000.0,
        'SYS_UNIT_DOZEN->SYS_UNIT_PIECE' => 12.0,
        'SYS_UNIT_PAIR->SYS_UNIT_PIECE' => 2.0,
        'SYS_UNIT_PIECE->SYS_UNIT_PAIR' => 0.5,
    ];

    /**
     * Resolve a unit ID, code, name, or symbol to canonical metadata.
     *
     * @return array<string, mixed>
     */
    public function resolveUnit(?string $unitRef, ?string $businessId = null): array
    {
        $raw = trim((string) $unitRef);
        if ($raw === '') {
            return self::STANDARD_UNITS['SYS_UNIT_PIECE'];
        }

        $lower = strtolower($raw);
        if (isset(self::ALIASES[$lower])) {
            $canonicalId = self::ALIASES[$lower];
            if (isset(self::STANDARD_UNITS[$canonicalId])) {
                return self::STANDARD_UNITS[$canonicalId];
            }
        }

        if (isset(self::STANDARD_UNITS[$raw])) {
            return self::STANDARD_UNITS[$raw];
        }

        // Lookup in central `units` table if available
        if (Schema::hasTable('units')) {
            $row = DB::table('units')
                ->where('is_deleted', 0)
                ->where(function ($q) use ($raw) {
                    $q->where('id', $raw)
                        ->orWhere('code', $raw)
                        ->orWhere('name', $raw)
                        ->orWhere('symbol', $raw);
                })
                ->when($businessId, function ($q) use ($businessId) {
                    $q->where(function ($sub) use ($businessId) {
                        $sub->whereNull('business_id')
                            ->orWhere('business_id', '')
                            ->orWhere('business_id', $businessId);
                    });
                })
                ->first();

            if ($row) {
                $payload = json_decode($row->payload ?? '{}', true) ?: [];
                $f = $payload['fields'] ?? [];
                $id = (string) ($f['unitId'] ?? $row->id);
                if (isset(self::STANDARD_UNITS[$id])) {
                    return self::STANDARD_UNITS[$id];
                }
                $name = (string) ($row->name ?? $f['name'] ?? $id);
                $displayName = (string) ($row->display_name ?? $f['displayName'] ?? $name);
                $symbol = (string) ($row->symbol ?? $f['symbol'] ?? $name);
                $precision = (int) ($row->decimal_precision ?? $f['precision'] ?? 0);
                $allowFrac = isset($row->allow_fractional)
                    ? (bool) $row->allow_fractional
                    : (bool) ($f['allowFractional'] ?? ($precision > 0));

                return [
                    'id' => $id,
                    'code' => (string) ($row->code ?? $f['code'] ?? strtolower($symbol)),
                    'name' => $name,
                    'displayName' => $displayName,
                    'symbol' => $symbol,
                    'allowFractional' => $allowFrac,
                    'precision' => $precision,
                    'dimension' => (string) ($row->measurement_dimension ?? $f['measurementDimension'] ?? 'COUNT'),
                ];
            }
        }

        // Custom unknown string (e.g., historic display text)
        return [
            'id' => $raw,
            'code' => $lower,
            'name' => $raw,
            'displayName' => $raw,
            'symbol' => $raw,
            'allowFractional' => false,
            'precision' => 0,
            'dimension' => 'COUNT',
        ];
    }

    /**
     * Suggest default commercial unit configuration based on category/subcategory/product name.
     *
     * @return array<string, mixed>
     */
    public function resolveCategoryDefault(?string $category, ?string $subcategory = null, ?string $productName = null): array
    {
        $haystack = strtolower(trim(($category ?? '').' '.($subcategory ?? '').' '.($productName ?? '')));

        if (preg_match('/\b(masking\s*tape|ptfe\s*tape|teflon\s*tape|electrical\s*tape|insulation\s*tape)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_ROLL'];
        }
        if (preg_match('/\b(paint\s*remover|paint\s*thinner|turpentine|pvc\s*solvent)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_LITRE'];
        }
        if (preg_match('/\b(paint\s*brush|paint\s*roller|roller\s*handle|roller\s*refill|paint\s*tray|paint\s*scraper|putty\s*knife|sandpaper|paint\s*bucket|paint\s*stirrer|wire\s*brush|spray\s*gun)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_PIECE'];
        }
        if (preg_match('/\b(iron\s*nails?|steel\s*nails?|concrete\s*nails?|roofing\s*nails?|binding\s*wire|gi\s*wire|tile\s*grout)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_KG'];
        }
        if (preg_match('/\b(electrical\s*wire|insulation\s*sleeve|nylon\s*rope|rope)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_METRE'];
        }
        if (preg_match('/\b(cable\s*ties?|tile\s*spacers?)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_PACKET'];
        }
        if (preg_match('/\b(construction\s*gloves?|hand\s*gloves?|safety\s*shoes?)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_PAIR'];
        }
        if (preg_match('/\b(tile\s*adhesive)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_BAG'];
        }

        if (preg_match('/\b(sand|gravel|stone\s*chip|aggregate|crushed\s*stone|bajri|gitti)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_CFT'];
        }
        if (preg_match('/\b(brick|aac\s*block|fly\s*ash\s*brick)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_PIECE'];
        }
        if (preg_match('/\b(cement|opc|ppc|psc|white\s*cement)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_BAG'];
        }
        if (preg_match('/\b(steel|tmt|rebar|steel\s*rod|steel\s*bar|ms\s*rod|binding\s*wire)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_KG'];
        }
        if (preg_match('/\b(accessories|construction\s*accessories)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_PIECE'];
        }
        if (preg_match('/\b(paint|emulsion|primer|enamel|varnish|distemper|thinner|stain)\b/', $haystack)) {
            return self::STANDARD_UNITS['SYS_UNIT_LITRE'];
        }

        return self::STANDARD_UNITS['SYS_UNIT_PIECE'];
    }

    /**
     * Resolve full unit configuration for a product variant + product fallback.
     *
     * @param  array<string, mixed>  $vFields
     * @param  array<string, mixed>  $pFields
     * @return array<string, mixed>
     */
    public function resolveVariantUnitConfig(array $vFields, array $pFields = [], ?string $businessId = null): array
    {
        $vAttrs = is_array($vFields['attributeValues'] ?? null) ? $vFields['attributeValues'] : [];
        $pAttrs = is_array($pFields['attributeValues'] ?? null) ? $pFields['attributeValues'] : [];

        $category = (string) ($pFields['categoryName'] ?? $pFields['category'] ?? $vFields['category'] ?? '');
        $subcategory = (string) ($pFields['subcategory'] ?? $pFields['subcategoryName'] ?? '');
        $productName = (string) ($pFields['productName'] ?? $pFields['name'] ?? $vFields['productName'] ?? '');
        $categoryDefault = $this->resolveCategoryDefault($category, $subcategory, $productName);

        // 1. Base Inventory Unit
        $rawBase = $vFields['baseUnitId']
            ?? $vFields['base_unit_id']
            ?? $pFields['baseUnitId']
            ?? $pFields['base_unit_id']
            ?? $vAttrs['baseUnit']
            ?? $pAttrs['baseUnit']
            ?? null;
        if (! $rawBase || strtoupper(trim((string) $rawBase)) === 'UNIT') {
            $rawBase = $vFields['defaultSalesUnitId']
                ?? $vFields['default_sales_unit_id']
                ?? $vFields['commercialUnitId']
                ?? $vFields['commercialUnitName']
                ?? $vAttrs['soldAs']
                ?? $pAttrs['soldAs']
                ?? $categoryDefault['id'];
        }
        $baseUnit = $this->resolveUnit((string) $rawBase, $businessId);

        // 2. Default Sales Unit
        $rawSales = $vFields['defaultSalesUnitId']
            ?? $vFields['default_sales_unit_id']
            ?? $vFields['salesUnitId']
            ?? $vFields['commercialUnitId']
            ?? $vFields['commercialUnitName']
            ?? $pFields['defaultSalesUnitId']
            ?? $pFields['default_sales_unit_id']
            ?? $vAttrs['soldAs']
            ?? $vAttrs['defaultUnit']
            ?? $pAttrs['soldAs']
            ?? $baseUnit['id'];
        $salesUnit = $this->resolveUnit((string) $rawSales, $businessId);

        // 3. Sales Conversion Factor (1 salesUnit = X baseUnit)
        $rawSalesFactor = $vFields['salesConversionFactor']
            ?? $vFields['sales_conversion_factor']
            ?? $vFields['baseQuantityPerCommercialUnit']
            ?? $pFields['salesConversionFactor']
            ?? $pFields['sales_conversion_factor']
            ?? null;
        $salesFactor = ($rawSalesFactor !== null && is_numeric($rawSalesFactor) && (float) $rawSalesFactor > 0)
            ? (float) $rawSalesFactor
            : $this->defaultPairFactor($salesUnit['id'], $baseUnit['id']);

        // 4. Purchase Unit
        $rawPurchase = $vFields['purchaseUnitId']
            ?? $vFields['purchase_unit_id']
            ?? $pFields['purchaseUnitId']
            ?? $pFields['purchase_unit_id']
            ?? $vFields['packUnitId']
            ?? $baseUnit['id'];
        $purchaseUnit = $this->resolveUnit((string) $rawPurchase, $businessId);

        // 5. Purchase Conversion Factor (1 purchaseUnit = X baseUnit)
        $rawPurchaseFactor = $vFields['purchaseConversionFactor']
            ?? $vFields['purchase_conversion_factor']
            ?? $vFields['purchaseUnitConversionFactor']
            ?? $pFields['purchaseConversionFactor']
            ?? $pFields['purchase_conversion_factor']
            ?? null;
        $purchaseFactor = ($rawPurchaseFactor !== null && is_numeric($rawPurchaseFactor) && (float) $rawPurchaseFactor > 0)
            ? (float) $rawPurchaseFactor
            : $this->defaultPairFactor($purchaseUnit['id'], $baseUnit['id']);

        // 6. Display labels, precision & permissions
        $displayName = trim((string) (
            $vFields['unitDisplayName']
            ?? $vFields['unit_display_name']
            ?? $pFields['unitDisplayName']
            ?? $pFields['unit_display_name']
            ?? $salesUnit['displayName']
        ));
        if ($displayName === '') {
            $displayName = $salesUnit['displayName'];
        }

        $symbol = trim((string) (
            $vFields['unitSymbol']
            ?? $vFields['unit_symbol']
            ?? $pFields['unitSymbol']
            ?? $pFields['unit_symbol']
            ?? $salesUnit['symbol']
        ));
        if ($symbol === '') {
            $symbol = $salesUnit['symbol'];
        }

        $explicitFrac = $vFields['allowFractionalQuantity']
            ?? $vFields['allow_fractional_quantity']
            ?? $pFields['allowFractionalQuantity']
            ?? $pFields['allow_fractional_quantity']
            ?? null;
        $allowFractional = $explicitFrac !== null
            ? filter_var($explicitFrac, FILTER_VALIDATE_BOOLEAN)
            : (bool) $salesUnit['allowFractional'];

        $explicitOverride = $vFields['allowManualUnitOverride']
            ?? $vFields['allow_manual_unit_override']
            ?? $pFields['allowManualUnitOverride']
            ?? $pFields['allow_manual_unit_override']
            ?? false;
        $allowManualOverride = filter_var($explicitOverride, FILTER_VALIDATE_BOOLEAN);

        $sellingPricePerUnit = (int) ($vFields['sellingPricePerUnit'] ?? $vFields['selling_price_per_unit'] ?? $vFields['sellingPrice'] ?? 0);
        $purchasePricePerUnit = (int) ($vFields['purchasePricePerUnit'] ?? $vFields['purchase_price_per_unit'] ?? $vFields['purchaseRate'] ?? 0);

        return [
            'baseUnitId' => $baseUnit['id'],
            'baseUnitSymbol' => $baseUnit['symbol'],
            'baseUnitDisplayName' => $baseUnit['displayName'],
            'purchaseUnitId' => $purchaseUnit['id'],
            'purchaseUnitSymbol' => $purchaseUnit['symbol'],
            'purchaseUnitDisplayName' => $purchaseUnit['displayName'],
            'purchaseConversionFactor' => round($purchaseFactor, 6),
            'defaultSalesUnitId' => $salesUnit['id'],
            'salesConversionFactor' => round($salesFactor, 6),
            'unitDisplayName' => $displayName,
            'unitSymbol' => $symbol,
            'allowFractionalQuantity' => $allowFractional,
            'allowManualUnitOverride' => $allowManualOverride,
            'purchasePricePerUnit' => $purchasePricePerUnit,
            'sellingPricePerUnit' => $sellingPricePerUnit,
        ];
    }

    /**
     * Lookup natural conversion factor between two known system units.
     */
    public function defaultPairFactor(string $fromUnitId, string $toUnitId): float
    {
        if ($fromUnitId === $toUnitId) {
            return 1.0;
        }
        $key = "{$fromUnitId}->{$toUnitId}";

        return self::KNOWN_PAIR_FACTORS[$key] ?? 1.0;
    }

    /**
     * Validate whether two units are compatible directly, via known physical conversion,
     * or via an explicit user/purchase-option conversion factor.
     *
     * @param  array<string, mixed>  $unitA
     * @param  array<string, mixed>  $unitB
     */
    public function areUnitsCompatible(array $unitA, array $unitB, ?float $explicitConversionFactor = null): bool
    {
        $idA = (string) ($unitA['id'] ?? '');
        $idB = (string) ($unitB['id'] ?? '');
        if ($idA !== '' && $idA === $idB) {
            return true;
        }

        $keyAtoB = "{$idA}->{$idB}";
        $keyBtoA = "{$idB}->{$idA}";
        if (isset(self::KNOWN_PAIR_FACTORS[$keyAtoB]) || isset(self::KNOWN_PAIR_FACTORS[$keyBtoA])) {
            return true;
        }

        // An explicit purchase option / package conversion (e.g. 1 Box = 100 PCS) bridges count/unit boundaries.
        if ($explicitConversionFactor !== null && $explicitConversionFactor > 0 && abs($explicitConversionFactor - 1.0) > 0.000001) {
            return true;
        }

        $dimA = strtoupper(trim((string) ($unitA['dimension'] ?? '')));
        $dimB = strtoupper(trim((string) ($unitB['dimension'] ?? '')));
        if ($dimA !== '' && $dimA === $dimB && $dimA !== 'COUNT') {
            return true;
        }

        return false;
    }

    /**
     * Exact base quantity calculation using integer micro-scaling to eliminate float drift.
     */
    public function toBaseQuantity(float $quantity, float $conversionFactor = 1.0): float
    {
        if ($conversionFactor <= 0) {
            $conversionFactor = 1.0;
        }
        if (function_exists('bcmul')) {
            return (float) bcmul(
                number_format($quantity, 6, '.', ''),
                number_format($conversionFactor, 6, '.', ''),
                6
            );
        }

        return round($quantity * $conversionFactor, 6);
    }

    /**
     * Convert base units into storage milli-units (1 base unit = 1000 milli-units).
     */
    public function toMilliUnits(float $baseQuantity): int
    {
        return (int) round($baseQuantity * 1000.0);
    }

    /**
     * Convert storage milli-units back to human base units.
     */
    public function fromMilliUnits(float|int $milliUnits): float
    {
        return round(((float) $milliUnits) / 1000.0, 3);
    }

    /**
     * Calculate monetary line gross in paise safely for decimal quantities.
     */
    public function calculateLineGrossPaise(float $quantity, int $unitPricePaise): int
    {
        $qtyMicro = (int) round($quantity * 1000000);
        if (function_exists('bcmul')) {
            $product = bcmul((string) $qtyMicro, (string) $unitPricePaise, 0);

            return (int) round(((float) $product) / 1000000);
        }

        return (int) round(($qtyMicro * $unitPricePaise) / 1000000);
    }

    /**
     * Validate a sold/purchased quantity against fractional permission.
     */
    public function validateQuantity(float $quantity, bool $allowFractional, string $unitSymbol = ''): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be greater than zero.',
            ]);
        }

        if (! $allowFractional && abs($quantity - round($quantity)) > 0.0001) {
            $label = $unitSymbol !== '' ? $unitSymbol : 'this unit';
            throw ValidationException::withMessages([
                'quantity' => "Fractional quantities are not allowed for {$label}. Please enter a whole number.",
            ]);
        }
    }

    /**
     * Return all standard reusable units formatted for /product-masters API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function standardUnitsForApi(): array
    {
        return array_values(array_map(function ($u) {
            return [
                'id' => $u['id'],
                'code' => $u['code'],
                'name' => $u['name'],
                'displayName' => $u['displayName'],
                'symbol' => $u['symbol'],
                'allowFractional' => $u['allowFractional'],
                'precision' => $u['precision'],
                'measurementDimension' => $u['dimension'],
            ];
        }, self::STANDARD_UNITS));
    }
}
