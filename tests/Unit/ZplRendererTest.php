<?php

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\LabelRenderer;
use App\Labels\Rendering\ZplRenderer;

test('text is rendered as a complete zpl label at the selected resolution', function () {
    $label = new ResolvedLabelDefinition([zplTextElement()], []);
    $renderer = new ZplRenderer;

    $zpl = $renderer->render($label, new LabelRenderContext(101.6, 50.8, 203));

    expect($renderer)->toBeInstanceOf(LabelRenderer::class)
        ->and($zpl)->toBe(implode("\n", [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL406',
            '^LH0,0',
            '^FO40,40^A0N,32,19^FB719,2,0,L,0^FH\\^FDPart ABC-123^FS',
            '^XZ',
        ]));
});

test('semantic fonts rotation alignment and bold weight map to zpl', function () {
    $element = zplTextElement([
        'rotation' => 90,
        'value' => 'Centered text',
        'style' => [
            'font_family' => 'monospace',
            'font_size' => 4,
            'font_weight' => 'bold',
            'alignment' => 'center',
        ],
    ]);

    $zpl = (new ZplRenderer)->render(
        new ResolvedLabelDefinition([$element], []),
        new LabelRenderContext(101.6, 100, 203),
    );

    expect($zpl)
        ->toContain('^AAR,32,19')
        ->toContain('^FB719,2,0,C,0')
        ->toContain('^FDCentered text^FS');
});

test('text field data is escaped and bold text is overprinted by one dot', function () {
    $element = zplTextElement([
        'value' => 'A^B~C\\D',
        'style' => [
            'font_family' => 'sans',
            'font_size' => 4,
            'font_weight' => 'bold',
            'alignment' => 'right',
        ],
    ]);

    $zpl = (new ZplRenderer)->render(
        new ResolvedLabelDefinition([$element], []),
        new LabelRenderContext(101.6, 50.8, 203),
    );

    expect($zpl)
        ->toContain('^FO40,40^A0N,32,19^FB719,2,0,R,0^FH\\^FDA\\5EB\\7EC\\5CD^FS')
        ->toContain('^FO41,40^A0N,32,19');
});

test('lines and rectangles render as zpl graphic boxes', function () {
    $elements = [
        zplShapeElement('line', ['width' => 25.4, 'height' => 1, 'stroke_width' => 0.5]),
        zplShapeElement('rectangle', ['y' => 10, 'width' => 25.4, 'height' => 12.7, 'stroke_width' => 1]),
    ];

    $zpl = (new ZplRenderer)->render(
        new ResolvedLabelDefinition($elements, []),
        new LabelRenderContext(101.6, 50.8, 203),
    );

    expect($zpl)
        ->toContain('^FO40,40^GB203,8,4,B,0^FS')
        ->toContain('^FO40,80^GB203,102,8,B,0^FS');
});

test('elements not included in the first zpl slice fail explicitly', function () {
    $element = zplShapeElement('image');

    (new ZplRenderer)->render(
        new ResolvedLabelDefinition([$element], []),
        new LabelRenderContext(101.6, 50.8, 203),
    );
})->throws(InvalidArgumentException::class, 'ZPL rendering for image elements is not implemented.');

function zplTextElement(array $overrides = []): array
{
    return [
        'id' => '01K00000000000000000000000',
        'type' => 'text',
        'x' => 5,
        'y' => 5,
        'width' => 90,
        'height' => 8,
        'rotation' => 0,
        'value' => 'Part ABC-123',
        'style' => [
            'font_family' => 'sans',
            'font_size' => 4,
            'font_weight' => 'normal',
            'alignment' => 'left',
        ],
        ...$overrides,
    ];
}

function zplShapeElement(string $type, array $overrides = []): array
{
    return [
        'id' => '01K00000000000000000000000',
        'type' => $type,
        'x' => 5,
        'y' => 5,
        'width' => 10,
        'height' => 10,
        'rotation' => 0,
        'stroke_width' => 0.5,
        ...$overrides,
    ];
}
