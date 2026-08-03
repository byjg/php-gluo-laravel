<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connectors
    |--------------------------------------------------------------------------
    |
    | Gluo for Laravel ships one connector per ByJG component and requires none
    | of them. Install only the components you want — `composer require
    | byjg/swagger-test`, and so on — and the matching connector activates by
    | itself.
    |
    |   'auto'  enable when the component is installed (default)
    |   true    require the connector; fail loudly when the component is missing
    |   false   never enable it
    |
    */

    'connectors' => [

        // byjg/swagger-test — OpenAPI contract testing and runtime validation
        'openapi' => env('GLUO_CONNECTOR_OPENAPI', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Specification
    |--------------------------------------------------------------------------
    |
    | Path to the OpenAPI/Swagger specification (JSON) that describes this API.
    | It is the single source of truth used both by the contract tests and by
    | the runtime validation middleware.
    |
    | Supported versions: Swagger 2.0, OpenAPI 3.0.x and OpenAPI 3.1.x.
    |
    */

    'openapi' => [

        'spec' => env('GLUO_OPENAPI_SPEC', base_path('openapi.json')),

        /*
        | When true, properties declared as non-nullable in the specification
        | are still allowed to hold a null value. Keep it false to enforce the
        | contract strictly.
        */
        'allow_null_values' => (bool)env('GLUO_OPENAPI_ALLOW_NULL_VALUES', false),

        /*
        |----------------------------------------------------------------------
        | Runtime Validation Middleware
        |----------------------------------------------------------------------
        |
        | Defaults applied by the `gluo.openapi` middleware. Each route can
        | override which side is validated:
        |
        |     Route::post('/users', ...)->middleware('gluo.openapi:request');
        |     Route::get('/users', ...)->middleware('gluo.openapi:request,response');
        |
        */

        'validation' => [

            // Validate the incoming request body against the specification.
            'request' => (bool)env('GLUO_OPENAPI_VALIDATE_REQUEST', true),

            // Validate the outgoing response body against the specification.
            'response' => (bool)env('GLUO_OPENAPI_VALIDATE_RESPONSE', false),

            // When false, requests whose path/method is absent from the
            // specification are passed through untouched. When true they are
            // rejected with `error_status`.
            'strict_paths' => (bool)env('GLUO_OPENAPI_STRICT_PATHS', false),

            // Status returned when the request does not match the contract.
            'error_status' => (int)env('GLUO_OPENAPI_ERROR_STATUS', 400),

            // Status returned when the response does not match the contract.
            // A contract violation on the way out is a server-side defect.
            'response_error_status' => (int)env('GLUO_OPENAPI_RESPONSE_ERROR_STATUS', 500),
        ],
    ],
];
