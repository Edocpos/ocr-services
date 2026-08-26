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

**POST** `/api/ocr/company` — Malaysian company documents (SSM certificate, company profile, SST / TIN letters, statutory employer documents)

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
- `data.extracted.lhdn_employer_no` / `epf_employer_no` / `socso_employer_no`
- `data.extracted.hrdc_employer_no` / `zakat_employer_no` / `jtk_employer_no`
- `data.derived.company_type` / `city` / `state` / `country`

Implementation: `CompanyOcrController`, `CompanyOcrPipeline`, `GeminiCompanyOcrClient`.
Tests: `tests/Feature/CompanyOcrApiTest.php`.

---

## Statutory receipt OCR
Dedicated endpoints for payroll payment receipts. Each scheme has its own parser.

| Method | Path |
|---|---|
| POST | `/api/ocr/epf` |
| POST | `/api/ocr/socso` |
| POST | `/api/ocr/eis` |
| POST | `/api/ocr/pcb` |
| POST | `/api/ocr/hrdc` |

Request: `multipart/form-data`

| Field | Required | Rules |
|---|---|---|
| `image` | Yes | PDF, JPG, JPEG, PNG or WebP. Max 10 MB. MIME is verified from file contents, not the filename. |
| `scheme` | Yes | Must match the endpoint (`epf`, `socso`, `eis`, `pcb`, `hrdc`). |

Success (HTTP 200):

```json
{
  "data": {
    "extracted": {
      "receipt_number": "20260004540969",
      "payment_date": "06/08/2026",
      "contribution_period": "07/2026",
      "contribution_reference": "ACR082260152714",
      "employer_number": "F9702103813F",
      "employer_name": "EDOCPOS SDN . BHD.",
      "transaction_id": "2608061236580909",
      "bank": "Public Bank Berhad",
      "amount": 318.45,
      "payment_description": "1.Caruman Bulanan(ACR082260152714-07/2026)(RM318.45) 2.Tunggakan Caruman- 3.Kekurangan Caruman-"
    }
  },
  "meta": {
    "scheme": "socso",
    "confidence": 0.98,
    "field_confidence": {
      "receipt_number": 0.99,
      "amount": 0.99
    },
    "requires_manual_review": false,
    "warnings": []
  }
}
```

Pipeline: embedded PDF text first, then rasterize + OCR fallback. SOCSO requires `ACR` references; EIS requires `ECR`. Cross-scheme uploads return HTTP 422 `receipt_scheme_mismatch`. PCB parses LHDN e-PCB acceptance letters (CP 502R), payment confirmation slips, and CP 6A official receipts; contribution period comes from Month/Year, BULAN/TAHUN TAKSIRAN, or the amount-month-year row, never from the payment date. EPF and HRDC extract common fields and set `requires_manual_review` until scheme samples exist.

Errors are always JSON:

- 422 validation: `{ "message": "The uploaded receipt is invalid.", "errors": { "image": ["..."] } }`
- 422 `receipt_scheme_mismatch`
- 422 `receipt_requires_manual_review`
- 429 `rate_limited`
- 503 `ocr_unavailable`

Rate limit: `throttle:ocr-statutory` (`OCR_STATUTORY_RATE_LIMIT_PER_MINUTE`).
Tests: `tests/Feature/StatutoryReceiptOcrApiTest.php`.

