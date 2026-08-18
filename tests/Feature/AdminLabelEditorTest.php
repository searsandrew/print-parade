<?php

use App\Labels\Definitions\LabelDefinition;
use App\Labels\Enums\LabelElementType;
use App\Labels\Examples\CalibrationLabel;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateDraft;
use App\Models\LabelTemplateVersion;
use App\Models\User;
use Livewire\Livewire;

test('the label designer requires administrator access', function () {
    $template = designerTemplate();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.label-template-editor', $template))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.label-template-editor', $template))
        ->assertOk()
        ->assertSee('Label designer');
});

test('a new design starts with the required job identifier selected', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->assertSet('selectedIndex', 0)
        ->assertSet('canvasRotation', 0)
        ->assertSet('elements.0.type', LabelElementType::JobIdentifier->value)
        ->assertSet('fields', [])
        ->assertSee('aspect-ratio:', false)
        ->assertSee('maxWidth:', false);
});

test('the designer lists code-owned datasource fields and lookup inputs', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->assertSee('Data sources')
        ->assertSee('Part description')
        ->assertSee('netsuite.part_description')
        ->assertSee('netsuite.upc')
        ->assertSee('Looks up by: part_number');
});

test('the designer can use a finished-label orientation independent of media feed', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('changeCanvasRotation', 90)
        ->assertSet('canvasRotation', 90)
        ->assertSet('canvasWidth', (float) $template->labelStock->height)
        ->assertSet('canvasHeight', (float) $template->labelStock->width)
        ->set('revisionCode', '0826')
        ->call('saveRevision')
        ->assertHasNoErrors();

    $version = $template->versions()->sole();

    expect($version->definition->toArray()['canvas_rotation'])->toBe(90)
        ->and($version->schema_version)->toBe(LabelDefinition::SCHEMA_VERSION);
});

test('an editor revision can begin from an existing immutable definition', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();
    $source = LabelTemplateVersion::factory()->for($template)->create([
        'definition' => CalibrationLabel::definition(),
    ]);

    Livewire::test('pages::admin.label-editor', [
        'labelTemplate' => $template,
        'labelTemplateVersion' => $source,
    ])
        ->assertSet('sourceVersionId', $source->id)
        ->assertSet('elements', CalibrationLabel::definition()->toArray()['elements'])
        ->assertSet('fields', CalibrationLabel::definition()->toArray()['fields']);
});

test('a revision source must belong to the edited template', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();
    $otherVersion = LabelTemplateVersion::factory()->create();

    Livewire::test('pages::admin.label-editor', [
        'labelTemplate' => $template,
        'labelTemplateVersion' => $otherVersion,
    ])->assertStatus(404);
});

test('administrators can add select reorder and remove supported elements', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('addElement', LabelElementType::Text->value)
        ->assertSet('selectedIndex', 1)
        ->assertSet('elements.1.type', LabelElementType::Text->value)
        ->set('elements.1.value', 'Part {{ part_number }}')
        ->call('moveSelectedElement', -1)
        ->assertSet('selectedIndex', 0)
        ->assertSet('elements.0.value', 'Part {{ part_number }}')
        ->call('removeSelectedElement')
        ->assertCount('elements', 1)
        ->assertSet('elements.0.type', LabelElementType::JobIdentifier->value);
});

test('canvas interactions update element geometry and keep it within the stock', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('addElement', 'text')
        ->call('updateElementGeometry', 1, 90.0, 40.0, 30.0, 20.0)
        ->assertSet('selectedIndex', 1)
        ->assertSet('elements.1.x', round((float) $template->labelStock->width - 30.0, 3))
        ->assertSet('elements.1.y', round((float) $template->labelStock->height - 20.0, 3))
        ->assertSet('elements.1.width', 30.0)
        ->assertSet('elements.1.height', 20.0);
});

test('quarter turn elements move within their rotated bounding box', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('addElement', 'text')
        ->call('changeElementRotation', 1, 90)
        ->set('elements.1.style.font_size', 6.0)
        ->call('updateElementGeometry', 1, 90.0, 0.0, 50.0, 8.0)
        ->assertSet('elements.1.rotation', 90)
        ->assertSet('elements.1.style.font_size', 6.0)
        ->assertSet('elements.1.x', 90.0)
        ->assertSet('elements.1.y', 0.0)
        ->assertSet('elements.0.rotation', 0)
        ->assertSet('elements.0.style.font_size', 2.0)
        ->assertSee('translate(-50%, -50%) rotate(90deg)', false)
        ->assertSee('Resize element');
});

test('the designer creates supported barcode elements with usable defaults', function (string $symbology, string $value) {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    $component = Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('addElement', 'barcode', $symbology)
        ->assertSet('elements.1.type', LabelElementType::Barcode->value)
        ->assertSet('elements.1.symbology', $symbology)
        ->assertSet('elements.1.value', $value)
        ->assertSee('data:image/svg+xml;base64,', false)
        ->call('preview')
        ->assertHasNoErrors();

    if ($symbology === 'qr_code') {
        $component->assertSet('elements.1.error_correction', 'medium');
    } else {
        $component->assertSet('elements.1.show_text', true)
            ->assertSet('elements.1.module_width', 0.25);
    }
})->with([
    'Code 128' => ['code128', 'ABC-123'],
    'UPC-A' => ['upc_a', '036000291452'],
    'QR code' => ['qr_code', 'https://example.com'],
]);

test('barcode widths snap to whole dot module breakpoints', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('addElement', 'barcode', 'upc_a')
        ->call('setBarcodeModuleWidth', 1, 0.375)
        ->assertSet('elements.1.module_width', 0.375)
        ->assertSet('elements.1.width', 42.417)
        ->assertSee('036000291452');
});

test('administrators can define fields for mixed element content', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->set('fieldName', 'part_number')
        ->set('fieldLabel', 'Part number')
        ->set('fieldType', 'string')
        ->set('fieldRequired', true)
        ->call('saveField')
        ->assertHasNoErrors()
        ->assertSet('fields.part_number.label', 'Part number')
        ->assertSet('fields.part_number.required', true);
});

test('field creation normalizes numeric element values received from browser controls', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->set('elements.0.rotation', '0')
        ->set('elements.0.x', '5.000')
        ->set('elements.0.style.font_size', '2.000')
        ->set('fieldName', 'part_number')
        ->set('fieldLabel', 'Part Number')
        ->call('saveField')
        ->assertHasNoErrors()
        ->assertSet('elements.0.rotation', 0)
        ->assertSet('elements.0.x', 5.0)
        ->assertSet('fields.part_number.label', 'Part Number');
});

test('a field cannot be removed while an element references it', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->set('fieldName', 'part_number')
        ->set('fieldLabel', 'Part number')
        ->call('saveField')
        ->call('addElement', 'text')
        ->set('elements.1.value', 'Replacement for {{ part_number }}')
        ->call('removeField', 'part_number')
        ->assertHasErrors(['fields'])
        ->assertSet('fields.part_number.label', 'Part number');
});

test('the editor produces a renderer validated svg preview', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('data-preview="approximate"', false)
        ->assertSee($template->code.' ('.now()->format('my').') | PREVIEW', false);
});

test('the editor rejects elements that extend beyond the stock', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->set('elements.0.x', 100)
        ->call('preview')
        ->assertHasErrors(['editor']);
});

test('removing job traceability requires explicit acknowledgement', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = designerTemplate();

    $component = Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('removeSelectedElement')
        ->call('saveRevision')
        ->assertHasErrors(['editor']);

    expect($template->versions()->count())->toBe(0);

    $component
        ->set('acknowledgeMissingJobIdentifier', true)
        ->call('saveRevision')
        ->assertHasNoErrors();

    expect($template->versions()->sole()->definition->toArray()['elements'])->toBe([]);
});

test('visual revisions use sequential versions and preserve their creator', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $template = designerTemplate();
    LabelTemplateVersion::factory()->for($template)->create(['version' => 1]);

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->set('revisionCode', '0826')
        ->call('saveRevision')
        ->assertHasNoErrors();

    $revision = $template->versions()->where('version', 2)->sole();

    expect($revision->revision_code)->toBe('0826')
        ->and($revision->created_by)->toBe($admin->id)
        ->and($revision->published_at)->toBeNull();
});

test('an administrator can save and recover a private working draft', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('addElement', 'text')
        ->set('elements.1.value', 'Replacement for {{ part_number }}')
        ->set('revisionCode', '0926')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft = LabelTemplateDraft::query()->sole();

    expect($draft->user_id)->toBe($admin->id)
        ->and($draft->revision_code)->toBe('0926')
        ->and($draft->definition['elements'][1]['value'])->toBe('Replacement for {{ part_number }}');

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->assertSet('draftId', $draft->id)
        ->assertSet('revisionCode', '0926')
        ->assertSet('elements.1.value', 'Replacement for {{ part_number }}');
});

test('working drafts are private to the administrator who created them', function () {
    $template = designerTemplate();
    $owner = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    LabelTemplateDraft::factory()->for($template)->for($owner)->create();

    $this->actingAs($otherAdmin);

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->assertSet('draftId', null)
        ->assertSet('elements.0.type', LabelElementType::JobIdentifier->value);
});

test('a visual revision can be created and published directly from the designer', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $template = designerTemplate();

    Livewire::test('pages::admin.label-editor', ['labelTemplate' => $template])
        ->call('saveDraft')
        ->call('saveRevision', true)
        ->assertHasNoErrors();

    $version = $template->versions()->sole();

    expect($version->published_at)->not->toBeNull()
        ->and(LabelTemplateDraft::query()->count())->toBe(0)
        ->and($template->fresh()->publishedVersion->is($version))->toBeTrue();
});

function designerTemplate(): LabelTemplate
{
    $stock = LabelStock::factory()->create([
        'width' => CalibrationLabel::WIDTH_IN_MILLIMETERS,
        'height' => CalibrationLabel::HEIGHT_IN_MILLIMETERS,
    ]);

    return LabelTemplate::factory()->for($stock)->create(['code' => 'CMM023']);
}
