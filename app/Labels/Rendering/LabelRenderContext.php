<?php

namespace App\Labels\Rendering;

use App\Models\LabelStock;
use InvalidArgumentException;

final readonly class LabelRenderContext
{
    public function __construct(
        public float $widthInMillimeters,
        public float $heightInMillimeters,
        public int $dotsPerInch,
        public ?string $previewArtworkDataUri = null,
        public float $horizontalCorrectionInMillimeters = 0.0,
        public float $verticalCorrectionInMillimeters = 0.0,
    ) {
        if ($widthInMillimeters <= 0 || $heightInMillimeters <= 0) {
            throw new InvalidArgumentException('Label dimensions must be positive.');
        }

        if (! in_array($dotsPerInch, [203, 300], true)) {
            throw new InvalidArgumentException('Label rendering currently supports 203 or 300 DPI.');
        }
    }

    public static function fromStock(
        LabelStock $stock,
        int $dotsPerInch,
        bool $includePreviewArtwork = false,
        float $horizontalCorrectionInMillimeters = 0.0,
        float $verticalCorrectionInMillimeters = 0.0,
    ): self {
        return new self(
            widthInMillimeters: (float) $stock->width,
            heightInMillimeters: (float) $stock->height,
            dotsPerInch: $dotsPerInch,
            previewArtworkDataUri: $includePreviewArtwork ? $stock->previewArtworkDataUri() : null,
            horizontalCorrectionInMillimeters: $horizontalCorrectionInMillimeters,
            verticalCorrectionInMillimeters: $verticalCorrectionInMillimeters,
        );
    }

    public function millimetersToDots(float|int|string $millimeters): int
    {
        return (int) round((float) $millimeters / 25.4 * $this->dotsPerInch);
    }

    public function widthInDots(): int
    {
        return $this->millimetersToDots($this->widthInMillimeters);
    }

    public function heightInDots(): int
    {
        return $this->millimetersToDots($this->heightInMillimeters);
    }
}
