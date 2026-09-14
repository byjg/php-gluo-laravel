---
sidebar_position: 2
---

# Contract testing

A contract test asserts that your API behaves exactly as its OpenAPI specification says: the
request body you send is accepted by the spec, the status code is the declared one, and the
response body matches the declared schema. Nothing is asserted twice — the specification *is*
the assertion.

Requests are dispatched straight through the Laravel HTTP kernel. No socket is opened and no web
server is needed, yet the router, the full middleware stack, your container bindings and the
exception handler all run exactly as in production.

## Setup

```php
use ByJG\Gluo\Laravel\ApiTools\Testing\InteractsWithOpenApi;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use InteractsWithOpenApi;
}
```

The trait pulls in `ByJG\ApiTools\OpenApiValidation`, so `sendRequest()` is available, and it
resolves the specification from the container — there is no `setUp()` to write and no path to
repeat in every test.

## Sending a request

`openApiRequest()` returns a requester already bound to the application under test and to the
specification. Chain the fluent methods and hand it to `sendRequest()`:

```php
public function testCreateUser(): void
{
    $request = $this->openApiRequest()
        ->withMethod('POST')
        ->withPath('/api/users')
        ->withRequestBody(['name' => 'John Doe', 'email' => 'john@example.com'])
        ->expectStatus(201);

    $response = $this->sendRequest($request);

    // The contract is already verified. Assert business behaviour here.
    $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
}
```

`sendRequest()` returns the PSR-7 response, so you can keep asserting on it:

```php
$body = json_decode((string)$response->getBody(), true);
$this->assertSame('John Doe', $body['name']);
```

### Available methods

| Method                              | Purpose                                                  |
|-------------------------------------|----------------------------------------------------------|
| `withMethod(string)`                | HTTP verb                                                |
| `withPath(string)`                  | Path, matched against the specification                  |
| `withQuery(?array)`                 | Query-string parameters                                  |
| `withRequestBody(array\|string)`    | Request body; arrays are encoded as JSON                 |
| `withRequestHeader(array)`          | Request headers                                          |
| `expectStatus(int)`                 | Expected status code (default `200`)                     |
| `expectHeaderContains(string, string)` | Assert a response header contains a value             |
| `expectBodyContains(string)`        | Assert the raw response body contains a string           |
| `expectJsonContains(array)`         | Assert a subset of the JSON response                     |
| `expectJsonPath(string, mixed)`     | Assert a value at a dot-notation path                    |

These come from `ByJG\ApiTools\AbstractRequester`; see the
[swagger-test documentation](https://github.com/byjg/php-swagger-test) for the full reference.

## What makes a test fail

| Exception                        | Cause                                                        |
|----------------------------------|--------------------------------------------------------------|
| `PathNotFoundException`          | The path is not described in the specification                |
| `HttpMethodNotFoundException`    | The path exists but not for that verb                         |
| `NotMatchedException`            | A body does not match its schema (wrong type, missing required property, undeclared property) |
| `StatusCodeNotMatchedException`  | The response status differs from `expectStatus()`             |
| `DefinitionNotFoundException`    | A `$ref` in the specification cannot be resolved              |

## Authentication

`actingAs()` works as in any Laravel test: it binds the user on the container's auth guard, and
the requester dispatches through that same container.

```php
public function testProfile(): void
{
    $this->actingAs(User::factory()->create());

    $request = $this->openApiRequest()
        ->withMethod('GET')
        ->withPath('/api/profile')
        ->expectStatus(200);

    $this->sendRequest($request);
}
```

For token-based schemes, send the header:

```php
$request->withRequestHeader(['Authorization' => "Bearer $token"]);
```

## Database

`RefreshDatabase`, `DatabaseTransactions` and factories are untouched by this package — combine
them freely:

```php
class UserApiTest extends TestCase
{
    use InteractsWithOpenApi;
    use RefreshDatabase;
}
```

## Content types

Three request content types are matched against the specification:

| Content-Type                        | In the controller                       |
|-------------------------------------|-----------------------------------------|
| `application/json` *(or none)*      | `$request->json()`, `$request->input()`  |
| `application/x-www-form-urlencoded` | `$request->input()`                     |
| `multipart/*`                       | raw body only — see below               |

When no `Content-Type` is declared the requester sends `application/json`, matching how
`byjg/swagger-test` interprets that same body, so `$request->json()` behaves normally.

Form bodies are parsed into Laravel's request bag, so a route reading `$request->input('name')`
works exactly as it would over HTTP:

```php
$request = $this->openApiRequest()
    ->withMethod('POST')
    ->withPath('/api/subscribe')
    ->withRequestHeader(['Content-Type' => 'application/x-www-form-urlencoded'])
    ->withRequestBody(http_build_query(['name' => 'Jane', 'email' => 'jane@example.com']))
    ->expectStatus(200);

$this->sendRequest($request);
```

A form carries only strings, but numeric properties are validated with `is_numeric()`, so
`age=42` still matches a schema declaring `type: integer`.

Two boundaries remain, both inherited from the layers underneath:

- **`multipart/*` uploads reach the kernel as a raw body.** The specification is matched
  correctly, but Symfony does not rebuild `$_FILES` from raw content, so `$request->file()` will
  be empty. Test file uploads with Laravel's `UploadedFile::fake()` helpers instead.
- **Any other content type is rejected** by `AbstractRequester` before reaching the kernel, with
  `InvalidRequestException` — `application/xml`, for instance.

## Under the hood

`LaravelRequester` extends `ByJG\ApiTools\AbstractRequester` and implements the single
`handleRequest()` seam:

1. The PSR-7 request is converted into an `Illuminate\Http\Request` — headers become CGI
   `$_SERVER` keys, the `Cookie` header is parsed into the cookie bag, the query string and body
   are carried over verbatim.
2. `Illuminate\Contracts\Http\Kernel::handle()` runs it, then `terminate()` fires the terminable
   middleware, exactly as Laravel's own test helpers do.
3. The response is converted back to PSR-7 so `AbstractRequester` can match it against the
   specification. Streamed and binary responses are captured through an output buffer, since
   their content only materialises while being sent.

You can use the requester directly, without the trait:

```php
$requester = new \ByJG\Gluo\Laravel\ApiTools\LaravelRequester($this->app);
$requester->withSchema(app(\ByJG\ApiTools\Base\Schema::class));
```
