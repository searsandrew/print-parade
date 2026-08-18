<?php

use App\Labels\Definitions\LabelDefinition;
use App\Labels\Definitions\LabelDefinitionResolver;
use App\Labels\DataSources\LabelDataSourceRegistry;
use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\LabelElementType;
use App\Labels\Enums\QrErrorCorrection;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\SvgRenderer;
use App\Labels\Rendering\TcLibBarcodeSvgGenerator;
use App\Labels\Templates\LabelRevisionCreator;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateDraft;
use App\Models\LabelTemplateVersion;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Label designer')] class extends Component {
    public int $templateId;

    public ?int $sourceVersionId = null;

    public ?int $draftId = null;

    public ?string $draftSavedAt = null;

    public string $lastDraftHash = '';

    public bool $draftRecovered = false;

    public string $revisionCode = '';

    public int $canvasRotation = 0;

    /** @var list<array<string, mixed>> */
    public array $elements = [];

    /** @var array<string, array<string, mixed>> */
    public array $fields = [];

    public ?int $selectedIndex = null;

    public bool $acknowledgeMissingJobIdentifier = false;

    public string $fieldName = '';

    public string $fieldLabel = '';

    public string $fieldType = 'string';

    public bool $fieldRequired = false;

    public string $fieldDefault = '';

    public string $fieldFormat = '';

    public string $previewSvg = '';

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    public function mount(LabelTemplate $labelTemplate, ?LabelTemplateVersion $labelTemplateVersion = null): void
    {
        $labelTemplate->load('labelStock');

        if ($labelTemplateVersion !== null && $labelTemplateVersion->label_template_id !== $labelTemplate->id) {
            abort(404);
        }

        $this->templateId = $labelTemplate->id;
        $this->sourceVersionId = $labelTemplateVersion?->id;
        $this->revisionCode = now()->format('my');

        $draft = $labelTemplateVersion === null
            ? LabelTemplateDraft::query()
                ->whereBelongsTo($labelTemplate)
                ->whereBelongsTo(Auth::user())
                ->first()
            : null;

        if ($draft !== null) {
            $this->draftId = $draft->id;
            $this->draftRecovered = true;
            $this->draftSavedAt = $draft->updated_at?->toIso8601String();
            $this->revisionCode = $draft->revision_code;
            $this->elements = $draft->definition['elements'];
            $this->fields = $draft->definition['fields'];
            $this->canvasRotation = $draft->definition['canvas_rotation'] ?? 0;
            $this->lastDraftHash = $this->draftHash();
        } elseif ($labelTemplateVersion === null) {
            $this->elements = [$this->newJobIdentifierElement()];
            $this->fields = [];
        } else {
            $definition = $labelTemplateVersion->definition->toArray();
            $this->elements = $definition['elements'];
            $this->fields = $definition['fields'];
            $this->canvasRotation = $definition['canvas_rotation'];
        }

        $this->selectedIndex = $this->elements === [] ? null : 0;
    }

    public function saveDraft(): void
    {
        if ($this->persistDraft()) {
            Flux::toast(variant: 'success', text: __('Draft saved.'));
        }
    }

    public function saveDraftSilently(): void
    {
        $this->persistDraft();
    }

    #[Computed]
    public function template(): LabelTemplate
    {
        return LabelTemplate::query()->with('labelStock')->findOrFail($this->templateId);
    }

    /**
     * @return array<string, array<string, array{label: string, type: string, required: bool, required_inputs: list<string>}>>
     */
    #[Computed]
    public function dataSourceCatalog(): array
    {
        return app(LabelDataSourceRegistry::class)->catalog();
    }

    #[Computed]
    public function canvasWidth(): float
    {
        return in_array($this->canvasRotation, [90, 270], true)
            ? (float) $this->template->labelStock->height
            : (float) $this->template->labelStock->width;
    }

    #[Computed]
    public function canvasHeight(): float
    {
        return in_array($this->canvasRotation, [90, 270], true)
            ? (float) $this->template->labelStock->width
            : (float) $this->template->labelStock->height;
    }

    public function changeCanvasRotation(int $rotation): void
    {
        abort_unless(in_array($rotation, [0, 90, 180, 270], true), 422);
        $this->canvasRotation = $rotation;
        $selectedIndex = $this->selectedIndex;

        foreach (array_keys($this->elements) as $index) {
            $element = $this->elements[$index];
            $this->updateElementGeometry(
                $index,
                (float) $element['x'],
                (float) $element['y'],
                (float) $element['width'],
                (float) $element['height'],
            );
        }

        $this->selectedIndex = $selectedIndex;
    }

    public function addElement(string $type, ?string $symbology = null): void
    {
        $elementType = LabelElementType::tryFrom($type);

        $element = match ($elementType) {
            LabelElementType::Text => $this->newTextElement(),
            LabelElementType::JobIdentifier => $this->newJobIdentifierElement(),
            LabelElementType::Line => $this->newLineElement(),
            LabelElementType::Rectangle => $this->newRectangleElement(),
            LabelElementType::Barcode => $this->newBarcodeElement($symbology),
            default => null,
        };

        abort_if($element === null, 422);

        $this->elements[] = $element;
        $this->selectedIndex = array_key_last($this->elements);
        $this->acknowledgeMissingJobIdentifier = false;
    }

    public function updateElementGeometry(int $index, float $x, float $y, float $width, float $height): void
    {
        abort_unless(array_key_exists($index, $this->elements), 404);

        $stockWidth = $this->canvasWidth;
        $stockHeight = $this->canvasHeight;
        $rotation = (int) ($this->elements[$index]['rotation'] ?? 0);
        $isQuarterTurn = in_array($rotation, [90, 270], true);
        $width = max(0.1, min($width, $isQuarterTurn ? $stockHeight : $stockWidth));
        $height = max(0.1, min($height, $isQuarterTurn ? $stockWidth : $stockHeight));
        $boundingWidth = $isQuarterTurn ? $height : $width;
        $boundingHeight = $isQuarterTurn ? $width : $height;

        $this->elements[$index]['x'] = round(max(0.0, min($x, $stockWidth - $boundingWidth)), 3);
        $this->elements[$index]['y'] = round(max(0.0, min($y, $stockHeight - $boundingHeight)), 3);
        $this->elements[$index]['width'] = round($width, 3);
        $this->elements[$index]['height'] = round($height, 3);

        if (isset($this->elements[$index]['bar_height'])) {
            $this->elements[$index]['bar_height'] = min((float) $this->elements[$index]['bar_height'], round($height, 3));
        }

        $this->selectedIndex = $index;
    }

    public function setBarcodeModuleWidth(int $index, float $moduleWidth): void
    {
        abort_unless(($this->elements[$index]['type'] ?? null) === LabelElementType::Barcode->value, 404);

        $moduleDots = max(2, min(10, (int) round($moduleWidth / 25.4 * 203)));
        $normalizedModuleWidth = $moduleDots / 203 * 25.4;
        $symbology = BarcodeSymbology::from($this->elements[$index]['symbology']);
        $totalModules = $this->barcodeTotalModules($this->elements[$index]);
        $physicalWidth = round($totalModules * $normalizedModuleWidth, 3);
        $stockWidth = $this->canvasWidth;
        $stockHeight = $this->canvasHeight;

        $this->elements[$index]['module_width'] = round($normalizedModuleWidth, 3);
        $this->elements[$index]['width'] = min($physicalWidth, $stockWidth);
        $this->elements[$index]['x'] = min((float) $this->elements[$index]['x'], max(0.0, $stockWidth - $physicalWidth));

        if ($symbology === BarcodeSymbology::QrCode) {
            $this->elements[$index]['height'] = min($physicalWidth, $stockHeight);
            $this->elements[$index]['y'] = min((float) $this->elements[$index]['y'], max(0.0, $stockHeight - $physicalWidth));
        }
    }

    public function syncBarcodeWidth(int $index): void
    {
        abort_unless(($this->elements[$index]['type'] ?? null) === LabelElementType::Barcode->value, 404);

        $this->setBarcodeModuleWidth($index, (float) ($this->elements[$index]['module_width'] ?? 0.25));
    }

    public function changeBarcodeSymbology(int $index, string $symbology): void
    {
        abort_unless(($this->elements[$index]['type'] ?? null) === LabelElementType::Barcode->value, 404);

        $barcodeSymbology = BarcodeSymbology::from($symbology);
        $this->elements[$index]['symbology'] = $barcodeSymbology->value;

        if ($barcodeSymbology === BarcodeSymbology::QrCode) {
            $this->elements[$index]['error_correction'] ??= QrErrorCorrection::Medium->value;
            $this->elements[$index]['module_width'] = 4 / 203 * 25.4;
        } else {
            $this->elements[$index]['show_text'] ??= true;
            $this->elements[$index]['bar_height'] ??= max(6.35, (float) $this->elements[$index]['height'] - 3.5);
            $this->elements[$index]['module_width'] = 2 / 203 * 25.4;
        }

        $this->setBarcodeModuleWidth($index, (float) $this->elements[$index]['module_width']);
    }

    public function barcodePreviewDataUri(array $element): string
    {
        try {
            $svg = (new TcLibBarcodeSvgGenerator)->generate(
                BarcodeSymbology::from($element['symbology']),
                $this->barcodePreviewValue($element),
                QrErrorCorrection::tryFrom($element['error_correction'] ?? '') ?? QrErrorCorrection::Medium,
            );

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    public function selectElement(int $index): void
    {
        abort_unless(array_key_exists($index, $this->elements), 404);
        $this->selectedIndex = $index;
    }

    public function changeElementRotation(int $index, int $rotation): void
    {
        abort_unless(array_key_exists($index, $this->elements), 404);

        $this->elements[$index]['rotation'] = $rotation;
        $this->updateElementGeometry(
            $index,
            (float) $this->elements[$index]['x'],
            (float) $this->elements[$index]['y'],
            (float) $this->elements[$index]['width'],
            (float) $this->elements[$index]['height'],
        );
    }

    public function removeSelectedElement(): void
    {
        if ($this->selectedIndex === null || ! array_key_exists($this->selectedIndex, $this->elements)) {
            return;
        }

        array_splice($this->elements, $this->selectedIndex, 1);
        $this->selectedIndex = $this->elements === [] ? null : min($this->selectedIndex, count($this->elements) - 1);
        $this->acknowledgeMissingJobIdentifier = false;
    }

    public function moveSelectedElement(int $direction): void
    {
        if ($this->selectedIndex === null || ! in_array($direction, [-1, 1], true)) {
            return;
        }

        $destination = $this->selectedIndex + $direction;

        if (! array_key_exists($destination, $this->elements)) {
            return;
        }

        [$this->elements[$this->selectedIndex], $this->elements[$destination]] = [$this->elements[$destination], $this->elements[$this->selectedIndex]];
        $this->selectedIndex = $destination;
    }

    public function openFieldForm(): void
    {
        $this->reset('fieldName', 'fieldLabel', 'fieldDefault', 'fieldFormat');
        $this->fieldType = 'string';
        $this->fieldRequired = false;
        $this->resetValidation();
        Flux::modal('field-form')->show();
    }

    public function saveField(): void
    {
        $validated = $this->validate([
            'fieldName' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]*\z/', Rule::notIn(['system']), Rule::notIn(array_keys($this->fields))],
            'fieldLabel' => ['required', 'string', 'max:255'],
            'fieldType' => ['required', Rule::in(['string', 'number', 'boolean', 'date'])],
            'fieldRequired' => ['required', 'boolean'],
            'fieldDefault' => ['nullable', 'string', 'max:1000'],
            'fieldFormat' => ['nullable', Rule::in(['', 'upc_a'])],
        ], [
            'fieldName.regex' => __('Field names must use snake_case and begin with a letter.'),
            'fieldName.not_in' => __('That field name is reserved or already in use.'),
        ]);

        if ($validated['fieldFormat'] === 'upc_a' && $validated['fieldType'] !== 'string') {
            $this->addError('fieldFormat', __('UPC-A formatting is only available for string fields.'));

            return;
        }

        $field = [
            'type' => $validated['fieldType'],
            'required' => $validated['fieldRequired'],
            'label' => $validated['fieldLabel'],
        ];

        if (filled($validated['fieldFormat'])) {
            $field['format'] = $validated['fieldFormat'];
        }

        if (filled($validated['fieldDefault'])) {
            $field['default'] = $this->castFieldValue($validated['fieldType'], $validated['fieldDefault']);
        }

        $this->normalizeEditorElements();

        try {
            LabelDefinition::fromArray([
                'elements' => $this->elements,
                'fields' => [...$this->fields, $validated['fieldName'] => $field],
                'canvas_rotation' => $this->canvasRotation,
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('fieldDefault', $exception->getMessage());

            return;
        }

        $this->fields[$validated['fieldName']] = $field;
        Flux::modal('field-form')->close();
    }

    public function removeField(string $name): void
    {
        abort_unless(array_key_exists($name, $this->fields), 404);

        $definitionText = json_encode($this->elements, JSON_THROW_ON_ERROR);

        if (preg_match('/\{\{\s*'.preg_quote($name, '/').'\s*\}\}/', $definitionText) === 1) {
            $this->addError('fields', __('Remove references to :field from elements before deleting the field.', ['field' => $name]));

            return;
        }

        unset($this->fields[$name]);
    }

    public function preview(LabelDefinitionResolver $resolver, SvgRenderer $renderer): void
    {
        try {
            $definition = $this->validatedDefinition($resolver, $renderer);
            $resolved = $resolver->resolve($definition, $this->sampleValues(), ['job_identifier' => "{$this->template->code} ({$this->revisionCode}) | PREVIEW"]);
            $this->previewSvg = $renderer->render($resolved, LabelRenderContext::fromStock($this->template->labelStock, 203));
            Flux::modal('designer-preview')->show();
        } catch (\InvalidArgumentException $exception) {
            $this->addError('editor', $exception->getMessage());
        }
    }

    public function saveRevision(
        LabelRevisionCreator $revisionCreator,
        LabelDefinitionResolver $resolver,
        SvgRenderer $renderer,
        bool $publish = false,
    ): void {
        $this->validate([
            'revisionCode' => ['required', 'string', 'regex:/\A(?:0[1-9]|1[0-2])\d{2}\z/'],
        ], [
            'revisionCode.regex' => __('The revision code must use MMYY format, such as 0826.'),
        ]);

        try {
            $definition = $this->validatedDefinition($resolver, $renderer);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('editor', $exception->getMessage());

            return;
        }

        $version = $revisionCreator->create($this->template, $this->revisionCode, $definition, Auth::user());

        if ($publish) {
            $version->forceFill(['published_at' => now()])->save();
        }

        LabelTemplateDraft::query()
            ->where('label_template_id', $this->templateId)
            ->where('user_id', Auth::id())
            ->delete();

        Flux::toast(variant: 'success', text: $publish ? __('Revision created and published.') : __('Immutable revision created.'));
        $this->redirectRoute('admin.label-templates', navigate: true);
    }

    private function persistDraft(): bool
    {
        $this->normalizeEditorElements();

        try {
            $definition = LabelDefinition::fromArray([
                'elements' => array_values($this->elements),
                'fields' => $this->fields,
                'canvas_rotation' => $this->canvasRotation,
            ]);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $hash = hash('xxh128', json_encode([$this->revisionCode, $definition->toArray()], JSON_THROW_ON_ERROR));

        if ($hash === $this->lastDraftHash) {
            return true;
        }

        $draft = LabelTemplateDraft::query()->updateOrCreate(
            [
                'label_template_id' => $this->templateId,
                'user_id' => Auth::id(),
            ],
            [
                'revision_code' => $this->revisionCode,
                'schema_version' => LabelDefinition::SCHEMA_VERSION,
                'definition' => $definition->toArray(),
            ],
        );

        $this->draftId = $draft->id;
        $this->draftSavedAt = $draft->updated_at?->toIso8601String();
        $this->lastDraftHash = $hash;

        return true;
    }

    private function draftHash(): string
    {
        return hash('xxh128', json_encode([
            $this->revisionCode,
            [
                'elements' => array_values($this->elements),
                'fields' => $this->fields,
                'canvas_rotation' => $this->canvasRotation,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function validatedDefinition(LabelDefinitionResolver $resolver, SvgRenderer $renderer): LabelDefinition
    {
        $this->normalizeEditorElements();

        if (! collect($this->elements)->contains(fn (array $element): bool => $element['type'] === LabelElementType::JobIdentifier->value)
            && ! $this->acknowledgeMissingJobIdentifier) {
            throw new \InvalidArgumentException('The job identifier was removed. Acknowledge that choice before creating the revision.');
        }

        $definition = LabelDefinition::fromArray([
            'elements' => array_values($this->elements),
            'fields' => $this->fields,
            'canvas_rotation' => $this->canvasRotation,
        ]);
        $resolved = $resolver->resolve($definition, $this->sampleValues(), ['job_identifier' => "{$this->template->code} ({$this->revisionCode}) | PREVIEW"]);
        $renderer->render($resolved, LabelRenderContext::fromStock($this->template->labelStock, 203));

        return $definition;
    }

    private function normalizeEditorElements(): void
    {
        $this->elements = array_map(function (array $element): array {
            foreach (['x', 'y', 'width', 'height', 'stroke_width', 'module_width', 'bar_height'] as $property) {
                if (array_key_exists($property, $element) && is_numeric($element[$property])) {
                    $element[$property] = (float) $element[$property];
                }
            }

            if (isset($element['rotation']) && is_numeric($element['rotation'])) {
                $element['rotation'] = (int) $element['rotation'];
            }

            if (isset($element['style']['font_size']) && is_numeric($element['style']['font_size'])) {
                $element['style']['font_size'] = (float) $element['style']['font_size'];
            }

            return $element;
        }, $this->elements);
    }

    /** @return array<string, mixed> */
    private function sampleValues(): array
    {
        $values = [];

        foreach ($this->fields as $name => $field) {
            $values[$name] = match (true) {
                ($field['format'] ?? null) === 'upc_a' => '036000291452',
                $field['type'] === 'number' => $field['default'] ?? 123,
                $field['type'] === 'boolean' => $field['default'] ?? true,
                $field['type'] === 'date' => $field['default'] ?? now()->toDateString(),
                default => $field['default'] ?? 'Sample text',
            };
        }

        return $values;
    }

    private function castFieldValue(string $type, string $value): mixed
    {
        return match ($type) {
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value,
            default => $value,
        };
    }

    /** @param array<string, mixed> $element */
    public function barcodePreviewValue(array $element): string
    {
        $value = (string) $element['value'];

        return preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)\s*\}\}/', function (array $matches): string {
            if ($matches[1] === 'system.job_identifier') {
                return 'JOB12345';
            }

            return (string) ($this->sampleValues()[$matches[1]] ?? 'SAMPLE');
        }, $value) ?? $value;
    }

    /** @param array<string, mixed> $element */
    private function barcodeTotalModules(array $element): int
    {
        $symbology = BarcodeSymbology::from($element['symbology']);
        $valueLength = strlen($this->barcodePreviewValue($element));

        return match ($symbology) {
            BarcodeSymbology::UpcA => 113,
            BarcodeSymbology::Code128 => (11 * $valueLength) + 55,
            BarcodeSymbology::QrCode => (21 + (4 * ($this->qrVersionForLength($valueLength) - 1))) + 8,
        };
    }

    private function qrVersionForLength(int $length): int
    {
        foreach ([14, 26, 42, 62, 84, 106, 122, 152, 180, 213] as $index => $capacity) {
            if ($length <= $capacity) {
                return $index + 1;
            }
        }

        return 10;
    }

    /** @return array<string, mixed> */
    private function newTextElement(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'type' => LabelElementType::Text->value,
            'x' => 5.0,
            'y' => 5.0,
            'width' => max(0.1, min(50.0, $this->canvasWidth - 10.0)),
            'height' => 8.0,
            'rotation' => 0,
            'hide_when_empty' => true,
            'value' => 'Text',
            'style' => ['font_family' => 'sans', 'font_size' => 4.0, 'font_weight' => 'normal', 'alignment' => 'left'],
        ];
    }

    /** @return array<string, mixed> */
    private function newJobIdentifierElement(): array
    {
        $element = $this->newTextElement();
        $element['type'] = LabelElementType::JobIdentifier->value;
        $element['y'] = max(0.0, $this->canvasHeight - 8.0);
        $element['height'] = 5.0;
        $element['style']['font_size'] = 2.0;
        unset($element['value']);

        return $element;
    }

    /** @return array<string, mixed> */
    private function newLineElement(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'type' => LabelElementType::Line->value,
            'x' => 5.0,
            'y' => 15.0,
            'width' => max(0.1, min(50.0, $this->canvasWidth - 10.0)),
            'height' => 0.1,
            'rotation' => 0,
            'stroke_width' => 0.25,
        ];
    }

    /** @return array<string, mixed> */
    private function newRectangleElement(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'type' => LabelElementType::Rectangle->value,
            'x' => 5.0,
            'y' => 5.0,
            'width' => max(0.1, min(40.0, $this->canvasWidth - 10.0)),
            'height' => max(0.1, min(20.0, $this->canvasHeight - 10.0)),
            'rotation' => 0,
            'stroke_width' => 0.25,
        ];
    }

    /** @return array<string, mixed> */
    private function newBarcodeElement(?string $symbology): array
    {
        $barcodeSymbology = BarcodeSymbology::tryFrom($symbology ?? '') ?? BarcodeSymbology::Code128;
        $stockWidth = $this->canvasWidth;
        $stockHeight = $this->canvasHeight;
        $isQrCode = $barcodeSymbology === BarcodeSymbology::QrCode;
        $previewValue = match ($barcodeSymbology) {
            BarcodeSymbology::UpcA => '036000291452',
            BarcodeSymbology::QrCode => 'https://example.com',
            BarcodeSymbology::Code128 => 'ABC-123',
        };
        $moduleWidth = ($isQrCode ? 4 : 2) / 203 * 25.4;
        $totalModules = match ($barcodeSymbology) {
            BarcodeSymbology::UpcA => 113,
            BarcodeSymbology::Code128 => (11 * strlen($previewValue)) + 55,
            BarcodeSymbology::QrCode => 33,
        };
        $width = max(0.1, min(round($totalModules * $moduleWidth, 3), $stockWidth - 10.0));
        $height = max(0.1, min($isQrCode ? 22.0 : 18.0, $stockHeight - 10.0));

        if ($isQrCode) {
            $height = $width;
        }

        return array_filter([
            'id' => (string) Str::ulid(),
            'type' => LabelElementType::Barcode->value,
            'x' => 5.0,
            'y' => 5.0,
            'width' => $width,
            'height' => $height,
            'rotation' => 0,
            'hide_when_empty' => true,
            'symbology' => $barcodeSymbology->value,
            'value' => $previewValue,
            'show_text' => $isQrCode ? null : true,
            'module_width' => round($moduleWidth, 3),
            'bar_height' => $isQrCode ? null : max(6.35, $height - 3.5),
            'error_correction' => $isQrCode ? QrErrorCorrection::Medium->value : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}; ?>

<div wire:poll.3s="saveDraftSilently" class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.label-templates')" wire:navigate>{{ __('Templates & revisions') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $this->template->code }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-3">{{ __('Label designer') }} · {{ $this->template->code }}</flux:heading>
            <flux:text class="mt-1">{{ $this->template->labelStock->name }} · {{ number_format((float) $this->template->labelStock->width, 3) }} × {{ number_format((float) $this->template->labelStock->height, 3) }} mm</flux:text>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live.number="canvasRotation" wire:change="changeCanvasRotation($event.target.value)" :label="__('Design orientation')" class="w-48">
                <flux:select.option value="0">{{ __('Media orientation') }}</flux:select.option>
                <flux:select.option value="90">{{ __('Rotate canvas 90°') }}</flux:select.option>
                <flux:select.option value="180">{{ __('Rotate canvas 180°') }}</flux:select.option>
                <flux:select.option value="270">{{ __('Rotate canvas 270°') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="revisionCode" :label="__('Revision (MMYY)')" class="w-36" maxlength="4" />
            <flux:button icon="cloud-arrow-up" wire:click="saveDraft">{{ __('Save draft') }}</flux:button>
            <flux:button icon="eye" wire:click="preview">{{ __('Rendered preview') }}</flux:button>
            <flux:button variant="primary" icon="check" wire:click="saveRevision" wire:confirm="{{ __('Create this immutable revision? Future changes will require another revision.') }}">{{ __('Create revision') }}</flux:button>
            <flux:button variant="primary" icon="paper-airplane" wire:click="saveRevision(true)" wire:confirm="{{ __('Create and publish this immutable revision? It will immediately become available for new print jobs.') }}">{{ __('Create & publish') }}</flux:button>
        </div>
    </div>

    @if ($draftId !== null)
        <flux:callout variant="info" icon="cloud" heading="{{ $draftRecovered ? __('Draft recovered') : __('Working draft saved') }}">
            {{ $draftRecovered ? __('Your previous working copy was restored.') : __('This working copy is now stored on the server.') }}
            {{ __('It is private to your account and will be removed when you create a revision.') }}
        </flux:callout>
    @endif

    @error('editor')<flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>@enderror
    @error('revisionCode')<flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>@enderror

    <div class="grid min-h-[720px] gap-5 xl:grid-cols-[240px_minmax(500px,1fr)_340px]">
        <flux:card class="space-y-5">
            <div>
                <flux:heading size="sm">{{ __('Elements') }}</flux:heading>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <flux:button size="sm" icon="plus" wire:click="addElement('text')">{{ __('Text') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="addElement('job_identifier')">{{ __('Job ID') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="addElement('line')">{{ __('Line') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="addElement('rectangle')">{{ __('Box') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="addElement('barcode', 'code128')">{{ __('Code 128') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="addElement('barcode', 'upc_a')">{{ __('UPC-A') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="addElement('barcode', 'qr_code')" class="col-span-2">{{ __('QR code') }}</flux:button>
                </div>
            </div>

            <flux:separator />

            <div class="space-y-2">
                @foreach ($elements as $index => $element)
                    <button type="button" wire:key="element-list-{{ $element['id'] }}" wire:click="selectElement({{ $index }})" @class([
                        'flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left text-sm transition',
                        'border-blue-500 bg-blue-50 text-blue-900 dark:bg-blue-950 dark:text-blue-100' => $selectedIndex === $index,
                        'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800' => $selectedIndex !== $index,
                    ])>
                        <span>{{ match ($element['type']) { 'text' => __('Text'), 'job_identifier' => __('Job identifier'), 'line' => __('Line'), 'rectangle' => __('Rectangle'), 'barcode' => match ($element['symbology'] ?? '') { 'code128' => __('Code 128'), 'upc_a' => __('UPC-A'), 'qr_code' => __('QR code'), default => __('Barcode') }, default => $element['type'] } }}</span>
                        <span class="font-mono text-xs text-zinc-400">{{ $index + 1 }}</span>
                    </button>
                @endforeach
            </div>

            @if ($selectedIndex !== null)
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveSelectedElement(-1)" :disabled="$selectedIndex === 0" />
                    <flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="moveSelectedElement(1)" :disabled="$selectedIndex === count($elements) - 1" />
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="removeSelectedElement" class="ms-auto" />
                </div>
            @endif

            <flux:separator />

            <div>
                <div class="flex items-center justify-between gap-2">
                    <flux:heading size="sm">{{ __('Data fields') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="openFieldForm">{{ __('Add') }}</flux:button>
                </div>
                @error('fields')<flux:text class="mt-2 text-red-600">{{ $message }}</flux:text>@enderror
                <div class="mt-3 space-y-2">
                    @forelse ($fields as $name => $field)
                        <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                            <div class="flex items-start justify-between gap-2">
                                <div><div class="font-medium">{{ $field['label'] }}</div><code class="text-xs text-zinc-500">&#123;&#123; {{ $name }} &#125;&#125;</code></div>
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeField('{{ $name }}')" />
                            </div>
                        </div>
                    @empty
                        <flux:text class="text-sm">{{ __('No variable fields yet.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <flux:separator />

            <div>
                <flux:heading size="sm">{{ __('Data sources') }}</flux:heading>
                <flux:text class="mt-1 text-xs">{{ __('Values supplied by integrations. Enter these placeholders directly into text or barcode elements.') }}</flux:text>

                <flux:accordion class="mt-3">
                    <flux:accordion.item heading="{{ __('System') }}">
                        <div class="space-y-2 text-sm">
                            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <div class="font-medium">{{ __('Job identifier') }}</div>
                                <code class="text-xs text-zinc-500">&#123;&#123; system.job_identifier &#125;&#125;</code>
                                <div class="mt-1 text-xs text-zinc-500">{{ __('Generated automatically for each print job.') }}</div>
                            </div>
                        </div>
                    </flux:accordion.item>

                    @foreach ($this->dataSourceCatalog as $namespace => $sourceFields)
                        <flux:accordion.item :heading="Str::headline($namespace)">
                            <div class="space-y-2">
                                @foreach ($sourceFields as $name => $field)
                                    <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="font-medium">{{ $field['label'] }}</div>
                                            <flux:badge size="sm" :color="$field['required'] ? 'amber' : 'zinc'">{{ $field['required'] ? __('Required') : __('Optional') }}</flux:badge>
                                        </div>
                                        <code class="text-xs text-zinc-500">&#123;&#123; {{ $namespace }}.{{ $name }} &#125;&#125;</code>
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ Str::headline($field['type']) }}
                                            @if ($field['required_inputs'] !== [])
                                                · {{ __('Looks up by: :inputs', ['inputs' => implode(', ', $field['required_inputs'])]) }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </flux:accordion.item>
                    @endforeach
                </flux:accordion>

                @if ($this->dataSourceCatalog === [])
                    <flux:text class="mt-2 text-xs">{{ __('No external data sources are registered yet.') }}</flux:text>
                @endif
            </div>
        </flux:card>

        <flux:card x-data="{ zoom: 'actual' }" class="flex min-w-0 flex-col overflow-hidden bg-zinc-100 p-0! dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <div>
                    <flux:text class="text-xs">{{ __('Finished-label view · approximate physical size') }}</flux:text>
                    @if ($canvasRotation !== 0)
                        <flux:text class="text-xs">{{ __('The complete design rotates :degrees° into the printer feed orientation.', ['degrees' => $canvasRotation]) }}</flux:text>
                    @endif
                </div>
                <flux:button.group>
                    <flux:button size="sm" x-on:click="zoom = 'actual'" x-bind:variant="zoom === 'actual' ? 'primary' : 'ghost'">{{ __('Actual size') }}</flux:button>
                    <flux:button size="sm" x-on:click="zoom = 'fit'" x-bind:variant="zoom === 'fit' ? 'primary' : 'ghost'">{{ __('Fit') }}</flux:button>
                    <flux:button size="sm" x-on:click="zoom = 'double'" x-bind:variant="zoom === 'double' ? 'primary' : 'ghost'">200%</flux:button>
                </flux:button.group>
            </div>
            <div class="flex min-h-[620px] flex-1 items-center justify-center overflow-auto p-8">
                <div
                x-data="{
                    interaction: null,
                    begin(event, index, mode, x, y, width, height, rotation) {
                        const canvas = this.$refs.canvas;
                        const element = event.currentTarget.closest('[data-editor-element]');
                        this.interaction = { index, mode, x, y, width, height, rotation, startX: event.clientX, startY: event.clientY, canvas, element };
                        event.currentTarget.setPointerCapture?.(event.pointerId);
                    },
                    move(event) {
                        if (! this.interaction) return;
                        const state = this.interaction;
                        const rect = state.canvas.getBoundingClientRect();
                        const dx = (event.clientX - state.startX) / rect.width * {{ $this->canvasWidth }};
                        const dy = (event.clientY - state.startY) / rect.height * {{ $this->canvasHeight }};
                        const quarterTurn = state.rotation === 90 || state.rotation === 270;
                        const boundingWidth = quarterTurn ? state.height : state.width;
                        const boundingHeight = quarterTurn ? state.width : state.height;
                        if (state.mode === 'move') {
                            state.nextX = Math.max(0, Math.min(state.x + dx, {{ $this->canvasWidth }} - boundingWidth));
                            state.nextY = Math.max(0, Math.min(state.y + dy, {{ $this->canvasHeight }} - boundingHeight));
                            state.nextWidth = state.width;
                            state.nextHeight = state.height;
                        } else if (state.mode === 'height') {
                            state.nextX = state.x;
                            state.nextY = state.y;
                            state.nextWidth = state.width;
                            state.nextHeight = Math.max(0.1, Math.min(state.height + dy, {{ $this->canvasHeight }} - state.y));
                        } else {
                            state.nextX = state.x;
                            state.nextY = state.y;
                            state.nextWidth = quarterTurn
                                ? Math.max(0.1, Math.min(state.width + dy, {{ $this->canvasHeight }} - state.y))
                                : Math.max(0.1, Math.min(state.width + dx, {{ $this->canvasWidth }} - state.x));
                            state.nextHeight = quarterTurn
                                ? Math.max(0.1, Math.min(state.height + dx, {{ $this->canvasWidth }} - state.x))
                                : Math.max(0.1, Math.min(state.height + dy, {{ $this->canvasHeight }} - state.y));
                        }
                        state.element.style.left = `${state.nextX / {{ $this->canvasWidth }} * 100}%`;
                        state.element.style.top = `${state.nextY / {{ $this->canvasHeight }} * 100}%`;
                        const displayWidth = quarterTurn ? state.nextHeight : state.nextWidth;
                        const displayHeight = quarterTurn ? state.nextWidth : state.nextHeight;
                        state.element.style.width = `${displayWidth / {{ $this->canvasWidth }} * 100}%`;
                        state.element.style.height = `${displayHeight / {{ $this->canvasHeight }} * 100}%`;
                    },
                    finish() {
                        if (! this.interaction) return;
                        const state = this.interaction;
                        this.interaction = null;
                        if (state.nextX === undefined) return;
                        $wire.updateElementGeometry(state.index, state.nextX, state.nextY, state.nextWidth, state.nextHeight);
                    },
                }"
                x-ref="canvas"
                x-on:pointermove.window="move($event)"
                x-on:pointerup.window="finish()"
                x-on:pointercancel.window="finish()"
                x-bind:style="{
                    width: zoom === 'fit' ? '100%' : (zoom === 'double' ? {{ $this->canvasWidth * 2 }} : {{ $this->canvasWidth }}) + 'mm',
                    maxWidth: zoom === 'fit' ? '100%' : 'none',
                }"
                class="relative shrink-0 overflow-hidden border border-zinc-300 bg-white shadow-xl dark:border-zinc-600"
                style="width: {{ $this->canvasWidth }}mm; aspect-ratio: {{ $this->canvasWidth }} / {{ $this->canvasHeight }}; container-type: size;"
                aria-label="{{ __('Label canvas') }}"
            >
                @foreach ($elements as $index => $element)
                    @php
                        $left = ((float) $element['x'] / $this->canvasWidth) * 100;
                        $top = ((float) $element['y'] / $this->canvasHeight) * 100;
                        $isQuarterTurn = in_array((int) $element['rotation'], [90, 270], true);
                        $boundingWidth = $isQuarterTurn ? (float) $element['height'] : (float) $element['width'];
                        $boundingHeight = $isQuarterTurn ? (float) $element['width'] : (float) $element['height'];
                        $displayWidth = ($boundingWidth / $this->canvasWidth) * 100;
                        $displayHeight = ($boundingHeight / $this->canvasHeight) * 100;
                        $innerWidth = ((float) $element['width'] / $boundingWidth) * 100;
                        $innerHeight = ((float) $element['height'] / $boundingHeight) * 100;
                    @endphp
                    <button
                        type="button"
                        data-editor-element
                        wire:key="canvas-element-{{ $element['id'] }}"
                        wire:click="selectElement({{ $index }})"
                        x-on:pointerdown.stop="begin($event, {{ $index }}, 'move', {{ (float) $element['x'] }}, {{ (float) $element['y'] }}, {{ (float) $element['width'] }}, {{ (float) $element['height'] }}, {{ (int) $element['rotation'] }})"
                        @class(['absolute touch-none overflow-visible text-left select-none', 'z-10 ring-2 ring-blue-500 ring-offset-1' => $selectedIndex === $index])
                        style="left: {{ $left }}%; top: {{ $top }}%; width: {{ $displayWidth }}%; height: {{ max($displayHeight, 0.3) }}%;"
                    >
                        <span class="absolute left-1/2 top-1/2 block" style="width: {{ $innerWidth }}%; height: {{ max($innerHeight, 0.3) }}%; transform: translate(-50%, -50%) rotate({{ $element['rotation'] }}deg);">
                        @if ($element['type'] === 'rectangle')
                            <span class="block size-full border border-black"></span>
                        @elseif ($element['type'] === 'line')
                            <span class="block w-full border-t border-black"></span>
                        @elseif ($element['type'] === 'barcode')
                            @php
                                $barcodeValue = $this->barcodePreviewValue($element);
                                $barcodeUri = $this->barcodePreviewDataUri($element);
                                $isQrCode = ($element['symbology'] ?? '') === 'qr_code';
                                $showBarcodeText = ! $isQrCode && ($element['show_text'] ?? true);
                                $textHeightPercent = $showBarcodeText ? min(35, 3 / (float) $element['height'] * 100) : 0;
                            @endphp
                            <span class="flex size-full flex-col overflow-hidden bg-white text-black">
                                @if ($barcodeUri !== '')
                                    <img src="{{ $barcodeUri }}" alt="" draggable="false" class="min-h-0 w-full flex-1 object-fill" />
                                @else
                                    <span class="flex min-h-0 flex-1 items-center justify-center bg-red-50 text-[8px] text-red-700">{{ __('Invalid sample') }}</span>
                                @endif
                                @if ($showBarcodeText)
                                    <span class="flex shrink-0 items-end justify-center overflow-hidden whitespace-nowrap font-sans text-black" style="height: {{ $textHeightPercent }}%; font-size: clamp(6px, 3cqh, 14px); line-height: 1;">{{ $barcodeValue }}</span>
                                @endif
                            </span>
                        @else
                            <span class="block size-full text-black" style="font-family: {{ ($element['style']['font_family'] ?? 'sans') === 'monospace' ? 'monospace' : 'sans-serif' }}; font-weight: {{ $element['style']['font_weight'] ?? 'normal' }}; font-size: clamp(8px, {{ ((float) ($element['style']['font_size'] ?? 3) / $this->canvasHeight) * 100 }}cqh, 36px); text-align: {{ $element['style']['alignment'] ?? 'left' }};">
                                {{ $element['type'] === 'job_identifier' ? $this->template->code.' ('.$revisionCode.') | JOB ID' : ($element['value'] ?? '') }}
                            </span>
                        @endif
                        </span>
                        @if ($selectedIndex === $index && (($element['type'] ?? '') !== 'barcode' || ($element['symbology'] ?? '') !== 'qr_code'))
                            <span
                                role="button"
                                aria-label="{{ __('Resize element') }}"
                                x-on:pointerdown.stop.prevent="begin($event, {{ $index }}, '{{ ($element['type'] ?? '') === 'barcode' ? 'height' : 'resize' }}', {{ (float) $element['x'] }}, {{ (float) $element['y'] }}, {{ (float) $element['width'] }}, {{ (float) $element['height'] }}, {{ (int) $element['rotation'] }})"
                                @class(['absolute -right-2 -bottom-2 z-20 size-4 rounded-sm border-2 border-white bg-blue-600 shadow', 'cursor-ns-resize' => ($element['type'] ?? '') === 'barcode', 'cursor-nwse-resize' => ($element['type'] ?? '') !== 'barcode'])
                            ></span>
                        @endif
                    </button>
                @endforeach
                </div>
            </div>
        </flux:card>

        <flux:card class="space-y-5">
            <flux:heading size="sm">{{ __('Properties') }}</flux:heading>
            @if ($selectedIndex === null)
                <flux:text>{{ __('Select an element on the canvas or in the element list.') }}</flux:text>
            @else
                @php($selected = $elements[$selectedIndex])
                <div wire:key="element-properties-{{ $selected['id'] }}" class="space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.x" :label="__('X (mm)')" type="number" min="0" step="0.1" />
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.y" :label="__('Y (mm)')" type="number" min="0" step="0.1" />
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.width" :label="__('Width (mm)')" type="number" min="0.1" step="0.1" :disabled="$selected['type'] === 'barcode'" />
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.height" :label="__('Height (mm)')" type="number" min="0.1" step="0.1" :disabled="$selected['type'] === 'barcode' && ($selected['symbology'] ?? '') === 'qr_code'" />
                </div>
                <flux:select wire:model.live.number="elements.{{ $selectedIndex }}.rotation" wire:change="changeElementRotation({{ $selectedIndex }}, $event.target.value)" :label="__('Rotation')" :disabled="$selected['type'] === 'barcode'">
                    <flux:select.option value="0">0°</flux:select.option><flux:select.option value="90">90°</flux:select.option><flux:select.option value="180">180°</flux:select.option><flux:select.option value="270">270°</flux:select.option>
                </flux:select>

                @if (in_array($selected['type'], ['text', 'job_identifier'], true))
                    @if ($selected['type'] === 'text')
                        <flux:textarea wire:model.live.debounce.250ms="elements.{{ $selectedIndex }}.value" :label="__('Content')" rows="3" />
                        <flux:switch wire:model.live="elements.{{ $selectedIndex }}.hide_when_empty" :label="__('Hide when empty')" />
                    @endif
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model.live="elements.{{ $selectedIndex }}.style.font_family" :label="__('Font')"><flux:select.option value="sans">{{ __('Sans') }}</flux:select.option><flux:select.option value="monospace">{{ __('Monospace') }}</flux:select.option></flux:select>
                        <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.style.font_size" :label="__('Size (mm)')" type="number" min="0.1" step="0.1" />
                        <flux:select wire:model.live="elements.{{ $selectedIndex }}.style.font_weight" :label="__('Weight')"><flux:select.option value="normal">{{ __('Normal') }}</flux:select.option><flux:select.option value="bold">{{ __('Bold') }}</flux:select.option></flux:select>
                        <flux:select wire:model.live="elements.{{ $selectedIndex }}.style.alignment" :label="__('Align')"><flux:select.option value="left">{{ __('Left') }}</flux:select.option><flux:select.option value="center">{{ __('Center') }}</flux:select.option><flux:select.option value="right">{{ __('Right') }}</flux:select.option></flux:select>
                    </div>
                @elseif ($selected['type'] === 'barcode')
                    <flux:select wire:model.live="elements.{{ $selectedIndex }}.symbology" wire:change="changeBarcodeSymbology({{ $selectedIndex }}, $event.target.value)" :label="__('Symbology')">
                        <flux:select.option value="code128">{{ __('Code 128') }}</flux:select.option>
                        <flux:select.option value="upc_a">{{ __('UPC-A') }}</flux:select.option>
                        <flux:select.option value="qr_code">{{ __('QR code') }}</flux:select.option>
                    </flux:select>
                    <flux:textarea wire:model.live.debounce.250ms="elements.{{ $selectedIndex }}.value" wire:blur="syncBarcodeWidth({{ $selectedIndex }})" :label="__('Content')" rows="3" />
                    <flux:text class="text-xs">{{ __('Content may be literal text, a field placeholder, or a mixture of both.') }}</flux:text>
                    <flux:switch wire:model.live="elements.{{ $selectedIndex }}.hide_when_empty" :label="__('Hide when empty')" />

                    @if (($selected['symbology'] ?? '') === 'qr_code')
                        <flux:select wire:change="setBarcodeModuleWidth({{ $selectedIndex }}, $event.target.value)" :value="number_format((float) ($selected['module_width'] ?? 0.5), 3, '.', '')" :label="__('Module size')">
                            <flux:select.option value="0.250">2 dots · 0.250 mm</flux:select.option>
                            <flux:select.option value="0.375">3 dots · 0.375 mm</flux:select.option>
                            <flux:select.option value="0.500">4 dots · 0.500 mm</flux:select.option>
                            <flux:select.option value="0.625">5 dots · 0.625 mm</flux:select.option>
                            <flux:select.option value="0.750">6 dots · 0.750 mm</flux:select.option>
                        </flux:select>
                        <flux:select wire:model.live="elements.{{ $selectedIndex }}.error_correction" :label="__('Error correction')">
                            <flux:select.option value="low">{{ __('Low') }}</flux:select.option>
                            <flux:select.option value="medium">{{ __('Medium') }}</flux:select.option>
                            <flux:select.option value="quartile">{{ __('Quartile') }}</flux:select.option>
                            <flux:select.option value="high">{{ __('High') }}</flux:select.option>
                        </flux:select>
                        <flux:callout variant="info" icon="information-circle">{{ __('QR codes render as a centered square inside the element bounds.') }}</flux:callout>
                    @else
                        <flux:switch wire:model.live="elements.{{ $selectedIndex }}.show_text" :label="__('Show human-readable text')" />
                        <div class="grid grid-cols-2 gap-4">
                            <flux:select wire:change="setBarcodeModuleWidth({{ $selectedIndex }}, $event.target.value)" :value="number_format((float) ($selected['module_width'] ?? 0.25), 3, '.', '')" :label="__('Module width')">
                                <flux:select.option value="0.250">2 dots · 0.250 mm</flux:select.option>
                                <flux:select.option value="0.375">3 dots · 0.375 mm</flux:select.option>
                                <flux:select.option value="0.500">4 dots · 0.500 mm</flux:select.option>
                                <flux:select.option value="0.625">5 dots · 0.625 mm</flux:select.option>
                            </flux:select>
                            <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.bar_height" :label="__('Bar height (mm)')" type="number" min="6.35" step="0.1" />
                        </div>
                        @if (($selected['symbology'] ?? '') === 'upc_a')
                            <flux:callout variant="warning" icon="exclamation-triangle">{{ __('UPC-A width is constrained by its module count and printer resolution. The rendered preview will reject an unreadable size.') }}</flux:callout>
                        @endif
                    @endif
                @else
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.stroke_width" :label="__('Stroke width (mm)')" type="number" min="0.01" step="0.05" />
                @endif
                </div>
            @endif

            @if (! collect($elements)->contains(fn ($element) => $element['type'] === 'job_identifier'))
                <flux:callout variant="warning" icon="exclamation-triangle" heading="{{ __('Job identifier removed') }}">
                    <flux:checkbox wire:model="acknowledgeMissingJobIdentifier" :label="__('I intentionally want this label to omit print-job traceability.')" />
                </flux:callout>
            @endif
        </flux:card>
    </div>

    <flux:modal name="field-form" class="md:w-lg">
        <form wire:submit="saveField" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Add data field') }}</flux:heading><flux:text class="mt-1">{{ __('Use the field name inside double braces. Literal text and multiple fields may be mixed.') }}</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="fieldName" :label="__('Field name')" placeholder="part_number" required />
                <flux:input wire:model="fieldLabel" :label="__('Label shown to operator')" placeholder="Part number" required />
                <flux:select wire:model="fieldType" :label="__('Type')"><flux:select.option value="string">{{ __('Text') }}</flux:select.option><flux:select.option value="number">{{ __('Number') }}</flux:select.option><flux:select.option value="boolean">{{ __('Yes / no') }}</flux:select.option><flux:select.option value="date">{{ __('Date') }}</flux:select.option></flux:select>
                <flux:select wire:model="fieldFormat" :label="__('Format')"><flux:select.option value="">{{ __('None') }}</flux:select.option><flux:select.option value="upc_a">{{ __('UPC-A') }}</flux:select.option></flux:select>
            </div>
            <flux:input wire:model="fieldDefault" :label="__('Default value')" />
            <flux:switch wire:model="fieldRequired" :label="__('Required')" />
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Add field') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="designer-preview" class="md:w-4xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ $this->template->code }} ({{ $revisionCode }})</flux:heading><flux:text>{{ __('Printer-media preview at 203 DPI with sample values. The feed direction runs from top to bottom.') }}</flux:text></div>
            <div class="flex min-h-80 items-center justify-center overflow-auto rounded-lg bg-zinc-100 p-6 dark:bg-zinc-800 [&>svg]:max-h-[60vh] [&>svg]:max-w-full">{!! $previewSvg !!}</div>
            <div class="flex justify-end"><flux:modal.close><flux:button variant="primary">{{ __('Close') }}</flux:button></flux:modal.close></div>
        </div>
    </flux:modal>
</div>
