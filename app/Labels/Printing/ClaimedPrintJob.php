<?php

namespace App\Labels\Printing;

use App\Models\PrintJob;

final readonly class ClaimedPrintJob
{
    public function __construct(
        public PrintJob $job,
        public string $claimToken,
    ) {}
}
