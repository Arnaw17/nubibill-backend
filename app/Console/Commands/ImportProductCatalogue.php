<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportProductCatalogue extends Command
{
    protected $signature = 'catalogue:import {path : Path to the json backup file}';
    protected $description = 'Extracts product catalogue from Firestore JSON backup and inserts into MySQL';

    public function handle()
    {
        $path = $this->argument('path');

        if (!File::exists($path)) {
            $this->error("File not found at: {$path}");
            return 1;
        }

        $this->info("Reading JSON backup file...");
        $json = File::get($path);
        $data = json_decode($json, true);

        if (!isset($data['collections']['central_product_master_verified'])) {
            $this->error("Collection 'central_product_master_verified' not found in JSON.");
            return 1;
        }

        $products = $data['collections']['central_product_master_verified'];
        $count = count($products);
        $this->info("Found {$count} products. Processing import...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::beginTransaction();
        try {
            foreach ($products as $docId => $docData) {
                $fields = $docData['fields'] ?? [];

                DB::table('products')->updateOrInsert(
                    ['master_product_id' => $docId],
                    [
                        'product_name'      => $fields['productName'] ?? 'Unknown Product',
                        'brand_name'        => $fields['brandName'] ?? null,
                        'category_name'     => $fields['categoryName'] ?? null,
                        'subcategory_name'  => $fields['subcategoryName'] ?? null,
                        'pack_sizes'        => $fields['packSizes'] ?? null,
                        'finish'            => $fields['finish'] ?? null,
                        'colour'            => $fields['colour'] ?? null,
                        'description'       => $fields['description'] ?? null,
                        'image_url'         => $fields['imageUrl'] ?? null,
                        'updated_at'        => now(),
                        'created_at'        => now(),
                    ]
                );

                $bar->advance();
            }

            DB::commit();
            $bar->finish();
            $this->info("\nProduct catalogue successfully imported into database!");
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("\nImport failed: " . $e->getMessage());
            return 1;
        }
    }
}