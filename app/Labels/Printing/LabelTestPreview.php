<?php

namespace App\Labels\Printing;

final readonly class LabelTestPreview
{
    /** @param array<string, mixed> $resolvedValues */
    public function __construct(
        public string $svg,
        public array $resolvedValues,
    ) {}
}
