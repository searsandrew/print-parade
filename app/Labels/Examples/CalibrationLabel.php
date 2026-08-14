<?php

namespace App\Labels\Examples;

use App\Labels\Definitions\LabelDefinition;

final class CalibrationLabel
{
    public const HEIGHT_IN_MILLIMETERS = 50.8;

    public const WIDTH_IN_MILLIMETERS = 101.6;

    public static function definition(): LabelDefinition
    {
        return LabelDefinition::fromArray([
            'elements' => [
                self::rectangle(),
                self::text('01K00000000000000000000002', 5, 3, 55, 6, 'PART: {{ part_number }}', 4, true),
                self::text('01K00000000000000000000003', 5, 9, 55, 5, 'MADE IN {{ country_of_origin }}', 2.5),
                self::text('01K00000000000000000000004', 5, 14, 55, 5, 'Replacement for {{ replacement_part_number }}', 2.5),
                self::barcode('01K00000000000000000000005', 5, 20, 55, 17, 'code128', '{{ part_number }}', [
                    'bar_height' => 8,
                ]),
                self::line(),
                self::jobIdentifier(),
                self::barcode('01K00000000000000000000008', 64, 3, 34, 23, 'upc_a', '{{ upc }}', [
                    'bar_height' => 14,
                ]),
                self::barcode('01K00000000000000000000009', 70, 28, 22, 22, 'qr_code', '{{ qr_url }}', [
                    'error_correction' => 'medium',
                ]),
            ],
            'fields' => [
                'part_number' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Part number',
                ],
                'country_of_origin' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Country of origin',
                    'default' => 'USA',
                ],
                'replacement_part_number' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Replacement part number',
                ],
                'upc' => [
                    'type' => 'string',
                    'format' => 'upc_a',
                    'required' => true,
                    'label' => 'UPC',
                ],
                'qr_url' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'QR URL',
                ],
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function sampleInput(): array
    {
        return [
            'part_number' => 'ABC-123',
            'upc' => '036000291452',
            'qr_url' => 'https://example.com/p/ABC-123',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sampleSystemValues(): array
    {
        return [
            'job_identifier' => 'CMM023 (0826) | A7K29Q4M',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rectangle(): array
    {
        return [
            'id' => '01K00000000000000000000001',
            'type' => 'rectangle',
            'x' => 1,
            'y' => 1,
            'width' => 99.6,
            'height' => 48.8,
            'rotation' => 0,
            'stroke_width' => 0.4,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(): array
    {
        return [
            'id' => '01K00000000000000000000006',
            'type' => 'line',
            'x' => 5,
            'y' => 39,
            'width' => 55,
            'height' => 0.5,
            'rotation' => 0,
            'stroke_width' => 0.25,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jobIdentifier(): array
    {
        $element = self::text('01K00000000000000000000007', 5, 42, 55, 5, '', 2);
        $element['type'] = 'job_identifier';
        unset($element['value']);

        return $element;
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(
        string $id,
        float $x,
        float $y,
        float $width,
        float $height,
        string $value,
        float $fontSize,
        bool $bold = false,
    ): array {
        return [
            'id' => $id,
            'type' => 'text',
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'rotation' => 0,
            'value' => $value,
            'style' => [
                'font_family' => 'sans',
                'font_size' => $fontSize,
                'font_weight' => $bold ? 'bold' : 'normal',
                'alignment' => 'left',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function barcode(
        string $id,
        float $x,
        float $y,
        float $width,
        float $height,
        string $symbology,
        string $value,
        array $options = [],
    ): array {
        return [
            'id' => $id,
            'type' => 'barcode',
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'rotation' => 0,
            'symbology' => $symbology,
            'value' => $value,
            ...$options,
        ];
    }
}
