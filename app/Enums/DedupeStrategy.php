<?php

declare(strict_types=1);

namespace App\Enums;

enum DedupeStrategy: string
{
    case Composite = 'composite';
    case ExternalId = 'external_id';
}
