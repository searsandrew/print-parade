<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Printing settings')] class extends Component {
    public bool $requiresPrintOperatorPin = false;

    public bool $hasPrintPin = false;

    public string $pin = '';

    public string $pin_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->requiresPrintOperatorPin = $user->requires_print_operator_pin;
        $this->hasPrintPin = $user->pin_hash !== null;
    }

    public function updatePrintMode(): void
    {
        $validated = $this->validate([
            'requiresPrintOperatorPin' => ['required', 'boolean'],
        ]);

        Auth::user()->update([
            'requires_print_operator_pin' => $validated['requiresPrintOperatorPin'],
        ]);

        Flux::toast(variant: 'success', text: __('Printing mode updated.'));
    }

    public function updatePin(): void
    {
        $validated = $this->validate([
            'pin' => ['required', 'string', 'regex:/\A\d{4,8}\z/', 'confirmed'],
        ]);
        $user = Auth::user();
        $user->assignPin($validated['pin']);
        $user->save();

        $this->reset('pin', 'pin_confirmation');
        $this->hasPrintPin = true;

        Flux::toast(variant: 'success', text: __('Print PIN updated.'));
    }

    public function removePin(): void
    {
        $user = Auth::user();
        $user->removePin();
        $user->save();

        $this->reset('pin', 'pin_confirmation');
        $this->hasPrintPin = false;

        Flux::toast(variant: 'success', text: __('Print PIN removed.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Printing settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Printing')" :subheading="__('Configure how this account authorizes print jobs')">
        <form wire:submit="updatePrintMode" class="my-6 space-y-6">
            <flux:callout icon="computer-desktop">
                Personal accounts use the logged-in user as the operator. Shared scanner or workstation accounts should require employees to select their name and enter a PIN.
            </flux:callout>

            <flux:switch
                wire:model="requiresPrintOperatorPin"
                :label="__('Require operator selection and PIN')"
                :description="__('Enable this for shared warehouse, scanner, or production-room accounts.')"
            />

            <flux:button variant="primary" type="submit" data-test="update-print-mode-button">
                {{ __('Save printing mode') }}
            </flux:button>
        </form>

        <flux:separator />

        <section class="mt-8 space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Operator PIN') }}</flux:heading>
                <flux:subheading>
                    {{ $hasPrintPin ? __('Your account has a print PIN configured.') : __('Add a PIN if this person should appear in shared-station operator lists.') }}
                </flux:subheading>
            </div>

            <form wire:submit="updatePin" class="space-y-5">
                <flux:input wire:model="pin" :label="__('New PIN')" type="password" inputmode="numeric" minlength="4" maxlength="8" autocomplete="new-password" viewable required />
                <flux:input wire:model="pin_confirmation" :label="__('Confirm PIN')" type="password" inputmode="numeric" minlength="4" maxlength="8" autocomplete="new-password" viewable required />

                <div class="flex flex-wrap gap-3">
                    <flux:button variant="primary" type="submit" data-test="update-print-pin-button">
                        {{ $hasPrintPin ? __('Replace PIN') : __('Set PIN') }}
                    </flux:button>

                    @if ($hasPrintPin)
                        <flux:button variant="danger" type="button" wire:click="removePin" wire:confirm="{{ __('Remove your print PIN?') }}" data-test="remove-print-pin-button">
                            {{ __('Remove PIN') }}
                        </flux:button>
                    @endif
                </div>
            </form>
        </section>
    </x-pages::settings.layout>
</section>
