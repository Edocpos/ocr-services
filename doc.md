We are building a business-focused Document OCR API designed to convert images of official documents into clean, structured data.

The goal is not to return raw OCR text, but to return a predefined, standardized JSON response that can be directly used by other systems such as onboarding, verification, and form autofill.

For the first phase, we are focusing on a fixed-template Identity Card (IC).

Document Clarification:
This product should be understood as a document data extraction service, not a general OCR text reader.

A normal OCR tool reads text from an image and returns raw text.
This system goes further:
- it receives a document image
- identifies the required fields based on the known document structure
- extracts only the relevant values
- applies validation and business rules
- returns a clean predefined response format

So the objective is structured business output, not plain text extraction.

Concept Overview:
A user uploads an image of an IC. The system processes the image, extracts relevant information, applies validation and business rules, and returns a structured response.

This is not a generic AI-based document understanding system. Instead, it is a controlled, rule-based extraction system because the IC follows a consistent layout and predictable data format.

Key Principles:
- The output must always follow a fixed JSON schema
- The system must prioritize accuracy, consistency, and predictability
- No guessing or hallucination — if data is unclear, return null or flag it
- Separate extracted data from derived data
- Ensure results are clean and usable without further processing
- Focus on business usability, not raw OCR output

Data Handling Logic:
There are two types of data in the response:

1. Extracted Data (directly read from the image)
   - IC number
   - Name
   - Address

2. Derived Data (calculated from extracted values)
   - Birth date (derived from IC number)
   - Gender (derived from IC number)

This means the system does not rely purely on OCR output. It also applies business logic to enrich and validate the data.

Processing Flow (Conceptual):
1. Receive image from user
2. Read text from the document image
3. Identify and map relevant fields based on known IC structure
4. Clean and normalize extracted values
5. Apply rules to derive additional fields such as birth date and gender
6. Validate all fields
7. Return structured JSON response

Output Expectations:
- Always return the same response structure
- Missing or unclear fields should be null, not guessed
- Include basic warning or confidence indicators if needed
- Ensure formatting is standardized such as date format, casing, and spacing
- Response should be ready for downstream system use

Database Clarification:
For the current phase, this product does not require a database as part of the core extraction flow.

The intended first version is stateless:
- user sends image
- system processes it
- system returns structured JSON
- no need to store the document or extracted result by default

This approach is suitable because:
- simpler to build
- lower operational complexity
- better for privacy-sensitive document handling
- enough for MVP and direct API integration use cases

A database may only become necessary later if the product needs:
- request history
- audit trail
- usage tracking
- async job processing
- retry handling
- analytics or reporting

For now, storage is not part of the main concept. The product should be treated as an on-demand extraction API.

Scope (Phase 1):
- Only supports one IC template
- Focus on reliability over flexibility
- No generic multi-document interpretation
- No database required for core operation
- No need for AI-based interpretation in this phase

Future Direction (not part of current scope):
- Support multiple document types
- Add optional storage and audit capability
- Introduce fallback handling for poor-quality images
- Add document classification
- Improve normalization for more complex fields

Summary:
We are building a structured document extraction API, not a generic OCR tool and not a document storage platform. The system should be deterministic, predictable, and designed for real business usage where clean, validated data is required from a document image and returned immediately in a fixed response structure.

---

## Phase 1 Clarified Specification (Confirmed)

### 1) IC Number Format and Parsing
- Target format: Malaysian NRIC / MyKad style
- Canonical display format: `XXXXXX-XX-XXXX`
- Internal parsing format: 12 digits
- Accepted input formats:
   - `900101-12-1234`
   - `900101121234`
- Normalization steps:
   1. Remove spaces
   2. Remove hyphens
   3. Ensure result is exactly 12 numeric digits
- Segment meaning:
   - First 6 digits: birth date portion (`YYMMDD`)
   - Next 2 digits: state/place code
   - Last 4 digits: serial sequence
- Checksum validation: not required in Phase 1

### 2) Derivation Rules
- Birth date derivation:
   - Use first 6 digits (`YYMMDD`)
   - Century rule:
      - If `YY` <= current year last 2 digits, interpret as `20YY`
      - Else interpret as `19YY`
   - Must pass calendar validation (invalid dates become null)
   - Example:
      - `900101` -> `1990-01-01`
      - `050312` -> `2005-03-12`
- Gender derivation:
   - Use last digit of 12-digit IC number
   - Odd = `male`
   - Even = `female`

### 3) Language Scope (Phase 1)
- Latin text only
- Name:
   - Latin letters (upper/lower), spaces, dots, apostrophes, common separators
- Address:
   - Latin letters, digits, commas, slashes, hyphens, line breaks
- Multilingual extraction is out of scope for Phase 1

### 4) API Contract
- Endpoint: `POST /api/ocr/ic`
- Endpoint is document-specific for Phase 1 (not generic multi-document)
- Behavior:
   - Receive IC image
   - Extract + validate + derive
   - Return fixed structured JSON response

### OCR Engine Decision (Phase 1)
- Selected OCR layer: Google Vision API
- Rationale:
   - Better extraction stability for real uploaded IC images in MVP
   - Lower OCR tuning and maintenance burden for initial rollout
   - Aligns with product goal: stateless structured extraction API, not local OCR engine development
- Constraints retained:
   - Fixed field mapping
   - Deterministic rule-based derivation for `birth_date` and `gender`
   - No AI interpretation layer
   - No database in core extraction flow
- Note:
   - Tesseract remains a future alternative only if cost, offline deployment, or vendor-independence becomes a stronger requirement

### 5) Input Format
- Phase 1 accepts multipart file upload only
- Deferred:
   - Base64 payload
   - Image URL input

### 6) Validation Policy
- If extraction/validation fails for a field:
   - Return `null` for that field
   - Include field-level issue in `validation`/`errors` section
- No guessing of missing values
- Dependency rule:
   - If source field invalid, dependent derived field must be `null`
- Example failures:
   - IC number not 12 digits after normalization
   - Invalid IC date portion
   - Unreadable name
   - Low-quality/empty address region

### 7) Confidence Model
- Phase 1: optional placeholder
- Keep response contract ready for confidence values
- Recommended:
   - Confidence on extracted fields may be provided
   - Derived fields may be `null` for confidence

### 8) Security and Operational Limits
- Max file size: 5 MB
- Allowed MIME types:
   - `image/jpeg`
   - `image/png`
   - `image/jpg`
   - Optional `image/webp` only if fully tested
- Rejected formats:
   - PDF
   - HEIC
   - Other unsupported/ambiguous formats
- Rate limiting:
   - Starting baseline: 30 requests/minute/client (or per IP)
- Privacy and safety:
   - Process in memory where possible
   - Do not store uploaded IC images by default
   - Do not log full IC values in plain text
   - Mask sensitive values if logging is needed

### Phase 1 Response Behavior (Final)
- Always return a fixed JSON structure
- Normalize IC number into canonical display format
- Derive `birth_date` and `gender` only from a valid IC number
- Return `null` for invalid/unreadable fields
- Include field-level validation/error details
- Keep system stateless by default

---

## Laravel API Architecture Blueprint (Phase 1)

The backend should be implemented as a pipeline with clear, single-purpose layers.

### Layer Mapping
1. API Entry Layer
   - Controller + Form Request only
   - Responsibilities: request intake, file validation, orchestration call, response return
   - Must remain thin

2. OCR Integration Layer
   - OCR provider service abstraction + Google Vision implementation
   - Responsibilities: send image to Vision API, return OCR payload
   - Keep provider isolated to allow future replacement

3. Field Extraction Layer
   - IC extraction service/action
   - Responsibilities: map OCR output into candidate values:
     - `ic_number`
     - `name`
     - `address`

4. Normalization Layer
   - Dedicated normalizers
   - Responsibilities: spacing cleanup, text normalization, IC canonical formatting prep

5. Rule and Derivation Layer
   - Business rule service (source of truth)
   - Responsibilities:
     - IC structural validation
     - date logic validation
     - derive `birth_date`
     - derive `gender`

6. Validation and Issue Reporting Layer
   - Result evaluator service
   - Responsibilities:
     - apply null-on-failure policy
     - classify field states (valid/invalid/missing/unreadable)
     - collect structured field-level issues

7. Response Assembly Layer
   - Response DTO/resource/transformer
   - Responsibilities:
     - return fixed JSON contract in all outcomes
     - separate extracted vs derived vs validation metadata

8. Security and Access Layer
   - Auth + middleware + throttling
   - Responsibilities: protect endpoint, enforce rate limiting, support production safety

### Pipeline Order
Request Intake
-> OCR Integration
-> Field Extraction
-> Normalization
-> Rule Validation and Derivation
-> Issue Collection
-> Fixed Response Assembly

### Package Direction
- Core:
  - `google/cloud-vision`
  - `laravel/sanctum`
- Optional:
  - `spatie/laravel-data`
  - `intervention/image`

### Architectural Constraints
- Stateless by default (no DB persistence in core flow)
- No AI interpretation layer in Phase 1
- Deterministic behavior only
- No guessing; invalid/unclear values return `null`
- Keep controller logic minimal and orchestration-focused