<?php

use App\Labels\DataSources\NetSuite\NetSuiteItemRepository;
use App\Labels\Definitions\LabelDefinition;
use App\Labels\Enums\PrintJobStatus;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateDraft;
use App\Models\LabelTemplateVersion;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

final class TestPrintNetSuiteItems implements NetSuiteItemRepository
{
    public function findByPartNumber(string $partNumber): ?array
    {
        return [
            'part_number' => $partNumber,
            'part_description' => 'Live description for '.$partNumber,
            'upc' => '036000291452',
        ];
    }
}

test('the administrative test print page requires administrator access', function () {
    $template = testPrintTemplate();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.label-template-test-print', $template))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.label-template-test-print', $template))
        ->assertOk()
        ->assertSee('Administrative test');
});

test('an administrator can resolve live values and queue a quantity one test print', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $this->app->bind(NetSuiteItemRepository::class, TestPrintNetSuiteItems::class);
    $template = testPrintTemplate();
    $bridge = PrintBridge::factory()->create(['last_seen_at' => null]);
    $printer = Printer::factory()->for($bridge)->for($template->labelStock)->create();

    $component = Livewire::test('pages::admin.label-test-print', ['labelTemplate' => $template]);

    expect($component->instance()->printerIsAvailable($printer))->toBeTrue();

    $component
        ->assertSet('values.part_number', '')
        ->set('values.part_number', 'CMM023')
        ->call('resolvePreview')
        ->assertHasNoErrors()
        ->assertSet('resolvedValues.netsuite.part_description', 'Live description for CMM023')
        ->assertSet('resolvedValues.netsuite.upc', '036000291452')
        ->assertSee('Live description for CMM023')
        ->set('printerId', $printer->id)
        ->call('queueTestPrint')
        ->assertHasNoErrors()
        ->assertSee('Test print queued');

    $job = PrintJob::query()->sole();

    expect($job->quantity)->toBe(1)
        ->and($job->is_test)->toBeTrue()
        ->and($job->revision_code_snapshot)->toBe('0826')
        ->and($job->status)->toBe(PrintJobStatus::Queued)
        ->and($job->submitted_by)->toBe($admin->id)
        ->and($job->executed_by)->toBe($admin->id)
        ->and($job->input_values)->toBe(['part_number' => 'CMM023'])
        ->and($job->resolved_values['netsuite']['part_description'])->toBe('Live description for CMM023');
});

test('an administrator can queue the saved draft as an immutable configuration test', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $this->app->bind(NetSuiteItemRepository::class, TestPrintNetSuiteItems::class);
    $template = testPrintTemplate();
    $bridge = PrintBridge::factory()->create(['last_seen_at' => null]);
    $printer = Printer::factory()->for($bridge)->for($template->labelStock)->create();
    $definition = $template->publishedVersion->definition->toArray();
    $definition['elements'][0]['value'] = 'DRAFT {{ part_number }} · {{ netsuite.part_description }}';
    LabelTemplateDraft::factory()->for($template)->for($admin)->create([
        'revision_code' => '0926',
        'definition' => $definition,
    ]);

    Livewire::withQueryParams(['draft' => 1])
        ->test('pages::admin.label-test-print', ['labelTemplate' => $template])
        ->assertSet('draftId', fn (?int $draftId): bool => $draftId !== null)
        ->assertSee('saved draft configuration')
        ->set('values.part_number', 'CMM023')
        ->call('resolvePreview')
        ->assertHasNoErrors()
        ->assertSet('previewSvg', fn (string $svg): bool => str_contains($svg, 'DRAFT CMM023'))
        ->set('printerId', $printer->id)
        ->call('queueTestPrint')
        ->assertHasNoErrors();

    $job = PrintJob::query()->sole();

    expect($job->is_test)->toBeTrue()
        ->and($job->quantity)->toBe(1)
        ->and($job->revision_code_snapshot)->toBe('0926')
        ->and($job->definition_snapshot->toArray()['elements'][0]['value'])->toStartWith('DRAFT')
        ->and($job->output_payload)->toContain('DRAFT CMM023')
        ->and($job->output_payload)->toContain('0926 TEST');
});

function testPrintTemplate(): LabelTemplate
{
    $stock = LabelStock::factory()->create([
        'width' => 101.6,
        'height' => 50.8,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create([
        'code' => 'CMM023',
        'name' => 'Component label',
    ]);
    LabelTemplateVersion::factory()->for($template)->published()->create([
        'revision_code' => '0826',
        'definition' => LabelDefinition::fromArray([
            'elements' => [
                [
                    'id' => (string) Str::ulid(),
                    'type' => 'text',
                    'x' => 5,
                    'y' => 5,
                    'width' => 80,
                    'height' => 8,
                    'rotation' => 0,
                    'value' => '{{ part_number }} · {{ netsuite.part_description }}',
                    'style' => ['font_family' => 'sans', 'font_size' => 4, 'font_weight' => 'normal', 'alignment' => 'left'],
                ],
                [
                    'id' => (string) Str::ulid(),
                    'type' => 'barcode',
                    'x' => 5,
                    'y' => 15,
                    'width' => 38,
                    'height' => 20,
                    'rotation' => 0,
                    'symbology' => 'upc_a',
                    'value' => '{{ netsuite.upc }}',
                    'show_text' => true,
                    'module_width' => 0.25,
                    'bar_height' => 14,
                ],
                [
                    'id' => (string) Str::ulid(),
                    'type' => 'job_identifier',
                    'x' => 5,
                    'y' => 40,
                    'width' => 80,
                    'height' => 4,
                    'rotation' => 0,
                    'style' => ['font_family' => 'sans', 'font_size' => 2, 'font_weight' => 'normal', 'alignment' => 'left'],
                ],
            ],
            'fields' => [
                'part_number' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Part number',
                ],
            ],
        ]),
    ]);

    return $template;
}
