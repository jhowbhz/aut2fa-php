<?php

declare(strict_types=1);

namespace Aut2fa;

use RuntimeException;

/** Preserves the API rejection without treating it as a transport failure. */
final class ApiError extends RuntimeException
{
    /** @var string PHP's inherited exception code is untyped; the API uses stable string codes. */
    public $code;

    public function __construct(
        public readonly int $status,
        string $code,
        public readonly string $detail,
        public readonly mixed $errors = null,
        public readonly ?string $retryAfter = null,
    ) {
        parent::__construct($detail);
        $this->code = $code;
    }
}
