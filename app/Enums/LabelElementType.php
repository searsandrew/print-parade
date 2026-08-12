<?php

namespace App\Enums;

enum LabelElementType: string
{
    case Text = 'text';
    case Barcode = 'barcode';
    case Line = 'line';
    case Rectangle = 'rectangle';
    case Image = 'image';
    case JobIdentifier = 'job_identifier';
}
