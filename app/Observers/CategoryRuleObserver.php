<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CategoryRule;
use App\Services\CategorizationService;

final class CategoryRuleObserver
{
    public function __construct(private readonly CategorizationService $categorization) {}

    public function saved(CategoryRule $rule): void
    {
        $this->categorization->flush();
    }

    public function deleted(CategoryRule $rule): void
    {
        $this->categorization->flush();
    }
}
