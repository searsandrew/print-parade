<?php

namespace App\Labels\DataSources\NetSuite;

use App\Labels\DataSources\LabelDataSource;
use App\Labels\DataSources\LabelDataSourceException;
use Throwable;

final readonly class NetSuiteLabelDataSource implements LabelDataSource
{
    public function __construct(private NetSuiteItemRepository $items) {}

    public function namespace(): string
    {
        return 'netsuite';
    }

    public function fields(): array
    {
        return [
            'part_description' => [
                'label' => 'Part description',
                'type' => 'string',
                'required' => true,
                'required_inputs' => ['part_number'],
            ],
            'upc' => [
                'label' => 'UPC-A',
                'type' => 'string',
                'format' => 'upc_a',
                'required' => false,
                'required_inputs' => ['part_number'],
            ],
        ];
    }

    public function resolve(array $fields, array $input): array
    {
        $partNumber = (string) $input['part_number'];

        try {
            $item = $this->items->findByPartNumber($partNumber);
        } catch (Throwable $exception) {
            throw new LabelDataSourceException('NetSuite is temporarily unavailable. Please try again.', previous: $exception);
        }

        if ($item === null) {
            throw new LabelDataSourceException("No active NetSuite item was found for part number {$partNumber}.");
        }

        return array_intersect_key($item, array_flip($fields));
    }
}
