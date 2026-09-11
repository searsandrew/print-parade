<?php

use App\Labels\Enums\LabelMediaType;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('label stock management requires administrator access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.label-stocks'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.label-stocks')
        ->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.label-stocks'))
        ->assertOk()
        ->assertSee('Label stocks');
});

test('administrators can create a label stock', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.label-stocks')
        ->set('name', '4 × 2 Thermal Label')
        ->set('sku', 'LBL-4X2')
        ->set('width', '101.6')
        ->set('height', '50.8')
        ->set('mediaType', LabelMediaType::Gap->value)
        ->set('description', 'Permanent adhesive thermal stock')
        ->call('saveStock')
        ->assertHasNoErrors();

    $stock = LabelStock::query()->sole();

    expect($stock->name)->toBe('4 × 2 Thermal Label')
        ->and($stock->sku)->toBe('LBL-4X2')
        ->and($stock->width)->toBe('101.600')
        ->and($stock->height)->toBe('50.800')
        ->and($stock->media_type)->toBe(LabelMediaType::Gap)
        ->and($stock->is_active)->toBeTrue();
});

test('administrators can attach preprinted artwork to a label stock', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.label-stocks')
        ->set('name', 'Branded component label')
        ->set('width', '101.6')
        ->set('height', '88.9')
        ->set('previewArtwork', UploadedFile::fake()->image('stock.png', 800, 700))
        ->call('saveStock')
        ->assertHasNoErrors();

    $stock = LabelStock::query()->sole();

    expect($stock->preview_artwork_path)->toStartWith('label-stock-artwork/')
        ->and($stock->previewArtworkDataUri())->toStartWith('data:image/png;base64,');
    Storage::disk('public')->assertExists($stock->preview_artwork_path);
});

test('administrators can replace and remove preprinted stock artwork', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());
    Storage::disk('public')->put('label-stock-artwork/old.png', 'old');
    $stock = LabelStock::factory()->create(['preview_artwork_path' => 'label-stock-artwork/old.png']);

    Livewire::test('pages::admin.label-stocks')
        ->call('editStock', $stock->id)
        ->set('previewArtwork', UploadedFile::fake()->image('new.png'))
        ->call('saveStock')
        ->assertHasNoErrors();

    Storage::disk('public')->assertMissing('label-stock-artwork/old.png');
    $newPath = $stock->refresh()->preview_artwork_path;
    Storage::disk('public')->assertExists($newPath);

    Livewire::test('pages::admin.label-stocks')
        ->call('editStock', $stock->id)
        ->set('removePreviewArtwork', true)
        ->call('saveStock')
        ->assertHasNoErrors();

    expect($stock->refresh()->preview_artwork_path)->toBeNull();
    Storage::disk('public')->assertMissing($newPath);
});

test('administrators can update and disable a referenced label stock', function () {
    $this->actingAs(User::factory()->admin()->create());
    $stock = LabelStock::factory()->create();
    LabelTemplate::factory()->for($stock)->create();

    Livewire::test('pages::admin.label-stocks')
        ->call('editStock', $stock->id)
        ->set('name', 'Archived 4 × 2 Stock')
        ->set('sku', '')
        ->set('isActive', false)
        ->call('saveStock')
        ->assertHasNoErrors()
        ->assertSee('1 template');

    expect($stock->refresh()->name)->toBe('Archived 4 × 2 Stock')
        ->and($stock->sku)->toBeNull()
        ->and($stock->is_active)->toBeFalse()
        ->and($stock->labelTemplates)->toHaveCount(1);
});

test('stock dimensions must be positive with no more than three decimal places', function (string $width, string $height) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.label-stocks')
        ->set('name', 'Invalid Stock')
        ->set('width', $width)
        ->set('height', $height)
        ->call('saveStock')
        ->assertHasErrors(['width', 'height']);
})->with([
    'zero' => ['0', '0'],
    'negative' => ['-1', '-2'],
    'too precise' => ['25.4001', '50.8001'],
]);

test('stock skus must be unique when provided', function () {
    $this->actingAs(User::factory()->admin()->create());
    LabelStock::factory()->create(['sku' => 'LBL-4X2']);

    Livewire::test('pages::admin.label-stocks')
        ->set('name', 'Duplicate SKU Stock')
        ->set('sku', 'LBL-4X2')
        ->set('width', '101.600')
        ->set('height', '50.800')
        ->call('saveStock')
        ->assertHasErrors(['sku' => 'unique']);
});

test('the form presents entered dimensions in inches', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.label-stocks')
        ->set('width', '101.6')
        ->set('height', '50.8')
        ->assertSee('4.000 × 2.000 inches');
});
