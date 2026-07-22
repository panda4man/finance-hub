<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
