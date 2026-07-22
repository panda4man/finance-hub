<?php

return [
    'sync_overlap_days' => (int) env('SYNC_OVERLAP_DAYS', 4),
    'backfill_window_days' => (int) env('BACKFILL_WINDOW_DAYS', 90),
    'backfill_empty_windows_to_stop' => (int) env('BACKFILL_EMPTY_WINDOWS_TO_STOP', 3),
    'backfill_max_windows' => (int) env('BACKFILL_MAX_WINDOWS', 200),
    'retry_backoff_ms' => [1000, 4000, 10000],
    'recategorize_chunk' => 500,
    'sync_queue' => env('FINANCE_SYNC_QUEUE', 'finance-sync'),
    'sync_cron' => env('SYNC_CRON', '0 4 * * *'),
    'sync_enabled' => (bool) env('SYNC_ENABLED', true),
    'stale_sync_threshold_hours' => (int) env('STALE_SYNC_THRESHOLD_HOURS', 24),
];
