<?php

use App\Labels\DataSources\LabelDataSource;
use App\Labels\DataSources\LabelDataSourceRegistry;
use App\Labels\DataSources\LabelDataSourceResolver;
use App\Labels\Definitions\LabelDefinition;
use Illuminate\Support\Str;

final class TestNetSuiteLabelDataSource implements LabelDataSource
{
    /** @var list<list<string>> */
    public array $requests = [];

    /** @param array<string, scalar|null> $resolved */
    public function __construct(private array $resolved = [
        'part_description' => 'Description for CMM023',
        'upc' => '036000291452',
    ]) {}

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
                'label' => 'UPC',
                'type' => 'string',
                'format' => 'upc_a',
                'required' => false,
                'required_inputs' => ['part_number'],
            ],
        ];
    }

    public function resolve(array $fields, array $input): array
    {
        $this->requests[] = $fields;

        $resolved = $this->resolved;

        if ($resolved['part_description'] === 'Description for CMM023') {
            $resolved['part_description'] = "Description for {$input['part_number']}";
        }

        return array_intersect_key($resolved, array_flip($fields));
    }
}

test('only referenced data source fields are resolved', function () {
    $source = new TestNetSuiteLabelDataSource;
    $resolver = new LabelDataSourceResolver(new LabelDataSourceRegistry([$source]));
    $definition = dataSourceDefinition('Replacement for {{ part_number }}: {{ netsuite.part_description }} / {{ netsuite.part_description }}');

    $values = $resolver->resolve($definition, ['part_number' => 'CMM023']);

    expect($values)->toBe([
        'netsuite' => ['part_description' => 'Description for CMM023'],
    ])->and($source->requests)->toBe([['part_description']]);
});

test('a definition without source references does not call a data source', function () {
    $source = new TestNetSuiteLabelDataSource;
    $resolver = new LabelDataSourceResolver(new LabelDataSourceRegistry([$source]));

    expect($resolver->resolve(dataSourceDefinition('{{ part_number }}'), ['part_number' => 'CMM023']))->toBe([])
        ->and($source->requests)->toBe([]);
});

test('unsupported source fields are rejected before making a request', function () {
    $source = new TestNetSuiteLabelDataSource;
    $resolver = new LabelDataSourceResolver(new LabelDataSourceRegistry([$source]));

    expect(fn () => $resolver->resolve(dataSourceDefinition('{{ netsuite.cost }}'), ['part_number' => 'CMM023']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported label value: netsuite.cost.')
        ->and($source->requests)->toBe([]);
});

test('a source field requires its declared operator input', function () {
    $source = new TestNetSuiteLabelDataSource;
    $resolver = new LabelDataSourceResolver(new LabelDataSourceRegistry([$source]));

    expect(fn () => $resolver->resolve(dataSourceDefinition('{{ netsuite.part_description }}'), ['part_number' => null]))
        ->toThrow(InvalidArgumentException::class, 'Data source netsuite.part_description requires input part_number.')
        ->and($source->requests)->toBe([]);
});

test('a required source value cannot be empty', function () {
    $source = new TestNetSuiteLabelDataSource(['part_description' => null]);
    $resolver = new LabelDataSourceResolver(new LabelDataSourceRegistry([$source]));

    expect(fn () => $resolver->resolve(dataSourceDefinition('{{ netsuite.part_description }}'), ['part_number' => 'CMM023']))
        ->toThrow(InvalidArgumentException::class, 'Data source value netsuite.part_description is required.');
});

test('a sourced upc is normalized before rendering', function () {
    $source = new TestNetSuiteLabelDataSource([
        'part_description' => 'Description',
        'upc' => '03600029145',
    ]);
    $resolver = new LabelDataSourceResolver(new LabelDataSourceRegistry([$source]));

    expect($resolver->resolve(dataSourceDefinition('{{ netsuite.upc }}'), ['part_number' => 'CMM023']))
        ->toBe(['netsuite' => ['upc' => '036000291452']]);
});

function dataSourceDefinition(string $value): LabelDefinition
{
    return LabelDefinition::fromArray([
        'elements' => [[
            'id' => (string) Str::ulid(),
            'type' => 'text',
            'x' => 0,
            'y' => 0,
            'width' => 50,
            'height' => 10,
            'rotation' => 0,
            'value' => $value,
            'style' => [
                'font_family' => 'sans',
                'font_size' => 4,
                'font_weight' => 'normal',
                'alignment' => 'left',
            ],
        ]],
        'fields' => [
            'part_number' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Part number',
            ],
        ],
    ]);
}
