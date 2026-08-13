<?php

namespace App\Labels\Rendering;

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Enums\LabelRotation;
use InvalidArgumentException;

final class LabelLayoutPreflight
{
    public function assertFits(ResolvedLabelDefinition $label, LabelRenderContext $context): void
    {
        foreach ($label->elements() as $element) {
            $rotation = LabelRotation::from($element['rotation']);
            $width = (float) $element['width'];
            $height = (float) $element['height'];

            if (in_array($rotation, [LabelRotation::Clockwise90, LabelRotation::Clockwise270], true)) {
                [$width, $height] = [$height, $width];
            }

            $rightOverflow = (float) $element['x'] + $width - $context->widthInMillimeters;
            $bottomOverflow = (float) $element['y'] + $height - $context->heightInMillimeters;

            if ($rightOverflow > 0) {
                throw new InvalidArgumentException(sprintf(
                    'Element %s extends %.3f mm beyond the right edge.',
                    $element['id'],
                    $rightOverflow,
                ));
            }

            if ($bottomOverflow > 0) {
                throw new InvalidArgumentException(sprintf(
                    'Element %s extends %.3f mm beyond the bottom edge.',
                    $element['id'],
                    $bottomOverflow,
                ));
            }
        }
    }
}
