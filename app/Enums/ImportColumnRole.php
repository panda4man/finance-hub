<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportColumnRole: string
{
    case Date = 'date';
    case Description = 'description';
    case Amount = 'amount';
    case Type = 'type';
    case Balance = 'balance';
}
