<?php

use App\Labels\Examples\CalibrationLabel;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\User;
use Livewire\Livewire;

test('template management requires administrator access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.label-templates'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.label-templates')
        ->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.label-templates'))
        ->assertOk()
        ->assertSee('Templates &amp; revisions', false);
});

test('administrators can create and update a template identity', function () {
    $this->actingAs(User::factory()->admin()->create());
    $stock = LabelStock::factory()->create();

    Livewire::test('pages::admin.label-templates')
        ->set('labelStockId', $stock->id)
        ->set('code', 'CMM023')
        ->set('name', 'Component identification label')
        ->set('slug', 'cmm023')
        ->set('description', 'Primary component label')
        ->call('saveTemplate')
        ->assertHasNoErrors();

    $template = LabelTemplate::query()->sole();

    Livewire::test('pages::admin.label-templates')
        ->call('editTemplate', $template->id)
        ->set('name', 'Component identification')
        ->set('isActive', false)
        ->call('saveTemplate')
        ->assertHasNoErrors();

    expect($template->refresh()->label_stock_id)->toBe($stock->id)
        ->and($template->code)->toBe('CMM023')
        ->and($template->name)->toBe('Component identification')
        ->and($template->is_active)->toBeFalse();
});

test('template id and stock become immutable after the first revision', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = LabelTemplate::factory()->create(['code' => 'CMM023']);
    $otherStock = LabelStock::factory()->create();
    LabelTemplateVersion::factory()->for($template)->create();

    Livewire::test('pages::admin.label-templates')
        ->call('editTemplate', $template->id)
        ->set('code', 'CMM024')
        ->set('labelStockId', $otherStock->id)
        ->call('saveTemplate')
        ->assertHasErrors(['code', 'labelStockId']);

    expect($template->refresh()->code)->toBe('CMM023')
        ->and($template->label_stock_id)->not->toBe($otherStock->id);
});

test('administrators create sequential immutable revisions attributed to themselves', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $template = LabelTemplate::factory()->create();
    LabelTemplateVersion::factory()->for($template)->create(['version' => 1]);

    Livewire::test('pages::admin.label-templates')
        ->call('createRevision', $template->id)
        ->set('revisionCode', '0826')
        ->set('definitionJson', json_encode(CalibrationLabel::definition(), JSON_THROW_ON_ERROR))
        ->call('saveRevision')
        ->assertHasNoErrors();

    $revision = $template->versions()->where('version', 2)->sole();

    expect($revision->revision_code)->toBe('0826')
        ->and($revision->created_by)->toBe($admin->id)
        ->and($revision->published_at)->toBeNull()
        ->and($revision->definition->toArray())->toEqual(CalibrationLabel::definition()->toArray());
});

test('new revisions can copy an existing definition', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = LabelTemplate::factory()->create();
    $source = LabelTemplateVersion::factory()->for($template)->create([
        'definition' => CalibrationLabel::definition(),
    ]);

    Livewire::test('pages::admin.label-templates')
        ->call('createRevision', $template->id, $source->id)
        ->assertSet('definitionJson', json_encode(CalibrationLabel::definition(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});

test('revision definitions and date codes are validated', function (string $revisionCode, string $definition, string $errorField) {
    $this->actingAs(User::factory()->admin()->create());
    $template = LabelTemplate::factory()->create();

    Livewire::test('pages::admin.label-templates')
        ->call('createRevision', $template->id)
        ->set('revisionCode', $revisionCode)
        ->set('definitionJson', $definition)
        ->call('saveRevision')
        ->assertHasErrors([$errorField]);
})->with([
    'invalid month' => ['1326', '{"elements":[],"fields":{}}', 'revisionCode'],
    'invalid json' => ['0826', '{nope}', 'definitionJson'],
    'invalid definition' => ['0826', '{"elements":[]}', 'definitionJson'],
]);

test('administrators can publish a draft revision', function () {
    $this->actingAs(User::factory()->admin()->create());
    $revision = LabelTemplateVersion::factory()->create();

    Livewire::test('pages::admin.label-templates')
        ->call('publishRevision', $revision->id)
        ->assertHasNoErrors();

    expect($revision->refresh()->published_at)->not->toBeNull();
});

test('administrators can render a revision preview with generated field values', function () {
    $this->actingAs(User::factory()->admin()->create());
    $stock = LabelStock::factory()->create([
        'width' => CalibrationLabel::WIDTH_IN_MILLIMETERS,
        'height' => CalibrationLabel::HEIGHT_IN_MILLIMETERS,
    ]);
    $template = LabelTemplate::factory()->for($stock)->create(['code' => 'CMM023']);
    $revision = LabelTemplateVersion::factory()->for($template)->create([
        'revision_code' => '0826',
        'definition' => CalibrationLabel::definition(),
    ]);

    Livewire::test('pages::admin.label-templates')
        ->call('previewRevision', $revision->id)
        ->assertSet('previewTitle', 'CMM023 (0826) · v1')
        ->assertSee('CMM023 (0826) | PREVIEW', false)
        ->assertSee('data-preview="approximate"', false);
});
