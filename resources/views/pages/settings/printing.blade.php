<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Printing settings')] class extends Component {
    public bool $requiresPrintOperatorPin = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->requiresPrintOperatorPin = $user->requires_print_operator_pin;
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

        <flux:callout class="mt-8" icon="identification">
            {{ __('Employee identities and PINs are managed by an administrator, separately from login accounts.') }}
        </flux:callout>
    </x-pages::settings.layout>
</section>
