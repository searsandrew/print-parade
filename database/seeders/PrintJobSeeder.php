<?php

namespace Database\Seeders;

use App\Models\PrintJob;
use Illuminate\Database\Seeder;

class PrintJobSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PrintJob::factory()->count(10)->create();
    }
}
