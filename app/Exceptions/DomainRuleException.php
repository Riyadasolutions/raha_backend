<?php

namespace App\Exceptions;

use RuntimeException;

/** A business rule was violated. Rendered as HTTP 422 {message} with the same Arabic text the frontend showed. */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'لا تملك صلاحية تنفيذ هذا الإجراء.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'السجل غير موجود.'): self
    {
        return new self($message, 404);
    }
}
