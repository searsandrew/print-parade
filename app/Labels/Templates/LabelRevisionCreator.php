<?php

namespace App\Labels\Templates;

use App\Labels\Definitions\LabelDefinition;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LabelRevisionCreator
{
    public function create(
        LabelTemplate $template,
        string $revisionCode,
        LabelDefinition $definition,
        User $creator,
    ): LabelTemplateVersion {
        return DB::transaction(function () use ($template, $revisionCode, $definition, $creator): LabelTemplateVersion {
            LabelTemplate::query()
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $latestVersion = LabelTemplateVersion::query()
                ->where('label_template_id', $template->getKey())
                ->max('version');

            return LabelTemplateVersion::query()->create([
                'label_template_id' => $template->getKey(),
                'version' => ((int) $latestVersion) + 1,
                'revision_code' => $revisionCode,
                'schema_version' => 1,
                'definition' => $definition,
                'created_by' => $creator->getKey(),
                'published_at' => null,
            ]);
        });
    }
}
