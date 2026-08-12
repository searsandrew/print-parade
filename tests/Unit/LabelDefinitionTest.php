<?php

use App\LabelDefinition;
use Illuminate\Support\Str;

function textElement(array $overrides = []): array
{
    return [
        'id' => (string) Str::ulid(),
        'type' => 'text',
        'x' => 5,
        'y' => 5,
        'width' => 90,
        'height' => 8,
        'rotation' => 0,
        'value' => '{{ part_number }}',
        'style' => [
            'font_family' => 'sans',
            'font_size' => 4,
            'font_weight' => 'normal',
            'alignment' => 'left',
        ],
        ...$overrides,
    ];
}

test('a printer neutral label definition can be created and serialized', function () {
    $definition = [
        'elements' => [textElement()],
        'fields' => [
            'part_number' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Part number',
            ],
        ],
    ];

    $labelDefinition = LabelDefinition::fromArray($definition);

    expect($labelDefinition->toArray())->toBe($definition)
        ->and($labelDefinition->jsonSerialize())->toBe($definition);
});

test('element coordinates and dimensions are required in millimeters', function (array $overrides) {
    LabelDefinition::fromArray([
        'elements' => [textElement($overrides)],
        'fields' => [],
    ]);
})->with([
    'negative x' => [['x' => -1]],
    'zero width' => [['width' => 0]],
    'missing height' => [['height' => null]],
])->throws(InvalidArgumentException::class);

test('elements require stable ulid identifiers', function () {
    LabelDefinition::fromArray([
        'elements' => [textElement(['id' => 'not-a-ulid'])],
        'fields' => [],
    ]);
})->throws(InvalidArgumentException::class);

test('element identifiers must be unique within a definition', function () {
    $element = textElement();

    LabelDefinition::fromArray([
        'elements' => [$element, $element],
        'fields' => [],
    ]);
})->throws(InvalidArgumentException::class);

test('only portable semantic fonts are accepted', function () {
    $element = textElement();
    $element['style']['font_family'] = 'Corporate Sans';

    LabelDefinition::fromArray([
        'elements' => [$element],
        'fields' => [],
    ]);
})->throws(InvalidArgumentException::class);

test('only quarter turn rotations are accepted', function () {
    LabelDefinition::fromArray([
        'elements' => [textElement(['rotation' => 45])],
        'fields' => [],
    ]);
})->throws(InvalidArgumentException::class);

test('job identifiers are first class elements', function () {
    $jobIdentifier = textElement([
        'type' => 'job_identifier',
    ]);
    unset($jobIdentifier['value']);

    $definition = LabelDefinition::fromArray([
        'elements' => [$jobIdentifier],
        'fields' => [],
    ]);

    expect($definition->toArray()['elements'][0]['type'])->toBe('job_identifier');
});

test('upc a is a supported barcode symbology', function () {
    $definition = LabelDefinition::fromArray([
        'elements' => [[
            'id' => (string) Str::ulid(),
            'type' => 'barcode',
            'x' => 5,
            'y' => 5,
            'width' => 40,
            'height' => 20,
            'rotation' => 0,
            'symbology' => 'upc_a',
            'value' => '{{ upc }}',
        ]],
        'fields' => [
            'upc' => [
                'type' => 'string',
                'required' => true,
                'label' => 'UPC',
            ],
        ],
    ]);

    expect($definition->toArray()['elements'][0]['symbology'])->toBe('upc_a');
});
