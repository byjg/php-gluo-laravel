---
sidebar_position: 3
---

# Runtime validation

The specification that drives your contract tests can also guard the API while it runs. The
`gluo.openapi` middleware validates incoming requests — and optionally outgoing responses —
against the very same file, so the rules cannot drift apart from the documentation.

## Applying the middleware

The alias is registered by the connector; attach it wherever you would attach any other
middleware.

```php
// Per route
Route::post('/users', [UserController::class, 'store'])
    ->middleware('gluo.openapi');

// Per group
Route::middleware('gluo.openapi')->prefix('api')->group(function () {
    Route::apiResource('users', UserController::class);
});
```

In `bootstrap/app.php`, for the whole API stack:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', \ByJG\Gluo\Laravel\ApiTools\Middleware\ValidateOpenApi::class);
})
```

## Choosing what gets validated

Without parameters the middleware follows `gluo.openapi.validation` in the configuration.
Parameters override it per route:

```php
Route::post('/users', ...)->middleware('gluo.openapi:request');
Route::get('/users', ...)->middleware('gluo.openapi:request,response');
Route::get('/report', ...)->middleware('gluo.openapi:response');
```

## Configuration

```php
'validation' => [
    'request'  => true,
    'response' => false,
    'strict_paths' => false,
    'error_status' => 400,
    'response_error_status' => 500,
],
```

### `request`

Validates the incoming body against the request schema. A violation is a **client** error: the
middleware answers `error_status` and the controller never runs.

```json
{
  "error": "The request does not match the API specification.",
  "message": "Required property 'email' in '#/components/schemas/NewUser' not found in object"
}
```

### `response`

Validates the outgoing body against the response schema for the status actually returned. A
violation is a **server** defect — your API is not honouring its own contract — so it is written
to the log at `error` level and the client receives `response_error_status`.

Leave this off in production unless you want that strictness: it costs a schema match on every
response, and it converts a merely-undocumented field into a `500`. It is most valuable in
staging, or enabled per route while a contract is being tightened:

```dotenv
GLUO_OPENAPI_VALIDATE_RESPONSE=true
```

### `strict_paths`

Decides what happens when a request reaches the middleware on a path or verb the specification
does not describe.

| Value             | Behaviour                                                       |
|-------------------|-----------------------------------------------------------------|
| `false` (default) | Pass through untouched — safe when the middleware sits on a group that also serves undocumented routes |
| `true`            | Reject with `error_status`, forcing every route under the middleware to be documented |

### `error_status` / `response_error_status`

The status codes used for the two cases above. Set `error_status` to `422` to match Laravel's
own validation convention:

```dotenv
GLUO_OPENAPI_ERROR_STATUS=422
```

## Relationship with Laravel's validation

The middleware checks *shape*: required properties, types, formats, undeclared properties. It
does not know that an email must be unique or that a coupon must still be valid. Keep
`FormRequest` classes for business rules — the two operate at different layers and complement
each other.

A practical division:

- **Middleware** — rejects payloads that could not possibly be valid, before any application
  code runs, using rules that are already documented.
- **FormRequest** — enforces the rules that depend on your data and your domain.

## Cost

The specification is parsed once per process and shared through the container, so the per-request
cost is the schema match itself. For a small payload it is negligible; for large documents with
deeply nested `$ref`s, measure before enabling it on a hot path.

To keep it out of production entirely while retaining it elsewhere:

```dotenv
GLUO_OPENAPI_VALIDATE_REQUEST=false
```
