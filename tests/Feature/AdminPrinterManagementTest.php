<?php

use App\Labels\Enums\PrinterLanguage;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\User;
use Livewire\Livewire;

test('printer management requires administrator access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.printers'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.printers')
        ->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.printers'))
        ->assertOk()
        ->assertSee('Bridges &amp; printers', false);
});

test('administrators can create a bridge and receive its token once', function () {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test('pages::admin.printers')
        ->set('bridgeName', 'Production Room PC')
        ->set('bridgeIsActive', true)
        ->call('saveBridge')
        ->assertHasNoErrors();

    $bridge = PrintBridge::query()->sole();
    $token = $component->get('issuedToken');

    expect($token)->toBeString()->not->toBeEmpty()
        ->and($bridge->name)->toBe('Production Room PC')
        ->and($bridge->token_hash)->toBe(hash('sha256', $token));
});

test('administrators can update a bridge and rotate its token', function () {
    $this->actingAs(User::factory()->admin()->create());
    $bridge = PrintBridge::factory()->create();
    $oldToken = $bridge->issueToken();

    $component = Livewire::test('pages::admin.printers')
        ->call('editBridge', $bridge->id)
        ->set('bridgeName', 'Shipping Bridge')
        ->set('bridgeIsActive', false)
        ->call('saveBridge')
        ->assertHasNoErrors()
        ->call('rotateBridgeToken', $bridge->id);

    $newToken = $component->get('issuedToken');

    expect($bridge->refresh()->name)->toBe('Shipping Bridge')
        ->and($bridge->is_active)->toBeFalse()
        ->and($newToken)->not->toBe($oldToken)
        ->and($bridge->token_hash)->toBe(hash('sha256', $newToken));
});

test('administrators can create and update a printer', function () {
    $this->actingAs(User::factory()->admin()->create());
    $bridge = PrintBridge::factory()->create();

    Livewire::test('pages::admin.printers')
        ->call('createPrinter', $bridge->id)
        ->set('printerName', 'Packing Zebra ZT411')
        ->set('printerLocation', 'Packing Station 1')
        ->set('printerLanguage', PrinterLanguage::Zpl->value)
        ->set('printerDpi', 300)
        ->set('printerIdentifier', 'packing-zebra-01')
        ->call('savePrinter')
        ->assertHasNoErrors();

    $printer = Printer::query()->sole();

    Livewire::test('pages::admin.printers')
        ->call('editPrinter', $printer->id)
        ->set('printerName', 'Packing Zebra')
        ->set('printerIsActive', false)
        ->call('savePrinter')
        ->assertHasNoErrors();

    expect($printer->refresh()->name)->toBe('Packing Zebra')
        ->and($printer->print_bridge_id)->toBe($bridge->id)
        ->and($printer->language)->toBe(PrinterLanguage::Zpl)
        ->and($printer->dpi)->toBe(300)
        ->and($printer->is_active)->toBeFalse();
});

test('bridge identifiers must be unique within a bridge', function () {
    $this->actingAs(User::factory()->admin()->create());
    $bridge = PrintBridge::factory()->create();
    Printer::factory()->for($bridge)->create(['bridge_identifier' => 'zebra-01']);

    Livewire::test('pages::admin.printers')
        ->call('createPrinter', $bridge->id)
        ->set('printerName', 'Second Zebra')
        ->set('printerIdentifier', 'zebra-01')
        ->call('savePrinter')
        ->assertHasErrors(['printerIdentifier' => 'unique']);
});
