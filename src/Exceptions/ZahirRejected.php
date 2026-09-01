<?php

namespace Sifrious\AccountsClient\Exceptions;

use RuntimeException;

/**
 * Zahir understood the request and refused it — a 4xx that is not a throttle.
 *
 * Carries the status so a consumer can separate "this caller is not
 * authenticated to Zahir" (an operator problem) from "no such account" (a
 * domain answer), without parsing a message body.
 *
 * The reason, when Zahir supplies one, is a stable machine-readable code such
 * as `recovery_required`. Messages are for operators; only the code is matched.
 */
final class ZahirRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message, $status);
    }
}
