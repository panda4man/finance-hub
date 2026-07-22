<?php

namespace App\Enums;

enum SyncTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Backfill = 'backfill';
}
