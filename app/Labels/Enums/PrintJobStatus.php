<?php

namespace App\Labels\Enums;

enum PrintJobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case DeliveryUncertain = 'delivery_uncertain';
    case Spooled = 'spooled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
