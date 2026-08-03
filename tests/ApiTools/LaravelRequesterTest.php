<?php

namespace ByJGTest\Gluo\Laravel\ApiTools;

use ByJG\ApiTools\Exception\InvalidRequestException;
use ByJG\ApiTools\Exception\NotMatchedException;
use ByJG\ApiTools\Exception\PathNotFoundException;
use ByJG\ApiTools\Exception\StatusCodeNotMatchedException;
use ByJG\Gluo\Laravel\ApiTools\Testing\InteractsWithOpenApi;
use ByJGTest\Gluo\Laravel\TestCase;

class LaravelRequesterTest extends TestCase
{
    use InteractsWithOpenApi;

    public function testDispatchesThroughTheKernelAndMatchesTheContract(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/ping')
            ->expectStatus(200);

        $response = $this->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'pong'], json_decode((string)$response->getBody(), true));
    }

    public function testPostsAJsonBodyAndValidatesTheCreatedResource(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('POST')
            ->withPath('/api/users')
            ->withRequestBody(['name' => 'John Doe', 'email' => 'john@example.com'])
            ->expectStatus(201);

        $response = $this->sendRequest($request);

        $this->assertSame(
            ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
            json_decode((string)$response->getBody(), true)
        );
    }

    public function testRejectsARequestBodyThatViolatesTheSpecification(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('POST')
            ->withPath('/api/users')
            ->withRequestBody(['name' => 'John Doe'])
            ->expectStatus(201);

        $this->expectException(NotMatchedException::class);
        $this->expectExceptionMessage("Required property 'email'");

        $this->sendRequest($request);
    }

    public function testRejectsAResponseBodyThatViolatesTheSpecification(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/broken')
            ->expectStatus(200);

        $this->expectException(NotMatchedException::class);

        $this->sendRequest($request);
    }

    public function testRejectsAnUnexpectedStatusCode(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/ping')
            ->expectStatus(204);

        $this->expectException(StatusCodeNotMatchedException::class);

        $this->sendRequest($request);
    }

    public function testRejectsAPathAbsentFromTheSpecification(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/not-in-the-spec');

        $this->expectException(PathNotFoundException::class);

        $this->sendRequest($request);
    }

    public function testForwardsTheQueryString(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/echo')
            ->withQuery(['message' => 'hello world'])
            ->expectStatus(200);

        $response = $this->sendRequest($request);

        $this->assertSame(['message' => 'hello world'], json_decode((string)$response->getBody(), true));
    }

    public function testForwardsRequestHeaders(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/headers')
            ->withRequestHeader(['X-Token' => 'abc123'])
            ->expectStatus(200);

        $response = $this->sendRequest($request);

        $this->assertSame(['token' => 'abc123'], json_decode((string)$response->getBody(), true));
    }

    /**
     * The form must survive the whole trip: matched against the specification
     * by AbstractRequester, then parsed into Laravel's request bag so the
     * route can read it with $request->input().
     */
    public function testParsesAUrlencodedFormIntoTheRequestInput(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('POST')
            ->withPath('/api/form')
            ->withRequestHeader(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withRequestBody(http_build_query(['name' => 'Jane', 'email' => 'jane@example.com']))
            ->expectStatus(200);

        $response = $this->sendRequest($request);

        $this->assertSame(
            ['name' => 'Jane', 'email' => 'jane@example.com'],
            json_decode((string)$response->getBody(), true)
        );
    }

    public function testRejectsAUrlencodedFormThatViolatesTheSpecification(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('POST')
            ->withPath('/api/form')
            ->withRequestHeader(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withRequestBody(http_build_query(['name' => 'Jane']))
            ->expectStatus(200);

        $this->expectException(NotMatchedException::class);
        $this->expectExceptionMessage("Required property 'email'");

        $this->sendRequest($request);
    }

    /**
     * Content types outside JSON, multipart and urlencoded are still rejected
     * by AbstractRequester before reaching the kernel.
     */
    public function testRejectsAnUnsupportedContentType(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('POST')
            ->withPath('/api/users')
            ->withRequestHeader(['Content-Type' => 'application/xml'])
            ->withRequestBody('<user><name>Jane</name></user>');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage("Cannot handle Content Type 'application/xml'");

        $this->sendRequest($request);
    }

    public function testExposesTheResponseHeaders(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('GET')
            ->withPath('/api/ping')
            ->expectStatus(200);

        $response = $this->sendRequest($request);

        $this->assertStringContainsString('application/json', $response->getHeaderLine('content-type'));
    }
}
