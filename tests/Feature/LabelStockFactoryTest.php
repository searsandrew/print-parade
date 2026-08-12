<?php

use App\Enums\LabelMediaType;
use App\Models\LabelStock;

test('the factory creates a valid active gap label stock', function () {
    $labelStock = LabelStock::factory()->create();

    expect($labelStock->name)->not->toBeEmpty()
        ->and((float) $labelStock->width)->toBeGreaterThan(0)
        ->and((float) $labelStock->height)->toBeGreaterThan(0)
        ->and($labelStock->media_type)->toBe(LabelMediaType::Gap)
        ->and($labelStock->is_active)->toBeTrue();
});

test('the factory provides useful label stock states', function () {
    $inactiveStock = LabelStock::factory()->inactive()->create();
    $continuousStock = LabelStock::factory()->continuous()->create();
    $blackMarkStock = LabelStock::factory()->blackMark()->create();

    expect($inactiveStock->is_active)->toBeFalse()
        ->and($continuousStock->media_type)->toBe(LabelMediaType::Continuous)
        ->and($blackMarkStock->media_type)->toBe(LabelMediaType::BlackMark);
});

test('it converts dimensions from millimeters to inches', function () {
    $labelStock = LabelStock::factory()->make([
        'width' => '101.600',
        'height' => '50.800',
    ]);

    expect($labelStock->widthInInches())->toBe(4.0)
        ->and($labelStock->heightInInches())->toBe(2.0);
});
