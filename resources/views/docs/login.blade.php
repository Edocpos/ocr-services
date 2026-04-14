<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Docs Access</title>
    <style>
        :root { --bg:#0b1020; --card:#121a33; --muted:#93a0c6; --text:#e8edff; --accent:#7aa2ff; --danger:#ff7b7b; }
        * { box-sizing:border-box; }
        body { margin:0; font-family: Inter, Segoe UI, Arial, sans-serif; background:linear-gradient(180deg,#0b1020,#080d1a); color:var(--text); min-height:100vh; display:grid; place-items:center; }
        .card { width:min(460px,92vw); background:var(--card); border:1px solid #2b3a66; border-radius:14px; padding:24px; box-shadow:0 15px 45px rgba(0,0,0,.35); }
        h1 { margin:0 0 8px; font-size:1.35rem; }
        p { margin:0 0 18px; color:var(--muted); }
        label { display:block; margin:0 0 8px; font-size:.9rem; color:#c3cff6; }
        input { width:100%; padding:12px 14px; border-radius:10px; border:1px solid #33467b; background:#0d1430; color:var(--text); outline:none; }
        input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(122,162,255,.18); }
        button { margin-top:14px; width:100%; padding:12px 14px; border:0; border-radius:10px; background:var(--accent); color:#081230; font-weight:700; cursor:pointer; }
        .error { margin-top:10px; color:var(--danger); font-size:.9rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>API Documentation</h1>
        <p>Enter password to access internal docs.</p>

        <form method="POST" action="{{ route('docs.unlock') }}">
            @csrf
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="off" required>
            <button type="submit">Unlock Docs</button>
        </form>

        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror
    </div>
</body>
</html>
