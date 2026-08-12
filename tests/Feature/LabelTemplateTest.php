<?php

use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a label template belongs to a stock and has versioned definitions', function () {
    $stock = LabelStock::factory()->create();
    $template = LabelTemplate::factory()->for($stock)->create([
        'code' => 'CMM023',
    ]);
    $creator = User::factory()->create();
    $definition = [
        'elements' => [
            ['type' => 'text', 'x' => 6, 'y' => 5, 'value' => 'Part: {{ part_number }}'],
        ],
        'fields' => [
            'part_number' => ['type' => 'string', 'required' => true],
        ],
    ];

    $version = LabelTemplateVersion::factory()->for($template)->for($creator, 'creator')->create([
        'revision_code' => '0826',
        'definition' => $definition,
    ]);

    expect($template->labelStock->is($stock))->toBeTrue()
        ->and($template->code)->toBe('CMM023')
        ->and($template->versions->first()->is($version))->toBeTrue()
        ->and($version->revision_code)->toBe('0826')
        ->and($version->labelTemplate->is($template))->toBeTrue()
        ->and($version->creator->is($creator))->toBeTrue()
        ->and($version->definition)->toBe($definition);
});

test('a template exposes its highest published version', function () {
    $template = LabelTemplate::factory()->create();

    LabelTemplateVersion::factory()->for($template)->published()->create(['version' => 1]);
    $latestPublishedVersion = LabelTemplateVersion::factory()->for($template)->published()->create(['version' => 2]);
    LabelTemplateVersion::factory()->for($template)->create(['version' => 3]);

    expect($template->publishedVersion->is($latestPublishedVersion))->toBeTrue();
});

test('version numbers are unique within a template', function () {
    $template = LabelTemplate::factory()->create();

    LabelTemplateVersion::factory()->for($template)->create(['version' => 1]);

    expect(fn () => LabelTemplateVersion::factory()->for($template)->create(['version' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('template codes are unique', function () {
    LabelTemplate::factory()->create(['code' => 'CMM023']);

    expect(fn () => LabelTemplate::factory()->create(['code' => 'CMM023']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a template may have multiple internal versions with the same revision code', function () {
    $template = LabelTemplate::factory()->create();

    $firstVersion = LabelTemplateVersion::factory()->for($template)->create([
        'version' => 1,
        'revision_code' => '0826',
    ]);
    $secondVersion = LabelTemplateVersion::factory()->for($template)->create([
        'version' => 2,
        'revision_code' => '0826',
    ]);

    expect($firstVersion->revision_code)->toBe($secondVersion->revision_code)
        ->and($firstVersion->version)->not->toBe($secondVersion->version);
});

test('different templates may use the same version number', function () {
    $firstVersion = LabelTemplateVersion::factory()->create(['version' => 1]);
    $secondVersion = LabelTemplateVersion::factory()->create(['version' => 1]);

    expect($firstVersion->label_template_id)->not->toBe($secondVersion->label_template_id);
});

test('a stock cannot be deleted while a template uses it', function () {
    $template = LabelTemplate::factory()->create();

    expect(fn () => $template->labelStock->delete())->toThrow(QueryException::class);
});
