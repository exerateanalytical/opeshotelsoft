<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo accounts carry fixed, published passwords: never seed them into a production database
        // unless the operator explicitly opts in (SEC1 audit).
        $seeders = [RolesAndPermissionsSeeder::class];
        if (! app()->isProduction() || filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $seeders[] = DemoSeeder::class;
            $seeders[] = DemoPosStockSeeder::class; // stocked outlet stores, floor plans, recipes: the POS can settle
        } else {
            logger()->warning('Production environment: DemoSeeder skipped (set ALLOW_DEMO_SEED=true to force).');
        }
        $this->call($seeders);
    }
}
