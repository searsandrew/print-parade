<?php

namespace App\Labels\Rendering;

use App\Labels\Definitions\ResolvedLabelDefinition;

final class MediaCoordinateTransformer
{
    public function transform(ResolvedLabelDefinition $label, LabelRenderContext $context): ResolvedLabelDefinition
    {
        $canvasRotation = $label->canvasRotation();

        if ($canvasRotation === 0) {
            return $label;
        }

        $elements = array_map(function (array $element) use ($canvasRotation, $context): array {
            $elementRotation = (int) $element['rotation'];
            $quarterTurn = in_array($elementRotation, [90, 270], true);
            $boundingWidth = $quarterTurn ? (float) $element['height'] : (float) $element['width'];
            $boundingHeight = $quarterTurn ? (float) $element['width'] : (float) $element['height'];
            $x = (float) $element['x'];
            $y = (float) $element['y'];

            [$element['x'], $element['y']] = match ($canvasRotation) {
                90 => [$context->widthInMillimeters - ($y + $boundingHeight), $x],
                180 => [$context->widthInMillimeters - ($x + $boundingWidth), $context->heightInMillimeters - ($y + $boundingHeight)],
                270 => [$y, $context->heightInMillimeters - ($x + $boundingWidth)],
            };
            $element['rotation'] = ($elementRotation + $canvasRotation) % 360;

            return $element;
        }, $label->elements());

        return new ResolvedLabelDefinition($elements, $label->values());
    }
}
