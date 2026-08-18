<?php

use App\Labels\Definitions\LabelDefinition;
use App\Labels\Definitions\LabelDefinitionResolver;
use App\Labels\Enums\LabelElementType;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\SvgRenderer;
use App\Labels\Templates\LabelRevisionCreator;
use App\Models\LabelTemplate;
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

    public string $revisionCode = '';

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

        if ($labelTemplateVersion === null) {
            $this->elements = [$this->newJobIdentifierElement()];
            $this->fields = [];
        } else {
            $definition = $labelTemplateVersion->definition->toArray();
            $this->elements = $definition['elements'];
            $this->fields = $definition['fields'];
        }

        $this->selectedIndex = $this->elements === [] ? null : 0;
    }

    #[Computed]
    public function template(): LabelTemplate
    {
        return LabelTemplate::query()->with('labelStock')->findOrFail($this->templateId);
    }

    public function addElement(string $type): void
    {
        $elementType = LabelElementType::tryFrom($type);

        $element = match ($elementType) {
            LabelElementType::Text => $this->newTextElement(),
            LabelElementType::JobIdentifier => $this->newJobIdentifierElement(),
            LabelElementType::Line => $this->newLineElement(),
            LabelElementType::Rectangle => $this->newRectangleElement(),
            default => null,
        };

        abort_if($element === null, 422);

        $this->elements[] = $element;
        $this->selectedIndex = array_key_last($this->elements);
        $this->acknowledgeMissingJobIdentifier = false;
    }

    public function selectElement(int $index): void
    {
        abort_unless(array_key_exists($index, $this->elements), 404);
        $this->selectedIndex = $index;
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

        try {
            LabelDefinition::fromArray(['elements' => $this->elements, 'fields' => [...$this->fields, $validated['fieldName'] => $field]]);
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

        $revisionCreator->create($this->template, $this->revisionCode, $definition, Auth::user());
        Flux::toast(variant: 'success', text: __('Immutable revision created.'));
        $this->redirectRoute('admin.label-templates', navigate: true);
    }

    private function validatedDefinition(LabelDefinitionResolver $resolver, SvgRenderer $renderer): LabelDefinition
    {
        if (! collect($this->elements)->contains(fn (array $element): bool => $element['type'] === LabelElementType::JobIdentifier->value)
            && ! $this->acknowledgeMissingJobIdentifier) {
            throw new \InvalidArgumentException('The job identifier was removed. Acknowledge that choice before creating the revision.');
        }

        $definition = LabelDefinition::fromArray([
            'elements' => array_values($this->elements),
            'fields' => $this->fields,
        ]);
        $resolved = $resolver->resolve($definition, $this->sampleValues(), ['job_identifier' => "{$this->template->code} ({$this->revisionCode}) | PREVIEW"]);
        $renderer->render($resolved, LabelRenderContext::fromStock($this->template->labelStock, 203));

        return $definition;
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

    /** @return array<string, mixed> */
    private function newTextElement(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'type' => LabelElementType::Text->value,
            'x' => 5.0,
            'y' => 5.0,
            'width' => max(0.1, min(50.0, (float) $this->template->labelStock->width - 10.0)),
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
        $element['y'] = max(0.0, (float) $this->template->labelStock->height - 8.0);
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
            'width' => max(0.1, min(50.0, (float) $this->template->labelStock->width - 10.0)),
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
            'width' => max(0.1, min(40.0, (float) $this->template->labelStock->width - 10.0)),
            'height' => max(0.1, min(20.0, (float) $this->template->labelStock->height - 10.0)),
            'rotation' => 0,
            'stroke_width' => 0.25,
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-5">
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
            <flux:input wire:model="revisionCode" :label="__('Revision (MMYY)')" class="w-36" maxlength="4" />
            <flux:button icon="eye" wire:click="preview">{{ __('Rendered preview') }}</flux:button>
            <flux:button variant="primary" icon="check" wire:click="saveRevision" wire:confirm="{{ __('Create this immutable revision? Future changes will require another revision.') }}">{{ __('Create revision') }}</flux:button>
        </div>
    </div>

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
                        <span>{{ match ($element['type']) { 'text' => __('Text'), 'job_identifier' => __('Job identifier'), 'line' => __('Line'), 'rectangle' => __('Rectangle'), default => $element['type'] } }}</span>
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
        </flux:card>

        <flux:card class="flex items-center justify-center overflow-auto bg-zinc-100 p-8! dark:bg-zinc-900">
            <div
                class="relative w-full max-w-[900px] overflow-hidden border border-zinc-300 bg-white shadow-xl dark:border-zinc-600"
                style="aspect-ratio: {{ (float) $this->template->labelStock->width }} / {{ (float) $this->template->labelStock->height }}; container-type: size;"
                aria-label="{{ __('Label canvas') }}"
            >
                @foreach ($elements as $index => $element)
                    @php
                        $left = ((float) $element['x'] / (float) $this->template->labelStock->width) * 100;
                        $top = ((float) $element['y'] / (float) $this->template->labelStock->height) * 100;
                        $width = ((float) $element['width'] / (float) $this->template->labelStock->width) * 100;
                        $height = ((float) $element['height'] / (float) $this->template->labelStock->height) * 100;
                    @endphp
                    <button
                        type="button"
                        wire:key="canvas-element-{{ $element['id'] }}"
                        wire:click="selectElement({{ $index }})"
                        @class(['absolute overflow-hidden text-left', 'ring-2 ring-blue-500 ring-offset-1' => $selectedIndex === $index])
                        style="left: {{ $left }}%; top: {{ $top }}%; width: {{ $width }}%; height: {{ max($height, 0.3) }}%; transform: rotate({{ $element['rotation'] }}deg); transform-origin: top left;"
                    >
                        @if ($element['type'] === 'rectangle')
                            <span class="block size-full border border-black"></span>
                        @elseif ($element['type'] === 'line')
                            <span class="block w-full border-t border-black"></span>
                        @else
                            <span class="block size-full text-black" style="font-family: {{ ($element['style']['font_family'] ?? 'sans') === 'monospace' ? 'monospace' : 'sans-serif' }}; font-weight: {{ $element['style']['font_weight'] ?? 'normal' }}; font-size: clamp(8px, {{ ((float) ($element['style']['font_size'] ?? 3) / (float) $this->template->labelStock->height) * 100 }}cqh, 36px); text-align: {{ $element['style']['alignment'] ?? 'left' }};">
                                {{ $element['type'] === 'job_identifier' ? $this->template->code.' ('.$revisionCode.') | JOB ID' : ($element['value'] ?? '') }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        </flux:card>

        <flux:card class="space-y-5">
            <flux:heading size="sm">{{ __('Properties') }}</flux:heading>
            @if ($selectedIndex === null)
                <flux:text>{{ __('Select an element on the canvas or in the element list.') }}</flux:text>
            @else
                @php($selected = $elements[$selectedIndex])
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.x" :label="__('X (mm)')" type="number" min="0" step="0.1" />
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.y" :label="__('Y (mm)')" type="number" min="0" step="0.1" />
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.width" :label="__('Width (mm)')" type="number" min="0.1" step="0.1" />
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.height" :label="__('Height (mm)')" type="number" min="0.1" step="0.1" />
                </div>
                <flux:select wire:model.live.number="elements.{{ $selectedIndex }}.rotation" :label="__('Rotation')">
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
                @else
                    <flux:input wire:model.live.number.debounce.250ms="elements.{{ $selectedIndex }}.stroke_width" :label="__('Stroke width (mm)')" type="number" min="0.01" step="0.05" />
                @endif
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
            <div><flux:heading size="lg">{{ $this->template->code }} ({{ $revisionCode }})</flux:heading><flux:text>{{ __('Renderer-validated preview at 203 DPI with sample values.') }}</flux:text></div>
            <div class="flex min-h-80 items-center justify-center overflow-auto rounded-lg bg-zinc-100 p-6 dark:bg-zinc-800 [&>svg]:max-h-[60vh] [&>svg]:max-w-full">{!! $previewSvg !!}</div>
            <div class="flex justify-end"><flux:modal.close><flux:button variant="primary">{{ __('Close') }}</flux:button></flux:modal.close></div>
        </div>
    </flux:modal>
</div>
