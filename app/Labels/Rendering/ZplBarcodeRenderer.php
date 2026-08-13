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
        if (LabelRotation::from($element['rotation']) !== LabelRotation::None) {
            throw new InvalidArgumentException("Element {$element['id']} uses barcode rotation that is not implemented safely yet.");
        }

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
        [$originY, $barHeight, $textCommand] = $this->linearVerticalLayout($element, $context, $value);
        $originX = $context->millimetersToDots($element['x']) + (9 * $moduleWidth);

        return "^BY{$moduleWidth},2,{$barHeight}^FO{$originX},{$originY}^BUN,{$barHeight},N,N^FD".substr($value, 0, 11)."^FS{$textCommand}";
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
        [$originY, $barHeight, $textCommand] = $this->linearVerticalLayout($element, $context, $value);
        $originX = $context->millimetersToDots($element['x']) + (10 * $moduleWidth);
        $encodedValue = $this->escapeFieldData('>:'.$value);

        return "^BY{$moduleWidth},2,{$barHeight}^FO{$originX},{$originY}^BCN,{$barHeight},N,N,N,A^FH\\^FD{$encodedValue}^FS{$textCommand}";
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
        $x = $context->millimetersToDots($element['x']) + $quietZone;
        $y = $context->millimetersToDots($element['y']) + $quietZone;
        $data = $this->escapeFieldData($errorCorrection->zplValue().'A,'.$value);

        return "^FO{$x},{$y}^BQN,2,{$moduleWidth},{$errorCorrection->zplValue()},7^FH\\^FD{$data}^FS";
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
     * @return array{int, int, string}
     */
    private function linearVerticalLayout(array $element, LabelRenderContext $context, string $humanReadableValue): array
    {
        $showText = $element['show_text'] ?? true;
        $elementTop = $context->millimetersToDots($element['y']);
        $elementHeight = $context->millimetersToDots($element['height']);
        $elementBottom = $elementTop + $elementHeight;
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

        $originY = $elementBottom - $textHeight - $gap - $barHeight;
        $textCommand = '';

        if ($showText) {
            $x = $context->millimetersToDots($element['x']);
            $textY = $elementBottom - $textHeight;
            $width = $context->millimetersToDots($element['width']);
            $fontWidth = max(1, (int) round($textHeight * 0.6));
            $text = $this->escapeFieldData($humanReadableValue);
            $textCommand = "^FO{$x},{$textY}^A0N,{$textHeight},{$fontWidth}^FB{$width},1,0,C,0^FH\\^FD{$text}^FS";
        }

        return [$originY, $barHeight, $textCommand];
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
