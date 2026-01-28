# Atlas Bank (Learning Project)

A production-style PHP banking API skeleton focused on fintech security and infrastructure:
- JWT auth + password hashing
- Idempotency keys for money movement
- Ledger-first architecture (double-entry ready)
- Rate limiting hooks
- Bank transfer abstraction (Nigeria bank rails pluggable)

## Requirements
- PHP 8.2+
- MySQL 8+

## Quickstart
1) `composer install`
2) Create `.env` from `.env.example`
3) Run migrations: `./bin/migrate`
4) Serve locally: `./bin/serve`

## Endpoints (starter)
- `GET /health`
- `POST /auth/register`
- `POST /auth/login`

> Next: internal transfers + Nigerian bank transfer flow with provider adapters.
