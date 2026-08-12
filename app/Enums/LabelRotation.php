<?php

namespace App\Enums;

enum LabelRotation: int
{
    case None = 0;
    case Clockwise90 = 90;
    case Clockwise180 = 180;
    case Clockwise270 = 270;
}
