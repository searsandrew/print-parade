<?php

use App\Labels\DataSources\LabelDataSource;
use App\Labels\DataSources\LabelDataSourceRegistry;
use App\Labels\Definitions\LabelDefinition;
use App\Labels\Enums\PrintJobStatus;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Str;

test('a published operator label flows from catalog through the bridge payload', function () {
    $this->app->instance(LabelDataSourceRegistry::class, new LabelDataSourceRegistry([
        new PublishedFlowNetSuiteDataSource,
    ]));
    $station = User::factory()->sharedPrintStation()->create();
    $operator = User::factory()->create(['name' => 'Amanda Operator']);
    $operator->assignPin('4826');
    $operator->save();
    $stock = LabelStock::factory()->create([
        'name' => '2.125 by 4 Roll',
        'width' => 53.975,
        'height' => 101.6,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create([
        'code' => 'CMM023',
        'name' => 'Product label',
    ]);
    $version = LabelTemplateVersion::factory()->for($template)->published()->create([
        'revision_code' => '0826',
        'schema_version' => LabelDefinition::SCHEMA_VERSION,
        'definition' => endToEndDefinition(),
    ]);
    $bridge = PrintBridge::factory()->create(['last_seen_at' => now()]);
    $bridgeToken = $bridge->issueToken();
    $printer = Printer::factory()->for($bridge)->for($stock)->create([
        'bridge_identifier' => 'packing-zebra-01',
        'dpi' => 203,
    ]);
    $this->actingAs($station);

    $this->getJson('/print/catalog')
        ->assertOk()
        ->assertJsonPath('templates.0.id', $template->id)
        ->assertJsonPath('templates.0.version.id', $version->id)
        ->assertJsonPath('templates.0.fields.part_number.required', true)
        ->assertJsonPath('templates.0.fields.upc.format', 'upc_a')
        ->assertJsonPath('printers.0.id', $printer->id)
        ->assertJsonPath('printers.0.label_stock_id', $stock->id)
        ->assertJsonPath('operators.0.id', $operator->id);

    $submission = $this->postJson('/print/jobs', [
        'label_template_id' => $template->id,
        'printer_id' => $printer->id,
        'user_id' => $operator->id,
        'pin' => '4826',
        'quantity' => 25,
        'values' => [
            'part_number' => 'CMM023',
            'upc' => '036000291452',
        ],
    ])->assertCreated()->assertJson([
        'status' => 'queued',
        'quantity' => 25,
    ]);

    $job = PrintJob::query()->findOrFail($submission->json('job_id'));

    expect($job->label_template_version_id)->toBe($version->id)
        ->and($job->submitted_by)->toBe($station->id)
        ->and($job->executed_by)->toBe($operator->id)
        ->and($job->output_payload)->toContain('^PW431', '^LL812', '^A0R,', '^BUR,')
        ->and($job->output_payload)->toContain('Part CMM023', 'Replacement filter assembly', '03600029145')
        ->and($job->resolved_values)->toMatchArray([
            'part_number' => 'CMM023',
            'upc' => '036000291452',
            'netsuite' => ['part_description' => 'Replacement filter assembly'],
        ])
        ->and($job->output_checksum)->toBe(hash('sha256', $job->output_payload));

    $claim = $this->withToken($bridgeToken)
        ->postJson('/api/bridge/jobs/claim')
        ->assertOk()
        ->assertJson([
            'job_id' => $job->id,
            'printer' => 'packing-zebra-01',
            'language' => 'zpl',
            'quantity' => 25,
            'payload' => $job->output_payload,
            'checksum' => $job->output_checksum,
        ]);

    $this->withToken($bridgeToken)
        ->postJson("/api/bridge/jobs/{$job->id}/complete", [
            'claim_token' => $claim->json('claim_token'),
        ])
        ->assertOk();

    expect($job->refresh()->status)->toBe(PrintJobStatus::Completed);
});

function endToEndDefinition(): LabelDefinition
{
    return LabelDefinition::fromArray([
        'canvas_rotation' => 90,
        'elements' => [
            [
                'id' => (string) Str::ulid(),
                'type' => 'text',
                'x' => 5,
                'y' => 5,
                'width' => 80,
                'height' => 8,
                'rotation' => 0,
                'value' => 'Part {{ part_number }}',
                'style' => ['font_family' => 'sans', 'font_size' => 4, 'font_weight' => 'bold', 'alignment' => 'left'],
            ],
            [
                'id' => (string) Str::ulid(),
                'type' => 'barcode',
                'x' => 5,
                'y' => 18,
                'width' => 28.267,
                'height' => 20,
                'rotation' => 0,
                'hide_when_empty' => true,
                'symbology' => 'upc_a',
                'value' => '{{ upc }}',
                'show_text' => true,
                'module_width' => 0.25,
                'bar_height' => 14,
            ],
            [
                'id' => (string) Str::ulid(),
                'type' => 'text',
                'x' => 5,
                'y' => 40,
                'width' => 80,
                'height' => 5,
                'rotation' => 0,
                'value' => '{{ netsuite.part_description }}',
                'style' => ['font_family' => 'sans', 'font_size' => 3, 'font_weight' => 'normal', 'alignment' => 'left'],
            ],
            [
                'id' => (string) Str::ulid(),
                'type' => 'job_identifier',
                'x' => 5,
                'y' => 46,
                'width' => 85,
                'height' => 4,
                'rotation' => 0,
                'style' => ['font_family' => 'sans', 'font_size' => 2, 'font_weight' => 'normal', 'alignment' => 'left'],
            ],
        ],
        'fields' => [
            'part_number' => ['type' => 'string', 'required' => true, 'label' => 'Part number'],
            'upc' => ['type' => 'string', 'format' => 'upc_a', 'required' => true, 'label' => 'UPC'],
        ],
    ]);
}

final class PublishedFlowNetSuiteDataSource implements LabelDataSource
{
    public function namespace(): string
    {
        return 'netsuite';
    }

    public function fields(): array
    {
        return [
            'part_description' => [
                'label' => 'Part description',
                'type' => 'string',
                'required' => true,
                'required_inputs' => ['part_number'],
            ],
        ];
    }

    public function resolve(array $fields, array $input): array
    {
        expect($fields)->toBe(['part_description'])
            ->and($input['part_number'])->toBe('CMM023');

        return ['part_description' => 'Replacement filter assembly'];
    }
}
