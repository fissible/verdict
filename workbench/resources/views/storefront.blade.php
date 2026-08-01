<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verdict Storefront Security Lab</title>
    <style>
        :root {
            color-scheme: dark;
            --ink: #e8edf5;
            --muted: #93a1b5;
            --panel: rgba(18, 25, 38, .88);
            --line: #2a3850;
            --red: #ff6b7a;
            --green: #55d6a6;
            --amber: #f6c76b;
            --violet: #a99cff;
        }

        * { box-sizing: border-box; }
        html {
            min-height: 100%;
            scroll-behavior: smooth;
            background: #080d16;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 8%, rgba(89, 72, 180, .24), transparent 34rem),
                radial-gradient(circle at 88% 18%, rgba(22, 132, 112, .16), transparent 30rem),
                #080d16;
            background-repeat: no-repeat;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        main { width: min(1380px, calc(100% - 40px)); margin: 0 auto; padding: 58px 0 80px; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { max-width: 780px; margin-bottom: 14px; font-size: clamp(2.35rem, 6vw, 5.4rem); line-height: .96; letter-spacing: -.055em; }
        h2 { margin-bottom: 8px; font-size: clamp(1.6rem, 3vw, 2.5rem); letter-spacing: -.035em; }
        h3 { margin-bottom: 8px; font-size: 1rem; }
        .eyebrow { color: var(--violet); font: 700 .75rem/1.2 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .14em; text-transform: uppercase; }
        .lede { max-width: 760px; color: #b9c4d4; font-size: 1.08rem; }
        .muted, .fine-print { color: var(--muted); }
        .fine-print { font-size: .82rem; }

        .panel {
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--panel);
            box-shadow: 0 22px 65px rgba(0, 0, 0, .22);
        }

        .scenario { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(260px, .65fr); gap: 18px; margin: 36px 0 24px; padding: 22px; }
        .scenario pre, .trace pre { overflow-x: auto; margin: 0; color: #cbd6e7; font: .83rem/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; }
        .scenario dl { display: grid; grid-template-columns: max-content 1fr; gap: 7px 16px; margin: 18px 0 0; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        select, button {
            border: 1px solid #435476;
            border-radius: 10px;
            color: var(--ink);
            background: #111a2a;
            padding: 10px 12px;
            font: inherit;
        }
        select {
            appearance: none;
            padding-right: 42px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 14 14'%3E%3Cpath d='m3 5 4 4 4-4' fill='none' stroke='%23d6deeb' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 14px;
        }
        button { cursor: pointer; border-color: #786ce3; background: #6558cf; font-weight: 700; }
        button:hover { border-color: #998eff; background: #7468dc; }
        button:focus-visible, select:focus-visible { outline: 3px solid rgba(169, 156, 255, .3); outline-offset: 2px; }
        .scenario-controls { display: grid; align-content: center; gap: 12px; padding: 4px 2px; }
        .scenario-controls select, .scenario-controls button { width: 100%; }
        .scenario-controls button { margin-top: 2px; }
        .control-copy { margin-bottom: 2px; color: #b4c0d1; font-size: .88rem; }
        label { display: grid; gap: 5px; color: var(--muted); font-size: .82rem; }

        .comparison { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .result { min-width: 0; padding: 20px; }
        .result.danger { border-color: rgba(255, 107, 122, .55); }
        .result.safe { border-color: rgba(85, 214, 166, .45); }
        .result.verdict { background: linear-gradient(145deg, rgba(29, 38, 58, .96), rgba(35, 28, 67, .92)); }
        .badge { display: inline-flex; margin-bottom: 22px; border-radius: 999px; padding: 4px 9px; font: 700 .7rem/1.3 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .06em; text-transform: uppercase; }
        .badge.danger { color: #ffd4d8; background: rgba(255, 107, 122, .18); }
        .badge.safe { color: #c9ffec; background: rgba(85, 214, 166, .16); }
        .result code, .receipt { overflow-wrap: anywhere; color: #c8d4e7; font: .78rem/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; }
        .result dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 12px; margin: 16px 0 0; font-size: .86rem; }

        .trace { margin-top: 14px; padding: 16px; border: 1px solid #34445f; border-radius: 12px; background: rgba(5, 9, 16, .66); }
        .trace + .trace { margin-top: 9px; }
        .trace-head { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; font: 700 .72rem/1.3 ui-monospace, SFMono-Regular, Menlo, monospace; text-transform: uppercase; }

        section { margin-top: 64px; }
        .section-copy { max-width: 760px; margin-bottom: 24px; color: #b4c0d1; }
        .attempts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .attempt { display: flex; min-width: 0; flex-direction: column; padding: 18px; }
        .attempt strong { display: block; margin-bottom: 8px; }
        .attempt-summary { min-height: 3em; margin-bottom: 14px; color: #aeb9ca; font-size: .82rem; }
        .attempt pre { max-width: 100%; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; }
        .attempt details { margin-top: auto; padding-top: 13px; border-top: 1px solid #2f3d54; }
        .attempt summary { cursor: pointer; color: #c8c0ff; font-size: .82rem; font-weight: 700; }
        .attempt summary:hover { color: #ded9ff; }
        .attempt summary:focus-visible { outline: 3px solid rgba(169, 156, 255, .3); outline-offset: 3px; }
        .attempt details[open] summary { margin-bottom: 12px; }
        .attempt-explanation { margin-bottom: 13px; color: #b8c3d3; font-size: .8rem; }
        .attempt-meta { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 5px 10px; margin: 0 0 13px; font-size: .76rem; }
        .attempt-meta code { overflow-wrap: anywhere; color: #d2daea; }
        .argument-box + .argument-box { margin-top: 9px; }
        .argument-box span { display: block; margin-bottom: 4px; color: var(--muted); font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .argument-box pre { padding: 9px 10px; border-radius: 8px; background: rgba(5, 9, 16, .6); font-size: .72rem; }
        .attempt.blocked { border-color: rgba(85, 214, 166, .4); }
        .attempt.executed { border-color: rgba(246, 199, 107, .5); }
        .flow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 18px 0 22px; color: #b9c4d4; font: .78rem/1.3 ui-monospace, SFMono-Regular, Menlo, monospace; }
        .flow span { border: 1px solid #34445f; border-radius: 999px; padding: 7px 10px; background: #101827; }
        .flow b { color: #65748c; }
        .confirmation-controls { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-top: 18px; padding: 18px 20px; }
        .confirmation-controls .flow { margin: 0; }
        .confirmation-controls form { flex: none; }
        .confirmation-controls button { min-width: 230px; }
        .confirmation-controls + .attempts { margin-top: 14px; }
        .results-section { scroll-margin-top: 24px; margin-top: 34px; }
        .results-intro { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 18px; }
        .results-intro h2 { margin-bottom: 0; }
        .reset-link { flex: none; color: #c8c0ff; font-size: .85rem; text-underline-offset: 3px; }
        .effects { margin-top: 14px; padding: 22px; }
        .effects-copy { max-width: 760px; margin-bottom: 18px; color: #b4c0d1; }
        .effect-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .metric { min-width: 0; padding: 14px; border: 1px solid #34445f; border-radius: 12px; background: rgba(5, 9, 16, .48); }
        .metric span { display: block; color: var(--muted); font-size: .75rem; }
        .metric strong { display: block; margin-top: 3px; color: var(--ink); font-size: 1.4rem; }
        .effect-details { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, .75fr); gap: 22px; margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--line); }
        .effect-details > div { min-width: 0; }
        .effect-details pre { max-width: 100%; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; color: #cbd6e7; font: .78rem/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; }
        footer { margin-top: 68px; padding-top: 20px; border-top: 1px solid var(--line); color: var(--muted); font-size: .86rem; }

        @media (max-width: 880px) {
            .comparison, .attempts { grid-template-columns: 1fr; }
            .scenario { grid-template-columns: 1fr; }
            .effect-details { grid-template-columns: 1fr; }
            .results-intro { align-items: start; flex-direction: column; }
            .confirmation-controls { align-items: stretch; flex-direction: column; }
            .confirmation-controls button { width: 100%; }
        }

        @media (max-width: 560px) {
            .effect-metrics { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Verdict / deterministic workbench</p>
    <h1>The model proposes. Laravel decides.</h1>
    <p class="lede">One captured AI tool proposal runs through three application boundaries. The model output is held constant, so the result demonstrates authorization—not jailbreak luck.</p>

    <div class="panel scenario">
        <div>
            <p class="eyebrow">Captured proposal</p>
            <pre>{{ json_encode($scenario['proposal'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <dl>
                <dt>Authenticated principal</dt>
                <dd>customer_{{ $scenario['customer']['id'] }}</dd>
                <dt>Resolved target owner</dt>
                <dd>customer_{{ $scenario['target']['customer_id'] }}</dd>
                <dt>User request</dt>
                <dd>“{{ $scenario['request'] }}”</dd>
            </dl>
        </div>
        <form class="scenario-controls" method="get" action="{{ route('verdict.demo') }}#comparison-results">
            <input type="hidden" name="run_comparison" value="1">
            <div>
                <p class="eyebrow">Run the lab</p>
                <p class="control-copy">Choose a target, then execute the same proposal through all three security boundaries.</p>
            </div>
            <label>
                Scenario
                <select name="order_id">
                    <option value="1001" @selected($scenario['target']['id'] === 1001)>Cross-customer order #1001</option>
                    <option value="1002" @selected($scenario['target']['id'] === 1002)>Customer's own order #1002</option>
                </select>
            </label>
            <button type="submit">Run security comparison</button>
            <span class="fine-print">Runs only this order-lookup comparison. Confirmation is a separate lab below.</span>
        </form>
    </div>

    @if ($hasComparisonRun)
    <section id="comparison-results" class="results-section">
        <div class="results-intro">
            <div>
                <p class="eyebrow">Executed comparison</p>
                <h2>Same proposal. Three application boundaries.</h2>
            </div>
            <a class="reset-link" href="{{ route('verdict.demo') }}">Reset the lab</a>
        </div>

    <div class="comparison">
        @php($naive = $comparison['implementations']['naive'])
        <article class="panel result {{ $naive['status'] === 'exposed' ? 'danger' : 'safe' }}">
            <span class="badge {{ $naive['status'] === 'exposed' ? 'danger' : 'safe' }}">{{ $naive['status'] }}</span>
            <h3>{{ $naive['label'] }}</h3>
            <p class="muted">The handler trusts the model-provided order ID and never invokes the existing Policy.</p>
            <dl>
                <dt>Decision</dt><dd>{{ $naive['decision'] }}</dd>
                <dt>Handler ran</dt><dd>{{ $naive['handler_invocations'] }} time</dd>
            </dl>
            <div class="trace"><pre>{{ json_encode($naive['disclosure'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        </article>

        @php($manual = $comparison['implementations']['manual'])
        <article class="panel result safe">
            <span class="badge safe">{{ $manual['status'] }}</span>
            <h3>{{ $manual['label'] }}</h3>
            <p class="muted">Ordinary secure Laravel code explicitly resolves the order and invokes the same Policy.</p>
            <dl>
                <dt>Decision</dt><dd>{{ $manual['decision'] }}</dd>
                <dt>Reason</dt><dd>{{ $manual['reason'] }}</dd>
                <dt>Handler ran</dt><dd>{{ $manual['handler_invocations'] }} times</dd>
            </dl>
            @if ($manual['disclosure'] !== null)
                <div class="trace"><pre>{{ json_encode($manual['disclosure'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
            @endif
        </article>

        @php($verdict = $comparison['implementations']['verdict'])
        <article class="panel result safe verdict">
            <span class="badge safe">{{ $verdict['status'] }}</span>
            <h3>{{ $verdict['label'] }}</h3>
            <p class="muted">Verdict makes resolution and Policy inspection mandatory before its target-bound executor can run.</p>
            <dl>
                <dt>Decision</dt><dd>{{ $verdict['decision'] }}</dd>
                <dt>Raw handler ran</dt><dd>{{ $verdict['definition_handler_invocations'] }} times</dd>
            </dl>
            @foreach ($verdict['evidence'] as $record)
                <div class="trace">
                    <div class="trace-head"><span>{{ $record['stage'] }}</span><span>{{ $record['disposition'] }}</span></div>
                    <div class="fine-print">{{ $record['reason'] }}</div>
                    <code>{{ $record['argument_fingerprint'] }}</code>
                </div>
            @endforeach
        </article>
    </div>
    </section>
    @endif

    <section id="confirmation-lab" class="results-section">
        <p class="eyebrow">Argument-bound confirmation</p>
        <h2>Approval is permission for one exact action.</h2>
        <p class="section-copy">This independent scenario issues approval for cancellation of order #1002 with one stated reason. Verdict then tests changed arguments, the exact approved action, and replay.</p>

        <div class="panel confirmation-controls">
            <div class="flow">
                <span>pending</span><b>→</b><span>approved</span><b>→</b><span>consumed</span>
            </div>
            <form method="get" action="{{ route('verdict.demo') }}#confirmation-lab">
                <input type="hidden" name="run_approval" value="1">
                <input type="hidden" name="order_id" value="{{ $scenario['target']['id'] }}">
                <button type="submit">{{ $hasApprovalRun ? 'Run confirmation flow again' : 'Run confirmation flow' }}</button>
            </form>
        </div>

        @if ($hasApprovalRun)
        <div class="attempts">
            @foreach ($approval['attempts'] as $attempt)
                <article class="panel attempt {{ $attempt['status'] }}">
                    <span class="badge {{ $attempt['status'] === 'blocked' ? 'safe' : '' }}">{{ $attempt['status'] }}</span>
                    <strong>{{ $attempt['label'] }}</strong>
                    <p class="attempt-summary">{{ $attempt['summary'] }}</p>
                    <pre class="fine-print">{{ json_encode($attempt['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    <details>
                        <summary>What happened?</summary>
                        <p class="attempt-explanation">{{ $attempt['explanation'] }}</p>
                        <dl class="attempt-meta">
                            <dt>Receipt</dt>
                            <dd><code>{{ $attempt['receipt_transition'] }}</code></dd>
                        </dl>
                        <div class="argument-box">
                            <span>Approved arguments</span>
                            <pre>{{ json_encode($attempt['approved_arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                        <div class="argument-box">
                            <span>Presented arguments</span>
                            <pre>{{ json_encode($attempt['presented_arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </details>
                </article>
            @endforeach
        </div>

        <div class="panel effects">
            <p class="eyebrow">Observed execution</p>
            <h3>The approved executor wrote exactly once.</h3>
            <p class="effects-copy">This workbench uses a scoped in-memory execution sink. It exercises the real capability executor without requiring a database, queue, payment provider, or commerce API.</p>

            <div class="effect-metrics">
                <div class="metric">
                    <span>Writes before execution</span>
                    <strong>{{ $approval['execution_summary']['writes_before'] }}</strong>
                </div>
                <div class="metric">
                    <span>Writes after execution</span>
                    <strong>{{ $approval['execution_summary']['writes_after'] }}</strong>
                </div>
                <div class="metric">
                    <span>Blocked attempts</span>
                    <strong>{{ $approval['execution_summary']['blocked_attempts'] }}</strong>
                </div>
            </div>

            <div class="effect-details">
                <div>
                    <p class="eyebrow">Execution sink contents</p>
                    <pre>{{ json_encode($approval['observed_actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
                <div>
                    <p class="eyebrow">Receipt fingerprint</p>
                    <div class="receipt">{{ $approval['receipt']['fingerprint'] }}</div>
                    <p class="fine-print">Raw receipt IDs and arguments are not stored in decision evidence.</p>
                </div>
            </div>
        </div>
        @endif
    </section>

    <footer>
        This UI exists only in Verdict's Testbench workbench. The distributed Laravel package remains headless. The manual implementation is intentionally shown as secure: Verdict's value is consistent mediation, bound execution, evidence, and regression testing—not replacing Laravel Policies.
    </footer>
</main>
</body>
</html>
