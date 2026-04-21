<?php

declare(strict_types=1);

namespace App\Http\Resolver;

use App\Dto\CreateProductRequestDto;
use App\Exception\ApiValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateProductRequestDtoResolver implements ValueResolverInterface
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== CreateProductRequestDto::class || !$request->isMethod(Request::METHOD_POST)) {
            return [];
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('Request body must be valid JSON.', $exception);
        }

        $errors = [];

        $name = $this->extractString($payload, 'name', $errors);
        $price = $this->extractFloat($payload, 'price', $errors);
        $quantity = $this->extractInteger($payload, 'quantity', $errors);

        if ($errors !== []) {
            throw ApiValidationException::fromErrors($errors);
        }

        $dto = new CreateProductRequestDto($name, $price, $quantity);
        $violations = $this->validator->validate($dto);

        if (\count($violations) > 0) {
            throw ApiValidationException::fromViolations($violations);
        }

        yield $dto;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, list<string>> $errors
     */
    private function extractString(array $payload, string $field, array &$errors): string
    {
        if (!array_key_exists($field, $payload)) {
            $errors[$field][] = 'This field is required.';

            return '';
        }

        if (!\is_string($payload[$field])) {
            $errors[$field][] = 'This field must be a string.';

            return '';
        }

        return trim($payload[$field]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, list<string>> $errors
     */
    private function extractFloat(array $payload, string $field, array &$errors): float
    {
        if (!array_key_exists($field, $payload)) {
            $errors[$field][] = 'This field is required.';

            return 0.0;
        }

        if (!\is_int($payload[$field]) && !\is_float($payload[$field])) {
            $errors[$field][] = 'This field must be a number.';

            return 0.0;
        }

        return (float) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, list<string>> $errors
     */
    private function extractInteger(array $payload, string $field, array &$errors): int
    {
        if (!array_key_exists($field, $payload)) {
            $errors[$field][] = 'This field is required.';

            return 0;
        }

        if (!\is_int($payload[$field])) {
            $errors[$field][] = 'This field must be an integer.';

            return 0;
        }

        return $payload[$field];
    }
}
