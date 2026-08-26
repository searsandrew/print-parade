<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from administration', function () {
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));
});

test('non administrators cannot access administration', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('administrators can access administration', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Administration')
        ->assertSee('Bridges &amp; printers', false);
});

test('administration uses the menu bar without starter navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertSee(route('admin.printers'))
        ->assertSee(route('admin.print-jobs'))
        ->assertSee(route('print.station'))
        ->assertSee('Manage profile')
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation')
        ->assertDontSee('Dashboard');
});

test('administration reports live equipment and job health', function () {
    $admin = User::factory()->admin()->create();
    $bridge = PrintBridge::factory()->create([
        'is_active' => true,
        'last_seen_at' => now()->subMinutes(3),
    ]);
    $printer = Printer::factory()->for($bridge)->create(['is_active' => true]);
    PrintJob::factory()->for($printer)->create(['status' => PrintJobStatus::DeliveryUncertain]);

    Livewire::actingAs($admin)
        ->test('pages::admin.dashboard')
        ->assertSee('1 offline')
        ->assertSee('0 of 1 active bridges connected')
        ->assertSee('1 uncertain')
        ->assertSeeHtml('wire:poll.15s.visible');
});

test('administration reports printers online after a recent bridge heartbeat', function () {
    $admin = User::factory()->admin()->create();
    $bridge = PrintBridge::factory()->create([
        'is_active' => true,
        'last_seen_at' => now(),
    ]);
    Printer::factory()->for($bridge)->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test('pages::admin.dashboard')
        ->assertSee('1 online')
        ->assertSee('1 of 1 active bridges connected');
});
