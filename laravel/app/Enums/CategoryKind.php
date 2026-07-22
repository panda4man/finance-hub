<?php

namespace App\Enums;

enum CategoryKind: string
{
    case SourceProvided = 'source_provided';
    case Custom = 'custom';
}
