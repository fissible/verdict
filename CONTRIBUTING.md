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

## Testing changes

Add the narrowest useful tests at each affected boundary:

- unit tests for canonicalization, validation, and value objects;
- feature tests for Laravel container, Policy, database, concurrency, and failure behavior;
- workbench tests only when the behavior benefits from an executable demonstration; and
- security and utility cases together when a defense could simply deny all legitimate behavior.

Tests should assert both the protected side effect and the evidence produced. Unexpected
infrastructure exceptions should remain distinguishable from ordinary policy denials.

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
