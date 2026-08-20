<?php

use App\Labels\Examples\CalibrationLabel;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\User;

test('the print catalog exposes published templates printers and pin enabled operators', function () {
    $requester = User::factory()->sharedPrintStation()->create();
    $this->actingAs($requester);
    $stock = LabelStock::factory()->create([
        'name' => 'Four by Two',
        'width' => 101.6,
        'height' => 50.8,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create([
        'code' => 'CMM023',
        'name' => 'Product Label',
    ]);
    LabelTemplateVersion::factory()->for($template)->published()->create([
        'version' => 1,
        'revision_code' => '0826',
        'definition' => CalibrationLabel::definition(),
    ]);
    $onlineBridge = PrintBridge::factory()->create(['last_seen_at' => now()]);
    $printer = Printer::factory()->for($onlineBridge)->for($stock)->create([
        'name' => 'Packing Zebra',
        'location' => 'Packing',
        'dpi' => 300,
    ]);
    $operator = catalogUserWithPin('Amanda Operator', '4826');

    $response = $this->getJson('/print/catalog');

    $response->assertOk()
        ->assertJsonPath('templates.0.id', $template->id)
        ->assertJsonPath('templates.0.code', 'CMM023')
        ->assertJsonPath('templates.0.version.revision_code', '0826')
        ->assertJsonPath('templates.0.stock.width_mm', 101.6)
        ->assertJsonPath('templates.0.fields.part_number.required', true)
        ->assertJsonPath('printers.0.id', $printer->id)
        ->assertJsonPath('printers.0.language', 'zpl')
        ->assertJsonPath('printers.0.label_stock_id', $stock->id)
        ->assertJsonPath('printers.0.dpi', 300)
        ->assertJsonPath('printers.0.online', true)
        ->assertJsonPath('operators.0', ['id' => $operator->id, 'name' => 'Amanda Operator']);
    $response->assertJsonPath('authorization.requires_operator_pin', true);

    expect($response->json('operators.0'))->not->toHaveKeys(['email', 'pin_hash']);
});

test('the catalog excludes unusable templates printers and users', function () {
    $this->actingAs(User::factory()->sharedPrintStation()->create());
    $activeTemplate = catalogTemplate('Active Label');
    catalogTemplate('Inactive Label')->update(['is_active' => false]);
    $draftTemplate = LabelTemplate::factory()->create(['name' => 'Draft Label']);
    LabelTemplateVersion::factory()->for($draftTemplate)->create();
    $inactiveStockTemplate = catalogTemplate('Inactive Stock Label');
    $inactiveStockTemplate->labelStock->update(['is_active' => false]);

    $activeBridge = PrintBridge::factory()->create();
    $availablePrinter = Printer::factory()->for($activeBridge)->for($activeTemplate->labelStock)->create(['name' => 'Available Printer']);
    Printer::factory()->for($activeBridge)->inactive()->create(['name' => 'Inactive Printer']);
    Printer::factory()->for($activeBridge)->create([
        'name' => 'Unconfigured Printer',
        'label_stock_id' => null,
    ]);
    $inactiveStock = LabelStock::factory()->inactive()->create();
    Printer::factory()->for($activeBridge)->for($inactiveStock)->create(['name' => 'Inactive Stock Printer']);
    $inactiveBridge = PrintBridge::factory()->inactive()->create();
    Printer::factory()->for($inactiveBridge)->create(['name' => 'Orphaned Printer']);

    $operator = catalogUserWithPin('Configured Operator', '4826');
    User::factory()->create(['name' => 'No PIN User']);

    $response = $this->getJson('/print/catalog')->assertOk();

    expect(collect($response->json('templates'))->pluck('id')->all())->toBe([$activeTemplate->id])
        ->and(collect($response->json('printers'))->pluck('id')->all())->toBe([$availablePrinter->id])
        ->and(collect($response->json('operators'))->pluck('id')->all())->toBe([$operator->id]);
});

test('an active bridge remains available for local simulation when its heartbeat is stale', function () {
    $this->actingAs(User::factory()->create());
    $bridge = PrintBridge::factory()->create(['last_seen_at' => now()->subMinutes(3)]);
    Printer::factory()->for($bridge)->create();

    $this->getJson('/print/catalog')
        ->assertOk()
        ->assertJsonPath('printers.0.online', true)
        ->assertJsonPath('authorization.requires_operator_pin', false)
        ->assertJsonPath('operators', []);
});

test('production catalogs show a printer offline when its bridge heartbeat is stale', function () {
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->actingAs(User::factory()->create());
        $bridge = PrintBridge::factory()->create(['last_seen_at' => now()->subMinutes(3)]);
        Printer::factory()->for($bridge)->create();

        $this->getJson('/print/catalog')
            ->assertOk()
            ->assertJsonPath('printers.0.online', false);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
});

test('guests cannot access the print catalog', function () {
    $this->getJson('/print/catalog')->assertUnauthorized();
});

function catalogTemplate(string $name): LabelTemplate
{
    $template = LabelTemplate::factory()->create(['name' => $name]);
    LabelTemplateVersion::factory()->for($template)->published()->create([
        'definition' => CalibrationLabel::definition(),
    ]);

    return $template;
}

function catalogUserWithPin(string $name, string $pin): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignPin($pin);
    $user->save();

    return $user;
}
