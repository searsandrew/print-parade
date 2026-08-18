<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabelTemplateCatalogResource;
use App\Http\Resources\PrinterCatalogResource;
use App\Http\Resources\PrintOperatorResource;
use App\Models\LabelTemplate;
use App\Models\Printer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $templates = LabelTemplate::query()
            ->where('is_active', true)
            ->whereHas('labelStock', fn ($query) => $query->where('is_active', true))
            ->whereHas('publishedVersion')
            ->with(['labelStock', 'publishedVersion'])
            ->orderBy('name')
            ->get();
        $printers = Printer::query()
            ->where('is_active', true)
            ->whereHas('labelStock', fn ($query) => $query->where('is_active', true))
            ->whereHas('printBridge', fn ($query) => $query->where('is_active', true))
            ->with(['labelStock', 'printBridge'])
            ->orderBy('name')
            ->get();
        $operators = $authenticatedUser->requires_print_operator_pin
            ? User::query()->whereNotNull('pin_hash')->orderBy('name')->get()
            : collect();

        return response()->json([
            'templates' => $templates->map(fn (LabelTemplate $template): array => (new LabelTemplateCatalogResource($template))->resolve($request)),
            'printers' => $printers->map(fn (Printer $printer): array => (new PrinterCatalogResource($printer))->resolve($request)),
            'operators' => $operators->map(fn (User $user): array => (new PrintOperatorResource($user))->resolve($request)),
            'authorization' => [
                'requires_operator_pin' => $authenticatedUser->requires_print_operator_pin,
                'authenticated_user' => [
                    'id' => $authenticatedUser->id,
                    'name' => $authenticatedUser->name,
                ],
            ],
        ]);
    }
}
