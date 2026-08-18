<?php

use App\Labels\Enums\LabelElementType;
use App\Labels\Examples\CalibrationLabel;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
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
        ->assertSet('elements.0.type', LabelElementType::JobIdentifier->value)
        ->assertSet('fields', []);
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

function designerTemplate(): LabelTemplate
{
    $stock = LabelStock::factory()->create([
        'width' => CalibrationLabel::WIDTH_IN_MILLIMETERS,
        'height' => CalibrationLabel::HEIGHT_IN_MILLIMETERS,
    ]);

    return LabelTemplate::factory()->for($stock)->create(['code' => 'CMM023']);
}
