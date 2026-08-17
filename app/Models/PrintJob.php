<?php

namespace App\Models;

use App\Labels\Enums\PrintJobStatus;
use Database\Factories\PrintJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property string $id
 * @property int $label_template_version_id
 * @property int $printer_id
 * @property int|null $submitted_by
 * @property array<string, mixed> $input_values
 * @property int $quantity
 * @property PrintJobStatus $status
 * @property string|null $output_payload
 * @property string|null $output_checksum
 * @property int|null $executed_by
 * @property int|null $claimed_by_bridge
 * @property string|null $claim_token_hash
 * @property Carbon|null $queued_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $delivery_uncertain_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label_template_version_id', 'printer_id', 'submitted_by', 'input_values', 'quantity'])]
#[Hidden(['claim_token_hash'])]
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
            'queued_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'delivery_uncertain_at' => 'immutable_datetime',
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

    public function queue(User $user, string $payload): void
    {
        $this->assertStatus(PrintJobStatus::Pending);
        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', PrintJobStatus::Pending->value)
            ->update([
                'status' => PrintJobStatus::Queued->value,
                'output_payload' => $payload,
                'output_checksum' => hash('sha256', $payload),
                'executed_by' => $user->getKey(),
                'queued_at' => now(),
            ]);

        $this->refresh();

        if ($updated !== 1) {
            throw new LogicException('This print job has already been authorized.');
        }
    }

    public function claim(PrintBridge $bridge): string
    {
        $this->assertStatus(PrintJobStatus::Queued);

        if ($this->printer->print_bridge_id !== $bridge->id) {
            throw new LogicException('This print job is assigned to a different bridge.');
        }

        if ($this->output_payload === null
            || $this->output_checksum === null
            || ! hash_equals($this->output_checksum, hash('sha256', $this->output_payload))) {
            throw new LogicException('The queued print payload failed its integrity check.');
        }

        $claimedAt = now();
        $claimToken = Str::random(64);
        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', PrintJobStatus::Queued->value)
            ->update([
                'status' => PrintJobStatus::Processing->value,
                'claimed_by_bridge' => $bridge->id,
                'claim_token_hash' => hash('sha256', $claimToken),
                'claimed_at' => $claimedAt,
                'started_at' => $claimedAt,
                'lease_expires_at' => $claimedAt->clone()->addMinute(),
            ]);

        if ($updated !== 1) {
            $this->refresh();

            throw new LogicException('This print job has already been claimed.');
        }

        $this->refresh();

        return $claimToken;
    }

    public function complete(PrintBridge $bridge, string $claimToken): void
    {
        $this->assertStatus(PrintJobStatus::Processing);
        $this->assertClaim($bridge, $claimToken);

        $this->forceFill([
            'status' => PrintJobStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    public function matchesClaim(PrintBridge $bridge, string $claimToken): bool
    {
        return $this->claimed_by_bridge === $bridge->id
            && $this->claim_token_hash !== null
            && hash_equals($this->claim_token_hash, hash('sha256', $claimToken));
    }

    public function fail(PrintBridge $bridge, string $claimToken, string $message): void
    {
        $this->assertStatus(PrintJobStatus::Processing);
        $this->assertClaim($bridge, $claimToken);
        $this->assertFailureMessage($message);

        $this->forceFill([
            'status' => PrintJobStatus::Failed,
            'failed_at' => now(),
            'failure_message' => $message,
        ])->save();
    }

    public function failPreparation(User $user, string $message): void
    {
        $this->assertStatus(PrintJobStatus::Pending);
        $this->assertFailureMessage($message);

        $this->forceFill([
            'status' => PrintJobStatus::Failed,
            'executed_by' => $user->getKey(),
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

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** @return BelongsTo<PrintBridge, $this> */
    public function claimingBridge(): BelongsTo
    {
        return $this->belongsTo(PrintBridge::class, 'claimed_by_bridge');
    }

    private function assertStatus(PrintJobStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new LogicException(
                "A {$this->status->value} print job cannot perform this transition.",
            );
        }
    }

    private function assertFailureMessage(string $message): void
    {
        if (trim($message) === '') {
            throw new LogicException('A failed print job must include a failure message.');
        }
    }

    private function assertClaim(PrintBridge $bridge, string $claimToken): void
    {
        if (! $this->matchesClaim($bridge, $claimToken)) {
            throw new LogicException('The print job acknowledgement is invalid.');
        }
    }
}
