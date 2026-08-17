<?php

namespace App\Models;

use App\Labels\Definitions\LabelDefinition;
use App\Labels\Definitions\LabelDefinitionCast;
use Database\Factories\LabelTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $label_template_id
 * @property int $version
 * @property string $revision_code
 * @property int $schema_version
 * @property LabelDefinition $definition
 * @property int|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label_template_id', 'version', 'revision_code', 'schema_version', 'definition', 'created_by', 'published_at'])]
class LabelTemplateVersion extends Model
{
    /** @use HasFactory<LabelTemplateVersionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => LabelDefinitionCast::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LabelTemplate, $this>
     */
    public function labelTemplate(): BelongsTo
    {
        return $this->belongsTo(LabelTemplate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PrintJob, $this>
     */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
