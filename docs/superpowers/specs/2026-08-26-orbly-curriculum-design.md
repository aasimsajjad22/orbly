# Orbly — Symfony Learning Curriculum Design

**Date:** 2026-08-26
**Status:** Approved

## 1. Vision

Orbly is a mini social network API, built as a Symfony learning curriculum and CV/portfolio
showcase. The goal is to cover Symfony end-to-end — authentication, Doctrine, async messaging
(RabbitMQ), payments, transactional email, CLI commands, testing, Docker, Kubernetes, and AWS —
by building one real, continuously-growing product rather than isolated exercises.

The author is an experienced Laravel/PHP backend developer (works daily on a large tenant-aware
Laravel 12 codebase) who is new to Symfony. Teaching leans on Laravel/Apex analogies throughout to
transfer existing expertise rather than starting from zero.

The project is built in 14 sequential phases (0–13). Each phase adds one real capability, ends in
a working and tested state, and is delivered as its own git branch merged into `main`. The commit
history and PR-style progression is itself part of the portfolio artifact.

## 2. Domain

A social network: users register, search for other users, send/accept friend requests, follow
each other, create posts, like/comment on posts, and send direct messages. A Pro/Premium
subscription (via Stripe) unlocks perks like higher friend limits and no post limits.

## 3. Architecture

- **Style:** API-first / headless. No server-rendered UI — the app is a pure REST API, documented
  via OpenAPI/Swagger and demoed via Postman. This mirrors the author's real-world API work and
  keeps focus on backend depth rather than UI concerns.
- **Framework:** Symfony 8.1 (latest stable at project start), PHP 8.4+.
- **Database:** PostgreSQL (deliberately different from the author's MySQL day job, to show range
  and exercise Doctrine's fuller feature set — native arrays, JSON, enums).
- **Auth:** JWT via LexikJWTAuthenticationBundle — stateless bearer tokens + refresh tokens. Closest
  analogue to Laravel Sanctum/Passport.
- **Async messaging:** Symfony Messenger with the AMQP (RabbitMQ) transport. Analogue to Laravel
  Queues/Jobs.
- **Payments:** Stripe, test-mode only. Never live API keys, never real charges. Local webhook
  delivery via the Stripe CLI (`stripe listen --forward-to`).
- **Email:** Symfony Mailer, using Mailpit locally to capture outgoing mail without sending real
  email.

## 4. Explicitly Out of Scope for v1

To keep the curriculum bounded and each phase finishable, the following are deliberately excluded
from the initial build and noted as v2 stretch goals if revisited later:

- Any server-rendered frontend/UI (Swagger/Postman is the demo surface for v1).
- Real-time features (WebSocket chat, live notifications).
- Multi-tenancy.
- Content moderation / abuse handling.
- Live Stripe payments (test mode only, permanently, unless explicitly revisited).

## 5. Curriculum Roadmap

| # | Phase | Scope | Key Symfony concepts | Laravel/Apex analogue |
|---|-------|-------|------------------------|--------------------------|
| 0 | Foundations & environment | Scaffold app, Docker Compose stack (PHP-FPM, Nginx, Postgres, RabbitMQ, Mailpit), git repo, CI skeleton | `symfony new`, `bin/console`, `.env` | `laravel new`, `artisan`, `.env` |
| 1 | Users & Doctrine | User entity, migrations, repositories, fixtures, registration endpoint | Doctrine ORM, Doctrine Migrations, Foundry fixtures | Eloquent models, migrations, factories/seeders |
| 2 | Authentication | Login/refresh endpoints, `security.yaml`, authorization | LexikJWTAuthenticationBundle, Voters | Sanctum, `auth.php`, Policies/Gates |
| 3 | Social graph | Friend requests (send/accept/reject), follow relationships, user search | Self-referencing Doctrine relations, DQL, repository queries | Pivot tables, Eloquent relations, query builder |
| 4 | Posts & feed API | Post CRUD, likes/comments, paginated feed | Serializer groups, Doctrine Paginator | API Resources, `paginate()` |
| 5 | Async messaging (RabbitMQ) | Friend-request notifications, feed fan-out to followers, welcome message — all dispatched async | Symfony Messenger, AMQP transport, message handlers | Queues, Jobs, `ShouldQueue` |
| 6 | Payments (Stripe) | Pro/Premium subscription via Stripe Checkout + Billing; webhook endpoint for subscription/invoice events, dispatched through Messenger for idempotent async processing; Customer Portal for self-service cancel/upgrade | Stripe Checkout Sessions, Billing, webhook signature verification | — (new concept for both stacks) |
| 7 | Transactional email | Welcome email, friend-request email, **plus** retrofitting receipt/renewal/payment-failed emails onto phase 6's Stripe events | Symfony Mailer, Twig email templates, Mailpit | Mailable, Blade mail views |
| 8 | CLI commands & scheduling | Daily digest command, expired-friend-request cleanup, demo data seeding, subscription reconciliation (catch missed webhooks, expire lapsed Pro accounts) | Custom `bin/console` commands, Symfony Scheduler | Artisan commands, `schedule()` |
| 9 | Testing | Functional tests (`WebTestCase`) per endpoint, unit tests, fixtures per test | PHPUnit, Doctrine test fixtures | Feature/Unit tests, `RefreshDatabase` |
| 10 | Dockerize for production | Multi-stage Dockerfile, prod-parity compose, healthchecks | — | — |
| 11 | Kubernetes | Local cluster (kind/minikube), Deployments, Services, ConfigMaps/Secrets, readiness/liveness probes | — | — |
| 12 | AWS deployment | ECS Fargate, RDS Postgres, Amazon MQ, SES, GitHub Actions CI/CD | — | — |
| 13 | Polish & showcase | Architecture diagram, published OpenAPI docs, demo script, CV bullet points | — | — |

Phase design beyond the first milestone is deliberately done lazily — in detail right before that
phase starts — rather than all upfront, so later designs benefit from what was actually built
earlier and don't go stale.

## 6. Per-Phase Workflow

1. Explain the Symfony concept(s) for the phase, anchored to the closest Laravel/Apex equivalent.
2. Show the concrete code/plan for the next step and get a go-ahead before writing it — small
   chunks, not large unreviewed diffs.
3. Write a failing test first where practical (TDD-lite), then implement, then run the suite.
4. Commit at each meaningful checkpoint with a clear message.
5. Merge the phase branch (`phase-N-<slug>`) into `main` when the phase is verified working.
6. Quick recap of what was learned and what's next.

## 7. Testing & Verification Strategy

- PHPUnit for both unit and functional (`WebTestCase`) tests.
- Every new API endpoint gets at least one functional test covering the happy path and one
  meaningful failure path (validation error, auth failure, or not-found).
- Stripe and RabbitMQ interactions are tested against real local instances (Stripe test mode +
  CLI, local RabbitMQ container) rather than mocked, to keep the learning grounded in real
  integration behavior — mirrors the "no mocking the database" lesson from the author's day job.
- Each phase is not considered done until its tests pass and the feature has been manually
  exercised via Postman/curl at least once.

## 8. First Milestone

Phases 0+1 combined form the first implementation plan: scaffold the Symfony app and Docker
Compose stack, then build the User entity, Doctrine setup, and registration endpoint. This is the
next unit of work to hand to detailed planning.
