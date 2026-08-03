<?php

namespace ByJG\Gluo\Laravel\ApiTools\Middleware;

use ByJG\ApiTools\Base\Schema;
use ByJG\ApiTools\Exception\BaseException;
use ByJG\ApiTools\Exception\HttpMethodNotFoundException;
use ByJG\ApiTools\Exception\PathNotFoundException;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates requests and responses against the OpenAPI specification at
 * runtime, using the very same contract the tests assert against.
 *
 * Registered by the service provider under the `gluo.openapi` alias:
 *
 * <code>
 * Route::post('/users', [UserController::class, 'store'])
 *     ->middleware('gluo.openapi');                    // uses config defaults
 *
 * Route::get('/users', [UserController::class, 'index'])
 *     ->middleware('gluo.openapi:request,response');   // explicit
 * </code>
 */
class ValidateOpenApi
{
    public const string MODE_REQUEST = 'request';
    public const string MODE_RESPONSE = 'response';

    public function __construct(
        protected Schema $schema,
        protected Config $config,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @param string ...$modes Any of `request`, `response`. Falls back to the
     *                         `gluo.openapi.validation` defaults when omitted.
     */
    public function handle(Request $request, Closure $next, string ...$modes): Response
    {
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        if ($this->shouldValidate(self::MODE_REQUEST, $modes)) {
            $violation = $this->validateRequest($path, $method, $request);

            if ($violation !== null) {
                return $violation;
            }
        }

        /** @var Response $response */
        $response = $next($request);

        if ($this->shouldValidate(self::MODE_RESPONSE, $modes)) {
            return $this->validateResponse($path, $method, $response) ?? $response;
        }

        return $response;
    }

    protected function validateRequest(string $path, string $method, Request $request): ?JsonResponse
    {
        try {
            $definition = $this->schema->getRequestParameters($path, $method);
        } catch (PathNotFoundException|HttpMethodNotFoundException $exception) {
            return $this->onUndefinedPath($exception);
        }

        try {
            $definition->match($this->decodeRequestBody($request));
        } catch (BaseException $exception) {
            return $this->error(
                'The request does not match the API specification.',
                $exception,
                (int)$this->config->get('gluo.openapi.validation.error_status', 400)
            );
        }

        return null;
    }

    protected function validateResponse(string $path, string $method, Response $response): ?JsonResponse
    {
        try {
            $definition = $this->schema->getResponseParameters($path, $method, $response->getStatusCode());
            $definition->match($this->decodeResponseBody($response));
        } catch (BaseException $exception) {
            // The response is produced by this application, so a mismatch is a
            // server-side defect rather than a client error — always log it.
            $this->logger->error('OpenAPI response validation failed', [
                'path' => $path,
                'method' => $method,
                'status' => $response->getStatusCode(),
                'error' => $exception->getMessage(),
            ]);

            return $this->error(
                'The response does not match the API specification.',
                $exception,
                (int)$this->config->get('gluo.openapi.validation.response_error_status', 500)
            );
        }

        return null;
    }

    /**
     * Mirrors AbstractRequester: an absent body is matched as an empty string
     * so that specifications requiring a body still fail.
     */
    protected function decodeRequestBody(Request $request): mixed
    {
        $body = $request->getContent();

        if ($body === '') {
            return '';
        }

        if (!str_contains((string)$request->headers->get('Content-Type', ''), 'application/json')) {
            return $body;
        }

        return json_decode($body, true);
    }

    protected function decodeResponseBody(Response $response): mixed
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return '';
        }

        if (!str_contains((string)$response->headers->get('Content-Type', ''), 'json')) {
            return $content;
        }

        return json_decode($content, true);
    }

    protected function onUndefinedPath(BaseException $exception): ?JsonResponse
    {
        if (!$this->config->get('gluo.openapi.validation.strict_paths', false)) {
            return null;
        }

        return $this->error(
            'The requested route is not described in the API specification.',
            $exception,
            (int)$this->config->get('gluo.openapi.validation.error_status', 400)
        );
    }

    /**
     * @param string[] $modes
     */
    protected function shouldValidate(string $mode, array $modes): bool
    {
        if ($modes !== []) {
            return in_array($mode, $modes, true);
        }

        return (bool)$this->config->get("gluo.openapi.validation.$mode", $mode === self::MODE_REQUEST);
    }

    protected function error(string $message, BaseException $exception, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $message,
            'message' => $exception->getMessage(),
        ], $status);
    }
}
