<x-layouts::auth :title="__('Log in')">
    <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-87.5">
        <x-auth-header :title="__('Sign in to Print Parade')" :description="__('Use your Choice Manufactured Parts Microsoft account to continue.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:button variant="primary" icon="building-office-2" :href="route('auth.microsoft.redirect')" class="w-full">
            {{ __('Continue with Microsoft') }}
        </flux:button>
    </div>
</x-layouts::auth>
