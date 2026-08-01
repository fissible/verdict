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

        body {
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 8%, rgba(89, 72, 180, .24), transparent 34rem),
                radial-gradient(circle at 88% 18%, rgba(22, 132, 112, .16), transparent 30rem),
                #080d16;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        main { width: min(1180px, calc(100% - 32px)); margin: 0 auto; padding: 58px 0 80px; }
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
        button { cursor: pointer; border-color: #786ce3; background: #6558cf; font-weight: 700; }
        form { display: flex; align-items: end; gap: 10px; flex-wrap: wrap; }
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
        .attempt { padding: 18px; }
        .attempt strong { display: block; margin-bottom: 8px; }
        .attempt.blocked { border-color: rgba(85, 214, 166, .4); }
        .attempt.executed { border-color: rgba(246, 199, 107, .5); }
        .flow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 18px 0 22px; color: #b9c4d4; font: .78rem/1.3 ui-monospace, SFMono-Regular, Menlo, monospace; }
        .flow span { border: 1px solid #34445f; border-radius: 999px; padding: 7px 10px; background: #101827; }
        .flow b { color: #65748c; }
        footer { margin-top: 68px; padding-top: 20px; border-top: 1px solid var(--line); color: var(--muted); font-size: .86rem; }

        @media (max-width: 880px) {
            .comparison, .attempts { grid-template-columns: 1fr; }
            .scenario { grid-template-columns: 1fr; }
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
            <pre>{{ json_encode($comparison['proposal'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <dl>
                <dt>Authenticated principal</dt>
                <dd>customer_{{ $comparison['customer']['id'] }}</dd>
                <dt>Resolved target owner</dt>
                <dd>customer_{{ $comparison['target']['customer_id'] }}</dd>
                <dt>User request</dt>
                <dd>“{{ $comparison['request'] }}”</dd>
            </dl>
        </div>
        <form method="get" action="{{ route('verdict.demo') }}">
            <label>
                Scenario
                <select name="order_id">
                    <option value="1002" @selected($comparison['target']['id'] === 1002)>Cross-customer order #1002</option>
                    <option value="1001" @selected($comparison['target']['id'] === 1001)>Customer's own order #1001</option>
                </select>
            </label>
            <button type="submit">Run comparison</button>
        </form>
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

    <section>
        <p class="eyebrow">Argument-bound confirmation</p>
        <h2>Approval is permission for one exact action.</h2>
        <p class="section-copy">The customer approves cancellation of order #1001 for one stated reason. Verdict rejects changed arguments, executes the exact approved action once, and rejects replay.</p>

        <div class="flow">
            <span>pending</span><b>→</b><span>approved</span><b>→</b><span>consumed</span>
        </div>

        <div class="attempts">
            @foreach ($approval['attempts'] as $attempt)
                <article class="panel attempt {{ $attempt['status'] }}">
                    <span class="badge {{ $attempt['status'] === 'blocked' ? 'safe' : '' }}">{{ $attempt['status'] }}</span>
                    <strong>{{ $attempt['label'] }}</strong>
                    <pre class="fine-print">{{ json_encode($attempt['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </article>
            @endforeach
        </div>

        <div class="panel scenario">
            <div>
                <p class="eyebrow">Observed side effects</p>
                <pre>{{ json_encode($approval['observed_actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div>
                <p class="eyebrow">Receipt fingerprint</p>
                <div class="receipt">{{ $approval['receipt']['fingerprint'] }}</div>
                <p class="fine-print">Raw receipt IDs and arguments are not stored in decision evidence.</p>
            </div>
        </div>
    </section>

    <footer>
        This UI exists only in Verdict's Testbench workbench. The distributed Laravel package remains headless. The manual implementation is intentionally shown as secure: Verdict's value is consistent mediation, bound execution, evidence, and regression testing—not replacing Laravel Policies.
    </footer>
</main>
</body>
</html>
