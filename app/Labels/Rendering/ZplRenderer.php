<?php

namespace App\Labels\Rendering;

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Enums\LabelElementType;
use App\Labels\Enums\LabelFontWeight;
use App\Labels\Enums\LabelRotation;
use App\Labels\Enums\LabelTextAlignment;
use App\Labels\Enums\SemanticFontFamily;
use InvalidArgumentException;

final readonly class ZplRenderer implements LabelRenderer
{
    public function __construct(private LabelLayoutPreflight $preflight = new LabelLayoutPreflight) {}

    public function render(ResolvedLabelDefinition $label, LabelRenderContext $context): string
    {
        $this->preflight->assertFits($label, $context);

        $commands = [
            '^XA',
            '^CI28',
            '^PW'.$context->widthInDots(),
            '^LL'.$context->heightInDots(),
            '^LH0,0',
        ];

        foreach ($label->elements() as $element) {
            $commands[] = match (LabelElementType::from($element['type'])) {
                LabelElementType::Text, LabelElementType::JobIdentifier => $this->renderText($element, $context),
                LabelElementType::Line, LabelElementType::Rectangle => $this->renderGraphicBox($element, $context),
                default => throw new InvalidArgumentException("ZPL rendering for {$element['type']} elements is not implemented."),
            };
        }

        $commands[] = '^XZ';

        return implode("\n", $commands);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderText(array $element, LabelRenderContext $context): string
    {
        $style = $element['style'];
        $font = match (SemanticFontFamily::from($style['font_family'])) {
            SemanticFontFamily::Sans => '0',
            SemanticFontFamily::Monospace => 'A',
        };
        $orientation = match (LabelRotation::from($element['rotation'])) {
            LabelRotation::None => 'N',
            LabelRotation::Clockwise90 => 'R',
            LabelRotation::Clockwise180 => 'I',
            LabelRotation::Clockwise270 => 'B',
        };
        $alignment = match (LabelTextAlignment::from($style['alignment'])) {
            LabelTextAlignment::Left => 'L',
            LabelTextAlignment::Center => 'C',
            LabelTextAlignment::Right => 'R',
        };
        $fontHeight = max(1, $context->millimetersToDots($style['font_size']));
        $fontWidth = max(1, (int) round($fontHeight * 0.6));
        $fieldWidth = $context->millimetersToDots($element['width']);
        $fieldHeight = $context->millimetersToDots($element['height']);
        $maximumLines = max(1, (int) floor($fieldHeight / $fontHeight));
        $x = $context->millimetersToDots($element['x']);
        $y = $context->millimetersToDots($element['y']);
        $text = $this->escapeFieldData((string) $element['value']);

        $field = "^FO{$x},{$y}^A{$font}{$orientation},{$fontHeight},{$fontWidth}^FB{$fieldWidth},{$maximumLines},0,{$alignment},0^FH\\^FD{$text}^FS";

        if (LabelFontWeight::from($style['font_weight']) === LabelFontWeight::Bold) {
            $boldX = $x + 1;
            $field .= "^FO{$boldX},{$y}^A{$font}{$orientation},{$fontHeight},{$fontWidth}^FB{$fieldWidth},{$maximumLines},0,{$alignment},0^FH\\^FD{$text}^FS";
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderGraphicBox(array $element, LabelRenderContext $context): string
    {
        $x = $context->millimetersToDots($element['x']);
        $y = $context->millimetersToDots($element['y']);
        $widthInMillimeters = $element['width'];
        $heightInMillimeters = $element['height'];

        if (in_array(LabelRotation::from($element['rotation']), [LabelRotation::Clockwise90, LabelRotation::Clockwise270], true)) {
            [$widthInMillimeters, $heightInMillimeters] = [$heightInMillimeters, $widthInMillimeters];
        }

        $width = max(1, $context->millimetersToDots($widthInMillimeters));
        $height = max(1, $context->millimetersToDots($heightInMillimeters));
        $thickness = max(1, $context->millimetersToDots($element['stroke_width']));

        return "^FO{$x},{$y}^GB{$width},{$height},{$thickness},B,0^FS";
    }

    private function escapeFieldData(string $value): string
    {
        return str_replace(
            ['\\', '^', '~'],
            ['\\5C', '\\5E', '\\7E'],
            $value,
        );
    }
}
