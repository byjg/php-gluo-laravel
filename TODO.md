# Connector Roadmap

Which `byjg/php-*` components are worth shipping as Gluo connectors, and which are not.

## How each one was judged

A connector earns its place only when all three hold:

1. **Laravel has a real extension point** — `Queue::extend()`, `Cache::extend()`, a notification
   channel, a session driver. Without a hook, a "connector" is just `composer require` plus a
   service-provider binding nobody needed.
2. **Laravel does not already do it well.** Competing with a first-party feature means asking
   people to fight their framework.
3. **The component adds something the ecosystem lacks** — a driver, a protocol, a backend.

A library with no hook is not a bad library. It just isn't a *connector*: you `use` it and move on.

---

## Shipped

| Connector | Component | What it gives you | Docs |
|---|---|---|---|
| `openapi` | `byjg/swagger-test` | Contract testing through the Laravel kernel, plus a runtime request/response validation middleware | [guide](docs/contract-testing.md) |
| `statemachine` | `byjg/statemachine` | Machines declared as configuration, bound to Eloquent models, validated in CI | [guide](docs/state-machine.md) |

### `openapi` → test helper + middleware

The connector this package was built around. Laravel has no first-party OpenAPI story at all:
no contract testing, no runtime validation against a specification. `byjg/swagger-test` supplies
both, and the only Laravel-specific work was dispatching through the kernel rather than over HTTP
— which is what keeps `actingAs()`, `RefreshDatabase` and the whole middleware stack working
inside a contract test.

### `statemachine` → no hook

Promoted from tier 2 and shipped ahead of the suggested order. The judgement below is kept because
it is the argument the connector had to answer.

The component has no Laravel extension point, and its own documentation says a bridge package
would have nothing to do: the container is already a valid resolver, `config()` already returns
the array `fromDefinition()` reads, and a native enum cast already produces the type every method
accepts. Four lines in a service provider.

What earned it a connector is what those four lines leave behind, identical in every project:

- **Persist-then-process across a transaction.** Inside `DB::transaction()`, "after the write" is
  not "after the commit". The hand-written version sends the receipt for an order that then rolls
  back. `Connection::afterCommit()` is the fix, and it is the same fix every time.
- **The uppercase mismatch.** State names are uppercased by the component, so
  `OrderState::from($state->getState())` throws on any enum whose case values are not uppercase —
  including the example in the component's own docs, had it been written `Paid = 'paid'`.
- Several machines, one registry, rather than one hand-written singleton each.
- An illegal move arriving from a client is a `422`, which needs a validation rule.

Shipped as `StateMachineManager` + `HasStateMachine`/`StatefulModel` + `CanTransitionTo`. It wraps
none of the component's API — `$order->stateMachine()` hands back the `FiniteStateMachine` itself.

The lesson for the rest of this list: **"no extension point" does not settle it.** The question is
whether every project would otherwise write the same non-obvious code. Here two of them would, and
one of those is a correctness bug rather than a convenience.

---

## Tier 1 — build these

### `message-queue-client` + `rabbitmq-client` + `redis-queue-client` → `Queue::extend()`

**The strongest candidate on the list.** Laravel ships `sync`, `database`, `beanstalkd`, `sqs`,
`redis`, `null` — and **no RabbitMQ**. The whole community leans on a third-party package for it.

The design fits unusually well. `ConnectorFactory::registerConnector()` dispatches on URI scheme,
and each connector declares its own (`RabbitMQConnector::schema()` returns `["amqp", "amqps"]`).
So **one** Laravel queue driver exposes every byjg protocol, present and future:

```php
'gluo' => [
    'driver' => 'gluo',
    'connection' => env('GLUO_QUEUE_URI'),  // amqp://…, redis://…, sqs://…
],
```

Adding a protocol later means installing a package — no new Laravel driver, no config change beyond
the URI. That is a genuinely better story than one-package-per-broker, and it is the argument for
doing this one first.

Scope: a `QueueConnector`, a `Queue` driver + `Job` wrapper, and honest handling of the bits
Laravel expects (delays, retries, `failed_jobs`). The last part is the real work — Laravel's queue
contract is wider than publish/consume.

### `sms-client` → Notification channel

Laravel has **no first-party SMS**; every option is a community channel package. `sms-client`
already abstracts providers (Twilio Messaging, Twilio Verify, ByJG) behind `ProviderFactory`, which
is exactly the shape a channel wants.

```php
public function via($notifiable): array { return ['gluo-sms']; }
public function toGluoSms($notifiable): Message { … }
```

Small, self-contained, immediately useful, and the `PhoneFormat`/`Phone` helpers are a real
differentiator — most channel packages make you normalise numbers yourself.

### `jwt-session` → `Session::extend()`

Laravel session drivers: `file`, `cookie`, `database`, `memcached`, `redis`, `dynamodb`, `array` —
**all stateful**. A JWT-backed session is genuinely stateless: no server store, no sticky sessions,
horizontal scaling for free.

Niche but a real gap, and the hook is clean. Worth being upfront in the docs about the trade-offs
(size limits, revocation, token in a cookie).

---

## Tier 2 — defensible, lower priority

| Component | Hook | Verdict |
|---|---|---|
| `php-resilience` | none — a service | Laravel has `Http::retry()` but **no circuit breaker**. `MutexExecutor` + `ExponentialBackoffExecutor` fill a real gap. Ships as a facade + attributes rather than a driver, so the "connector" is thin — but the gap is genuine. |
| `anydataset-nosql` | `Storage::extend()` | S3 is covered by Laravel. **CloudflareKV** is not, and neither is DynamoDB-as-KV. Build only the drivers Laravel lacks; skip S3. |
| `wallets` | migrations + services | No hook, but a connector could publish the Laravel migrations for its tables and bind the repositories. That's real convenience for a schema-heavy domain library. |
| `cache-engine` | `Cache::extend()` | Laravel covers apc/array/database/file/memcached/redis/dynamodb. byjg adds only **shmop**, **tmpfs**, **session** — plus atomic-operation and garbage-collector interfaces Laravel's store contract lacks. Thin, but cheap to build. |
| `jinja-php` | `View::addExtension()` | The hook is clean and the work is small. The question is who wants Jinja in a Blade project — probably only teams porting Python templates. |
| `serializer` | response macros | Laravel resources cover JSON well but have **no XML/CSV output**. `response()->xml($data)` is a nice macro. Cosmetic, cheap, low risk. |
| `crypto` | `Encrypter` binding | Laravel's `Crypt` is AES-CBC/GCM with a static key. The **keyless exchange mechanism** is the novel part — if that's genuinely useful, it deserves docs more than it deserves a connector. |

---

## Tier 3 — do not build

| Component | Why not |
|---|---|
| `mailwrapper` | Laravel already ships SES, Mailgun, Postmark, Resend, SMTP, sendmail, log, failover, round-robin. byjg adds **nothing Laravel lacks**. |
| `featureflag` | **Laravel Pennant** is first-party. Competing with it is a losing position. |
| `migration` | Laravel migrations are core and well-loved, and already accept raw SQL. Replacing them fights the framework. |
| `config` | PSR-11 DI + config, i.e. exactly what Laravel's container and `config/` already are. Direct conflict. |
| `restserver` | It *is* a routing/server framework. Two routers in one app is not a feature. |
| `micro-orm` | See the Eloquent discussion below. |
| `webrequest` | Laravel's `Http` facade wraps Guzzle. No gap. |
| `i18n` | Laravel localisation is core. |
| `authuser` | Laravel has Eloquent/database user providers. A custom `Auth::provider()` only pays off for someone migrating an existing byjg app — worth revisiting **if that migration path becomes a goal**, not before. |
| `shortid`, `convert`, `wordnumber`, `fonemabr`, `uri`, `singleton-pattern`, `xmlutil`, `imageutil`, `text-classifier`, `llm-api-objects`, `phpthread`, `scriptify`, `soap-server`, `graphql-service` | Plain libraries with no Laravel extension point. `composer require` and use them. A connector would add a service-provider binding and nothing else. |

---

## Answers to the open questions

### message-queue — yes, and it's the one to do first

Right instinct, and the reason is stronger than "Redis events". Laravel's queue *is* solid on
Redis; the gap is **RabbitMQ**, which Laravel has never shipped. Combined with the URI-scheme
factory, one connector covers every broker byjg supports now and later. Start here.

### serializer, shortid, crypto — mostly no

- **`shortid`** — no. It's a pure function. There is nothing to connect; a `Str::macro()` would be
  decoration, not integration.
- **`serializer`** — marginal yes, as response macros for XML/CSV, which Laravel genuinely lacks.
  Cheap. Don't oversell it.
- **`crypto`** — depends entirely on the keyless exchange mechanism. If that solves a problem
  Laravel's `Crypt` can't, it's interesting; if it's another AES wrapper, skip it.

The pattern: **small libraries rarely make good connectors.** Their size means they have no
extension surface, and a connector wrapping a one-line library is pure overhead. Size isn't the
disqualifier — absence of a hook is.

### Anydataset as an Eloquent alternative — not as framed

Positioned as "replace Eloquent" this loses. Eloquent is why many people choose Laravel; it's
wired into validation, resources, policies, factories, and the whole package ecosystem. A parallel
ORM splits the codebase and every third-party package keeps expecting Eloquent models.

There are two adjacent framings that **do** work:

1. **Drivers Laravel lacks.** `anydataset-db` ships **OCI8/Oracle** and `PdoDblib`. Laravel has no
   first-party Oracle support — that's a real, specific gap, and a much easier sell than replacing
   the ORM. `DatabaseRouter` (read/write splitting) is also more capable than Laravel's read/write
   config.
2. **Heterogeneous sources, which Eloquent was never for.** The `anydataset-*` family reads CSV,
   XML, JSON, text, arrays, S3, DynamoDB, MongoDB, SPARQL through one iterator interface. Laravel
   has no equivalent. "Query a CSV, an API and a table the same way" is a distinct capability, not
   an ORM competitor — and it doesn't ask anyone to give up Eloquent.

Framing 1 is the more defensible first step.

---

## Suggested order

Already shipped: **`openapi`** and **`statemachine`** — see [Shipped](#shipped). What is left:

1. **`message-queue`** — biggest gap, best design fit, widest audience
2. **`sms-client`** — small, self-contained, no Laravel equivalent
3. **`jwt-session`** — small, clean hook, genuinely novel
4. Reassess. Tier 2 is worth revisiting once real usage shows which gaps people actually hit.

Each one should follow the pattern already in `docs/writing-a-connector.md`: its own directory,
`publishables()` and `postInstallNotes()`, a `suggest` entry rather than a `require`, and testbench
tests covering the component-missing case.

---

## A note on scope

There are ~50 `php-*` repositories. Roughly **three** are strong connector candidates, and about
eight more are defensible. That ratio is healthy, not disappointing — most of these are good
libraries precisely because they do one thing and need no framework wiring.

The risk to watch is building connectors because a component exists rather than because a gap does.
The `gluo.connectors` registry makes adding one cheap, which makes it easy to add ones nobody asked
for. Let demand pull them in.
