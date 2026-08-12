# Application-owned Verdict approval flow

These files are a starting point, not a working reviewer experience. Verdict did not register the route file, middleware, a view, a notification channel, a queue job, or a policy.

Before including `routes/verdict-approval-flow.php`, the application must:

- authenticate the reviewer and authorize their tenant/conversation access;
- verify that the exact receipt and tool call belong to a conversation the reviewer may decide;
- look up the pending Verdict summary with `ApprovalManager::challengeForToolCall($toolCallId)`, then combine it with application-owned safe display context; it returns no challenge for a missing, expired, or non-pending receipt and never exposes raw arguments;
- present every material binding fact without copying raw prompts or tool arguments into Verdict receipts;
- use an opaque application identifier (for example, `user:42`) as the decision maker;
- handle `not_found`, `mismatch`, `expired`, and `invalid_state` as terminal non-success outcomes and never resume the agent for them; an already-decided or already-consumed receipt returns `invalid_state` from approve/reject;
- select and test its own agent/conversation resumption strategy; and
- decide whether and how to dispatch `NotifyVerdictApprovalDecision` through its notification transport.

Read the [Verdict adoption guide](https://github.com/fissible/verdict/blob/main/docs/adoption-guide.md) ([#103](https://github.com/fissible/verdict/issues/103)) before a pilot. It covers independently committed security-state connections, opt-in evidence, topology, alerting, and the separate high-consequence production gate.
