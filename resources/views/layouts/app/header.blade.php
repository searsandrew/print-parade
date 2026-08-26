<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        @php
            $user = auth()->user();
            $homeRoute = $user->is_admin ? route('admin.dashboard') : route('print.station');
        @endphp

        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="me-2 lg:hidden" icon="bars-2" inset="left" />
            <x-app-logo :href="$homeRoute" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                @if ($user->is_admin)
                    <flux:navbar.item icon="squares-2x2" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>{{ __('Overview') }}</flux:navbar.item>
                    <flux:navbar.item icon="printer" :href="route('admin.printers')" :current="request()->routeIs('admin.printers')" wire:navigate>{{ __('Printers') }}</flux:navbar.item>
                    <flux:navbar.item icon="rectangle-stack" :href="route('admin.label-stocks')" :current="request()->routeIs('admin.label-stocks')" wire:navigate>{{ __('Stocks') }}</flux:navbar.item>
                    <flux:navbar.item icon="document-text" :href="route('admin.label-templates')" :current="request()->routeIs('admin.label-template*')" wire:navigate>{{ __('Templates') }}</flux:navbar.item>
                    <flux:navbar.item icon="queue-list" :href="route('admin.print-jobs')" :current="request()->routeIs('admin.print-jobs')" wire:navigate>{{ __('Jobs') }}</flux:navbar.item>
                    <flux:navbar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:navbar.item>
                    <flux:navbar.item icon="identification" :href="route('admin.employees')" :current="request()->routeIs('admin.employees')" wire:navigate>{{ __('Employees') }}</flux:navbar.item>
                    <flux:navbar.item icon="arrow-top-right-on-square" :href="route('print.station')" :current="request()->routeIs('print.*')">{{ __('Print labels') }}</flux:navbar.item>
                @else
                    <flux:navbar.item icon="printer" :href="route('print.station')" :current="request()->routeIs('print.*')" wire:navigate>{{ __('Print labels') }}</flux:navbar.item>
                @endif
            </flux:navbar>

            <flux:spacer />

            <flux:dropdown position="bottom" align="end">
                <flux:profile :initials="$user->initials()" icon-trailing="chevron-down" />
                <flux:menu>
                    <div class="px-2 py-1.5">
                        <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                        <flux:text class="truncate text-sm">{{ $user->email }}</flux:text>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>{{ __('Manage profile') }}</flux:menu.item>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">{{ __('Log out') }}</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:sidebar collapsible="mobile" sticky class="border-e border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" :href="$homeRoute" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @if ($user->is_admin)
                    <flux:sidebar.item icon="squares-2x2" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>{{ __('Overview') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="printer" :href="route('admin.printers')" wire:navigate>{{ __('Printers') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="rectangle-stack" :href="route('admin.label-stocks')" wire:navigate>{{ __('Label stocks') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('admin.label-templates')" wire:navigate>{{ __('Templates') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="queue-list" :href="route('admin.print-jobs')" wire:navigate>{{ __('Print jobs') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('admin.users')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="identification" :href="route('admin.employees')" wire:navigate>{{ __('Employees') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="arrow-top-right-on-square" :href="route('print.station')">{{ __('Print labels') }}</flux:sidebar.item>
                @else
                    <flux:sidebar.item icon="printer" :href="route('print.station')" wire:navigate>{{ __('Print labels') }}</flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />
            <flux:sidebar.nav>
                <flux:sidebar.item icon="user-circle" :href="route('profile.edit')" wire:navigate>{{ __('Manage profile') }}</flux:sidebar.item>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:sidebar.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">{{ __('Log out') }}</flux:sidebar.item>
                </form>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group><flux:toast /></flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
