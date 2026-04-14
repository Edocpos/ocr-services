<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $docs['title'] }}</title>
    <style>
        :root {
            --bg:#070d1f;
            --sidebar:#0c1531;
            --panel:#121d40;
            --panel-soft:#0f1735;
            --text:#e8edff;
            --muted:#8ea3d4;
            --accent:#7ba8ff;
            --accent-2:#8ddcff;
            --border:#2d3f79;
            --ok:#57d39b;
            --warn:#ffc85c;
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:radial-gradient(1200px 600px at 15% -10%, #13265f 0%, transparent 40%),var(--bg);color:var(--text)}
        a{color:inherit;text-decoration:none}
        .layout{display:grid;grid-template-columns:280px 1fr;min-height:100vh}
        .sidebar{position:sticky;top:0;height:100vh;overflow:auto;background:linear-gradient(180deg,#0d1635,#0a122a);border-right:1px solid #233568;padding:18px}
        .brand{font-size:1.15rem;font-weight:800;margin:2px 0 4px}
        .muted{color:var(--muted)}
        .tiny{font-size:.82rem}
        .pill{display:inline-block;padding:6px 10px;border-radius:999px;background:#152556;border:1px solid var(--border);font-size:.78rem;color:#cfe0ff;margin-top:8px}
        .nav-group{margin-top:18px}
        .nav-title{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:#9db2e4;margin-bottom:8px}
        .nav-item{display:block;padding:10px 10px;border-radius:8px;color:#d9e4ff;border:1px solid transparent}
        .nav-item:hover{background:#15265a;border-color:#2a4182}
        .nav-item small{display:block;color:#8da3d6;margin-top:2px}
        .logout{margin-top:18px;width:100%;border:1px solid #3559a8;background:#173071;color:#e6efff;border-radius:10px;padding:9px 12px;cursor:pointer}
        .content{padding:26px 28px 46px}
        .header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
        .title{margin:0;font-size:2rem;line-height:1.1}
        .sub{margin:8px 0 0;color:var(--muted)}
        .quick{margin-top:18px;background:var(--panel-soft);border:1px solid var(--border);border-radius:12px;padding:14px}
        .quick b{color:#cfe1ff}
        .module{margin-top:22px;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:18px}
        .module h2{margin:0 0 6px;font-size:1.45rem}
        .endpoint{margin-top:14px;background:#101a39;border:1px solid #2a3f78;border-radius:12px;padding:14px}
        .endpoint h3{margin:0 0 10px;font-size:1.06rem}
        .meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
        .meta-box{background:#0c1532;border:1px solid #26386e;border-radius:10px;padding:10px}
        .k{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:#a5b8e6}
        .v{margin-top:5px;font-weight:600}
        .method{display:inline-block;border-radius:999px;padding:3px 9px;font-size:.78rem;font-weight:700;background:#173f2f;border:1px solid #2f7a58;color:#a8ffce}
        .tag{display:inline-block;border-radius:999px;padding:3px 9px;font-size:.74rem;font-weight:700;background:#152c66;border:1px solid #3559aa;color:#c9dcff;margin-left:6px}
        h4{margin:14px 0 8px}
        table{width:100%;border-collapse:collapse;margin-top:8px;font-size:.93rem}
        th,td{border-bottom:1px solid #263864;padding:10px;text-align:left;vertical-align:top}
        th{color:#ccdbff;font-weight:700}
        code,pre{font-family:Consolas,Monaco,monospace}
        pre{margin:8px 0 0;background:#08112a;border:1px solid #253b78;border-radius:12px;padding:12px;overflow:auto;line-height:1.38;font-size:.87rem}
        .resp{margin-top:10px}
        .resp-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
        .status{font-size:.78rem;padding:3px 8px;border-radius:999px;border:1px solid #2b478d;background:#14295f;color:#d5e2ff}
        .copy{font-size:.78rem;border:1px solid #3858a5;background:#1a3272;color:#e9f1ff;border-radius:8px;padding:5px 8px;cursor:pointer}
        @media (max-width: 980px){
            .layout{grid-template-columns:1fr}
            .sidebar{position:relative;height:auto}
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">{{ $docs['title'] }}</div>
        <div class="muted tiny">{{ $docs['subtitle'] }}</div>
        <span class="pill">Version {{ $docs['version'] }}</span>

        <div class="nav-group">
            <div class="nav-title">Modules</div>
            @foreach($docs['modules'] as $module)
                <a class="nav-item" href="#module-{{ $module['id'] }}">
                    {{ $module['name'] }}
                    <small>{{ count($module['endpoints']) }} endpoint(s)</small>
                </a>
                @foreach($module['endpoints'] as $endpoint)
                    <a class="nav-item" style="margin-left:8px" href="#endpoint-{{ $endpoint['id'] }}">
                        <span class="method">{{ $endpoint['method'] }}</span>
                        <small>{{ $endpoint['path'] }}</small>
                    </a>
                @endforeach
            @endforeach
        </div>

        <form method="POST" action="{{ route('docs.logout') }}">
            @csrf
            <button class="logout" type="submit">Lock Docs</button>
        </form>
    </aside>

    <main class="content">
        <div class="header">
            <div>
                <h1 class="title">{{ $docs['title'] }}</h1>
                <p class="sub">{{ $docs['subtitle'] }}</p>
            </div>
            <span class="pill">Base URL {{ $docs['base_url'] }}</span>
        </div>

        <div class="quick">
            <b>Authentication:</b> {{ $docs['auth']['type'] }}
            <div class="muted tiny" style="margin-top:6px">{{ $docs['auth']['note'] }}</div>
        </div>

        @foreach($docs['modules'] as $module)
            <section class="module" id="module-{{ $module['id'] }}">
                <h2>{{ $module['name'] }}</h2>
                <p class="muted" style="margin:0 0 10px">{{ $module['description'] }}</p>

                @foreach($module['endpoints'] as $endpoint)
                    <article class="endpoint" id="endpoint-{{ $endpoint['id'] }}">
                        <h3>
                            {{ $endpoint['title'] }}
                            <span class="tag">{{ $endpoint['tag'] }}</span>
                        </h3>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="k">Method & Path</div>
                                <div class="v"><span class="method">{{ $endpoint['method'] }}</span> {{ $endpoint['path'] }}</div>
                            </div>
                            <div class="meta-box">
                                <div class="k">Content Type</div>
                                <div class="v">{{ $endpoint['content_type'] }}</div>
                            </div>
                            <div class="meta-box">
                                <div class="k">Rate Limit</div>
                                <div class="v">{{ $endpoint['rate_limit'] }}</div>
                            </div>
                        </div>

                        <h4>Request Fields</h4>
                        <table>
                            <thead>
                            <tr><th>Field</th><th>Type</th><th>Required</th><th>Notes</th></tr>
                            </thead>
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
                            <thead>
                            <tr><th>Code</th><th>HTTP</th><th>Note</th></tr>
                            </thead>
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
</script>
</body>
</html>
