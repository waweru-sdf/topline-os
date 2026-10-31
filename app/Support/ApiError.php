<?php

namespace App\Support;

use RuntimeException;

/**
 * One error shape for the whole API: {"message": "...", "field": "phone" | null}.
 * Messages are written for shop owners, not developers: the website shows them as-is.
 */
class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** 422 for a specific form field. */
    public static function invalid(string $message, ?string $field = null): self
    {
        return new self($message, 422, $field);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self($message, 404);
    }

    public static function forbidden(string $message = "You don't have access to this."): self
    {
        return new self($message, 403);
    }

    public static function unauthorized(string $message = 'Log in to continue.'): self
    {
        return new self($message, 401);
    }
}
