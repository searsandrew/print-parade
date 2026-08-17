<?php

use App\Labels\Definitions\LabelDefinition;
use App\Labels\Rendering\LabelPreviewService;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Templates & revisions')] class extends Component {
    public ?int $templateId = null;

    public int|string|null $labelStockId = null;

    public string $code = '';

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public bool $isActive = true;

    public ?int $revisionTemplateId = null;

    public string $revisionCode = '';

    public string $definitionJson = '';

    public string $previewSvg = '';

    public string $previewTitle = '';

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return Collection<int, LabelTemplate> */
    #[Computed]
    public function templates(): Collection
    {
        return LabelTemplate::query()
            ->with([
                'labelStock',
                'versions' => fn ($query) => $query->with('creator')->orderByDesc('version'),
            ])
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();
    }

    /** @return Collection<int, LabelStock> */
    #[Computed]
    public function stocks(): Collection
    {
        return LabelStock::query()->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function createTemplate(): void
    {
        $this->resetTemplateForm();
        Flux::modal('template-form')->show();
    }

    public function editTemplate(int $templateId): void
    {
        $template = LabelTemplate::query()->withCount('versions')->findOrFail($templateId);

        $this->templateId = $template->id;
        $this->labelStockId = $template->label_stock_id;
        $this->code = $template->code;
        $this->name = $template->name;
        $this->slug = $template->slug;
        $this->description = $template->description ?? '';
        $this->isActive = $template->is_active;
        $this->resetValidation();

        Flux::modal('template-form')->show();
    }

    public function updatedCode(): void
    {
        if ($this->templateId === null && $this->slug === '') {
            $this->slug = Str::slug($this->code);
        }
    }

    public function saveTemplate(): void
    {
        $validated = $this->validate([
            'labelStockId' => ['required', 'integer', 'exists:label_stocks,id'],
            'code' => ['required', 'string', 'max:255', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/', Rule::unique('label_templates', 'code')->ignore($this->templateId)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('label_templates', 'slug')->ignore($this->templateId)],
            'description' => ['nullable', 'string', 'max:5000'],
            'isActive' => ['required', 'boolean'],
        ]);

        $template = $this->templateId === null
            ? new LabelTemplate()
            : LabelTemplate::query()->withCount('versions')->findOrFail($this->templateId);

        if ($template->exists && $template->versions_count > 0) {
            if ($template->code !== $validated['code']) {
                $this->addError('code', __('The template ID cannot change after its first revision.'));
            }

            if ($template->label_stock_id !== (int) $validated['labelStockId']) {
                $this->addError('labelStockId', __('The label stock cannot change after the first revision.'));
            }

            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        $template->fill([
            'label_stock_id' => $validated['labelStockId'],
            'code' => $validated['code'],
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => filled($validated['description']) ? $validated['description'] : null,
            'is_active' => $validated['isActive'],
        ])->save();

        $this->resetTemplateForm();
        unset($this->templates);
        Flux::modal('template-form')->close();
        Flux::toast(variant: 'success', text: __('Label template saved.'));
    }

    public function createRevision(int $templateId, ?int $sourceVersionId = null): void
    {
        $template = LabelTemplate::query()->findOrFail($templateId);
        $definition = ['elements' => [], 'fields' => []];

        if ($sourceVersionId !== null) {
            $source = $template->versions()->findOrFail($sourceVersionId);
            $definition = $source->definition->toArray();
        }

        $this->revisionTemplateId = $template->id;
        $this->revisionCode = now()->format('my');
        $this->definitionJson = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->resetValidation();

        Flux::modal('revision-form')->show();
    }

    public function saveRevision(): void
    {
        $validated = $this->validate([
            'revisionTemplateId' => ['required', 'integer', 'exists:label_templates,id'],
            'revisionCode' => ['required', 'string', 'regex:/\A(?:0[1-9]|1[0-2])\d{2}\z/'],
            'definitionJson' => ['required', 'string'],
        ], [
            'revisionCode.regex' => __('The revision code must use MMYY format, such as 0826.'),
        ]);

        try {
            $decoded = json_decode($validated['definitionJson'], true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('The label definition must be a JSON object.');
            }

            $definition = LabelDefinition::fromArray($decoded);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            $this->addError('definitionJson', $exception->getMessage());

            return;
        }

        DB::transaction(function () use ($validated, $definition): void {
            LabelTemplate::query()
                ->whereKey($validated['revisionTemplateId'])
                ->lockForUpdate()
                ->firstOrFail();

            $latestVersion = LabelTemplateVersion::query()
                ->where('label_template_id', $validated['revisionTemplateId'])
                ->max('version');

            LabelTemplateVersion::query()->create([
                'label_template_id' => $validated['revisionTemplateId'],
                'version' => ((int) $latestVersion) + 1,
                'revision_code' => $validated['revisionCode'],
                'schema_version' => 1,
                'definition' => $definition,
                'created_by' => Auth::id(),
                'published_at' => null,
            ]);
        });

        $this->resetRevisionForm();
        unset($this->templates);
        Flux::modal('revision-form')->close();
        Flux::toast(variant: 'success', text: __('Immutable revision created.'));
    }

    public function publishRevision(int $versionId): void
    {
        $version = LabelTemplateVersion::query()->findOrFail($versionId);

        if ($version->published_at === null) {
            $version->forceFill(['published_at' => now()])->save();
            unset($this->templates);
        }

        Flux::toast(variant: 'success', text: __('Revision published.'));
    }

    public function previewRevision(int $versionId, LabelPreviewService $previewService): void
    {
        $version = LabelTemplateVersion::query()->with('labelTemplate.labelStock')->findOrFail($versionId);
        $values = [];

        foreach ($version->definition->toArray()['fields'] as $name => $field) {
            $values[$name] = match (true) {
                ($field['format'] ?? null) === 'upc_a' => '036000291452',
                $field['type'] === 'number' => 123,
                $field['type'] === 'boolean' => true,
                $field['type'] === 'date' => now()->toDateString(),
                default => $field['default'] ?? 'Sample text',
            };
        }

        try {
            $this->previewSvg = $previewService->render($version, $values);
            $this->previewTitle = "{$version->labelTemplate->code} ({$version->revision_code}) · v{$version->version}";
            Flux::modal('revision-preview')->show();
        } catch (\InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    private function resetTemplateForm(): void
    {
        $this->reset('templateId', 'labelStockId', 'code', 'name', 'slug', 'description');
        $this->isActive = true;
        $this->resetValidation();
    }

    private function resetRevisionForm(): void
    {
        $this->reset('revisionTemplateId', 'revisionCode', 'definitionJson');
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.dashboard')" wire:navigate>{{ __('Administration') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Templates & revisions') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-4">{{ __('Templates & revisions') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage stable label identities and their immutable printer-neutral definitions.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="createTemplate" :disabled="$this->stocks->isEmpty()">{{ __('Add template') }}</flux:button>
    </div>

    @if ($this->stocks->isEmpty())
        <flux:callout variant="warning" icon="rectangle-stack" heading="{{ __('Create a label stock first') }}">
            {{ __('Every template needs physical dimensions before its elements can be positioned.') }}
            <x-slot name="actions">
                <flux:button :href="route('admin.label-stocks')" wire:navigate>{{ __('Manage label stocks') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @forelse ($this->templates as $template)
        @php($latestVersion = $template->versions->first())
        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $template->code }} · {{ $template->name }}</flux:heading>
                        <flux:badge :color="$template->is_active ? 'green' : 'zinc'" size="sm">{{ $template->is_active ? __('Active') : __('Disabled') }}</flux:badge>
                    </div>
                    <flux:text class="mt-1">{{ $template->labelStock->name }} · {{ number_format((float) $template->labelStock->width, 3) }} × {{ number_format((float) $template->labelStock->height, 3) }} mm</flux:text>
                    @if ($template->description)<flux:text class="mt-2">{{ $template->description }}</flux:text>@endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editTemplate({{ $template->id }})">{{ __('Edit') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="createRevision({{ $template->id }}, {{ $latestVersion?->id ?? 'null' }})">
                        {{ $latestVersion ? __('New revision') : __('First revision') }}
                    </flux:button>
                </div>
            </div>

            @if ($template->versions->isEmpty())
                <flux:callout icon="document-text">{{ __('This template has no revisions and cannot be printed yet.') }}</flux:callout>
            @else
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Internal version') }}</flux:table.column>
                            <flux:table.column>{{ __('Revision code') }}</flux:table.column>
                            <flux:table.column>{{ __('Created') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($template->versions as $version)
                                <flux:table.row :key="$version->id">
                                    <flux:table.cell>v{{ $version->version }}</flux:table.cell>
                                    <flux:table.cell><span class="font-mono">{{ $version->revision_code }}</span></flux:table.cell>
                                    <flux:table.cell>
                                        {{ $version->created_at?->format('M j, Y g:i A') }}
                                        @if ($version->creator)<div class="text-sm text-zinc-500">{{ $version->creator->name }}</div>@endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$version->published_at ? 'green' : 'amber'" size="sm">{{ $version->published_at ? __('Published') : __('Draft') }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex justify-end gap-2">
                                            <flux:button size="sm" variant="ghost" icon="eye" wire:click="previewRevision({{ $version->id }})">{{ __('Preview') }}</flux:button>
                                            @if (! $version->published_at)
                                                <flux:button size="sm" variant="primary" wire:click="publishRevision({{ $version->id }})" wire:confirm="{{ __('Publish this immutable revision? It will become available for new print jobs.') }}">{{ __('Publish') }}</flux:button>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </flux:card>
    @empty
        <flux:callout icon="document-text" heading="{{ __('No label templates configured') }}">
            {{ __('Create a stable template identity, such as CMM023, and then add its first immutable revision.') }}
        </flux:callout>
    @endforelse

    <flux:modal name="template-form" class="md:w-xl">
        <form wire:submit="saveTemplate" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $templateId ? __('Edit label template') : __('Add label template') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The template ID and stock become fixed after the first revision is created.') }}</flux:text>
            </div>
            <flux:select wire:model="labelStockId" :label="__('Label stock')" required>
                <flux:select.option value="">{{ __('Select a stock') }}</flux:select.option>
                @foreach ($this->stocks as $stock)
                    <flux:select.option :value="$stock->id">{{ $stock->name }} ({{ number_format($stock->widthInInches(), 3) }} × {{ number_format($stock->heightInInches(), 3) }} in){{ $stock->is_active ? '' : ' · Disabled' }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model.live.debounce.300ms="code" :label="__('Template ID')" placeholder="CMM023" required />
                <flux:input wire:model="name" :label="__('Name')" placeholder="Component identification label" required />
            </div>
            <flux:input wire:model="slug" :label="__('URL slug')" placeholder="cmm023" required />
            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />
            <flux:switch wire:model="isActive" :label="__('Template is active')" :description="__('Disabled templates are hidden from the print station.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save template') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="revision-form" class="md:w-3xl">
        <form wire:submit="saveRevision" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create immutable revision') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This definition cannot be edited after creation. A visual designer will replace direct JSON editing later.') }}</flux:text>
            </div>
            <flux:input wire:model="revisionCode" :label="__('Revision code (MMYY)')" placeholder="0826" maxlength="4" required />
            <flux:textarea wire:model="definitionJson" :label="__('Printer-neutral definition (JSON)')" rows="18" class="font-mono text-sm" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create revision') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="revision-preview" class="md:w-4xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $previewTitle }}</flux:heading>
                <flux:text class="mt-1">{{ __('Preview rendered at 203 DPI with generated sample values.') }}</flux:text>
            </div>
            <div class="flex min-h-80 items-center justify-center overflow-auto rounded-lg bg-zinc-100 p-6 dark:bg-zinc-800 [&>svg]:max-h-[60vh] [&>svg]:max-w-full">
                {!! $previewSvg !!}
            </div>
            <div class="flex justify-end"><flux:modal.close><flux:button variant="primary">{{ __('Close') }}</flux:button></flux:modal.close></div>
        </div>
    </flux:modal>
</div>
