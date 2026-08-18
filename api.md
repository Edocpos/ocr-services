# IC OCR API Documentation (Phase 1)

## Overview
This API accepts a Malaysian IC image and returns a fixed structured JSON response.

- Stateless processing (no DB persistence in core flow)
- Deterministic business output
- Null-on-failure policy (no guessing)
- OCR provider: Google Vision

---

## Endpoints
**POST** `/api/ocr/ic` — Malaysian IC (MyKad)

**POST** `/api/ocr/company` — Malaysian company documents (SSM certificate, company profile, SST / TIN letters)

Route reference: `routes/api.php`

---

## Authentication
Phase 1 currently runs without mandatory auth middleware.

> Recommended for production: add Sanctum or token-based protection.

---

## Rate Limit
Route middleware: `throttle:ocr-ic`

Default limit: `30 requests/minute/IP`

Config: `config/ocr.php` (`OCR_RATE_LIMIT_PER_MINUTE`)

---

## Request
### Content Type
`multipart/form-data`

### Fields
| Field | Type | Required | Rules |
|---|---|---:|---|
| `image` | file | Yes | Must be image, `jpeg/jpg/png` (optional `webp`), max `5MB` |

Validation source: `app/Http/Requests/ProcessIcOcrRequest.php`

### Example (cURL)
```bash
curl --location 'http://127.0.0.1:8000/api/ocr/ic' \
  --form 'image=@"C:/path/to/ic.jpg"'
```

---

## Response Contract
The API always returns the same top-level shape:

- `data.extracted`
- `data.derived`
- `validation`
- `confidence`
- `meta`

### Success / Partial (HTTP 200)
```json
{
  "data": {
    "extracted": {
      "ic_number": "900101-12-1235",
      "name": "JOHN DOE",
      "address": "123 JALAN TEST, KUALA LUMPUR"
    },
    "derived": {
      "birth_date": "1990-01-01",
      "gender": "male"
    }
  },
  "validation": {
    "status": "ok",
    "errors": [],
    "warnings": []
  },
  "confidence": {
    "ic_number": null,
    "name": null,
    "address": null,
    "birth_date": null,
    "gender": null
  },
  "meta": {
    "provider": "google_vision",
    "stateless": true
  }
}
```

### Status values
| Value | Meaning |
|---|---|
| `ok` | All required extraction/derivation valid |
| `partial` | Some fields invalid/unreadable but at least one usable value exists |
| `failed` | No usable output or system-level failure |

---

## Derivation Rules
### IC Number
- Accepts `XXXXXX-XX-XXXX` or `XXXXXXXXXXXX`
- Normalized internally to 12 digits
- Display normalized to `XXXXXX-XX-XXXX`

### Birth Date
Derived from first 6 digits (`YYMMDD`) using century rule:
- If `YY <= current year last 2 digits` => `20YY`
- Else => `19YY`

Must be valid calendar date, else `birth_date = null` and rule error added.

### Gender
Derived from last digit of IC number:
- Odd => `male`
- Even => `female`

If IC invalid, `gender = null`.

---

## Error Handling
### 1) Request validation error (HTTP 422)
Occurs when file is missing/invalid/too large/unsupported MIME.

Laravel default validation JSON format is returned when `Accept: application/json` is set.

### 2) OCR processing failure (HTTP 502)
Returned if OCR provider call fails unexpectedly.

Example:
```json
{
  "data": {
    "extracted": {
      "ic_number": null,
      "name": null,
      "address": null
    },
    "derived": {
      "birth_date": null,
      "gender": null
    }
  },
  "validation": {
    "status": "failed",
    "errors": [
      {
        "field": "system",
        "code": "ocr_processing_error",
        "message": "OCR processing failed for this request."
      }
    ],
    "warnings": []
  },
  "confidence": {
    "ic_number": null,
    "name": null,
    "address": null,
    "birth_date": null,
    "gender": null
  },
  "meta": {
    "provider": "google_vision",
    "stateless": true
  }
}
```

---

## Environment Setup
Add to `.env`:

```dotenv
GOOGLE_VISION_API_KEY=your_api_key_here
# optional alternative mode:
# GOOGLE_VISION_PROJECT_ID=...
# GOOGLE_VISION_CREDENTIALS=C:\path\service-account.json

OCR_MAX_FILE_SIZE_KB=5120
OCR_ALLOW_WEBP=false
OCR_RATE_LIMIT_PER_MINUTE=30
```

Then run:

```bash
php artisan config:clear
```

---

## Internal Layering (Implementation)
- Entry: `IcOcrController` + `ProcessIcOcrRequest`
- OCR integration: `GoogleVisionOcrClient`
- Extraction: `IcFieldExtractor`
- Normalization: `IcValueNormalizer`
- Rules/derivation: `IcRuleEngine`
- Response assembly: `IcResponseBuilder`
- Orchestration: `IcOcrPipeline`

---

## Test Coverage
Feature tests included in:
`tests/Feature/IcOcrApiTest.php`

Covers:
- valid flow
- invalid IC birth date
- unsupported file type

---

## Company OCR
**POST** `/api/ocr/company`

Same request contract as IC OCR (`image` multipart file). Returns company fields mapped for form autofill:

- `data.extracted.company_name`
- `data.extracted.company_type` (`sole_proprietor` | `partnership` | `llp` | `sdn_bhd` | `bhd`)
- `data.extracted.ssm_number`
- `data.extracted.tin_number`
- `data.extracted.sst_number`
- `data.extracted.msic_codes`
- `data.extracted.phone` / `country_code` / `email`
- `data.extracted.address_line_1` / `address_line_2` / `address_line_3`
- `data.extracted.postcode` / `city` / `state` / `country`
- `data.derived.company_type` / `city` / `state` / `country`

Implementation: `CompanyOcrController`, `CompanyOcrPipeline`, `GeminiCompanyOcrClient`.
Tests: `tests/Feature/CompanyOcrApiTest.php`.
