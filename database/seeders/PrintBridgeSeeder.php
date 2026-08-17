<?php

namespace Database\Seeders;

use App\Models\PrintBridge;
use Illuminate\Database\Seeder;

class PrintBridgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PrintBridge::factory()->create(['name' => 'Production Print Bridge']);
    }
}
