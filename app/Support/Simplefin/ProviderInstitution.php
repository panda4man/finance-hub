<?php

declare(strict_types=1);

namespace App\Support\Simplefin;

/**
 * Provider-agnostic institution/org shape. Named "Provider*" (not "Simplefin*")
 * because a future non-SimpleFin provider would produce the same shape.
 */
final readonly class ProviderInstitution
{
    public function __construct(
        public string $provider,
        public ?string $externalOrgId,
        public ?string $name,
        public ?string $url,
    ) {}
}
