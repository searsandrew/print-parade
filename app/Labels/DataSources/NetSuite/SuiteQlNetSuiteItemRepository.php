<?php

namespace App\Labels\DataSources\NetSuite;

use Searsandrew\BriarRose\BriarRoseManager;

final readonly class SuiteQlNetSuiteItemRepository implements NetSuiteItemRepository
{
    public function __construct(private BriarRoseManager $briarRose) {}

    public function findByPartNumber(string $partNumber): ?array
    {
        $escapedPartNumber = str_replace("'", "''", $partNumber);
        $query = <<<SQL
            SELECT itemid AS part_number,
                   description AS part_description,
                   upccode AS upc,
                   BUILTIN.DF(countryofmanufacture) AS country_of_origin
            FROM item
            WHERE itemid = '{$escapedPartNumber}'
              AND isinactive = 'F'
            SQL;
        $items = $this->briarRose
            ->rest()
            ->suiteql()
            ->query($query, ['limit' => 1])
            ->throw()
            ->json('items', []);

        if (! is_array($items) || ! isset($items[0]) || ! is_array($items[0])) {
            return null;
        }

        return [
            'part_number' => (string) ($items[0]['part_number'] ?? $partNumber),
            'part_description' => isset($items[0]['part_description']) ? (string) $items[0]['part_description'] : null,
            'upc' => isset($items[0]['upc']) && $items[0]['upc'] !== '' ? (string) $items[0]['upc'] : null,
            'country_of_origin' => isset($items[0]['country_of_origin']) && $items[0]['country_of_origin'] !== '' ? (string) $items[0]['country_of_origin'] : null,
        ];
    }
}
