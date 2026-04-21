<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ApiValidationException extends BadRequestHttpException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Validation failed.',
    ) {
        parent::__construct($message);
    }

    public static function fromViolations(ConstraintViolationListInterface $violations): self
    {
        $errors = [];

        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            $errors[$field] ??= [];
            $errors[$field][] = $violation->getMessage();
        }

        return new self($errors);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function fromErrors(array $errors): self
    {
        return new self($errors);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
