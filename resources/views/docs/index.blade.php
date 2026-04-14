<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $docs['title'] }}</title>
    <style>
        :root {
            --bg:#0b1220;
            --surface:#111a2b;
            --line:#24314a;
            --text:#e7eefb;
            --muted:#94a4c2;
            --accent:#7ca7ff;
            --badge:#1a2740;
            --hover:#1a2742;
            --active:#21345a;
            --active-line:#35548d;
            --code-bg:#090f1d;
            --code-line:#24314a;
            --code-text:#dbe7ff;
            --ok:#32c287;
            --warn:#f2b936;
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;color:var(--text);background:var(--bg)}
        .shell{max-width:1460px;margin:0 auto;padding:20px 18px 28px}
        .topbar{
            display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
            padding:12px 14px;background:var(--surface);border:1px solid var(--line);border-radius:10px;
            box-shadow:0 10px 24px rgba(0,0,0,.24);
        }
        .title{margin:0;font-size:1.2rem}
        .subtitle{margin:4px 0 0;color:var(--muted);font-size:.9rem}
        .badge{display:inline-block;margin-top:8px;padding:4px 9px;border-radius:999px;border:1px solid #31476f;background:var(--badge);font-size:.75rem;color:#cfe0ff}
        .btn{border:1px solid #355287;background:#182845;color:#dce8ff;border-radius:8px;padding:8px 11px;cursor:pointer;font-weight:600}

        .layout{display:grid;grid-template-columns:270px 1fr 360px;gap:16px;margin-top:16px;align-items:start}
        .main{min-width:0;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:22px 24px;box-shadow:0 10px 24px rgba(0,0,0,.2)}
        .sidebar{
            position:sticky;top:16px;align-self:start;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px;
            box-shadow:0 10px 24px rgba(0,0,0,.2);
        }
        .side-title{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#8395b7;margin:12px 0 10px}
        .nav-tools{display:flex;gap:8px;margin-bottom:10px}
        .nav-tool{flex:1;border:1px solid #32496f;background:#16253f;color:#bcd1f6;border-radius:8px;padding:6px 8px;font-size:.75rem;cursor:pointer}
        .nav{display:flex;flex-direction:column;gap:0}
        .nav-group{border:0;background:transparent;border-radius:0;overflow:visible;margin-bottom:10px}
        .nav-module{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border:0;background:transparent;color:#d6e5ff;cursor:pointer;font-weight:700;font-size:.95rem}
        .nav-module:hover{background:#1a2742}
        .nav-module-left{display:flex;align-items:center;gap:8px;min-width:0}
        .nav-module-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .nav-count{font-size:.72rem;color:#bcd2ff;border:1px solid #34507e;background:#1a2b49;border-radius:999px;padding:2px 7px}
        .nav-chevron{font-size:.8rem;opacity:.9;transition:transform .15s ease}
        .nav-module[aria-expanded="false"] .nav-chevron{transform:rotate(-90deg)}
        .nav-children{padding:0}
        .nav-children-inner{display:flex;flex-direction:column;gap:0;border-left:0}
        .nav-link{display:flex;align-items:center;gap:8px;padding:8px 12px;border-left:3px solid transparent;color:#b8c9ea;text-decoration:none;border-radius:0;font-size:.93rem;position:relative}
        .nav-link:hover{background:var(--hover)}
        .nav-link.active{background:var(--active);border-left-color:#f5c01f}
        .nav-link.module-link{font-weight:600;color:#d8e5ff;padding-left:28px}
        .nav-link.endpoint-link{padding-left:12px}
        .nav-icon{width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;color:#8395b7}
        .nav-icon svg{width:100%;height:100%;stroke:currentColor;stroke-width:1.5;fill:none;stroke-linecap:round;stroke-linejoin:round}
        .nav-link-content{display:flex;align-items:center;gap:6px;min-width:0;flex:1}
        .nav-link-method{display:inline-block;font-size:.7rem;font-weight:700;padding:2px 6px;border-radius:4px;flex-shrink:0}
        .method{display:inline-block;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid #2a7857;background:#13372b;color:#9af2c7}
        .path{display:block;color:#8ca0c5;margin-top:4px;word-break:break-all;font-size:.85rem}

        .intro{background:#0f1a2f;border:1px solid #2b4268;border-radius:10px;padding:14px 16px;line-height:1.55}
        .intro b{color:#cfe0ff}

        .module{margin-top:24px;border-top:1px solid #243550;padding-top:20px}
        .module:first-of-type{margin-top:16px;border-top:0;padding-top:0}
        .module h2{margin:0 0 8px;font-size:1.22rem}
        .module p{margin:0;color:var(--muted)}

        .endpoint{margin-top:16px;background:#0e182b;border:1px solid #273b5c;border-radius:12px;padding:18px}
        .endpoint h3{margin:0;font-size:1.08rem}
        .tag{display:inline-block;margin-left:6px;padding:3px 8px;border-radius:999px;font-size:.72rem;border:1px solid #334f80;background:#152746;color:#b5cdfa}
        .endpoint > * + *{margin-top:14px}

        .segment{background:#111d33;border:1px solid #2a4167;border-radius:10px;padding:12px}
        .segment-title{margin:0 0 10px;font-size:1rem;color:#d6e5ff}

        .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
        .meta .card{border:1px solid #2b426a;background:#13203a;border-radius:9px;padding:10px}
        .k{font-size:.73rem;text-transform:uppercase;letter-spacing:.08em;color:#8ea3cc}
        .v{margin-top:5px;font-weight:600}

        h4{margin:0;font-size:1rem}
        table{width:100%;border-collapse:collapse;font-size:.92rem}
        th,td{padding:11px 10px;border-bottom:1px solid #2b3f62;text-align:left;vertical-align:top}
        th{color:#b9ccf3}
        pre,code{font-family:Consolas,Monaco,monospace}
        pre{margin:8px 0 0;padding:14px;border:1px solid #2b4268;background:#0b1426;border-radius:10px;overflow:auto;line-height:1.48;font-size:.86rem;max-height:360px;color:#d9e7ff}
        .resp{margin-top:12px}
        .resp:first-of-type{margin-top:0}
        .resp-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .status{padding:3px 9px;border-radius:999px;border:1px solid #365186;background:#162747;color:#bfd3ff;font-size:.77rem}
        .copy{border:1px solid #355185;background:#152640;color:#c9dcff;border-radius:8px;padding:5px 8px;font-size:.78rem;cursor:pointer}

        .code-panel{position:sticky;top:16px;align-self:start;background:var(--code-bg);border:1px solid var(--code-line);border-radius:12px;overflow:hidden}
        .code-tabs{display:flex;border-bottom:1px solid var(--code-line)}
        .code-tab{flex:1;text-align:center;padding:10px 8px;font-size:.78rem;font-weight:700;color:#8ea7dd;background:#0f152b;border:0;cursor:pointer}
        .code-tab.active{background:#151d38;color:#e4edff}
        .code-body{padding:12px}
        .code-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#9eb2df;font-size:.78rem;margin-bottom:10px}
        .code-pre{margin:0;border:1px solid #243056;background:#080d1b;color:var(--code-text);border-radius:9px;padding:12px;min-height:260px;max-height:420px;line-height:1.45}
        .code-actions{display:flex;gap:8px;margin-top:10px}
        .code-btn{flex:1;padding:8px 10px;border-radius:8px;border:1px solid #344676;background:#18234a;color:#e8efff;cursor:pointer;font-size:.8rem}
        .code-btn.primary{background:#f5c01f;border-color:#d2a010;color:#2f2500;font-weight:800}
        .muted-note{font-size:.83rem;color:var(--muted)}

        /* Mobile: content first, nav as quick links below */
        @media (max-width: 1200px){
            .layout{grid-template-columns:260px 1fr}
            .code-panel{grid-column:1 / -1;position:relative;top:0}
        }
        @media (max-width: 940px){
            .layout{grid-template-columns:1fr}
            .sidebar,.code-panel{position:relative;top:0}
            .title{font-size:1.1rem}
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div>
            <h1 class="title">{{ $docs['title'] }}</h1>
            <p class="subtitle">{{ $docs['subtitle'] }}</p>
            <span class="badge">Version {{ $docs['version'] }}</span>
            <span class="badge">Base URL {{ $docs['base_url'] }}</span>
        </div>
        <form method="POST" action="{{ route('docs.logout') }}">
            @csrf
            <button class="btn" type="submit">Lock Docs</button>
        </form>
    </header>

    <div class="layout">
        <aside class="sidebar">
            <div class="side-title">Main Concepts</div>
            <nav class="nav" style="margin-bottom:14px">
                <a class="nav-link module-link" href="#intro-section">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    </span>Introduction
                </a>
                <a class="nav-link module-link" href="#auth-section">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>Authentication
                </a>
            </nav>

            <div class="side-title">Endpoints</div>
            <div class="nav-tools">
                <button class="nav-tool" type="button" data-nav-action="expand">Expand all</button>
                <button class="nav-tool" type="button" data-nav-action="collapse">Collapse all</button>
            </div>
            <nav class="nav">
                @foreach($docs['modules'] as $module)
                    <section class="nav-group" data-nav-group>
                        <button class="nav-module" type="button" data-nav-toggle aria-expanded="{{ $loop->first ? 'true' : 'false' }}">
                            <span class="nav-module-left">
                                <span class="nav-module-name">{{ $module['name'] }}</span>
                                <span class="nav-count">{{ count($module['endpoints']) }}</span>
                            </span>
                            <span class="nav-chevron">▾</span>
                        </button>

                        <div class="nav-children" {{ $loop->first ? '' : 'hidden' }}>
                            <div class="nav-children-inner">
                                @foreach($module['endpoints'] as $endpoint)
                                    <a class="nav-link endpoint-link js-nav-endpoint"
                                       href="#endpoint-{{ $endpoint['id'] }}"
                                       data-endpoint-id="{{ $endpoint['id'] }}"
                                       data-method="{{ $endpoint['method'] }}"
                                       data-path="{{ $endpoint['path'] }}"
                                       data-title="{{ $endpoint['title'] }}"
                                       data-curl-id="curl-{{ $endpoint['id'] }}"
                                       data-response-id="resp-{{ $endpoint['id'] }}-0">
                                        <span class="nav-link-method" style="background:{{ $endpoint['method'] === 'POST' ? '#d4a62e' : '#8fa1c9' }};color:{{ $endpoint['method'] === 'POST' ? '#2f2500' : '#fff' }}">{{ $endpoint['method'] }}</span>
                                        <span class="nav-link-content">
                                            <span style="font-weight:600">{{ str_replace('/api/', '', $endpoint['path']) }}</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endforeach
            </nav>
        </aside>

        <main class="main">
            <section class="intro" id="intro-section">
                <b>Authentication:</b> {{ $docs['auth']['type'] }}
                <div id="auth-section" style="margin-top:6px;color:var(--muted)">{{ $docs['auth']['note'] }}</div>
            </section>

            @foreach($docs['modules'] as $module)
                <section class="module" id="module-{{ $module['id'] }}">
                    <h2>{{ $module['name'] }}</h2>
                    <p>{{ $module['description'] }}</p>

                    @foreach($module['endpoints'] as $endpoint)
                        <article class="endpoint" id="endpoint-{{ $endpoint['id'] }}">
                            <h3>{{ $endpoint['title'] }} <span class="tag">{{ $endpoint['tag'] }}</span></h3>
                            <div class="muted-note">Clean request/response reference for this endpoint.</div>

                            <div class="meta">
                                <div class="card">
                                    <div class="k">Method & Path</div>
                                    <div class="v"><span class="method">{{ $endpoint['method'] }}</span> {{ $endpoint['path'] }}</div>
                                </div>
                                <div class="card">
                                    <div class="k">Content Type</div>
                                    <div class="v">{{ $endpoint['content_type'] }}</div>
                                </div>
                                <div class="card">
                                    <div class="k">Rate Limit</div>
                                    <div class="v">{{ $endpoint['rate_limit'] }}</div>
                                </div>
                            </div>

                            <section class="segment">
                                <h4 class="segment-title">Request Fields</h4>
                                <table>
                                    <thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Notes</th></tr></thead>
                                    <tbody>
                                    @foreach($endpoint['request_fields'] as $row)
                                        <tr>
                                            <td>{{ $row['field'] }}</td>
                                            <td>{{ $row['type'] }}</td>
                                            <td>{{ $row['required'] ? 'Yes' : 'No' }}</td>
                                            <td>{{ $row['notes'] }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </section>

                            <section class="segment">
                                <h4 class="segment-title">cURL Example</h4>
                                <div class="resp">
                                    <div class="resp-head">
                                        <span class="status">Request</span>
                                        <button class="copy" type="button" data-copy-id="curl-{{ $endpoint['id'] }}">Copy</button>
                                    </div>
                                    <pre id="curl-{{ $endpoint['id'] }}">{{ $endpoint['curl_example'] }}</pre>
                                </div>
                            </section>

                            <section class="segment">
                                <h4 class="segment-title">Responses</h4>
                                @foreach($endpoint['responses'] as $idx => $response)
                                    <div class="resp">
                                        <div class="resp-head">
                                            <span class="status">{{ $response['label'] }}</span>
                                            <button class="copy" type="button" data-copy-id="resp-{{ $endpoint['id'] }}-{{ $idx }}">Copy</button>
                                        </div>
                                        <pre id="resp-{{ $endpoint['id'] }}-{{ $idx }}">{{ json_encode($response['json'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                @endforeach
                            </section>

                            <section class="segment">
                                <h4 class="segment-title">Error Codes</h4>
                                <table>
                                    <thead><tr><th>Code</th><th>HTTP</th><th>Note</th></tr></thead>
                                    <tbody>
                                    @foreach($endpoint['error_codes'] as $err)
                                        <tr>
                                            <td><code>{{ $err['code'] }}</code></td>
                                            <td>{{ $err['http'] }}</td>
                                            <td>{{ $err['note'] }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </section>
                        </article>
                    @endforeach
                </section>
            @endforeach
        </main>

        <aside class="code-panel">
            <div class="code-tabs">
                <button type="button" class="code-tab active" data-code-tab="curl">cURL</button>
                <button type="button" class="code-tab" data-code-tab="response">Response</button>
            </div>
            <div class="code-body">
                <div class="code-meta">
                    <span class="method" id="panel-method">GET</span>
                    <span id="panel-path">/api/ocr/ic</span>
                    <span id="panel-title">Endpoint Preview</span>
                </div>
                <pre class="code-pre" id="panel-curl"></pre>
                <pre class="code-pre" id="panel-response" hidden></pre>
                <div class="code-actions">
                    <button type="button" class="code-btn" id="panel-copy">Copy Snippet</button>
                    <button type="button" class="code-btn primary">Try It</button>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
document.querySelectorAll('[data-copy-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const targetId = btn.getAttribute('data-copy-id');
        const target = document.getElementById(targetId);
        if (!target) return;

        navigator.clipboard.writeText(target.textContent || '').then(function () {
            const old = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = old; }, 900);
        });
    });
});

document.querySelectorAll('[data-nav-toggle]').forEach(function (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
        const group = toggleBtn.closest('[data-nav-group]');
        const children = group ? group.querySelector('.nav-children') : null;
        if (!children) return;

        const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
        toggleBtn.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
        children.hidden = isExpanded;
    });
});

document.querySelectorAll('[data-nav-action]').forEach(function (actionBtn) {
    actionBtn.addEventListener('click', function () {
        const shouldExpand = actionBtn.getAttribute('data-nav-action') === 'expand';
        document.querySelectorAll('[data-nav-group]').forEach(function (group) {
            const toggleBtn = group.querySelector('[data-nav-toggle]');
            const children = group.querySelector('.nav-children');
            if (!toggleBtn || !children) return;

            toggleBtn.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
            children.hidden = !shouldExpand;
        });
    });
});

const codeTabs = document.querySelectorAll('[data-code-tab]');
const panelCurl = document.getElementById('panel-curl');
const panelResponse = document.getElementById('panel-response');

codeTabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
        codeTabs.forEach(function (btn) { btn.classList.remove('active'); });
        tab.classList.add('active');
        const isCurl = tab.getAttribute('data-code-tab') === 'curl';
        panelCurl.hidden = !isCurl;
        panelResponse.hidden = isCurl;
    });
});

function updateCodePanelFromLink(link) {
    if (!link) return;

    const curlId = link.getAttribute('data-curl-id');
    const responseId = link.getAttribute('data-response-id');
    const curlEl = curlId ? document.getElementById(curlId) : null;
    const responseEl = responseId ? document.getElementById(responseId) : null;

    document.getElementById('panel-method').textContent = link.getAttribute('data-method') || 'GET';
    document.getElementById('panel-path').textContent = link.getAttribute('data-path') || '';
    document.getElementById('panel-title').textContent = link.getAttribute('data-title') || 'Endpoint Preview';
    panelCurl.textContent = curlEl ? (curlEl.textContent || '') : '';
    panelResponse.textContent = responseEl ? (responseEl.textContent || '') : '';
}

const endpointLinks = document.querySelectorAll('.js-nav-endpoint');
endpointLinks.forEach(function (link) {
    link.addEventListener('click', function () {
        endpointLinks.forEach(function (el) { el.classList.remove('active'); });
        link.classList.add('active');
        updateCodePanelFromLink(link);
    });
});

if (endpointLinks.length > 0) {
    updateCodePanelFromLink(endpointLinks[0]);
    endpointLinks[0].classList.add('active');
}

document.getElementById('panel-copy').addEventListener('click', function () {
    const activeTab = document.querySelector('.code-tab.active');
    const toCopy = activeTab && activeTab.getAttribute('data-code-tab') === 'response'
        ? panelResponse.textContent
        : panelCurl.textContent;

    navigator.clipboard.writeText(toCopy || '').then(function () {
        const btn = document.getElementById('panel-copy');
        const old = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = old; }, 900);
    });
});
</script>
</body>
</html>
