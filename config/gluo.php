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

        // byjg/statemachine — finite state machines bound to your models
        'statemachine' => env('GLUO_CONNECTOR_STATEMACHINE', 'auto'),
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

    /*
    |--------------------------------------------------------------------------
    | State Machines
    |--------------------------------------------------------------------------
    |
    | Each machine is named here and asked for by that name, either through
    | `app(StateMachineManager::class)->machine('order')` or by a model using
    | the `HasStateMachine` trait.
    |
    | A definition is the plain array `FiniteStateMachine::fromDefinition()`
    | reads, so this file is the definition — there is no separate format.
    |
    |   'enum'         the enum whose cases ARE the states. Required, and the
    |                  reason a misspelled state is caught when the machine is
    |                  built rather than on the transition nobody exercised.
    |   'transitions'  each one declares `from` and `to`, optionally a
    |                  `condition` and an `action` (class names, resolved
    |                  through the container, so they may have dependencies),
    |                  and a `name` when the same pair of states is joined more
    |                  than once. `from` also accepts a list.
    |
    | The two flags are off by default, which is the component's own default:
    | a move that is not allowed returns null rather than throwing, and the
    | first matching transition wins.
    |
    |   'order' => [
    |       'enum' => App\Enums\OrderState::class,
    |       'transitions' => [
    |           ['from' => 'DRAFT', 'to' => 'PAID',
    |            'condition' => App\Fsm\PaymentCleared::class,
    |            'action'    => App\Fsm\SendReceipt::class],
    |           ['from' => 'PAID', 'to' => 'SHIPPED', 'condition' => App\Fsm\StockReserved::class],
    |           ['from' => ['DRAFT', 'PAID'], 'to' => 'CANCELLED'],
    |       ],
    |       'throw_on_no_transition' => false,
    |       'throw_on_ambiguity'     => false,
    |   ],
    |
    */

    'statemachine' => [

        'machines' => [
            //
        ],
    ],
];
