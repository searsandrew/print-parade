<?php

use App\Labels\Examples\CalibrationLabel;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\User;

test('an authenticated user can preview a label template version as svg', function () {
    $version = previewableLabelTemplateVersion();

    $response = $this
        ->actingAs(User::factory()->create())
        ->postJson(route('label-template-versions.preview', $version), [
            'values' => CalibrationLabel::sampleInput(),
            'dpi' => 300,
        ]);

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('width="101.6mm" height="50.8mm"', false)
        ->assertSee('data-dpi="300"', false)
        ->assertSee('PART: ABC-123', false)
        ->assertSee('CMM023 (0826) | PREVIEW', false);
});

test('preview validation reports unresolved label input', function () {
    $version = previewableLabelTemplateVersion();

    $response = $this
        ->actingAs(User::factory()->create())
        ->postJson(route('label-template-versions.preview', $version), [
            'values' => [],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('preview')
        ->assertJsonPath('errors.preview.0', 'Field part_number is required.');
});

test('preview dpi must be supported', function () {
    $version = previewableLabelTemplateVersion();

    $response = $this
        ->actingAs(User::factory()->create())
        ->postJson(route('label-template-versions.preview', $version), [
            'values' => CalibrationLabel::sampleInput(),
            'dpi' => 600,
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dpi');
});

test('guests cannot preview label template versions', function () {
    $version = previewableLabelTemplateVersion();

    $this->post(route('label-template-versions.preview', $version), [
        'values' => CalibrationLabel::sampleInput(),
    ])->assertRedirect(route('login'));
});

function previewableLabelTemplateVersion(): LabelTemplateVersion
{
    $stock = LabelStock::factory()->create([
        'width' => CalibrationLabel::WIDTH_IN_MILLIMETERS,
        'height' => CalibrationLabel::HEIGHT_IN_MILLIMETERS,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create([
        'code' => 'CMM023',
    ]);

    return LabelTemplateVersion::factory()->for($template)->create([
        'revision_code' => '0826',
        'definition' => CalibrationLabel::definition(),
    ]);
}
