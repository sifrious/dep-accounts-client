<?php

namespace Sifrious\AccountsClient\Exceptions;

use RuntimeException;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;
use Throwable;

/**
 * A login attempt failed protocol verification.
 *
 * The message is for operators and is never rendered to a person or matched on;
 * the outcome is the stable thing consumers branch on. Messages deliberately
 * describe the check that failed without echoing the value that failed it, so
 * an exception can be logged verbatim without leaking a code, token, or subject.
 */
final class LoginVerificationFailed extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly AuthenticationOutcome $outcome = AuthenticationOutcome::CallbackInvalid,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function withOutcome(
        AuthenticationOutcome $outcome,
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self($message, 0, $previous, $outcome);
    }
}
