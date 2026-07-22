<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * All named constructors deliberately omit the SimpleFin Access URL / credential
 * from their messages (it embeds `user:pass@host` HTTP basic-auth) — only the
 * host is ever included, never the full URL.
 */
final class SimplefinException extends \RuntimeException
{
    /**
     * @param  list<string>  $errlistCodes  Raw `errlist[].code` values (e.g. "gen.auth", "act.failed"),
     *                                      when the failure originated from a parsed SimpleFin response.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $errlistCodes = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidSetupToken(): self
    {
        return new self('SimpleFin setup token could not be decoded into a valid claim URL.');
    }

    public static function claimFailed(string $claimUrl, int $status): self
    {
        $host = parse_url($claimUrl, PHP_URL_HOST) ?: 'unknown-host';

        return new self("SimpleFin setup-token claim to [{$host}] failed with HTTP status {$status}.", $status);
    }

    public static function malformedAccessUrl(): self
    {
        return new self('SimpleFin claim response did not contain a valid Access URL.');
    }

    /**
     * @param  list<string>  $errlistCodes
     */
    public static function fetchFailed(int $status, array $errlistCodes = []): self
    {
        return new self("SimpleFin /accounts request failed with HTTP status {$status}.", $status, $errlistCodes);
    }

    public static function malformedResponse(): self
    {
        return new self('SimpleFin /accounts response was not valid JSON / did not match the expected shape.');
    }

    /**
     * A 200 response whose `errlist` reports an auth failure (a "gen.auth" or
     * "con.auth"-prefixed code) even though the HTTP layer itself succeeded —
     * the credential still needs re-authing, so this is treated the same as a
     * 402/403 transport failure.
     *
     * @param  list<string>  $errlistCodes
     */
    public static function authError(array $errlistCodes): self
    {
        return new self(
            'SimpleFin reported an authentication error: '.implode(', ', $errlistCodes),
            403,
            $errlistCodes,
        );
    }
}
