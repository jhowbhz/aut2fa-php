<?php

declare(strict_types=1);

namespace Aut2fa;

use RuntimeException;

/** Keeps cURL failures identifiable when the API has not returned a usable response. */
final class NetworkError extends RuntimeException
{
    public readonly bool $timedOut;

    public function __construct(public readonly int $curlCode, string $detail)
    {
        parent::__construct($detail, $curlCode);
        $this->timedOut = $curlCode === CURLE_OPERATION_TIMEDOUT;
    }
}
