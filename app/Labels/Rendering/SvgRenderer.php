<?php

namespace App\Labels\Rendering;

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\LabelElementType;
use App\Labels\Enums\LabelRotation;
use App\Labels\Enums\LabelTextAlignment;
use App\Labels\Enums\QrErrorCorrection;
use App\Labels\Enums\SemanticFontFamily;
use InvalidArgumentException;

final readonly class SvgRenderer implements LabelRenderer
{
    public function __construct(
        private LabelLayoutPreflight $preflight = new LabelLayoutPreflight,
        private BarcodeSvgGenerator $barcodeGenerator = new TcLibBarcodeSvgGenerator,
    ) {}

    public function render(ResolvedLabelDefinition $label, LabelRenderContext $context): string
    {
        $this->preflight->assertFits($label, $context);

        $width = self::number($context->widthInMillimeters);
        $height = self::number($context->heightInMillimeters);
        $elements = [];

        foreach ($label->elements() as $element) {
            $elements[] = match (LabelElementType::from($element['type'])) {
                LabelElementType::Text, LabelElementType::JobIdentifier => $this->renderText($element),
                LabelElementType::Line => $this->renderLine($element),
                LabelElementType::Rectangle => $this->renderRectangle($element),
                LabelElementType::Barcode => $this->renderBarcode($element),
                LabelElementType::Image => throw new InvalidArgumentException('SVG image elements are not implemented.'),
            };
        }

        $elements = $this->orientCanvas($elements, $label->canvasRotation(), $context);

        return implode("\n", [
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%smm" height="%smm" viewBox="0 0 %s %s" role="img" aria-label="Label preview" data-preview="approximate" data-dpi="%d">',
                $width,
                $height,
                $width,
                $height,
                $context->dotsPerInch,
            ),
            '<rect width="100%" height="100%" fill="white"/>',
            ...$elements,
            '</svg>',
        ]);
    }

    /**
     * @param  list<string>  $elements
     * @param  0|90|180|270  $rotation
     * @return list<string>
     */
    private function orientCanvas(array $elements, int $rotation, LabelRenderContext $context): array
    {
        if ($rotation === 0 || $elements === []) {
            return $elements;
        }

        $transform = match ($rotation) {
            90 => sprintf('translate(%s 0) rotate(90)', self::number($context->widthInMillimeters)),
            180 => sprintf('translate(%s %s) rotate(180)', self::number($context->widthInMillimeters), self::number($context->heightInMillimeters)),
            270 => sprintf('translate(0 %s) rotate(270)', self::number($context->heightInMillimeters)),
        };

        return [sprintf('<g data-canvas-rotation="%d" transform="%s">%s</g>', $rotation, $transform, implode("\n", $elements))];
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderText(array $element): string
    {
        $style = $element['style'];
        $alignment = LabelTextAlignment::from($style['alignment']);
        $x = (float) $element['x'];
        $textAnchor = match ($alignment) {
            LabelTextAlignment::Left => 'start',
            LabelTextAlignment::Center => 'middle',
            LabelTextAlignment::Right => 'end',
        };
        $x += match ($alignment) {
            LabelTextAlignment::Left => 0,
            LabelTextAlignment::Center => (float) $element['width'] / 2,
            LabelTextAlignment::Right => (float) $element['width'],
        };
        $fontWidth = (float) ($style['font_width'] ?? 1.0);
        $widthTransform = sprintf(
            'translate(%1$s 0) scale(%2$s 1) translate(-%1$s 0)',
            self::number($x),
            self::number($fontWidth),
        );
        /** @noinspection HtmlWrongAttributeValue */
        $markup = sprintf(
            '<text x="%s" y="%s" font-family="%s" font-size="%s" font-weight="%s" text-anchor="%s" dominant-baseline="hanging" transform="%s">%s</text>',
            self::number($x),
            self::number((float) $element['y']),
            self::svgFontFamily((string) $style['font_family']),
            self::number((float) $style['font_size']),
            self::xml((string) $style['font_weight']),
            $textAnchor,
            $widthTransform,
            self::xml((string) $element['value']),
        );

        return $this->rotate($markup, $element);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderRectangle(array $element): string
    {
        $markup = sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="none" stroke="black" stroke-width="%s"/>',
            self::number((float) $element['x']),
            self::number((float) $element['y']),
            self::number((float) $element['width']),
            self::number((float) $element['height']),
            self::number((float) $element['stroke_width']),
        );

        return $this->rotate($markup, $element);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderLine(array $element): string
    {
        $markup = sprintf(
            '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="black" stroke-width="%s"/>',
            self::number((float) $element['x']),
            self::number((float) $element['y']),
            self::number((float) $element['x'] + (float) $element['width']),
            self::number((float) $element['y'] + (float) $element['height']),
            self::number((float) $element['stroke_width']),
        );

        return $this->rotate($markup, $element);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderBarcode(array $element): string
    {
        $symbology = BarcodeSymbology::from($element['symbology']);
        $errorCorrection = QrErrorCorrection::tryFrom($element['error_correction'] ?? 'medium') ?? QrErrorCorrection::Medium;
        $barcodeSvg = $this->barcodeGenerator->generate($symbology, (string) $element['value'], $errorCorrection);
        $dataUri = 'data:image/svg+xml;base64,'.base64_encode($barcodeSvg);
        $x = (float) $element['x'];
        $y = (float) $element['y'];
        $width = (float) $element['width'];
        $height = (float) $element['height'];
        $text = '';

        if ($symbology === BarcodeSymbology::QrCode) {
            $size = min($width, $height);
            $x += ($width - $size) / 2;
            $y += ($height - $size) / 2;
            $width = $height = $size;
        } else {
            $showText = $element['show_text'] ?? true;
            $textHeight = $showText ? 3.0 : 0.0;
            $gap = $showText ? 0.5 : 0.0;
            $barHeight = array_key_exists('bar_height', $element)
                ? (float) $element['bar_height']
                : $height - $textHeight - $gap;

            if ($barHeight < 6.35 || $barHeight + $textHeight + $gap > $height) {
                throw new InvalidArgumentException("Element {$element['id']} has invalid linear barcode height.");
            }

            $y += $height - $textHeight - $gap - $barHeight;
            $height = $barHeight;

            if ($showText) {
                $text = sprintf(
                    '<text x="%s" y="%s" font-family="sans-serif" font-size="3" text-anchor="middle">%s</text>',
                    self::number((float) $element['x'] + ((float) $element['width'] / 2)),
                    self::number((float) $element['y'] + (float) $element['height'] - 0.3),
                    self::xml((string) $element['value']),
                );
            }
        }

        /** @noinspection HtmlUnknownAttribute */
        $image = sprintf(
            '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="none" href="%s" data-barcode-symbology="%s"/>%s',
            self::number($x),
            self::number($y),
            self::number($width),
            self::number($height),
            $dataUri,
            $symbology->value,
            $text,
        );

        return $this->rotate($image, $element);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function rotate(string $markup, array $element): string
    {
        $rotation = LabelRotation::from($element['rotation']);

        if ($rotation === LabelRotation::None) {
            return $markup;
        }

        return sprintf(
            '<g transform="rotate(%d %s %s)">%s</g>',
            $rotation->value,
            self::number((float) $element['x']),
            self::number((float) $element['y']),
            $markup,
        );
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function svgFontFamily(string $fontFamily): string
    {
        return match (SemanticFontFamily::from($fontFamily)) {
            SemanticFontFamily::Sans => 'sans-serif',
            SemanticFontFamily::Monospace => 'monospace',
        };
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
