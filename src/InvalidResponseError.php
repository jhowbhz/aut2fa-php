<?php

declare(strict_types=1);

namespace Aut2fa;

use RuntimeException;

/** Rejects successful HTTP responses that cannot satisfy the endpoint's JSON contract. */
final class InvalidResponseError extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('The API returned an invalid JSON response.');
    }
}
