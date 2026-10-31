<?php
declare(strict_types=1);

namespace Forum\Exception;

/**
 * The envelope or payload is permanently invalid (contract: HTTP 400/422,
 * the game records a dead-letter). getErrors() lists field => problem.
 */
class ValidationFailed extends ForumException
{
    /** @param array<string, string> $errors */
    public function __construct(private array $errors, string $message = 'Invalid event')
    {
        parent::__construct($message . ': ' . implode('; ', array_map(
            static fn (string $k, string $v): string => $k . ' ' . $v,
            array_keys($errors),
            $errors
        )));
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
