# ADR 0019: Verdict service constructors are not part of the supported surface

Status: Accepted

## Related issues

- [#157](https://github.com/fissible/verdict/issues/157) raised the inconsistency this ADR settles.
- [#116](https://github.com/fissible/verdict/issues/116) added `BoundTool` collaborators as optional
  parameters with a container fallback.
- [#149](https://github.com/fissible/verdict/issues/149) added a `VerdictManager` collaborator as a required
  parameter.

## Context

Two required-collaborator additions landed in one minor release and took opposite approaches to the same
question.

#116 made `BoundTool`'s new collaborators optional and resolved them lazily:
`AbstractVerdictTool.php` declares `?InvocationContext $invocations = null` and
`?ApprovalExecutionContext $approvalExecutions = null`, falling back to
`Container::getInstance()->make(...)` when they are absent. Direct construction kept working. The cost,
flagged during that review, is a hidden global dependency: a caller who constructs the tool with four
arguments gets a working object whose collaborators come from wherever the global container happens to
point, with no signal that anything was substituted.

#149 made `VerdictManager`'s dispatcher required. That is honest about what the object needs, and it breaks
direct construction.

Neither is wrong in isolation. The inconsistency is the defect: an adopter cannot tell which rule applies,
and the next addition has precedent for both. Two more cycles and the answer is unrecoverable.

Underneath the inconsistency is an unstated question — **is a Verdict service constructor part of the
supported surface at all?** Everything else follows from the answer, and nothing in the repository states
it.

What the repository does say points one way. `RELEASES.md` scopes support to "`Verdict` and
`VerdictManager` registration, release, evaluation, approval, and tool-adapter **methods** documented in the
README." That is a statement about methods, and no README or documentation page has ever shown
`new VerdictManager(...)`, `new BoundTool(...)`, or `new GuardedTool(...)`. The repository's own practice
matches: `CapabilitySecurityTestKit` resolves `VerdictManager` from the container, and the workbench builds
tools through `$verdict->bound(...)`.

## Decision

**Verdict service constructors are internal. Resolve Verdict services from the container.**

`VerdictManager`, the managers it composes, and the tool adapters are container-resolved collaborators, not
hand-constructed value objects. Their constructors may gain required parameters in any release without that
being a breaking change, because constructing them directly was never supported.

This states a boundary that already existed rather than drawing a new one. The alternative — treating
constructors as supported — would retroactively reclassify #149 as a breaking change requiring a compatible
reshape, which is a large cost to defend a surface nobody documented.

Three further reasons settle it:

1. **The constructor is not an ergonomic API and should not be constrained as one.** `VerdictManager` takes
   a twelve-parameter collaborator list. Treating that as a public contract constrains every future
   addition to a shape chosen for a construction path nobody uses.
2. **Container-first is already the practice**, in the shipped test kit and in the workbench.
3. **It makes future collaborator additions non-breaking**, which matters for a pre-1.0 package still
   finding its boundaries.

### The boundary is marked in code, not only in prose

`RELEASES.md` remains the authority, but prose alone is discoverable only by someone who already went
looking. The constructors carry an `@internal` annotation so the rule reaches static analysis and IDEs —
where a developer about to write `new VerdictManager(...)` will actually meet it, at the moment they are
making the mistake.

### Removing the container fallback is a deliberate downgrade in tolerance

Under this decision, `AbstractVerdictTool`'s `?? Container::getInstance()->make(...)` fallback is not a
compatibility affordance. It is a hidden global that lets unsupported usage half-work.

Removing it changes direct four-argument construction from **silently degrading** — collaborators
substituted from the global container, no memoization of the caller's intent, no signal — to **failing
loudly** with a missing required argument. That is the improvement, and it is the reason for the removal
rather than a side effect of it. A caller doing something unsupported should find out at the call, not
discover later that an invocation frame or approval context was not the one they expected.

Verdict's own tests are currently the only direct constructors of these adapters, and they move to the
supported path as part of the change.

## Consequences

- A new collaborator on a Verdict service is added as a required constructor parameter. It is not made
  optional to preserve direct construction, and it does not fall back to the global container.
- `CONTRIBUTING.md` states this, so a contributor adding the next collaborator does not have to re-derive
  it from two conflicting precedents.
- `RELEASES.md`'s "Intended public surface in 0.x" states explicitly that constructors are excluded, so the
  answer is not inferred from the word "methods."
- A changelog entry accompanies the fallback removal, because an application that constructs tool adapters
  directly — unsupported, but possible today — will get a fatal error rather than degraded behavior.
- This does not affect contracts under `Fissible\Verdict\Contracts`. Their stability is governed separately
  and audited by #17; an application-supplied adapter implements an interface rather than constructing a
  Verdict service.

## Alternatives rejected

### Treat constructors as a supported surface, and require compatible additions

Every new collaborator would need an optional parameter with a default, or a named constructor, forever.
That defends a construction path that is undocumented, unused outside Verdict's own tests, and unergonomic
at twelve parameters. It would also make #149 a breaking change after the fact.

The deeper objection is that "optional with a default" is rarely honest for a collaborator: the object
genuinely requires it, so the default has to come from somewhere, and that somewhere is a global. #116 is
the worked example — the compatible shape produced the hidden dependency.

### Keep both shapes and decide case by case

This is the status quo, and it is what produced the inconsistency. Case-by-case judgment about a rule that
should be uniform gives the next contributor precedent for either choice.

### State the rule in `RELEASES.md` only

Rejected as insufficient rather than wrong. It leaves the rule discoverable only to a reader who consults
the release policy before constructing an object, which is not when the question arises. The `@internal`
annotation puts it where the mistake happens.
