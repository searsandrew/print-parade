<?php

use App\Labels\Definitions\LabelDefinition;
use Illuminate\Support\Str;

function resolvableTextElement(string $value, array $overrides = []): array
{
    return [
        'id' => (string) Str::ulid(),
        'type' => 'text',
        'x' => 5,
        'y' => 5,
        'width' => 90,
        'height' => 8,
        'rotation' => 0,
        'value' => $value,
        'style' => [
            'font_family' => 'sans',
            'font_size' => 4,
            'font_weight' => 'normal',
            'alignment' => 'left',
        ],
        ...$overrides,
    ];
}

function resolvableDefinition(array $elements, array $fields = []): LabelDefinition
{
    return LabelDefinition::fromArray([
        'elements' => $elements,
        'fields' => $fields,
    ]);
}

test('mixed static input and system values are resolved', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('Replacement for {{ part_number }} | {{ system.job_identifier }}')],
        ['part_number' => ['type' => 'string', 'required' => true, 'label' => 'Part number']],
    );

    $resolved = $definition->resolve(
        input: ['part_number' => 'ABC-123'],
        system: ['job_identifier' => 'A7K29Q4M'],
    );

    expect($resolved->elements()[0]['value'])->toBe('Replacement for ABC-123 | A7K29Q4M')
        ->and($resolved->values()['part_number'])->toBe('ABC-123');
});

test('optional dynamic elements are hidden by default when their referenced values are empty', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('Replacement for {{ replacement_part_number }}')],
        ['replacement_part_number' => ['type' => 'string', 'required' => false, 'label' => 'Replacement part']],
    );

    expect($definition->resolve([], [])->elements())->toBe([]);
});

test('an empty dynamic element can remain visible explicitly', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('Replacement for {{ replacement_part_number }}', ['hide_when_empty' => false])],
        ['replacement_part_number' => ['type' => 'string', 'required' => false, 'label' => 'Replacement part']],
    );

    expect($definition->resolve([], [])->elements()[0]['value'])->toBe('Replacement for ');
});

test('static elements are not hidden', function () {
    $definition = resolvableDefinition([resolvableTextElement('MADE IN USA')]);

    expect($definition->resolve([], [])->elements()[0]['value'])->toBe('MADE IN USA');
});

test('literal field defaults are applied', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('{{ country_of_origin }}')],
        ['country_of_origin' => [
            'type' => 'string',
            'required' => false,
            'label' => 'Country of origin',
            'default' => 'USA',
        ]],
    );

    $resolved = $definition->resolve([], []);

    expect($resolved->elements()[0]['value'])->toBe('USA')
        ->and($resolved->values()['country_of_origin'])->toBe('USA');
});

test('eleven digit upc a input receives a check digit', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('{{ upc }}')],
        ['upc' => ['type' => 'string', 'format' => 'upc_a', 'required' => true, 'label' => 'UPC']],
    );

    expect($definition->resolve(['upc' => '03600029145'], [])->values()['upc'])->toBe('036000291452');
});

test('twelve digit upc a input retains a valid check digit', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('{{ upc }}')],
        ['upc' => ['type' => 'string', 'format' => 'upc_a', 'required' => true, 'label' => 'UPC']],
    );

    expect($definition->resolve(['upc' => '036000291452'], [])->values()['upc'])->toBe('036000291452');
});

test('invalid upc a check digits are rejected', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('{{ upc }}')],
        ['upc' => ['type' => 'string', 'format' => 'upc_a', 'required' => true, 'label' => 'UPC']],
    );

    $definition->resolve(['upc' => '036000291453'], []);
})->throws(InvalidArgumentException::class, 'invalid UPC-A check digit');

test('required fields must be supplied', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('{{ part_number }}')],
        ['part_number' => ['type' => 'string', 'required' => true, 'label' => 'Part number']],
    );

    $definition->resolve([], []);
})->throws(InvalidArgumentException::class, 'Field part_number is required.');

test('input values must match their declared type', function () {
    $definition = resolvableDefinition(
        [resolvableTextElement('{{ quantity }}')],
        ['quantity' => ['type' => 'number', 'required' => true, 'label' => 'Quantity']],
    );

    $definition->resolve(['quantity' => '12'], []);
})->throws(InvalidArgumentException::class, 'Field quantity must be a valid number value.');

test('unknown placeholders and unavailable system values are rejected', function (string $value, string $message) {
    resolvableDefinition([resolvableTextElement($value)])->resolve([], []);
})->with([
    ['{{ missing }}', 'Unknown label field: missing.'],
    ['{{ system.job_identifier }}', 'System value job_identifier is unavailable.'],
    ['{{ netsuite.item }}', 'Unsupported label value namespace: netsuite.'],
])->throws(InvalidArgumentException::class);

test('the job identifier element resolves from its dedicated system value', function () {
    $element = resolvableTextElement('', ['type' => 'job_identifier']);
    unset($element['value']);

    $resolved = resolvableDefinition([$element])->resolve([], ['job_identifier' => 'A7K29Q4M']);

    expect($resolved->elements()[0]['value'])->toBe('A7K29Q4M');
});
