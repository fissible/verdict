# Contributing to Verdict

Verdict is a pre-1.0 security package. Contributions are welcome, but changes to a security
boundary need an explicit threat model, failure behavior, and evidence story—not only a successful
happy path.

## Before opening a pull request

- Search the issue tracker and open or claim an issue before starting substantial work.
- Use issues labeled `scope: ready` for implementation work. An issue labeled `scope: design`
  still has unresolved product or security decisions and is not ready for an implementation PR.
- Keep changes focused. Do not combine a new policy primitive, storage adapter, and user interface
  into one pull request unless the issue explicitly calls for them together.
- Never include provider credentials, real prompts, customer data, or unredacted evidence.
- Report suspected vulnerabilities privately as described in
  [.github/SECURITY.md](.github/SECURITY.md).

## Development setup

Verdict requires PHP 8.3 or newer and Composer.

```bash
composer install
composer test
```

`composer test` runs PHPStan, Pint in check mode, 100% type coverage, and the Pest suite. Pull
requests must pass the complete PHP/Laravel/operating-system matrix in GitHub Actions.

When adding or changing a documented limitation, add its `@verdict-claim` annotation in
`docs/limitations.md` and either annotate the proving Pest test, give an untestable reason, or link
the required open follow-up issue. `composer test` verifies this mapping.

The Testbench storefront workbench can be started with:

```bash
composer build
vendor/bin/testbench serve
```

The ordinary test suite is deterministic and must not require network access or provider
credentials. Live-model evaluation work must use an explicit command or test group and synthetic,
reversible data.

## Security design expectations

A change that affects authorization, confirmation, replay, rate limits, identity, target freshness,
data release, or durable security state should document:

1. Which inputs are trusted and which are model- or user-controlled.
2. What the application resolves from trusted state.
3. The fail-closed behavior for missing, malformed, stale, or unavailable state.
4. Concurrency, retry, transaction, and replay behavior.
5. What evidence is retained and which sensitive values are excluded.
6. Security containment and legitimate utility tests.

Substantial or difficult-to-reverse decisions should be recorded in `docs/adr`. Storage migrations
must be additive within a patch release and must include publication/configuration tests.

A new collaborator on a Verdict service is a **required** constructor parameter. Do not make it
optional to preserve direct construction, and do not fall back to `Container::getInstance()`: these
services are container-resolved, their constructors are `@internal`, and a default that resolves
itself from a global is a hidden dependency that degrades silently. See
[ADR 0019](docs/adr/0019-verdict-services-are-container-resolved.md).

## Testing changes

Add the narrowest useful tests at each affected boundary:

- unit tests for canonicalization, validation, and value objects;
- feature tests for Laravel container, Policy, database, concurrency, and failure behavior;
- workbench tests only when the behavior benefits from an executable demonstration; and
- security and utility cases together when a defense could simply deny all legitimate behavior.

Tests should assert both the protected side effect and the evidence produced. Unexpected
infrastructure exceptions should remain distinguishable from ordinary policy denials.

### Genuine concurrency tests

For a database atomicity claim, do not call a store twice in one test process and call that a race.
Use `tests/Support/ConcurrencyHarness.php` with a small child script in
`tests/Support/concurrency-children/`. Each child must:

- decode the JSON payload supplied as `$argv[1]`, including its connection configuration;
- create and force its own PDO connection, then apply any session setup, before the barrier;
- signal readiness on fd 3, then wait for the parent release on fd 4;
- perform one mutation; and
- catch `Throwable` around that mutation and write one JSON outcome to STDOUT: an `ok: true`
  result on success, or `ok: false` with the exception facts on failure;

The parent receives transport records shaped as `{exit_code, stdout, stderr}` from
`ConcurrencyHarness::run()` and must decode each record's `stdout` before asserting the outcomes.
Keep STDOUT to that final JSON object only: PHP CLI notices or other output corrupt its JSON
channel. Do not produce unbounded output before readiness; the harness has a 10-second readiness
deadline and drains child output while it waits, but pre-barrier diagnostics can still delay the
signal that establishes the race.

The harness releases every child only after all connections are ready. The optional
`force_serializable` examples use PostgreSQL's `SET SESSION CHARACTERISTICS` syntax; only set it
for PostgreSQL children, and do so before signalling readiness. The default test run uses SQLite
and intentionally skips these cases. To run a real MariaDB race locally, start the checked-in
service and use Laravel's MariaDB connection name:

```sh
docker compose -f docker-compose.spike.yml up -d mariadb
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3309 DB_DATABASE=verdict_spike \
  DB_USERNAME=verdict DB_PASSWORD=verdict vendor/bin/pest tests/Feature/SecurityStateConcurrencyRetryTest.php
```

MySQL and PostgreSQL use the corresponding `mysql` and `pgsql` connection names and services in
the same compose file. SQLite does not exercise the relevant locking and isolation behavior.

## Pull requests

A pull request should include:

- a linked issue;
- a concise description of the security boundary being changed;
- tests for success, denial, and relevant failure/concurrency paths;
- documentation and changelog updates for user-visible behavior; and
- migration or upgrade notes when existing applications must act.

By contributing, you agree that your contribution is licensed under the repository's MIT license.

Maintainers should follow [`RELEASES.md`](RELEASES.md) and use the repository's `release.sh` rather
than editing tags or the `VERSION` file independently.
