<?php

namespace App\Labels\DataSources\NetSuite;

interface NetSuiteItemRepository
{
    /** @return array{part_number: string, part_description: string|null, upc: string|null}|null */
    public function findByPartNumber(string $partNumber): ?array;
}
