<?php
declare(strict_types=1);

namespace App\Core\Http;

/** An error that is safe to show to the client (status, machine code, human message, field errors). */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(array $fields, string $message = 'Please correct the highlighted fields.'): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }

    public static function notFound(string $message = 'Record not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}
