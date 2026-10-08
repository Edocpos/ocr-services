# Accounting OCR integration

`POST /api/ocr/accounting` remains a stateless multipart endpoint. Upload one logical transaction and `accounts` as a JSON array. Business fields are optional. AP/AR accounts require explicit control metadata to be eligible; existing clients must supply it before their AP/AR proposals can pass validation.

## Business and account context

Optional fields:

- `msic_code`: primary company business code as a five-digit **string**, e.g. `01111`. `00000` provides no industry context.
- `additional_msic_codes`: JSON array of up to ten other code strings.
- `business_description`: actual business operations, up to 2,000 characters.
- `posting_date`: valid `YYYY-MM-DD` accounting date. Coverage assessment falls back to the extracted transaction date; it never uses the server's current date.

Codes are validated against `resources/accounting/msic.json`, imported from the supplied MSIC 2008 list with its duplicate 16211 removed. Gemini receives only selected codes and resolved descriptions, not the complete catalogue. Unknown codes return HTTP 422. Names/descriptions and all document content remain untrusted reference data.

The `accounts` field is required but may contain an empty array (up to 500 entries). Each submitted account can also include `purpose` (up to 1,000 characters) and `usage_examples` (up to three strings, each up to 500 characters). Subtypes accept the existing cash/bank/accounts_receivable/accounts_payable/input_tax/output_tax/other values and trade_receivable, trade_payable, prepayment, supplier_advance, customer_advance, refundable_deposit, accrual, other_payable, income_tax_asset and income_tax_payable.

The AI receives all 80 system categories with exact ACC01 source names and definitions, separate accounting purposes, aliases, roles, examples, exclusions, types and normal balances. The source wording is preserved, including ambiguous deposit labels; purpose and exclusions explain transaction direction separately. Submitted account codes link to categories through a server-derived `category_prefix`; category guidance is supplied once rather than repeated for every account. Custom codes can declare `role` and `acc01_prefix` (a canonical category). Known ACC01 codes derive their role on the server; supplied metadata cannot override that role. Mark an AP/AR control account with `is_control_account: true` and `control_role: "ap"` or `"ar"`. A supplier/customer ledger without these flags is not an eligible control account.

Journal lines now return `account_guidance` from the dictionary, plus `account_purpose` and `account_usage_examples` from the submitted matched account. Unknown custom prefixes return null system guidance. `GET /api/ocr/accounting/accounts` returns the whole versioned dictionary and shares the accounting rate limit.

## PDF convention and compatibility

The ACC01 v2.0 hierarchy tables on PDF pages 25–34 define canonical category requirements:

- Operating revenue: `PL/TI/TIN/*`.
- Operating expenses: `PL/TE/TEX/*`.
- Incidental income: `PL/OI/OIN/*`.
- Administration/general expenses: `PL/OE/OEX/*` (DPRC, GNEX).

Exact codes of matched submitted accounts are preserved, including recognised older prefixes. Required classifications use canonical PDF parents even when existing accounts use an older alias. OCR does not migrate existing accounts or allocate leaf numbers.

The PDF contains conflicts between hierarchy tables and presets. The dictionary uses asset/liability location and transaction direction to distinguish supplier deposits paid, refundable deposits paid and customer advances received. It keeps other creditors separate from trade creditors, and income-tax assets separate from recoverable supplier input tax. Ambiguous uses are identified in exclusions. No tax rates, capitalisation thresholds or tax deductibility assumptions are imported as automatic rules.

## Treatment before account selection

Gemini identifies the document and economic event, considers company business nature, distinguishes trade/non-trade, determines required treatment/roles, then matches the available chart. MSIC is supporting context, never the sole accounting rule. `classification` returns `document_kind`, `economic_event`, `trade_nature` and a concise `treatment_summary` alongside voucher type.

Each journal line returns `required_account` (role, account_type, parent_code, account_subtype, requires_control_account and control_role), `eligible_account_codes`, and `error_code`. A missing account produces `ACCOUNT_NOT_AVAILABLE` with null account code/name. Incorrect selections produce `ACCOUNT_SELECTION_INVALID`; contradictory requirements produce `ACCOUNT_REQUIREMENT_INVALID`. A trade obligation assigned to Other Creditors produces `ACCOUNT_TREATMENT_CONFLICT`. Candidates are structural matches, not automatic substitutions. The AI may still identify a missing specific expense account despite structurally eligible generic accounts.

When an account is missing, `recommendation` describes a proposed account for review: `suggested_name`, role/type/subtype, canonical `parent_code`, exact source `parent_name`, `parent_definition_key`, purpose, usage examples, reason, and any required control role. The name is a proposal for a company account; it does not rename the ACC01 source category. A final leaf code is never generated. Matched accounts and invalid/conflicting requirements return null recommendations. Arkcloudant/user review must resolve or create the account and resubmit the updated chart; OCR never creates or posts it automatically. `journal_entry.is_complete` is false when any account is unresolved; a balanced amount alone does not make a proposal postable. AP/AR requirements force the corresponding control role and cannot be satisfied by a non-control ledger. `validation.final_validation_required_by` and `meta.posting_authority` identify Arkcloudant as the final validation/posting authority. `is_postable` means local proposal checks passed, not that an entry has been posted or finally approved.

Credit/debit notes describe adjustments rather than payment by default. Gemini extracts `original_document_reference`, `invoice_date`, `event_date` and `payment_date` separately. Credit reversals use positive magnitudes on the opposite journal sides. COD terms, printed bank details, invoice validation timestamps and a promise to issue a receipt are not payment evidence. Unknown settlement status requires review.

## Prepayment and period review

Gemini extracts `service_periods` per item: description, amount, start/end dates, period kind, evidence and possible prepayment. Period kinds distinguish service coverage, billing periods, payment terms and unknown periods. Annual durations without exact dates are returned with null dates. Payment status is paid/unpaid/partially_paid/unknown and requires evidence.

`data.prepayment_assessment` returns the normalised periods, their relation to the posting date, payment evidence, review status, and `schedule_generated: false`. Future or overlapping coverage, incomplete coverage information, malformed dates/ranges, and prepayment/deposit/advance/accrual account use require manual review. Past coverage is not automatically prepaid. Payment terms alone do not indicate prepayment. A prepaid account without coverage or full-payment evidence produces additional warnings.

The initial journal remains a proposal. No monthly allocation, future journal, account creation or posting is executed. Invalid/unbalanced proposals are returned for review rather than forcibly balanced. A period does not prove a paid advance; an unpaid future-service invoice needs an accountant's recognition assessment. Customer advances are liabilities rather than automatically earned revenue.

No feedback storage, training or fine-tuning is included.

## Supplied sample review

Local regression scenarios use anonymised facts derived from the supplied folder; the PDFs are not copied into the repository:

- Seafood supplier invoice: restaurant business context is a hypothetical evaluation input, not a fact inferred about the sample buyer. A trade purchase must use an explicitly marked AP control; Other Creditors must not substitute.
- Insurance debit note: coverage 13 January 2023–12 January 2024, total RM298.59; supporting pages belong to one policy. A future receipt promise does not establish payment. Coverage requires timing review and no schedule is generated.
- Supplier credit note: RM423.60 goods adjustment with an original invoice reference; debit AP control and credit the appropriate original asset/expense under the company policy.
- Transport/undercharge debit notes represent adjustments; an outgoing fish credit note adjusts a prior sale. Multiple unrelated invoice references must be reviewed as separate transactions.

Tests check the structured provider contract and local validation using mocked model output. Live Gemini classification accuracy on these PDFs has not been verified. Company identity, chart, policy, settlement and posting date remain necessary inputs; document samples alone cannot determine final accrual or prepayment treatment.
