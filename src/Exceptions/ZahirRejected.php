<?php

namespace Sifrious\AccountsClient\Exceptions;

use RuntimeException;

/**
 * Zahir understood the request and refused it — a 4xx that is not a throttle.
 *
 * Carries the status so a consumer can separate "this caller is not
 * authenticated to Zahir" (an operator problem) from "no such account" (a
 * domain answer), without parsing a message body.
 */
final class ZahirRejected extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
