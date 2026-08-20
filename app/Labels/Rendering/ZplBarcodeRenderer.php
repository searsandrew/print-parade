<?php

namespace App\Labels\Rendering;

use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\LabelRotation;
use App\Labels\Enums\QrErrorCorrection;
use InvalidArgumentException;

final class ZplBarcodeRenderer
{
    private const UPC_A_TOTAL_MODULES = 113;

    /** @var list<int> */
    private const QR_MEDIUM_BYTE_CAPACITIES = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213];

    /**
     * @param  array<string, mixed>  $element
     */
    public function render(array $element, LabelRenderContext $context): string
    {
        return match (BarcodeSymbology::from($element['symbology'])) {
            BarcodeSymbology::Code128 => $this->renderCode128($element, $context),
            BarcodeSymbology::UpcA => $this->renderUpcA($element, $context),
            BarcodeSymbology::QrCode => $this->renderQrCode($element, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderUpcA(array $element, LabelRenderContext $context): string
    {
        $value = (string) $element['value'];

        if (preg_match('/\A\d{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("Element {$element['id']} requires a normalized 12-digit UPC-A value.");
        }

        $moduleWidth = $this->linearModuleWidth($element, $context, self::UPC_A_TOTAL_MODULES);
        $symbolWidth = self::UPC_A_TOTAL_MODULES * $moduleWidth;
        $horizontalOffset = (int) floor(($context->millimetersToDots($element['width']) - $symbolWidth) / 2);
        [$barY, $barHeight] = $this->linearVerticalLayout($element, $context);
        [$originX, $originY] = $this->orientedOrigin(
            $element,
            $context,
            $horizontalOffset,
            $barY,
            $symbolWidth,
            $barHeight,
        );
        $orientation = $this->orientation($element);
        $printInterpretationLine = ($element['show_text'] ?? true) ? 'Y' : 'N';

        return "^BY{$moduleWidth},2,{$barHeight}^FO{$originX},{$originY}^BU{$orientation},{$barHeight},{$printInterpretationLine},N^FD".substr($value, 0, 11).'^FS';
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderCode128(array $element, LabelRenderContext $context): string
    {
        $value = (string) $element['value'];

        if ($value === '' || preg_match('/\A[\x20-\x7E]+\z/', $value) !== 1) {
            throw new InvalidArgumentException("Element {$element['id']} requires printable ASCII Code 128 data.");
        }

        $totalModules = (11 * strlen($value)) + 55;
        $moduleWidth = $this->linearModuleWidth($element, $context, $totalModules);
        [$barY, $barHeight, $textY] = $this->linearVerticalLayout($element, $context);
        [$originX, $originY] = $this->orientedOrigin(
            $element,
            $context,
            10 * $moduleWidth,
            $barY,
            ($totalModules - 20) * $moduleWidth,
            $barHeight,
        );
        $orientation = $this->orientation($element);
        $textCommand = $this->humanReadableText($element, $context, $value, $textY);
        $encodedValue = $this->escapeFieldData('>:'.$value);

        return "^BY{$moduleWidth},2,{$barHeight}^FO{$originX},{$originY}^BC{$orientation},{$barHeight},N,N,N,A^FH\\^FD{$encodedValue}^FS{$textCommand}";
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function renderQrCode(array $element, LabelRenderContext $context): string
    {
        $value = (string) $element['value'];

        if ($value === '' || preg_match('/\A[\x20-\x7E]+\z/', $value) !== 1) {
            throw new InvalidArgumentException("Element {$element['id']} requires printable ASCII QR data.");
        }

        $errorCorrection = QrErrorCorrection::tryFrom($element['error_correction'] ?? 'medium') ?? QrErrorCorrection::Medium;

        if ($errorCorrection !== QrErrorCorrection::Medium) {
            throw new InvalidArgumentException("Element {$element['id']} currently supports medium QR error correction only.");
        }

        $version = $this->qrVersionForByteLength(strlen($value), $element['id']);
        $symbolModules = 21 + (4 * ($version - 1));
        $totalModules = $symbolModules + 8;
        $boxDots = min(
            $context->millimetersToDots($element['width']),
            $context->millimetersToDots($element['height']),
        );
        $moduleWidth = $this->moduleWidth($element, $context, $boxDots, $totalModules, 100);
        $quietZone = 4 * $moduleWidth;
        [$x, $y] = $this->orientedOrigin(
            $element,
            $context,
            $quietZone,
            $quietZone,
            $symbolModules * $moduleWidth,
            $symbolModules * $moduleWidth,
        );
        $orientation = $this->orientation($element);
        $data = $this->escapeFieldData($errorCorrection->zplValue().'A,'.$value);

        return "^FO{$x},{$y}^BQ{$orientation},2,{$moduleWidth},{$errorCorrection->zplValue()},7^FH\\^FD{$data}^FS";
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function linearModuleWidth(array $element, LabelRenderContext $context, int $totalModules): int
    {
        $availableWidth = $context->millimetersToDots($element['width']);

        return $this->moduleWidth($element, $context, $availableWidth, $totalModules, 10);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function moduleWidth(
        array $element,
        LabelRenderContext $context,
        int $availableDots,
        int $totalModules,
        int $maximum,
    ): int {
        $minimum = $context->dotsPerInch === 203 ? 2 : 3;
        $moduleWidth = array_key_exists('module_width', $element)
            ? $context->millimetersToDots($element['module_width'])
            : min($maximum, (int) floor($availableDots / $totalModules));

        if ($moduleWidth < $minimum) {
            throw new InvalidArgumentException("Element {$element['id']} is too narrow for a scan-safe barcode module width.");
        }

        if ($moduleWidth > $maximum || $moduleWidth * $totalModules > $availableDots) {
            throw new InvalidArgumentException("Element {$element['id']} barcode does not fit its assigned width.");
        }

        return $moduleWidth;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array{int, int, int|null}
     */
    private function linearVerticalLayout(array $element, LabelRenderContext $context): array
    {
        $showText = $element['show_text'] ?? true;
        $elementHeight = $context->millimetersToDots($element['height']);
        $textHeight = $showText ? max(12, $context->millimetersToDots(3.0)) : 0;
        $gap = $showText ? max(1, $context->millimetersToDots(0.5)) : 0;
        $availableBarHeight = $elementHeight - $textHeight - $gap;
        $barHeight = array_key_exists('bar_height', $element)
            ? $context->millimetersToDots($element['bar_height'])
            : $availableBarHeight;
        $minimumBarHeight = $context->millimetersToDots(6.35);

        if ($barHeight < $minimumBarHeight) {
            throw new InvalidArgumentException("Element {$element['id']} barcode bars are too short for reliable scanning.");
        }

        if ($barHeight > $availableBarHeight) {
            throw new InvalidArgumentException("Element {$element['id']} barcode height does not leave room for its human-readable text.");
        }

        $originY = $elementHeight - $textHeight - $gap - $barHeight;

        return [$originY, $barHeight, $showText ? $elementHeight - $textHeight : null];
    }

    /** @param array<string, mixed> $element */
    private function humanReadableText(array $element, LabelRenderContext $context, string $value, ?int $textY): string
    {
        if ($textY === null) {
            return '';
        }

        $textHeight = max(12, $context->millimetersToDots(3.0));
        $fontWidth = max(1, (int) round($textHeight * 0.6));
        $width = $context->millimetersToDots($element['width']);
        $textWidth = min($width, mb_strlen($value) * $fontWidth);
        $textOffset = max(0, (int) floor(($width - $textWidth) / 2));
        [$x, $y] = $this->orientedOrigin(
            $element,
            $context,
            $textOffset,
            $textY,
            $textWidth,
            $textHeight,
        );
        $orientation = $this->orientation($element);
        $text = $this->escapeFieldData($value);

        return "^FO{$x},{$y}^A0{$orientation},{$textHeight},{$fontWidth}^FH\\^FD{$text}^FS";
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array{int, int}
     */
    private function orientedOrigin(
        array $element,
        LabelRenderContext $context,
        int $localX,
        int $localY,
        int $contentWidth,
        int $contentHeight,
    ): array {
        $x = $context->millimetersToDots($element['x']);
        $y = $context->millimetersToDots($element['y']);
        $width = $context->millimetersToDots($element['width']);
        $height = $context->millimetersToDots($element['height']);

        return match (LabelRotation::from($element['rotation'])) {
            LabelRotation::None => [$x + $localX, $y + $localY],
            LabelRotation::Clockwise90 => [$x + $localY, $y + $localX],
            LabelRotation::Clockwise180 => [$x + $width - $localX - $contentWidth, $y + $height - $localY - $contentHeight],
            LabelRotation::Clockwise270 => [$x + $localY, $y + $width - $localX - $contentWidth],
        };
    }

    /** @param array<string, mixed> $element */
    private function orientation(array $element): string
    {
        return match (LabelRotation::from($element['rotation'])) {
            LabelRotation::None => 'N',
            LabelRotation::Clockwise90 => 'R',
            LabelRotation::Clockwise180 => 'I',
            LabelRotation::Clockwise270 => 'B',
        };
    }

    private function qrVersionForByteLength(int $length, mixed $elementId): int
    {
        foreach (self::QR_MEDIUM_BYTE_CAPACITIES as $index => $capacity) {
            if ($length <= $capacity) {
                return $index + 1;
            }
        }

        throw new InvalidArgumentException("Element {$elementId} QR data exceeds the validated renderer capacity.");
    }

    private function escapeFieldData(string $value): string
    {
        return str_replace(['\\', '^', '~'], ['\\5C', '\\5E', '\\7E'], $value);
    }
}
