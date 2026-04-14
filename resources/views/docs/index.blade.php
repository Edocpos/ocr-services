<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $docs['title'] }}</title>
    <style>
        :root { --bg:#0a1022; --panel:#121b3a; --panel2:#0f1730; --text:#e8edff; --muted:#9aabd7; --accent:#78a2ff; --ok:#57d39b; --warn:#ffc85c; --border:#293b72; }
        *{box-sizing:border-box}
        body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--text)}
        .wrap{max-width:1080px;margin:0 auto;padding:28px 18px 44px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
        .title{margin:0;font-size:1.65rem}
        .sub{margin:6px 0 0;color:var(--muted)}
        .pill{display:inline-block;padding:6px 10px;border-radius:999px;background:#1a2651;border:1px solid var(--border);font-size:.8rem;color:#cdd9ff}
        .logout{background:#1b2a58;color:#dce6ff;border:1px solid #3a5197;border-radius:10px;padding:8px 12px;cursor:pointer}
        .card{margin-top:18px;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:18px}
        .grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-top:12px}
        .box{background:var(--panel2);border:1px solid var(--border);border-radius:12px;padding:12px}
        .k{color:var(--muted);font-size:.82rem;text-transform:uppercase;letter-spacing:.04em}
        .v{margin-top:4px;font-weight:600}
        table{width:100%;border-collapse:collapse;margin-top:10px;font-size:.95rem}
        th,td{border-bottom:1px solid #263564;padding:10px;text-align:left;vertical-align:top}
        th{color:#cdd9ff;font-weight:600}
        code,pre{font-family:Consolas,Monaco,monospace}
        pre{background:#0b1330;border:1px solid #2a3f79;border-radius:12px;padding:14px;overflow:auto;line-height:1.35}
        .method{display:inline-block;background:#1b3f2f;color:#a8ffce;border:1px solid #2f7a58;border-radius:999px;padding:3px 10px;font-size:.8rem;font-weight:700}
        .status-ok{color:var(--ok)} .status-warn{color:var(--warn)}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1 class="title">{{ $docs['title'] }}</h1>
            <p class="sub">{{ $docs['subtitle'] }}</p>
            <span class="pill">Version {{ $docs['version'] }}</span>
        </div>
        <form method="POST" action="{{ route('docs.logout') }}">
            @csrf
            <button class="logout" type="submit">Lock Docs</button>
        </form>
    </div>

    @foreach($docs['modules'] as $module)
        <section class="card">
            <h2 style="margin:0 0 6px">{{ $module['name'] }}</h2>
            <p style="margin:0;color:var(--muted)">{{ $module['description'] }}</p>

            <div class="grid">
                <div class="box">
                    <div class="k">Endpoint</div>
                    <div class="v"><span class="method">{{ $module['endpoint']['method'] }}</span> {{ $module['endpoint']['path'] }}</div>
                </div>
                <div class="box">
                    <div class="k">Content Type</div>
                    <div class="v">{{ $module['endpoint']['content_type'] }}</div>
                </div>
                <div class="box">
                    <div class="k">Rate Limit</div>
                    <div class="v">{{ $module['endpoint']['rate_limit'] }}</div>
                </div>
            </div>

            <h3 style="margin:16px 0 6px">Request Fields</h3>
            <table>
                <thead>
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Notes</th></tr>
                </thead>
                <tbody>
                @foreach($module['request'] as $row)
                    <tr>
                        <td>{{ $row['field'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['required'] ? 'Yes' : 'No' }}</td>
                        <td>{{ $row['notes'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <h3 style="margin:16px 0 6px">Example Response</h3>
            <pre>{{ json_encode($module['response_example'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>

            <h3 style="margin:16px 0 6px">Known Error Codes</h3>
            <table>
                <thead>
                <tr><th>Code</th><th>HTTP</th><th>Note</th></tr>
                </thead>
                <tbody>
                @foreach($module['errors'] as $err)
                    <tr>
                        <td><code>{{ $err['code'] }}</code></td>
                        <td>{{ $err['http'] }}</td>
                        <td>{{ $err['note'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</div>
</body>
</html>
