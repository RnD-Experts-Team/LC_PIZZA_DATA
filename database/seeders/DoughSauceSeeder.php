<?php

namespace Database\Seeders;

use App\Models\Aggregation\DsIngredient;
use Illuminate\Database\Seeder;

/**
 * The three tracked ingredients.
 *
 * Idempotent: updateOrCreate on `key`, so running it twice changes nothing and
 * running it after a name correction fixes the name without touching the recipes
 * that point at it.
 *
 * Recipes are NOT seeded here. The 82 rows come out of the existing workbook and
 * are a one-time import with real dates on them — seeding invented ones would put
 * numbers in the plan that nobody chose.
 */
class DoughSauceSeeder extends Seeder
{
    public function run(): void
    {
        $ingredients = [
            [
                'key'           => 'dough_18oz',
                'name'          => '18 OZ Dough ball',
                'unit'          => 'ball',
                // Pizzas are counted one for one: a pizza is a ball.
                'divisor'       => 1,
                'inventory_ref' => '0000',
                'sort_order'    => 1,
            ],
            [
                'key'           => 'dough_10oz',
                'name'          => '10 OZ Dough balls',
                'unit'          => 'ball',
                // One ball yields 12 bread portions.
                'divisor'       => 12,
                'inventory_ref' => '00001',
                'sort_order'    => 2,
            ],
            [
                'key'           => 'sauce',
                'name'          => 'Sauce containers',
                'unit'          => 'container',
                // 80 portions to a container.
                'divisor'       => 80,
                'inventory_ref' => '00002',
                'sort_order'    => 3,
            ],
        ];

        foreach ($ingredients as $ingredient) {
            DsIngredient::updateOrCreate(
                ['key' => $ingredient['key']],
                $ingredient + ['active' => true],
            );
        }

        $this->command?->info('Dough & Sauce: 3 ingredients seeded.');
    }
}
