<?php

namespace App\Labels\Enums;

enum PrintJobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case DeliveryUncertain = 'delivery_uncertain';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
