<?php

namespace App\Labels\Definitions;

use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\LabelElementType;
use App\Labels\Enums\LabelFontWeight;
use App\Labels\Enums\LabelRotation;
use App\Labels\Enums\LabelTextAlignment;
use App\Labels\Enums\QrErrorCorrection;
use App\Labels\Enums\SemanticFontFamily;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class LabelDefinition implements Arrayable, JsonSerializable
{
    public const SCHEMA_VERSION = 2;

    /**
     * @param  array{elements: list<array<string, mixed>>, fields: array<string, array<string, mixed>>, canvas_rotation: int}  $definition
     */
    private function __construct(private array $definition) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $definition['canvas_rotation'] ??= 0;
        self::validate($definition);

        /** @var array{elements: list<array<string, mixed>>, fields: array<string, array<string, mixed>>, canvas_rotation: int} $definition */
        return new self($definition);
    }

    /**
     * @return array{elements: list<array<string, mixed>>, fields: array<string, array<string, mixed>>, canvas_rotation: int}
     */
    public function toArray(): array
    {
        return $this->definition;
    }

    /**
     * @return array{elements: list<array<string, mixed>>, fields: array<string, array<string, mixed>>, canvas_rotation: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Resolve input and system values into renderer-ready elements.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $system
     */
    public function resolve(array $input, array $system): ResolvedLabelDefinition
    {
        return (new LabelDefinitionResolver)->resolve($this, $input, $system);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function validate(array $definition): void
    {
        if (! isset($definition['elements']) || ! is_array($definition['elements']) || ! array_is_list($definition['elements'])) {
            throw new InvalidArgumentException('The label definition must contain an elements list.');
        }

        if (! isset($definition['fields']) || ! is_array($definition['fields'])) {
            throw new InvalidArgumentException('The label definition must contain a fields object.');
        }

        if (! is_int($definition['canvas_rotation']) || LabelRotation::tryFrom($definition['canvas_rotation']) === null) {
            throw new InvalidArgumentException('The label definition must use a supported canvas rotation.');
        }

        $elementIds = [];

        foreach ($definition['elements'] as $index => $element) {
            if (! is_array($element)) {
                throw new InvalidArgumentException("Element {$index} must be an object.");
            }

            self::validateElement($element, $index);

            if (in_array($element['id'], $elementIds, true)) {
                throw new InvalidArgumentException("Element {$index} must have a unique id.");
            }

            $elementIds[] = $element['id'];
        }

        foreach ($definition['fields'] as $name => $field) {
            self::validateField($name, $field);
        }
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private static function validateElement(array $element, int $index): void
    {
        if (! isset($element['id']) || ! is_string($element['id']) || ! Str::isUlid($element['id'])) {
            throw new InvalidArgumentException("Element {$index} must have a valid ULID id.");
        }

        $type = isset($element['type']) && is_string($element['type'])
            ? LabelElementType::tryFrom($element['type'])
            : null;

        if ($type === null) {
            throw new InvalidArgumentException("Element {$index} has an unsupported type.");
        }

        foreach (['x', 'y'] as $property) {
            self::requireNonNegativeNumber($element, $property, $index);
        }

        foreach (['width', 'height'] as $property) {
            self::requirePositiveNumber($element, $property, $index);
        }

        if (! isset($element['rotation']) || ! is_int($element['rotation']) || LabelRotation::tryFrom($element['rotation']) === null) {
            throw new InvalidArgumentException("Element {$index} must use a supported rotation.");
        }

        if (array_key_exists('hide_when_empty', $element) && ! is_bool($element['hide_when_empty'])) {
            throw new InvalidArgumentException("Element {$index} hide_when_empty must be a boolean.");
        }

        match ($type) {
            LabelElementType::Text => self::validateTextElement($element, $index, true),
            LabelElementType::JobIdentifier => self::validateTextElement($element, $index, false),
            LabelElementType::Barcode => self::validateBarcodeElement($element, $index),
            LabelElementType::Line, LabelElementType::Rectangle => self::requirePositiveNumber($element, 'stroke_width', $index),
            LabelElementType::Image => self::requireNonEmptyString($element, 'asset_id', $index),
        };
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private static function validateTextElement(array $element, int $index, bool $requiresValue): void
    {
        if ($requiresValue) {
            self::requireNonEmptyString($element, 'value', $index);
        }

        if (! isset($element['style']) || ! is_array($element['style'])) {
            throw new InvalidArgumentException("Element {$index} must contain a style object.");
        }

        $style = $element['style'];

        if (! isset($style['font_family']) || ! is_string($style['font_family']) || SemanticFontFamily::tryFrom($style['font_family']) === null) {
            throw new InvalidArgumentException("Element {$index} must use a supported semantic font family.");
        }

        self::requirePositiveNumber($style, 'font_size', $index);

        if (array_key_exists('font_width', $style)) {
            self::requirePositiveNumber($style, 'font_width', $index);

            if ((float) $style['font_width'] < 0.5 || (float) $style['font_width'] > 2.0) {
                throw new InvalidArgumentException("Element {$index} font_width must be between 0.5 and 2.0.");
            }
        }

        if (! isset($style['font_weight']) || ! is_string($style['font_weight']) || LabelFontWeight::tryFrom($style['font_weight']) === null) {
            throw new InvalidArgumentException("Element {$index} must use a supported font weight.");
        }

        if (! isset($style['alignment']) || ! is_string($style['alignment']) || LabelTextAlignment::tryFrom($style['alignment']) === null) {
            throw new InvalidArgumentException("Element {$index} must use a supported text alignment.");
        }
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private static function validateBarcodeElement(array $element, int $index): void
    {
        self::requireNonEmptyString($element, 'value', $index);

        if (! isset($element['symbology']) || ! is_string($element['symbology']) || BarcodeSymbology::tryFrom($element['symbology']) === null) {
            throw new InvalidArgumentException("Element {$index} must use a supported barcode symbology.");
        }

        if (array_key_exists('show_text', $element) && ! is_bool($element['show_text'])) {
            throw new InvalidArgumentException("Element {$index} show_text must be a boolean.");
        }

        if (array_key_exists('module_width', $element)) {
            self::requirePositiveNumber($element, 'module_width', $index);
        }

        if (array_key_exists('bar_height', $element)) {
            self::requirePositiveNumber($element, 'bar_height', $index);

            if ((float) $element['bar_height'] > (float) $element['height']) {
                throw new InvalidArgumentException("Element {$index} bar_height cannot exceed its height.");
            }
        }

        if (array_key_exists('error_correction', $element)
            && (! is_string($element['error_correction']) || QrErrorCorrection::tryFrom($element['error_correction']) === null)) {
            throw new InvalidArgumentException("Element {$index} must use a supported QR error correction level.");
        }
    }

    private static function validateField(mixed $name, mixed $field): void
    {
        if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidArgumentException('Field names must use snake_case and begin with a letter.');
        }

        if (! is_array($field)) {
            throw new InvalidArgumentException("Field {$name} must be an object.");
        }

        if (! isset($field['type']) || ! is_string($field['type']) || ! in_array($field['type'], ['string', 'number', 'boolean', 'date'], true)) {
            throw new InvalidArgumentException("Field {$name} has an unsupported type.");
        }

        if (! array_key_exists('required', $field) || ! is_bool($field['required'])) {
            throw new InvalidArgumentException("Field {$name} must declare whether it is required.");
        }

        if (! isset($field['label']) || ! is_string($field['label']) || trim($field['label']) === '') {
            throw new InvalidArgumentException("Field {$name} must have a label.");
        }

        if ($name === 'system') {
            throw new InvalidArgumentException('The system namespace is reserved.');
        }

        if (isset($field['format']) && ($field['format'] !== 'upc_a' || $field['type'] !== 'string')) {
            throw new InvalidArgumentException("Field {$name} has an unsupported format for its type.");
        }

        if (array_key_exists('default', $field)) {
            LabelDefinitionResolver::validateFieldValue($name, $field, $field['default']);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function requireNonEmptyString(array $values, string $property, int $index): void
    {
        if (! isset($values[$property]) || ! is_string($values[$property]) || trim($values[$property]) === '') {
            throw new InvalidArgumentException("Element {$index} must have a {$property} value.");
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function requireNonNegativeNumber(array $values, string $property, int $index): void
    {
        if (! isset($values[$property]) || ! is_numeric($values[$property]) || (float) $values[$property] < 0) {
            throw new InvalidArgumentException("Element {$index} must have a non-negative {$property} value in millimeters.");
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function requirePositiveNumber(array $values, string $property, int $index): void
    {
        if (! isset($values[$property]) || ! is_numeric($values[$property]) || (float) $values[$property] <= 0) {
            throw new InvalidArgumentException("Element {$index} must have a positive {$property} value in millimeters.");
        }
    }
}
