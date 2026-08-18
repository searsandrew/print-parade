<?php

namespace App\Models;

use Database\Factories\LabelTemplateDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $label_template_id
 * @property int $user_id
 * @property string $revision_code
 * @property int $schema_version
 * @property array<string, mixed> $definition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label_template_id', 'user_id', 'revision_code', 'schema_version', 'definition'])]
class LabelTemplateDraft extends Model
{
    /** @use HasFactory<LabelTemplateDraftFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    /** @return BelongsTo<LabelTemplate, $this> */
    public function labelTemplate(): BelongsTo
    {
        return $this->belongsTo(LabelTemplate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
