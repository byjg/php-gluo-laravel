<?php

namespace ByJG\Gluo\Laravel\ApiTools;

use ByJG\ApiTools\AbstractRequester;
use ByJG\WebRequest\Psr7\MemoryStream;
use ByJG\WebRequest\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request as LaravelRequest;
use Override;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Sends the request through the Laravel HTTP kernel instead of the network.
 *
 * The full middleware stack, the router, the container bindings and the
 * exception handler of the application under test are exercised, but no
 * socket is opened and no web server is required.
 *
 * <code>
 * $requester = new LaravelRequester($this->app);
 * $requester->withSchema($schema)
 *     ->withMethod('POST')
 *     ->withPath('/api/users')
 *     ->withRequestBody(['name' => 'John'])
 *     ->expectStatus(201);
 *
 * $this->sendRequest($requester);
 * </code>
 */
class LaravelRequester extends AbstractRequester
{
    /**
     * Headers that CGI exposes without the `HTTP_` prefix.
     */
    private const array UNPREFIXED_HEADERS = ['CONTENT_TYPE', 'CONTENT_LENGTH'];

    public function __construct(protected Application $app)
    {
        parent::__construct();
    }

    #[Override]
    protected function handleRequest(RequestInterface $request): ResponseInterface
    {
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(HttpKernel::class);

        $laravelRequest = $this->toLaravelRequest($request);

        $response = $kernel->handle($laravelRequest);
        $kernel->terminate($laravelRequest, $response);

        return $this->toPsr7Response($response);
    }

    /**
     * Convert the PSR-7 request into the request object Laravel expects.
     */
    protected function toLaravelRequest(RequestInterface $request): LaravelRequest
    {
        $body = (string)$request->getBody();

        return LaravelRequest::create(
            (string)$request->getUri(),
            strtoupper($request->getMethod()),
            $this->parseFormParameters($request, $body),
            $this->transformCookies($request),
            [],
            $this->transformHeaders($request, $body),
            $body
        );
    }

    /**
     * Symfony populates the request bag from `$parameters`, never from the raw
     * body, so a urlencoded payload would leave `$request->input()` empty
     * unless the form is parsed here.
     *
     * @return array<string, mixed>
     */
    protected function parseFormParameters(RequestInterface $request, string $body): array
    {
        if ($body === '' || !str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded')) {
            return [];
        }

        parse_str($body, $parameters);

        return $parameters;
    }

    /**
     * Map PSR-7 headers onto the CGI-style `$_SERVER` keys Laravel reads.
     *
     * When the caller did not declare a Content-Type, JSON is assumed — that is
     * already how AbstractRequester interprets the very same body when matching
     * it against the specification. Without it Symfony would fall back to
     * `application/x-www-form-urlencoded` on writes and `$request->input()`
     * would come back empty for a JSON payload.
     *
     * @return array<string, string>
     */
    protected function transformHeaders(RequestInterface $request, string $body = ''): array
    {
        $server = [];

        foreach ($request->getHeaders() as $name => $values) {
            $key = strtoupper(str_replace('-', '_', (string)$name));

            if (!in_array($key, self::UNPREFIXED_HEADERS, true)) {
                $key = 'HTTP_' . $key;
            }

            $server[$key] = implode(', ', $values);
        }

        if ($body !== '' && !isset($server['CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        return $server;
    }

    /**
     * @return array<string, string>
     */
    protected function transformCookies(RequestInterface $request): array
    {
        $cookies = [];

        foreach ($request->getHeader('Cookie') as $line) {
            foreach (explode(';', $line) as $pair) {
                $parts = explode('=', $pair, 2);

                if (count($parts) !== 2) {
                    continue;
                }

                $cookies[trim($parts[0])] = urldecode(trim($parts[1]));
            }
        }

        return $cookies;
    }

    /**
     * Convert the Laravel/Symfony response back into PSR-7 so that
     * AbstractRequester can match it against the specification.
     */
    protected function toPsr7Response(SymfonyResponse $response): ResponseInterface
    {
        $psr7Response = Psr7Response::getInstance($response->getStatusCode());

        foreach ($response->headers->all() as $name => $values) {
            $psr7Response = $psr7Response->withHeader((string)$name, $values);
        }

        return $psr7Response->withBody(new MemoryStream($this->extractContent($response)));
    }

    /**
     * Streamed and binary-file responses return `false` from `getContent()`;
     * for those the content only materialises while being sent.
     */
    protected function extractContent(SymfonyResponse $response): string
    {
        $content = $response->getContent();

        if ($content === false) {
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
        }

        return (string)$content;
    }
}
