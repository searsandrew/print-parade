<?php

namespace App\Labels\Rendering;

use App\Labels\Definitions\ResolvedLabelDefinition;

interface LabelRenderer
{
    public function render(ResolvedLabelDefinition $label, LabelRenderContext $context): string;
}
