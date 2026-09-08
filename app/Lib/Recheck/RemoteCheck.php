<?php

namespace App\Lib\Recheck;

use App\Lib\Restriction\BasisClassifier;

/**
 * Result of probing a document at its source registry.
 */
final class RemoteCheck
{
    public const PUBLIC = 'public';
    public const RESTRICTED = 'restricted';
    public const GONE = 'gone';
    public const ERROR = 'error';

    public const ERROR_HTTP_5XX = 'http5xx';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_CONNECTION = 'connection';
    public const ERROR_BOT_CHECK = 'bot_check';
    public const ERROR_REDIRECT = 'redirect';
    public const ERROR_UNPARSEABLE = 'unparseable';

    /**
     * @param string[] $bases
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?int $httpStatus = null,
        public readonly ?string $restriction = null,
        public readonly array $bases = [],
        public readonly ?string $changeBasis = null,
        public readonly ?string $errorKind = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Build from a registry's raw restriction value. "Avalik"/"PUBLIC" (or an
     * empty value) means public; anything else means restricted.
     *
     * @param string[] $bases
     */
    public static function fromRestriction(?string $restriction, array $bases = [], ?string $changeBasis = null, int $httpStatus = 200): self
    {
        $restriction = $restriction !== null ? trim($restriction) : null;
        $bases = array_values(array_filter(array_map(fn($b) => trim((string)$b), $bases), fn($b) => $b !== ''));

        $public = $restriction === null
            || $restriction === ''
            || in_array(mb_strtolower($restriction), ['avalik', 'public'], true);

        return new self(
            outcome: $public ? self::PUBLIC : self::RESTRICTED,
            httpStatus: $httpStatus,
            restriction: $restriction === '' ? null : $restriction,
            bases: $bases,
            changeBasis: $changeBasis !== null && trim($changeBasis) !== '' ? trim($changeBasis) : null,
        );
    }

    public static function gone(int $httpStatus): self
    {
        return new self(outcome: self::GONE, httpStatus: $httpStatus);
    }

    public static function error(string $kind, ?int $httpStatus = null, ?string $message = null): self
    {
        return new self(outcome: self::ERROR, httpStatus: $httpStatus, errorKind: $kind, errorMessage: $message);
    }

    public function isError(): bool
    {
        return $this->outcome === self::ERROR;
    }

    public function isBotCheck(): bool
    {
        return $this->errorKind === self::ERROR_BOT_CHECK;
    }

    public function basisString(): ?string
    {
        return $this->bases === [] ? null : implode(', ', $this->bases);
    }

    public function isPersonalData(): bool
    {
        return $this->outcome === self::RESTRICTED && BasisClassifier::anyPersonalData($this->bases);
    }
}
