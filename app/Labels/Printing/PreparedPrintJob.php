<?php

namespace App\Labels\Printing;

final readonly class PreparedPrintJob
{
    public function __construct(
        public string $jobId,
        public string $jobIdentifier,
        public int $printerId,
        public string $bridgeIdentifier,
        public int $quantity,
        public string $zpl,
    ) {}
}
