# Frontend Integration Guide
## Single Page HTML/CSS for IC OCR API

This guide helps you build a simple single-page frontend that calls the IC OCR API from any separate project.

---

## What You Are Building

A single HTML file with:
- a file upload input for IC image
- a submit button
- a result section that shows extracted and derived data
- basic error and validation feedback
- no frameworks required (vanilla HTML + CSS + JavaScript)

---

## Prerequisites

- IC OCR API is running at a known base URL (example: `http://127.0.0.1:8000`)
- You have a valid API key configured on the backend
- CORS must be enabled on the Laravel backend (see CORS section below)

---

## Step 1: Enable CORS on the Laravel Backend

By default Laravel allows same-origin requests only.  
Since your HTML file lives in a different project/origin, you need to allow cross-origin requests.

Open `bootstrap/app.php` in the Laravel project and add the CORS middleware:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->statefulApi();
})
```

Then configure `config/cors.php` (publish it first if not present):

```bash
php artisan config:publish cors
```

In `config/cors.php`, allow your frontend origin:

```php
'allowed_origins' => ['*'], // tighten this in production
'allowed_methods' => ['POST'],
'allowed_headers' => ['*'],
```

---

## Step 2: Page Structure

Your HTML file should have three sections:

1. **Upload form** — file input + submit button
2. **Loading indicator** — shown while request is in progress
3. **Result panel** — shows structured output or error details

---

## Step 3: HTML Skeleton

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IC OCR</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>

  <div class="container">
    <h1>IC OCR Scanner</h1>

    <!-- Upload Form -->
    <form id="upload-form">
      <label for="image-input" class="upload-label">
        <span id="file-name">Click to select IC image</span>
        <input type="file" id="image-input" accept="image/jpeg,image/png" required />
      </label>
      <button type="submit" id="submit-btn">Extract Data</button>
    </form>

    <!-- Loading -->
    <div id="loading" class="hidden">Processing...</div>

    <!-- Result Panel -->
    <div id="result-panel" class="hidden">

      <div id="status-badge"></div>

      <section>
        <h2>Extracted</h2>
        <table id="extracted-table">
          <tr><th>IC Number</th><td id="r-ic-number">—</td></tr>
          <tr><th>Name</th><td id="r-name">—</td></tr>
          <tr><th>Address</th><td id="r-address">—</td></tr>
        </table>
      </section>

      <section>
        <h2>Derived</h2>
        <table id="derived-table">
          <tr><th>Birth Date</th><td id="r-birth-date">—</td></tr>
          <tr><th>Gender</th><td id="r-gender">—</td></tr>
        </table>
      </section>

      <section id="errors-section" class="hidden">
        <h2>Validation Issues</h2>
        <ul id="errors-list"></ul>
      </section>

    </div>

    <!-- Request Error -->
    <div id="request-error" class="hidden error-box"></div>
  </div>

  <script src="app.js"></script>
</body>
</html>
```

---

## Step 4: CSS (style.css)

```css
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: system-ui, sans-serif;
  background: #f4f4f5;
  color: #1a1a1a;
  padding: 2rem;
}

.container {
  max-width: 640px;
  margin: 0 auto;
  background: #fff;
  border-radius: 12px;
  padding: 2rem;
  box-shadow: 0 2px 12px rgba(0,0,0,0.08);
}

h1 { margin-bottom: 1.5rem; font-size: 1.5rem; }
h2 { font-size: 1rem; margin-bottom: 0.5rem; color: #444; }
section { margin-top: 1.25rem; }

.upload-label {
  display: block;
  border: 2px dashed #d1d5db;
  border-radius: 8px;
  padding: 1.5rem;
  text-align: center;
  cursor: pointer;
  color: #6b7280;
  margin-bottom: 1rem;
  transition: border-color 0.2s;
}

.upload-label:hover { border-color: #6366f1; color: #6366f1; }
.upload-label input { display: none; }

button {
  width: 100%;
  padding: 0.75rem;
  background: #6366f1;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 1rem;
  cursor: pointer;
  transition: background 0.2s;
}

button:hover { background: #4f46e5; }
button:disabled { background: #a5b4fc; cursor: not-allowed; }

#loading {
  text-align: center;
  padding: 1rem;
  color: #6366f1;
  font-weight: 500;
}

table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
th, td { padding: 0.5rem 0.75rem; border: 1px solid #e5e7eb; font-size: 0.9rem; text-align: left; }
th { background: #f9fafb; width: 35%; font-weight: 600; }

#status-badge {
  display: inline-block;
  padding: 0.3rem 0.75rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
  margin-bottom: 0.75rem;
  text-transform: uppercase;
}

.status-ok    { background: #d1fae5; color: #065f46; }
.status-partial { background: #fef3c7; color: #92400e; }
.status-failed  { background: #fee2e2; color: #991b1b; }

.error-box {
  background: #fee2e2;
  color: #991b1b;
  border-radius: 8px;
  padding: 0.75rem 1rem;
  margin-top: 1rem;
  font-size: 0.9rem;
}

#errors-list { padding-left: 1.25rem; }
#errors-list li { font-size: 0.85rem; color: #b45309; margin-top: 0.25rem; }

.hidden { display: none; }
```

---

## Step 5: JavaScript (app.js)

```js
const API_URL = 'http://127.0.0.1:8000/api/ocr/ic';

const form       = document.getElementById('upload-form');
const fileInput  = document.getElementById('image-input');
const fileLabel  = document.getElementById('file-name');
const submitBtn  = document.getElementById('submit-btn');
const loading    = document.getElementById('loading');
const panel      = document.getElementById('result-panel');
const reqErr     = document.getElementById('request-error');

fileInput.addEventListener('change', () => {
  fileLabel.textContent = fileInput.files[0]?.name ?? 'Click to select IC image';
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const file = fileInput.files[0];
  if (!file) return;

  setLoadingState(true);
  clearResults();

  const body = new FormData();
  body.append('image', file);

  try {
    const res = await fetch(API_URL, { method: 'POST', body });
    const json = await res.json();

    if (res.status === 422) {
      showRequestError('Invalid file. Please upload a valid JPEG or PNG image under 5MB.');
      return;
    }

    if (!res.ok) {
      showRequestError('Server error. Please try again.');
      return;
    }

    renderResult(json);

  } catch (err) {
    showRequestError('Could not reach the API. Check your connection or API URL.');
  } finally {
    setLoadingState(false);
  }
});

function renderResult(json) {
  const ext = json?.data?.extracted ?? {};
  const der = json?.data?.derived   ?? {};
  const val = json?.validation      ?? {};

  setText('r-ic-number',  ext.ic_number  ?? '—');
  setText('r-name',       ext.name       ?? '—');
  setText('r-address',    ext.address    ?? '—');
  setText('r-birth-date', der.birth_date ?? '—');
  setText('r-gender',     der.gender     ?? '—');

  const badge = document.getElementById('status-badge');
  badge.textContent = val.status ?? 'unknown';
  badge.className = '';
  badge.classList.add('status-' + (val.status ?? 'failed'));

  const errors = val.errors ?? [];
  if (errors.length > 0) {
    const list = document.getElementById('errors-list');
    list.innerHTML = '';
    errors.forEach(err => {
      const li = document.createElement('li');
      li.textContent = '[' + err.field + '] ' + err.message;
      list.appendChild(li);
    });
    show('errors-section');
  }

  show('result-panel');
}

function setText(id, val) {
  document.getElementById(id).textContent = val;
}

function show(id)   { document.getElementById(id).classList.remove('hidden'); }
function hide(id)   { document.getElementById(id).classList.add('hidden'); }

function clearResults() {
  hide('result-panel');
  hide('request-error');
  hide('errors-section');
  reqErr.textContent = '';
  ['r-ic-number','r-name','r-address','r-birth-date','r-gender'].forEach(id => setText(id, '—'));
}

function showRequestError(msg) {
  reqErr.textContent = msg;
  show('request-error');
}

function setLoadingState(active) {
  submitBtn.disabled = active;
  active ? show('loading') : hide('loading');
}
```

---

## Step 6: File Structure (Frontend Project)

```
your-frontend/
  index.html
  style.css
  app.js
```

No build tool needed. Open `index.html` in a browser or serve with any static file server.

---

## Step 7: Changing the API URL

In `app.js`, change this line to point to your deployed API:

```js
const API_URL = 'http://127.0.0.1:8000/api/ocr/ic';
```

---

## Notes

- Always send file as `multipart/form-data` — the API does not accept JSON body
- File field name must be exactly `image`
- Accepted formats: `image/jpeg`, `image/jpg`, `image/png`
- Maximum file size: `5MB`
- Response always returns same JSON structure regardless of success or failure
- Set `Accept: application/json` header if you want Laravel validation errors as JSON
