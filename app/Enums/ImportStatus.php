<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
