<?php

namespace App\Exceptions;

use App\Enums\ConnectionFailure;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A repository could not be verified against GitHub.
 *
 * The exception carries the mapped reason, so callers can either surface it
 * on the right form field or store it on the connection.
 */
class RepositoryVerificationException extends RuntimeException
{
    public function __construct(public readonly ConnectionFailure $failure)
    {
        parent::__construct($failure->value);
    }

    /**
     * Turn the failure into the validation error the owner sees.
     */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->failure->field() => $this->failure->message(),
        ]);
    }
}
