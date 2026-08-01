# Changelog

All notable changes to Verdict will be documented in this file.

## Unreleased

- Scaffold the Laravel package for PHP 8.3 and Laravel 12/13.
- Add capability registration and Laravel Gate/Policy authorization.
- Add a fail-closed Laravel AI guarded-tool adapter that preserves tool approvals.
- Add pluggable, redacted decision evidence with stable argument fingerprints.
- Add deterministic IDOR coverage, static analysis, formatting, type coverage, and CI.
- Add target-bound capability executors and a strict Laravel AI `BoundTool` adapter.
- Re-inspect the same resolved in-process target immediately before bound execution.
- Distinguish proposal and execution authorization evidence.
- Convert explicitly signaled target-resolution failures into recorded denials.
- Document the current freshness boundary and unsafe long-running use of in-memory evidence.
