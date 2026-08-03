# Release policy

Verdict follows [Semantic Versioning](https://semver.org/) and uses Git tags prefixed with `v`.

## Developer-preview releases

Verdict is pre-1.0 software. A `0.x` release is usable, tested software, but its public API may
change between minor releases as real Laravel applications expose better boundaries. Breaking
changes must be called out in the changelog and include an upgrade path when practical.

- Patch releases (`0.1.1`) contain compatible fixes, documentation, and narrowly additive changes.
- Minor releases (`0.2.0`) may change APIs or migrations and must include upgrade notes.
- Pre-release suffixes are reserved for builds that are not ready for ordinary developer-preview
  installation. `v0.1.0` is the first developer preview, not an alpha tag.

Before 1.0, security and correctness fixes are supported on the latest released minor line. Older
minor lines may receive a fix when the change is low-risk, but are not guaranteed maintenance.

## Supported platform matrix

| Verdict line | PHP | Laravel | Laravel AI | Status |
|---|---|---|---|---|
| `0.1.x` | `8.3`–`8.5` | `12.x`, `13.x` | `0.10.2`–`0.10.x` | Developer preview |

Laravel AI is pre-1.0. Verdict intentionally constrains each supported Laravel AI minor line and
tests released public contracts rather than allowing an unreviewed minor upgrade. Support for a
new Laravel AI minor requires a compatibility review and a Verdict release.

## Intended public surface in 0.1

The supported integration surface is:

- `Verdict` and `VerdictManager` registration, release, evaluation, approval, and tool-adapter
  methods documented in the README;
- capability, action, target, rate-limit, execution-claim, context-release, and evaluation value
  objects used by those documented examples;
- contracts under `Fissible\Verdict\Contracts` for application-supplied adapters;
- `BoundTool`, with `GuardedTool` retained only as the documented migration adapter; and
- published configuration, migrations, and Artisan commands.

Workbench classes, test fixtures, private/protected methods, undocumented implementation classes,
database row shapes, and internal evidence metadata keys are not stable extension points during
the developer preview.

## Release readiness

A release is cut only when:

1. `composer validate --strict` passes.
2. `composer test` passes locally.
3. The complete GitHub Actions compatibility matrix passes.
4. Installation and package discovery succeed from a clean consumer project.
5. The changelog describes every user-visible change.
6. New configuration and migrations have publication tests and upgrade notes.
7. Documentation distinguishes implemented behavior from planned behavior.
8. Known security limitations are documented without overstating guarantees.

The release commit is tagged only after these checks pass. `VERSION`, `release.sh`, the curated
`CHANGELOG.md`, and the tag-triggered GitHub release workflow follow the Fissible organization
release convention. The release script promotes the existing Unreleased notes without regenerating
prior release history. Run `bash release.sh patch`, `minor`, or `major` from a clean `main` branch
for subsequent releases. Publication to Packagist and changing repository visibility are explicit
maintainer actions, not automated side effects of a merge. Verdict is currently public and
registered on Packagist.

## Security releases

Vulnerabilities are reported privately through the process in
[.github/SECURITY.md](.github/SECURITY.md). A security release should minimize disclosure before a
fix is available, include regression coverage, and describe affected versions and required operator
action without publishing sensitive reproduction data.

## Toward 1.0

Verdict's 1.0 is not coupled to Laravel AI reaching 1.0. It requires stable documented contracts,
an explicit Laravel AI compatibility strategy, upgrade-safe migrations, real-application feedback,
and no known silent bypass within the supported integration paths. Optional UI, arbitrary
free-text PII detection, general anomaly detection, and exactly-once external effects are not 1.0
requirements.
