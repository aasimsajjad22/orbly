# Orbly

A small social network built with Symfony 8 — friend requests, a filtered feed, async processing, and Stripe subscriptions.

I built this to learn Symfony properly, coming from seven years of Laravel. Rather than following a tutorial, I built one application in sequential phases, each one adding a real capability and ending in a tested, working state. The git history is meant to be readable as a story: each phase is a branch, each branch is a set of small commits.

> **Status:** the application is feature-complete and tested. Containerisation, Kubernetes, and AWS deployment are the remaining phases — see [Roadmap](#roadmap).

---

## What it does

| | |
|---|---|
| **Accounts** | Email + password, or Google sign-in. Email verification required before first login. |
| **Social graph** | Friend requests with accept/decline/cancel, mutual-consent auto-accept, and blocking that tears down existing relationships. |
| **Posts** | Public, friends-only, or private. Soft-deleted. Likes and comments with denormalised counters. |
| **Feed** | Cursor-paginated, filtered by friendship, visibility, and blocks — all in SQL. |
| **Payments** | Stripe Checkout subscription with webhook-driven state, gating a Pro feature. |
| **Interfaces** | A JSON API (JWT) and a server-rendered web UI (session), over the same domain services. |

---

## Stack

- **PHP 8.4**, **Symfony 8**
- **PostgreSQL 16** — Doctrine ORM, migrations, Foundry factories
- **RabbitMQ 4** — Symfony Messenger for async work
- **Stripe** — Checkout, Billing Portal, webhooks (test mode only)
- **Twig + Tailwind + Turbo + Live Components** — the web UI, with almost no hand-written JavaScript
- **Mailpit** — local mail catching
- **PHPUnit 13** — ~114 tests

---

## Running it locally

**Requirements:** PHP 8.4 with the `amqp` extension, Composer, Docker, and the Symfony CLI.

```bash
git clone <REPO_URL> orbly && cd orbly
composer install

# Postgres, RabbitMQ and Mailpit
docker compose up -d

# Local overrides — see "Configuration" below
cp .env .env.local

php bin/console doctrine:migrations:migrate
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:fixtures:load

symfony serve -d
```

Then, in separate terminals:

```bash
# Tailwind, rebuilding as templates change
php bin/console tailwind:build --watch

# The queue worker — without this, emails and webhooks are never processed
php bin/console messenger:consume async -vv
```

Open **http://127.0.0.1:8001** and sign in as `sajjad@orbly.test` / `password`.

### Local services

| | URL | Credentials |
|---|---|---|
| App | http://127.0.0.1:8001 | `sajjad@orbly.test` / `password` |
| Mailpit | http://localhost:8025 | — |
| RabbitMQ | http://localhost:15672 | `orbly` / `orbly` |
| Postgres | `127.0.0.1:5433` | `orbly` / `orbly` |

Note the Postgres host port is **5433**, not the default 5432, to avoid clashing with a local install.

### Configuration

Everything sensitive goes in `.env.local`, which is git-ignored.

```dotenv
DATABASE_URL="postgresql://orbly:orbly@127.0.0.1:5433/orbly?serverVersion=16&charset=utf8"
MESSENGER_TRANSPORT_DSN=amqp://orbly:orbly@127.0.0.1:5672/%2f/messages
MAILER_DSN=smtp://127.0.0.1:1025

# Optional — Google sign-in
GOOGLE_CLIENT_ID=...apps.googleusercontent.com

# Optional — Stripe, test keys only
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PRO_PRICE_ID=price_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_API_VERSION=2025-03-31.basil
```

For Stripe webhooks locally:

```bash
stripe listen --forward-to localhost:8001/api/stripe/webhook
```

Copy the `whsec_` it prints into `.env.local`. It changes each session.

### Tests

```bash
php bin/phpunit
```

The test suite needs no RabbitMQ, no Stripe account, and no mail server — Messenger uses an in-memory transport, Mailer uses `null://`, and the Google and Stripe gateways are swapped for fakes. It does need Postgres running, since the tests exercise real queries.

---

## API

A Postman collection lives in [`postman/`](postman/). Import it, run **Auth → Login**, and the JWT is captured automatically for every other request.

<details>
<summary>Endpoints</summary>

```
POST   /api/register                     create an account
POST   /api/login                        exchange credentials for a JWT
POST   /api/auth/google                  exchange a Google ID token for a JWT
GET    /api/verify-email                 confirm an email address (signed link)
POST   /api/resend-verification          request a new link (rate limited)
GET    /api/me                           the authenticated user

POST   /api/friend-requests              send
GET    /api/friend-requests              list, ?direction= &status=
POST   /api/friend-requests/{id}/accept  recipient only
POST   /api/friend-requests/{id}/decline recipient only
DELETE /api/friend-requests/{id}         sender cancels
GET    /api/friends                      paginated
DELETE /api/friends/{id}                 unfriend
POST   /api/blocks/{id}                  block
DELETE /api/blocks/{id}                  unblock

POST   /api/posts                        create
GET    /api/posts/{id}                   read
PATCH  /api/posts/{id}                   author only
DELETE /api/posts/{id}                   soft delete
GET    /api/feed                         cursor-paginated
POST   /api/posts/{id}/like              idempotent
POST   /api/posts/{id}/comments          comment
DELETE /api/comments/{id}                comment author or post author

GET    /api/subscription                 current state
POST   /api/subscription/checkout        Stripe Checkout session
POST   /api/subscription/portal          Stripe Billing Portal
POST   /api/stripe/webhook               signature-verified
```

</details>

---

## Design decisions

The parts I'd want to talk through in a code review.

### Friendships are stored twice

A friendship between A and B is two rows: `(A, B)` and `(B, A)`. Denormalised on purpose.

Friendship is symmetric but a table row is directional, so a single row means every read needs `WHERE user_id = :me OR friend_id = :me` — an OR across two columns that indexes badly, plus a conditional at every call site to work out which column you're in. With mirror rows, "list my friends" is one indexed equality and `friend_id` is *always* the other person.

The feed join is where it pays off:

```sql
JOIN friendships f ON f.friend_id = p.author_id
WHERE f.user_id = :me
```

The cost is that the two rows must be written and deleted together. `FriendshipService` wraps both in a transaction, so a half-created friendship can't exist, and a test asserts both rows appear on accept.

### The feed uses cursor pagination

Offset pagination breaks on a moving feed: a post created between page 1 and page 2 shifts everything down, so you see one post twice and skip another.

Instead, pages are "older than this point", with a composite cursor of timestamp **plus** ID — a timestamp alone is ambiguous when several posts share a second. The cursor is base64-encoded so clients treat it as opaque.

The endpoint asks for `limit + 1` rows to detect a next page, rather than running a `COUNT(*)` that would be slow and stale by the time it returned.

### Private posts return 404, not 403

A 403 confirms the resource exists. For friend requests that's harmless. For a private post it lets someone walk the IDs and map who posts and when — so `PostVoter` denials become 404s, indistinguishable from a post that was never written.

Edit and delete still return 403: to have the ID you already know it exists.

### Stripe is the source of truth

The local `Subscription` table is a mirror, updated by webhooks. Access is decided by Stripe's status, never by comparing dates locally.

Three things this required:

- **Signature verification** on every webhook — it's the only authentication the endpoint has.
- **Idempotency**, via a unique index on Stripe's event ID. Stripe retries on any non-2xx and can duplicate after a success; processing `invoice.paid` twice would grant two billing periods for one payment.
- **Fast acknowledgement.** The endpoint verifies, records, queues, and returns 200 in milliseconds. The actual work happens in a worker, so a slow handler can't cause a retry storm.

`past_due` keeps Pro access, deliberately — cutting someone off over a temporarily declined card is hostile, and most dunning failures resolve within days. That rule lives in one place, on the status enum.

One gotcha worth recording: Stripe's Basil API version (2025-03-31) moved `current_period_end` off the subscription object and onto its items. Code that upgraded without pinning kept getting 200 OK with the field silently undefined. The payload reader checks both locations, and the API version is pinned in config.

### External services sit behind interfaces

`GoogleIdTokenVerifier` and `StripeGateway` are interfaces with a real implementation and a fake, swapped per environment in `services_test.yaml`. The fakes are marked `#[When('test')]`, so they don't exist in the dev or production container at all.

Beyond keeping tests off the network, this makes the failure cases testable — a Google token with the wrong `aud`, an unverified email claim, a forged webhook signature. None of those can be produced on demand against the real services, and each is now a test.

### Two firewalls over one user

`^/api` is stateless JWT; everything else is session-based form login. Same user provider, same password hasher, same `UserChecker` — so the email-verification gate applies to both entry points without duplication.

The web controllers call the domain services directly rather than the app's own API over HTTP. Calling your own API from your own server adds a round trip, loses the transaction boundary, and makes error handling worse.

### Async where failure shouldn't block the user

Verification emails, friend-request notifications, and Stripe webhook processing all go through Messenger. Registration no longer waits on SMTP, and a mail outage no longer fails account creation.

Messages carry IDs, not entities: by the time a worker runs, the row may have changed. Every handler reloads and re-checks — the friend-request notification skips sending if the request was accepted or cancelled while it sat in the queue.

Returning versus throwing is the retry decision. "User was deleted" returns, because retrying can't help. "SMTP refused the connection" throws, because it probably will.

---

## Project layout

```
src/
├── Controller/          JSON API controllers
│   └── Web/             Twig controllers
├── Dto/                 request payloads, validated by #[MapRequestPayload]
├── Entity/
├── Enum/                backed enums, mapped by Doctrine
├── Message/             Messenger messages (plain data)
├── MessageHandler/
├── Pagination/          the cursor value object
├── Payment/             Stripe gateway interface + real/fake implementations
├── Repository/          all read queries
├── Security/
│   ├── Google/          ID token verification
│   └── Voter/           object-level authorization
├── Service/             business rules
└── Twig/Components/     Live Components
```

---

## Roadmap

- [ ] **Phase 8** — transactional email: shared layout, CSS inlining, plain-text alternative part
- [ ] **Phase 9** — CLI commands and scheduling, including counter reconciliation and webhook-event pruning
- [ ] **Phase 10** — formalise the test setup
- [ ] **Phase 11** — multi-stage production Dockerfile
- [ ] **Phase 12** — Kubernetes on a local cluster
- [ ] **Phase 13** — AWS: ECS Fargate, RDS, Amazon MQ, SES, GitHub Actions
- [ ] **Phase 14** — OpenAPI docs, architecture diagram

### Known gaps

Honest list, kept in `TODO.md`:

- The real Google ID token verifier has never been exercised against Google. All Google tests run through the fake.
- Query-count testing was removed — the profiler's DB collector counts every query since kernel boot, not per request, so the numbers were meaningless. Needs a Doctrine middleware instead.
- A React island for the feed and composer is deferred. It needs a cleaner bridge between the session UI and the JWT API than putting a token in the page HTML.
- `messenger` currently uses RabbitMQ locally; the Docker phase will need the `amqp` extension in the image.

---

## Licence

MIT.
