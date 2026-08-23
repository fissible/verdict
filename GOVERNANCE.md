# Governance

Verdict is a single-maintainer project. This document says who holds access to what, and what
each role is responsible for, so that the trust model of the project itself is as explicit as the
one it asks adopters to apply to their agents.

## Roles

### Maintainer

Currently **Allen McCabe** ([@fissible](https://github.com/fissible)) — the sole maintainer.

Responsible for:

- reviewing and merging every change to `main` (pull requests are required; see
  [CONTRIBUTING.md](CONTRIBUTING.md));
- cutting releases, signing release tags, and publishing to Packagist
  ([RELEASES.md](RELEASES.md), [ADR 0030](docs/adr/0030-release-tags-are-ssh-signed.md));
- receiving and triaging private vulnerability reports within the timeframes in
  [.github/SECURITY.md](.github/SECURITY.md);
- recording substantial decisions as ADRs in [docs/adr](docs/adr).

### Contributor

Anyone who opens an issue or a pull request. Contributors have no standing access; their changes
land only through a reviewed pull request that passes the full CI matrix. The expectations for a
contribution — scope, security design notes, tests — are in [CONTRIBUTING.md](CONTRIBUTING.md).

## Access to sensitive resources

| Resource | Who has access |
| --- | --- |
| GitHub repository (admin) | Maintainer |
| `main` branch | Nobody directly — pull request and passing `CI success` check required for everyone, including the maintainer |
| GitHub Actions secrets (`SCORECARD_TOKEN`, `FISSIBLE_PAT`) | Maintainer; never exposed to pull requests from forks |
| Release tag signing key (SSH) | Maintainer, on their own machine |
| Packagist package `fissible/verdict` | Maintainer |
| Private vulnerability reports (GitHub security advisories) | Maintainer |

There are no shared accounts, bots with write access, or deploy keys. Dependabot opens pull
requests and cannot merge them.

## Changing this document

Adding a maintainer, granting any standing access, or changing the release or disclosure process
is itself a change to this file, made by pull request like any other.
