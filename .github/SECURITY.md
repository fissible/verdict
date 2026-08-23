# Security policy

Please do not disclose suspected security vulnerabilities in public issues, discussions, or pull
requests. Reports may contain evidence about authorization behavior, so remove prompts, secrets,
customer data, and provider credentials unless they are essential to reproduce the issue.

Report vulnerabilities privately through GitHub's security advisory feature for this repository:

<https://github.com/fissible/verdict/security/advisories/new>

## Response timeframe

- A report is acknowledged within **3 business days**.
- An initial assessment (affected versions, severity, whether it is in scope) follows within
  **10 business days**.
- A fix or documented mitigation ships within **30 days** for critical or high severity findings and
  within **90 days** otherwise. If a fix needs longer, the reporter is told why and given a revised
  date rather than silence.
- Disclosure is coordinated with the reporter. Verdict does not ask reporters to wait indefinitely:
  once a fix is released, or the 90-day window passes without one, the reporter is free to publish.

## How vulnerabilities are published

Every confirmed vulnerability is published as a
[GitHub Security Advisory](https://github.com/fissible/verdict/security/advisories) for this
repository — with a CVE requested through GitHub when one applies — and as a **Security** entry in
[CHANGELOG.md](../CHANGELOG.md) naming the affected and fixed versions and any operator action
required. Advisories are published when the fix is released, not before. Sensitive reproduction
data (prompts, evidence, customer data) is never included; see
[RELEASES.md](../RELEASES.md#security-releases).

## Supported versions

Verdict is pre-1.0 software. Supported versions and release handling are described in
[RELEASES.md](../RELEASES.md).
