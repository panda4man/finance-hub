<?php

namespace App\Enums;

enum AmountSign: string
{
    case Any = 'any';
    case Outflow = 'outflow';
    case Inflow = 'inflow';
}
