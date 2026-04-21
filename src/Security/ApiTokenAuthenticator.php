<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire('%env(API_TOKEN)%')]
        private readonly string $apiToken,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/products');
    }

    public function authenticate(Request $request): Passport
    {
        $authorizationHeader = $request->headers->get('Authorization');

        if ($authorizationHeader === null || !str_starts_with($authorizationHeader, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('Missing bearer token.');
        }

        $providedToken = trim(substr($authorizationHeader, 7));

        if ($providedToken === '' || !hash_equals($this->apiToken, $providedToken)) {
            throw new CustomUserMessageAuthenticationException('Invalid bearer token.');
        }

        return new SelfValidatingPassport(
            new UserBadge($providedToken, static fn (): ApiTokenUser => new ApiTokenUser()),
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['message' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['message' => 'Authentication is required.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
