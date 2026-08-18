<?php

use App\Labels\DataSources\LabelDataSourceException;
use App\Labels\DataSources\NetSuite\NetSuiteItemRepository;
use App\Labels\DataSources\NetSuite\NetSuiteLabelDataSource;

final class FakeNetSuiteItemRepository implements NetSuiteItemRepository
{
    public function __construct(
        private ?array $item = null,
        private ?Throwable $exception = null,
    ) {}

    public function findByPartNumber(string $partNumber): ?array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->item;
    }
}

test('netsuite exposes a code-owned item field catalog', function () {
    $source = new NetSuiteLabelDataSource(new FakeNetSuiteItemRepository);

    expect($source->namespace())->toBe('netsuite')
        ->and($source->fields())->toHaveKeys(['part_description', 'upc'])
        ->and($source->fields()['part_description'])->toMatchArray([
            'required' => true,
            'required_inputs' => ['part_number'],
        ])
        ->and($source->fields()['upc'])->toMatchArray([
            'format' => 'upc_a',
            'required' => false,
        ]);
});

test('netsuite resolves only requested values from the part number lookup', function () {
    $source = new NetSuiteLabelDataSource(new FakeNetSuiteItemRepository([
        'part_number' => 'CMM023',
        'part_description' => 'Replacement filter assembly',
        'upc' => '036000291452',
    ]));

    expect($source->resolve(['part_description'], ['part_number' => 'CMM023']))->toBe([
        'part_description' => 'Replacement filter assembly',
    ]);
});

test('a missing netsuite item produces an operator-safe failure', function () {
    $source = new NetSuiteLabelDataSource(new FakeNetSuiteItemRepository);

    expect(fn () => $source->resolve(['part_description'], ['part_number' => 'MISSING']))
        ->toThrow(LabelDataSourceException::class, 'No active NetSuite item was found for part number MISSING.');
});

test('a netsuite connection failure produces a temporary service error', function () {
    $source = new NetSuiteLabelDataSource(new FakeNetSuiteItemRepository(exception: new RuntimeException('Connection failed')));

    expect(fn () => $source->resolve(['part_description'], ['part_number' => 'CMM023']))
        ->toThrow(LabelDataSourceException::class, 'NetSuite is temporarily unavailable. Please try again.');
});
