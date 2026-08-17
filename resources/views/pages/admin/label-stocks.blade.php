<?php

use App\Labels\Enums\LabelMediaType;
use App\Models\LabelStock;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Label stocks')] class extends Component {
    public ?int $stockId = null;

    public string $name = '';

    public string $sku = '';

    public string $width = '';

    public string $height = '';

    public string $mediaType = 'gap';

    public string $description = '';

    public bool $isActive = true;

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return Collection<int, LabelStock> */
    #[Computed]
    public function stocks(): Collection
    {
        return LabelStock::query()
            ->withCount('labelTemplates')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function dimensionsInInches(): ?string
    {
        if (! is_numeric($this->width) || ! is_numeric($this->height)) {
            return null;
        }

        $width = (float) $this->width;
        $height = (float) $this->height;

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return number_format($width / 25.4, 3).' × '.number_format($height / 25.4, 3).' inches';
    }

    public function createStock(): void
    {
        $this->resetStockForm();
        Flux::modal('stock-form')->show();
    }

    public function editStock(int $stockId): void
    {
        $stock = LabelStock::query()->findOrFail($stockId);

        $this->stockId = $stock->id;
        $this->name = $stock->name;
        $this->sku = $stock->sku ?? '';
        $this->width = $stock->width;
        $this->height = $stock->height;
        $this->mediaType = $stock->media_type->value;
        $this->description = $stock->description ?? '';
        $this->isActive = $stock->is_active;
        $this->resetValidation();

        Flux::modal('stock-form')->show();
    }

    public function saveStock(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('label_stocks', 'sku')->ignore($this->stockId)],
            'width' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:99999.999'],
            'height' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:99999.999'],
            'mediaType' => ['required', Rule::enum(LabelMediaType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'isActive' => ['required', 'boolean'],
        ]);

        $stock = $this->stockId === null
            ? new LabelStock()
            : LabelStock::query()->findOrFail($this->stockId);

        $stock->fill([
            'name' => $validated['name'],
            'sku' => filled($validated['sku']) ? $validated['sku'] : null,
            'width' => number_format((float) $validated['width'], 3, '.', ''),
            'height' => number_format((float) $validated['height'], 3, '.', ''),
            'media_type' => $validated['mediaType'],
            'description' => filled($validated['description']) ? $validated['description'] : null,
            'is_active' => $validated['isActive'],
        ])->save();

        $this->resetStockForm();
        unset($this->stocks);
        Flux::modal('stock-form')->close();
        Flux::toast(variant: 'success', text: __('Label stock saved.'));
    }

    private function resetStockForm(): void
    {
        $this->reset('stockId', 'name', 'sku', 'width', 'height', 'description');
        $this->mediaType = LabelMediaType::Gap->value;
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.dashboard')" wire:navigate>{{ __('Administration') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Label stocks') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-4">{{ __('Label stocks') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Define the physical media that label templates can be designed for and printed on.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="createStock">{{ __('Add label stock') }}</flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->stocks as $stock)
            <flux:card class="flex min-h-72 flex-col gap-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-wrap gap-2">
                        <flux:badge :color="$stock->is_active ? 'green' : 'zinc'" size="sm">
                            {{ $stock->is_active ? __('Active') : __('Disabled') }}
                        </flux:badge>
                        <flux:badge color="zinc" size="sm">
                            {{ match ($stock->media_type) {
                                LabelMediaType::Gap => __('Gap'),
                                LabelMediaType::Continuous => __('Continuous'),
                                LabelMediaType::BlackMark => __('Black mark'),
                            } }}
                        </flux:badge>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editStock({{ $stock->id }})">{{ __('Edit') }}</flux:button>
                </div>

                <div class="flex min-h-28 items-center justify-center rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800">
                    <div
                        class="max-h-24 max-w-full border border-zinc-300 bg-white shadow-sm dark:border-zinc-600"
                        style="aspect-ratio: {{ (float) $stock->width }} / {{ (float) $stock->height }}; width: {{ min(100, max(25, $stock->widthInInches() * 24)) }}%;"
                        aria-hidden="true"
                    ></div>
                </div>

                <div>
                    <flux:heading size="lg">{{ $stock->name }}</flux:heading>
                    @if ($stock->sku)
                        <flux:text class="mt-1 font-mono text-xs">{{ $stock->sku }}</flux:text>
                    @endif
                    <flux:text class="mt-2">
                        {{ number_format((float) $stock->width, 3) }} × {{ number_format((float) $stock->height, 3) }} mm
                        <span class="text-zinc-400">·</span>
                        {{ number_format($stock->widthInInches(), 3) }} × {{ number_format($stock->heightInInches(), 3) }} in
                    </flux:text>
                </div>

                @if ($stock->description)
                    <flux:text>{{ $stock->description }}</flux:text>
                @endif

                <flux:text class="mt-auto text-sm">
                    {{ trans_choice('{0} No templates|{1} :count template|[2,*] :count templates', $stock->label_templates_count, ['count' => $stock->label_templates_count]) }}
                </flux:text>
            </flux:card>
        @empty
            <flux:callout class="md:col-span-2 xl:col-span-3" icon="rectangle-stack" heading="{{ __('No label stocks configured') }}">
                {{ __('Add the physical label size you plan to design for first. A common Zebra shipping label is 101.6 × 152.4 mm (4 × 6 inches).') }}
            </flux:callout>
        @endforelse
    </div>

    <flux:modal name="stock-form" class="md:w-xl">
        <form wire:submit="saveStock" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $stockId ? __('Edit label stock') : __('Add label stock') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Dimensions are stored in millimeters and converted to printer dots at render time.') }}</flux:text>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" placeholder="4 × 2 Thermal Label" required />
                <flux:input wire:model="sku" :label="__('Stock SKU')" placeholder="LBL-4X2" />
                <flux:input wire:model.live.debounce.300ms="width" :label="__('Width (mm)')" type="number" min="0.001" max="99999.999" step="0.001" placeholder="101.600" required />
                <flux:input wire:model.live.debounce.300ms="height" :label="__('Height (mm)')" type="number" min="0.001" max="99999.999" step="0.001" placeholder="50.800" required />
            </div>

            @if ($this->dimensionsInInches)
                <flux:callout icon="arrows-pointing-out">{{ __('Approximately :dimensions', ['dimensions' => $this->dimensionsInInches]) }}</flux:callout>
            @endif

            <flux:select wire:model="mediaType" :label="__('Media sensing')" required>
                <flux:select.option value="gap">{{ __('Gap between labels') }}</flux:select.option>
                <flux:select.option value="continuous">{{ __('Continuous media') }}</flux:select.option>
                <flux:select.option value="black_mark">{{ __('Black mark') }}</flux:select.option>
            </flux:select>

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" placeholder="Optional notes about the material, adhesive, color, or intended use." />
            <flux:switch wire:model="isActive" :label="__('Stock is active')" :description="__('Disabled stocks and their templates are hidden from the print station.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save label stock') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
