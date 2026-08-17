<?php

namespace App\Http\Controllers;

use App\Labels\Rendering\LabelPreviewService;
use App\Models\LabelTemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LabelPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        LabelTemplateVersion $labelTemplateVersion,
        LabelPreviewService $previewService,
    ): Response {
        $validated = $request->validate([
            'values' => ['sometimes', 'array'],
            'dpi' => ['sometimes', 'integer', Rule::in([203, 300])],
        ]);

        try {
            $svg = $previewService->render(
                $labelTemplateVersion,
                $validated['values'] ?? [],
                $validated['dpi'] ?? 203,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'preview' => $exception->getMessage(),
            ]);
        }

        return response($svg, 200, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
        ]);
    }
}
