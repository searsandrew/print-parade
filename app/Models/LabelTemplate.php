<?php

namespace App\Models;

use Database\Factories\LabelTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $label_stock_id
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label_stock_id', 'code', 'name', 'slug', 'description', 'is_active'])]
class LabelTemplate extends Model
{
    /** @use HasFactory<LabelTemplateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LabelStock, $this>
     */
    public function labelStock(): BelongsTo
    {
        return $this->belongsTo(LabelStock::class);
    }

    /**
     * @return HasMany<LabelTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(LabelTemplateVersion::class);
    }

    /**
     * @return HasOne<LabelTemplateVersion, $this>
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(LabelTemplateVersion::class)
            ->ofMany(['version' => 'max'], function ($query): void {
                $query->whereNotNull('published_at');
            });
    }
}
