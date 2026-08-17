<?php

namespace App\Models;

use App\Labels\Enums\PrintJobStatus;
use Database\Factories\PrintJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property int $label_template_version_id
 * @property array<string, mixed> $input_values
 * @property int $quantity
 * @property PrintJobStatus $status
 * @property int|null $executed_by
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label_template_version_id', 'input_values', 'quantity'])]
class PrintJob extends Model
{
    /** @use HasFactory<PrintJobFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'input_values' => 'array',
            'quantity' => 'integer',
            'status' => PrintJobStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $printJob): void {
            $printJob->status ??= PrintJobStatus::Pending;

            if ($printJob->quantity < 1) {
                throw new LogicException('A print job quantity must be at least one.');
            }
        });
    }

    public function shortIdentifier(): string
    {
        return strtoupper(substr($this->id, -8));
    }

    public function start(User $user): void
    {
        $this->assertStatus(PrintJobStatus::Pending);

        $this->forceFill([
            'status' => PrintJobStatus::Processing,
            'executed_by' => $user->getKey(),
            'started_at' => now(),
        ])->save();
    }

    public function complete(): void
    {
        $this->assertStatus(PrintJobStatus::Processing);

        $this->forceFill([
            'status' => PrintJobStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    public function fail(string $message): void
    {
        $this->assertStatus(PrintJobStatus::Processing);

        if (trim($message) === '') {
            throw new LogicException('A failed print job must include a failure message.');
        }

        $this->forceFill([
            'status' => PrintJobStatus::Failed,
            'failed_at' => now(),
            'failure_message' => $message,
        ])->save();
    }

    public function cancel(): void
    {
        $this->assertStatus(PrintJobStatus::Pending);

        $this->forceFill([
            'status' => PrintJobStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();
    }

    /**
     * @return BelongsTo<LabelTemplateVersion, $this>
     */
    public function labelTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(LabelTemplateVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    private function assertStatus(PrintJobStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new LogicException(
                "A {$this->status->value} print job cannot perform this transition.",
            );
        }
    }
}
