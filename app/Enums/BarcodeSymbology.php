<?php

namespace App\Enums;

enum BarcodeSymbology: string
{
    case Code128 = 'code128';
    case QrCode = 'qr_code';
}
