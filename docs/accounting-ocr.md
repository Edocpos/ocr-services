# Accounting OCR integration

`POST /api/ocr/accounting` is a stateless multipart endpoint for one logical transaction. Its public response is limited to `data` and `validation`.

## Request

- `document`: PDF, JPG, PNG or WebP, up to 10 MB.
- `accounts`: required JSON array, up to 500 accounts; an empty chart is allowed. Each account needs code, name, type and subtype. Optional aliases, purpose and usage_examples improve matching.
- `company`: optional JSON string or company context to identify the buyer/seller.
- `msic_code`: optional five-digit primary business code; preserve leading zeros. `00000` gives no industry context.
- `additional_msic_codes`: optional JSON array of up to ten additional five-digit strings.
- `business_description`: optional actual business operations, up to 2,000 characters.
- `posting_date`: optional for extraction, required for posting readiness, in YYYY-MM-DD format. It is never inferred from invoice/event dates.

MSIC codes are validated against the local catalogue. Gemini receives selected business activities, company accounts and the ACC01 semantic library. Business/account/document text is reference data, not instructions.

Named supplier/customer matching must satisfy the required classification. Trade creditors cover ordinary trading inputs, such as restaurant ingredients. Other creditors cover purchases outside that activity, such as restaurant renovation. A matching name under Trade Creditors cannot satisfy an Other Creditors requirement. Custom accounts may supply role and canonical acc01_prefix. When a trade AP/AR control is required, it needs is_control_account=true and control_role=ap/ar; an individual ledger cannot bypass that requirement.

## Response

- `data.extracted`: transaction_date, reference_number, counterparty, description, currency.
- `data.classification`: voucher_type only.
- `data.journal_entry.lines`: account_code, account_name, description, debit, credit, confidence, reason, evidence, recommendation.
- `validation`: status, is_postable, requires_manual_review, warnings, errors.

Debit/credit amounts are decimal strings with exactly two places. Confidence remains numeric. Matched entries sharing the same account, treatment and debit/credit side are grouped; opposing sides are never netted. Supporting reasons/evidence are retained in the public strings. Missing accounts remain separate.

For a missing account, account_code is null and account_name is a proposed name, not an existing account. Named supplier recommendations use the supplier name alone, with no creditor-category prefix; the category is in recommendation. recommendation contains only parent_definition_key, parent_code, account_type and account_subtype. Matched accounts return recommendation=null. OCR does not allocate leaf codes or create accounts. Arkcloudant reviews the requirement, chooses or creates the appropriate account, and resubmits the chart.

Validation messages and warning fields are preserved from the internal checks. Line validation uses zero-based paths such as journal_entry.lines[1], referring to the grouped public lines. ACCOUNT_NOT_AVAILABLE, ACCOUNT_SELECTION_INVALID, ACCOUNT_REQUIREMENT_INVALID and ACCOUNT_TREATMENT_CONFLICT block posting. Operational/provider failures retain their HTTP error responses; request validation failures return HTTP 422.

Payment terms/instructions do not establish paid/unpaid status. Unknown settlement, missing posting dates, uncertain coverage and PPE policy assumptions remain actionable warnings; they are not suppressed by the smaller response. Arkcloudant remains responsible for final validation and posting. No feedback training or allocation schedules are performed.

Business context, detailed classification, item assessments, source lines, account semantics, provider metadata, usage estimates and internal diagnostics are not included in the public OCR response. `GET /api/ocr/accounting/accounts` still provides the complete versioned ACC01 reference library when explicitly needed.

## Example

```json
{
  "data": {
    "extracted": {
      "transaction_date": "2026-09-21",
      "reference_number": "SRI-INV-2026-0921-001",
      "counterparty": "SABAH RENOVATION & INTERIOR SDN BHD",
      "description": "Restaurant Renovation Works - Kitchen & Dining Area",
      "currency": "MYR"
    },
    "classification": {
      "voucher_type": "general_voucher"
    },
    "journal_entry": {
      "lines": [
        {
          "account_code": "BS/NA/PPE/RENO/10000",
          "account_name": "RENOVATION",
          "description": "Restaurant renovation costs",
          "debit": "12250.00",
          "credit": "0.00",
          "confidence": 0.9,
          "reason": "The renovation costs are proposed for capitalisation.",
          "evidence": "Invoice: Restaurant Renovation Works",
          "recommendation": null
        },
        {
          "account_code": null,
          "account_name": "SABAH RENOVATION & INTERIOR SDN BHD",
          "description": "Amount payable for restaurant renovation",
          "debit": "0.00",
          "credit": "12250.00",
          "confidence": 1.0,
          "reason": "The invoice creates a non-trade payable to the contractor.",
          "evidence": "Invoice issued by Sabah Renovation & Interior Sdn Bhd",
          "recommendation": {
            "parent_definition_key": "OPCR",
            "parent_code": "BS/CL/OPY/OPCR",
            "account_type": "liability",
            "account_subtype": "other_payable"
          }
        }
      ]
    }
  },
  "validation": {
    "status": "review_required",
    "is_postable": false,
    "requires_manual_review": true,
    "warnings": [
      {
        "code": "posting_date_required",
        "field": "posting_date",
        "message": "Arkcloudant must supply the accounting posting date before posting; invoice and event dates do not establish it."
      },
      {
        "code": "payment_status_unclear",
        "field": "payment_status",
        "message": "Payment is not established by document terms or bank details; confirm settlement status before posting."
      },
      {
        "code": "capitalisation_policy_review",
        "field": "capitalisation",
        "message": "PPE treatment is a proposal. Confirm improvements versus repairs, directly attributable costs and company capitalisation policy for each item before approval."
      }
    ],
    "errors": [
      {
        "code": "ACCOUNT_NOT_AVAILABLE",
        "field": "journal_entry.lines[1]",
        "message": "No supplied account satisfies the required accounting classification and control role. Arkcloudant must resolve this requirement before posting."
      }
    ]
  }
}
```
