<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Print labels'])
        <style>[x-cloak] { display: none !important; }</style>
    </head>
    <body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased">
        <main x-data="printStation" x-init="init" class="mx-auto min-h-screen w-full max-w-3xl px-4 py-5 sm:px-6 sm:py-8">
            <header class="mb-6 flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-zinc-500">Print Parade</p>
                    <h1 class="mt-1 text-3xl font-bold tracking-tight sm:text-4xl">Print labels</h1>
                </div>
                <div class="rounded-full bg-white px-4 py-2 text-sm font-medium shadow-sm ring-1 ring-zinc-200">
                    Production
                </div>
            </header>

            <div x-show="loading" class="rounded-2xl bg-white p-8 text-center text-lg shadow-sm ring-1 ring-zinc-200">
                Loading labels and printers…
            </div>

            <form x-cloak x-show="!loading" x-on:submit.prevent="submit" class="space-y-5">
                <section class="space-y-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200 sm:p-7">
                    <div>
                        <label for="template" class="mb-2 block text-base font-semibold">Label</label>
                        <select id="template" x-model="templateId" x-on:change="templateChanged" required class="min-h-14 w-full rounded-xl border-zinc-300 bg-white px-4 text-lg shadow-sm focus:border-zinc-900 focus:ring-zinc-900">
                            <option value="">Select a label</option>
                            <template x-for="template in catalog.templates" :key="template.id">
                                <option :value="template.id" x-text="`${template.code} — ${template.name}`"></option>
                            </template>
                        </select>
                        <p x-show="selectedTemplate" class="mt-2 text-sm text-zinc-500">
                            <span x-text="selectedTemplate?.stock.name"></span>
                            · revision <span x-text="selectedTemplate?.version.revision_code"></span>
                        </p>
                    </div>

                    <div x-show="selectedTemplate" class="grid gap-5 sm:grid-cols-2">
                        <template x-for="([name, field]) in Object.entries(selectedTemplate?.fields ?? {})" :key="name">
                            <div :class="field.type === 'boolean' ? 'flex items-center gap-3 pt-7' : ''">
                                <template x-if="field.type !== 'boolean'">
                                    <div>
                                        <label :for="`field-${name}`" class="mb-2 block text-base font-semibold">
                                            <span x-text="field.label"></span><span x-show="field.required" class="text-red-600"> *</span>
                                        </label>
                                        <input :id="`field-${name}`" :type="inputType(field)" :inputmode="field.format === 'upc_a' ? 'numeric' : null" x-model="values[name]" :required="field.required" class="min-h-14 w-full rounded-xl border-zinc-300 px-4 text-lg shadow-sm focus:border-zinc-900 focus:ring-zinc-900">
                                    </div>
                                </template>
                                <template x-if="field.type === 'boolean'">
                                    <label class="flex min-h-14 items-center gap-3 text-base font-semibold">
                                        <input type="checkbox" x-model="values[name]" class="size-6 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900">
                                        <span x-text="field.label"></span>
                                    </label>
                                </template>
                            </div>
                        </template>
                    </div>
                </section>

                <section class="grid gap-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200 sm:grid-cols-2 sm:p-7">
                    <div>
                        <label for="printer" class="mb-2 block text-base font-semibold">Printer</label>
                        <select id="printer" x-model="printerId" required class="min-h-14 w-full rounded-xl border-zinc-300 bg-white px-4 text-lg shadow-sm focus:border-zinc-900 focus:ring-zinc-900">
                            <option value="">Select a printer</option>
                            <template x-for="printer in availablePrinters" :key="printer.id">
                                <option :value="printer.id" :disabled="!printer.online" x-text="`${printer.name}${printer.location ? ` — ${printer.location}` : ''}${printer.online ? '' : ' (offline)'}`"></option>
                            </template>
                        </select>
                        <p x-show="selectedTemplate && availablePrinters.length === 0" class="mt-2 text-sm font-medium text-amber-700">No active printer currently has this label stock loaded.</p>
                    </div>
                    <div>
                        <label for="quantity" class="mb-2 block text-base font-semibold">Quantity</label>
                        <flux:input id="quantity" type="number" inputmode="numeric" min="1" max="10000" x-model="quantity" required class:input="min-h-14 text-lg" />
                    </div>
                    <div x-show="requiresOperatorPin">
                        <label for="operator" class="mb-2 block text-base font-semibold">Your name</label>
                        <select id="operator" x-model="userId" x-bind:required="requiresOperatorPin" x-bind:disabled="!requiresOperatorPin" class="min-h-14 w-full rounded-xl border-zinc-300 bg-white px-4 text-lg shadow-sm focus:border-zinc-900 focus:ring-zinc-900">
                            <option value="">Select your name</option>
                            <template x-for="operator in catalog.operators" :key="operator.id">
                                <option :value="operator.id" x-text="operator.name"></option>
                            </template>
                        </select>
                    </div>
                    <div x-show="requiresOperatorPin">
                        <label for="pin" class="mb-2 block text-base font-semibold">PIN</label>
                        <flux:input id="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" autocomplete="off" x-model="pin" x-bind:required="requiresOperatorPin" x-bind:disabled="!requiresOperatorPin" class:input="min-h-14 text-center text-2xl tracking-[0.35em]" />
                    </div>
                    <div x-show="!requiresOperatorPin" class="sm:col-span-2">
                        <flux:callout icon="user-circle" color="zinc">
                            Printing as <strong x-text="catalog.authorization.authenticated_user?.name"></strong>. Your login will be recorded with this job.
                        </flux:callout>
                    </div>
                </section>

                <div x-show="error" x-text="error" role="alert" class="rounded-xl bg-red-50 p-4 font-medium text-red-800 ring-1 ring-red-200"></div>
                <div x-show="confirmation" role="status" class="rounded-xl bg-emerald-50 p-5 text-emerald-900 ring-1 ring-emerald-200">
                    <p class="text-lg font-bold">Print job queued</p>
                    <p class="mt-1">Job <span class="font-mono font-semibold" x-text="confirmation?.job_identifier"></span> · <span x-text="confirmation?.quantity"></span> labels</p>
                </div>

                <flux:button type="submit" variant="primary" x-bind:disabled="submitting" class="min-h-16 w-full rounded-2xl! text-xl! font-bold! shadow-lg">
                    <span x-show="!submitting">Queue print job</span>
                    <span x-show="submitting">Submitting…</span>
                </flux:button>
            </form>
        </main>

        @fluxScripts
    </body>
</html>
