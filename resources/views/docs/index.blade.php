<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $docs['title'] }}</title>
    <style>
        :root {
            --bg:#f6f8fc;
            --surface:#ffffff;
            --line:#e2e8f3;
            --text:#182236;
            --muted:#6c7a96;
            --accent:#3f6fd8;
            --badge:#eef3ff;
            --code-bg:#0b0f1f;
            --code-line:#202841;
            --code-text:#d8e5ff;
            --ok:#22b573;
            --warn:#f2b936;
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;color:var(--text);background:var(--bg)}
        .shell{max-width:1440px;margin:0 auto;padding:14px}
        .topbar{
            display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
            padding:12px 14px;background:var(--surface);border:1px solid var(--line);border-radius:10px;
        }
        .title{margin:0;font-size:1.2rem}
        .subtitle{margin:4px 0 0;color:var(--muted);font-size:.9rem}
        .badge{display:inline-block;margin-top:8px;padding:4px 9px;border-radius:999px;border:1px solid #d8e2f8;background:var(--badge);font-size:.75rem}
        .btn{border:1px solid #d2ddf7;background:#f8faff;color:#274488;border-radius:8px;padding:8px 11px;cursor:pointer;font-weight:600}

        .layout{display:grid;grid-template-columns:270px 1fr 360px;gap:14px;margin-top:14px;align-items:start}
        .main{min-width:0;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:18px}
        .sidebar{
            position:sticky;top:14px;align-self:start;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:12px;
        }
        .side-title{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#8a98b4;margin:8px 0}
        .nav-tools{display:flex;gap:8px;margin-bottom:10px}
        .nav-tool{flex:1;border:1px solid #dae3f8;background:#f5f8ff;color:#355499;border-radius:8px;padding:6px 8px;font-size:.75rem;cursor:pointer}
        .nav{display:flex;flex-direction:column;gap:10px}
        .nav-group{border:1px solid #e1e8f6;background:#fbfcff;border-radius:10px;overflow:hidden}
        .nav-module{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px;border:0;background:#f6f9ff;color:#1f335d;cursor:pointer}
        .nav-module:hover{background:#edf3ff}
        .nav-module-left{display:flex;align-items:center;gap:8px;min-width:0}
        .nav-module-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .nav-count{font-size:.72rem;color:#35549a;border:1px solid #cddbf7;background:#edf3ff;border-radius:999px;padding:2px 7px}
        .nav-chevron{font-size:.8rem;opacity:.9;transition:transform .15s ease}
        .nav-module[aria-expanded="false"] .nav-chevron{transform:rotate(-90deg)}
        .nav-children{padding:8px 8px 10px}
        .nav-children-inner{margin-left:8px;padding-left:10px;border-left:1px dashed #c3d3f3;display:flex;flex-direction:column;gap:6px}
        .nav-link{display:block;padding:8px 9px;border:1px solid transparent;border-radius:8px;color:#2a3d66;text-decoration:none}
        .nav-link:hover{background:#edf3ff;border-color:#ccdaf6}
        .nav-link.module-link{font-weight:600;color:#1d3462}
        .nav-link.endpoint-link{padding-left:10px}
        .method{display:inline-block;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid #bde8d4;background:#e9fff4;color:#19714a}
        .path{display:block;color:#607399;margin-top:4px;word-break:break-all;font-size:.85rem}

        .intro{background:#f7faff;border:1px solid #deE8fb;border-radius:10px;padding:12px}
        .intro b{color:#2a4073}

        .module{margin-top:14px;border-top:1px solid #e8eef9;padding-top:14px}
        .module:first-of-type{margin-top:12px;border-top:0;padding-top:0}
        .module h2{margin:0 0 8px;font-size:1.15rem}
        .module p{margin:0;color:var(--muted)}

        .endpoint{margin-top:14px;background:#fbfcff;border:1px solid #e2eaf8;border-radius:10px;padding:13px}
        .endpoint h3{margin:0 0 10px;font-size:1.02rem}
        .tag{display:inline-block;margin-left:6px;padding:3px 8px;border-radius:999px;font-size:.72rem;border:1px solid #d8e3fb;background:#eff4ff;color:#38569a}

        .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
        .meta .card{border:1px solid #dde6f8;background:#fff;border-radius:9px;padding:10px}
        .k{font-size:.73rem;text-transform:uppercase;letter-spacing:.08em;color:#8699c1}
        .v{margin-top:5px;font-weight:600}

        h4{margin:14px 0 8px;font-size:1rem}
        table{width:100%;border-collapse:collapse;font-size:.92rem}
        th,td{padding:10px;border-bottom:1px solid #e5ecf9;text-align:left;vertical-align:top}
        th{color:#365388}
        pre,code{font-family:Consolas,Monaco,monospace}
        pre{margin:8px 0 0;padding:12px;border:1px solid #d9e3fa;background:#f7faff;border-radius:10px;overflow:auto;line-height:1.38;font-size:.86rem}
        .resp{margin-top:10px}
        .resp-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .status{padding:3px 9px;border-radius:999px;border:1px solid #d5e2fb;background:#eef4ff;color:#305090;font-size:.77rem}
        .copy{border:1px solid #cad9f8;background:#f2f7ff;color:#31519a;border-radius:8px;padding:5px 8px;font-size:.78rem;cursor:pointer}

        .code-panel{position:sticky;top:14px;align-self:start;background:var(--code-bg);border:1px solid var(--code-line);border-radius:10px;overflow:hidden}
        .code-tabs{display:flex;border-bottom:1px solid var(--code-line)}
        .code-tab{flex:1;text-align:center;padding:10px 8px;font-size:.78rem;font-weight:700;color:#8ea7dd;background:#0f152b;border:0;cursor:pointer}
        .code-tab.active{background:#151d38;color:#e4edff}
        .code-body{padding:12px}
        .code-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#9eb2df;font-size:.78rem;margin-bottom:10px}
        .code-pre{margin:0;border:1px solid #243056;background:#080d1b;color:var(--code-text);border-radius:9px;padding:11px;min-height:230px}
        .code-actions{display:flex;gap:8px;margin-top:10px}
        .code-btn{flex:1;padding:8px 10px;border-radius:8px;border:1px solid #344676;background:#18234a;color:#e8efff;cursor:pointer;font-size:.8rem}
        .code-btn.primary{background:#f5c01f;border-color:#d2a010;color:#2f2500;font-weight:800}

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
            <nav class="nav" style="margin-bottom:10px">
                <a class="nav-link module-link" href="#intro-section">Introduction</a>
                <a class="nav-link module-link" href="#auth-section">Authentication</a>
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
                                <a class="nav-link module-link" href="#module-{{ $module['id'] }}">Module overview</a>
                                @foreach($module['endpoints'] as $endpoint)
                                    <a class="nav-link endpoint-link js-nav-endpoint"
                                       href="#endpoint-{{ $endpoint['id'] }}"
                                       data-endpoint-id="{{ $endpoint['id'] }}"
                                       data-method="{{ $endpoint['method'] }}"
                                       data-path="{{ $endpoint['path'] }}"
                                       data-title="{{ $endpoint['title'] }}"
                                       data-curl-id="curl-{{ $endpoint['id'] }}"
                                       data-response-id="resp-{{ $endpoint['id'] }}-0">
                                        <span class="method">{{ $endpoint['method'] }}</span>
                                        <span class="path">{{ $endpoint['path'] }}</span>
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

                            <h4>Request Fields</h4>
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

                            <h4>cURL Example</h4>
                            <div class="resp">
                                <div class="resp-head">
                                    <span class="status">Request</span>
                                    <button class="copy" type="button" data-copy-id="curl-{{ $endpoint['id'] }}">Copy</button>
                                </div>
                                <pre id="curl-{{ $endpoint['id'] }}">{{ $endpoint['curl_example'] }}</pre>
                            </div>

                            <h4>Responses</h4>
                            @foreach($endpoint['responses'] as $idx => $response)
                                <div class="resp">
                                    <div class="resp-head">
                                        <span class="status">{{ $response['label'] }}</span>
                                        <button class="copy" type="button" data-copy-id="resp-{{ $endpoint['id'] }}-{{ $idx }}">Copy</button>
                                    </div>
                                    <pre id="resp-{{ $endpoint['id'] }}-{{ $idx }}">{{ json_encode($response['json'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @endforeach

                            <h4>Error Codes</h4>
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
        endpointLinks.forEach(function (el) { el.style.background = ''; el.style.borderColor = ''; });
        link.style.background = '#edf3ff';
        link.style.borderColor = '#ccdaf6';
        updateCodePanelFromLink(link);
    });
});

if (endpointLinks.length > 0) {
    updateCodePanelFromLink(endpointLinks[0]);
    endpointLinks[0].style.background = '#edf3ff';
    endpointLinks[0].style.borderColor = '#ccdaf6';
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
