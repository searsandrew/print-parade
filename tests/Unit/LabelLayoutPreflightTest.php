<?php

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Rendering\LabelLayoutPreflight;
use App\Labels\Rendering\LabelRenderContext;

test('elements that fit the stock pass layout preflight', function () {
    $label = new ResolvedLabelDefinition([[
        'id' => '01K00000000000000000000000',
        'type' => 'rectangle',
        'x' => 5,
        'y' => 5,
        'width' => 90,
        'height' => 40,
        'rotation' => 0,
        'stroke_width' => 0.5,
    ]], []);

    (new LabelLayoutPreflight)->assertFits($label, new LabelRenderContext(101.6, 50.8, 203));

    expect(true)->toBeTrue();
});

test('preflight reports how far an element extends beyond the stock', function () {
    $label = new ResolvedLabelDefinition([[
        'id' => '01K00000000000000000000000',
        'type' => 'rectangle',
        'x' => 95,
        'y' => 5,
        'width' => 10,
        'height' => 5,
        'rotation' => 0,
        'stroke_width' => 0.5,
    ]], []);

    (new LabelLayoutPreflight)->assertFits($label, new LabelRenderContext(101.6, 50.8, 203));
})->throws(InvalidArgumentException::class, 'extends 3.400 mm beyond the right edge');

test('preflight accounts for quarter turn rotation', function () {
    $label = new ResolvedLabelDefinition([[
        'id' => '01K00000000000000000000000',
        'type' => 'rectangle',
        'x' => 80,
        'y' => 5,
        'width' => 40,
        'height' => 10,
        'rotation' => 90,
        'stroke_width' => 0.5,
    ]], []);

    (new LabelLayoutPreflight)->assertFits($label, new LabelRenderContext(101.6, 50.8, 203));

    expect(true)->toBeTrue();
});
