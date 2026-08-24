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
| `0.5.x` | `8.3`–`8.5` | `12.x`, `13.x` | `0.10.2`–`0.10.x` | Developer preview — current |
| `0.4.x` | `8.3`–`8.5` | `12.x`, `13.x` | `0.10.2`–`0.10.x` | Superseded |
| `0.3.x` | `8.3`–`8.5` | `12.x`, `13.x` | `0.10.2`–`0.10.x` | Superseded |
| `0.2.x` | `8.3`–`8.5` | `12.x`, `13.x` | `0.10.2`–`0.10.x` | Superseded |
| `0.1.x` | `8.3`–`8.5` | `12.x`, `13.x` | `0.10.2`–`0.10.x` | Superseded |

Only the current line receives fixes. Older `0.x` lines may receive a low-risk fix but are not
guaranteed maintenance.

Laravel AI is pre-1.0. Verdict intentionally constrains each supported Laravel AI minor line and
tests released public contracts rather than allowing an unreviewed minor upgrade. Support for a
new Laravel AI minor requires a compatibility review and a Verdict release.

`composer.json` pins `laravel/ai: ^0.10.2`, which in Composer's pre-1.0 caret semantics means
`>=0.10.2 <0.11.0`. A `0.11.0` release is therefore not picked up automatically; widening the
constraint is a deliberate act that triggers the compatibility review above. See
[MILESTONES.md](MILESTONES.md) for the current upstream dependency watch.

## Intended public surface in 0.x

This surface has held unchanged since `0.1` and is restated here for each developer-preview line. Any
change to it is a minor release with upgrade notes, per the policy above.

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

**Service constructors are excluded.** `VerdictManager`, the managers it composes, and the tool
adapters are container-resolved collaborators; constructing them directly is unsupported and their
constructors are marked `@internal`. A new collaborator may therefore be added as a required
constructor parameter in any release without that counting as a change to the surface above. Resolve
Verdict services from the container, and build tool adapters through `Verdict::bound()` or
`Verdict::guard()`. See [ADR 0019](docs/adr/0019-verdict-services-are-container-resolved.md).

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
9. No open issue labeled `bug`, or describing incorrect published behavior, falls within the
   release's scope. A release never ships over a known defect in what it publishes — including a
   wrong evaluation result or a claim the code does not support.

The release commit is tagged only after these checks pass. `VERSION`, `release.sh`, the curated
`CHANGELOG.md`, and the tag-triggered GitHub release workflow follow the Fissible organization
release convention. The release script promotes the existing Unreleased notes without regenerating
prior release history. Run `bash release.sh patch`, `minor`, or `major` from a clean `main` branch
for subsequent releases. Publication to Packagist and changing repository visibility are explicit
maintainer actions, not automated side effects of a merge. Verdict is currently public and
registered on Packagist.

## Release cadence

Releases are **milestone-gated, not time-driven**. A version is cut when a themed milestone is
complete and the readiness checklist above passes — not on a schedule and not once per merge. Shipping
a release for every landed PR reads as unstable and pressures the readiness gate; batching a themed
set of changes behind one tag is the norm.

The single exception is correctness and security. A defect in published behavior — readiness item 9,
a wrong evaluation number, a claim the code does not support — is **not** held back to preserve a
batch. It ships a prompt patch release of its own rather than waiting for the next themed minor.
Batch features; never batch a fix for something already published wrong.

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
