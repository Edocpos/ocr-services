# Accounting OCR integration

`POST /api/ocr/accounting` remains a stateless multipart endpoint. Upload one logical transaction and `accounts` as a JSON array. Business fields are optional. Named supplier/customer ledgers can be matched directly by counterparty name or aliases. AP/AR control accounts require explicit control metadata only when used as controls.

## Business and account context

Optional fields:

- `msic_code`: primary company business code as a five-digit **string**, e.g. `01111`. `00000` provides no industry context.
- `additional_msic_codes`: JSON array of up to ten other code strings.
- `business_description`: actual business operations, up to 2,000 characters.
- `posting_date`: valid `YYYY-MM-DD` accounting date. Required before a proposal can be marked postable. Coverage is assessed only against this supplied accounting date; invoice/event/transaction dates are not substituted.

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

Gemini identifies the document and economic event, considers company business nature, distinguishes trade/non-trade, determines required treatment/roles, then matches the available chart. MSIC is supporting context, never the sole accounting rule. `classification` returns `document_kind`, `economic_event`, `trade_nature` and a concise `treatment_summary` alongside voucher type. `business_context_reason` explains how supplied MSIC/business activities relate to the economic event and classification; supplying MSIC without this explanation requires review. Trade means ordinary inputs to the principal trading activity (for example restaurant ingredients). Non-trade purchases such as restaurant renovation may support operations but require Other Creditors. Select a named supplier only within the correct required category; a TPTC supplier cannot satisfy an OPCR requirement. Mixed purchases require item-level assessment. Capitalisation versus expense treatment is a separate question.

Each journal line returns `required_account` (role, account_type, parent_code, account_subtype, requires_control_account and control_role), `eligible_account_codes`, and `error_code`. A missing account produces `ACCOUNT_NOT_AVAILABLE` with null account code/name. Incorrect selections produce `ACCOUNT_SELECTION_INVALID`; contradictory requirements produce `ACCOUNT_REQUIREMENT_INVALID`. A trade obligation assigned to Other Creditors, or a non-trade obligation assigned to Trade Creditors, produces `ACCOUNT_TREATMENT_CONFLICT`. Candidates are structural matches, not automatic substitutions. The AI may still identify a missing specific expense account despite structurally eligible generic accounts.

When an account is missing, `recommendation` describes a proposed account for review: `suggested_name`, role/type/subtype, canonical `parent_code`, exact source `parent_name`, `parent_definition_key`, purpose, usage examples, reason, and any required control role. The name is a proposal for a company account; it does not rename the ACC01 source category. A final leaf code is never generated. Matched accounts and invalid/conflicting requirements return null recommendations. Arkcloudant/user review must resolve or create the account and resubmit the updated chart; OCR never creates or posts it automatically. `journal_entry.is_complete` is false when any account is unresolved; a balanced amount alone does not make a proposal postable. When `requires_control_account` is true, AP/AR requirements enforce the corresponding control role and cannot be satisfied by a non-control ledger. Otherwise, a trade payable/receivable line must match exactly one named supplier/customer account by counterparty name or alias; a generic ledger, wrong counterparty, ambiguous identity or explicitly marked control cannot satisfy this direct-ledger path. Names are compared case-insensitively with punctuation/spacing normalised; use aliases for known naming variations. No fuzzy substitution is performed. `validation.final_validation_required_by` and `meta.posting_authority` identify Arkcloudant as the final validation/posting authority. `is_postable` means local proposal checks passed, not that an entry has been posted or finally approved.

Credit/debit notes describe adjustments rather than payment by default. Gemini extracts `original_document_reference`, `invoice_date`, `event_date` and `payment_date` separately. Credit reversals use positive magnitudes on the opposite journal sides. COD terms, printed bank details, invoice validation timestamps and a promise to issue a receipt are not payment evidence. Unknown settlement status requires review.

## Prepayment and period review

Gemini extracts `service_periods` per item: description, amount, start/end dates, period kind, evidence and possible prepayment. Period kinds distinguish service coverage, billing periods, payment terms and unknown periods. Annual durations without exact dates are returned with null dates. Payment status is paid/unpaid/partially_paid/unknown and requires structured evidence. `payment_evidence_kind` distinguishes settlement_confirmation, outstanding_confirmation, payment_terms, payment_instructions and none. Paid/partially paid require settlement evidence; unpaid requires an explicit outstanding/unpaid balance statement. Terms, due dates, bank details and lack of a receipt result in unknown status and manual review. Actual settlement fields (`payment_method`, `payment_reference`, `payment_date`) are cleared without supported settlement evidence; printed instructions and references remain under `payment_instructions` and `payment_instruction_reference`.

`data.prepayment_assessment` returns the normalised periods, their relation to the posting date, payment evidence, review status, and `schedule_generated: false`. Future or overlapping coverage, incomplete coverage information, malformed dates/ranges, and prepayment/deposit/advance/accrual account use require manual review. Past coverage is not automatically prepaid. Payment terms alone do not indicate prepayment. A prepaid account without coverage or full-payment evidence produces additional warnings.

The initial journal remains a proposal. No monthly allocation, future journal, account creation or posting is executed. Invalid/unbalanced proposals are returned for review rather than forcibly balanced. A period does not prove a paid advance; an unpaid future-service invoice needs an accountant's recognition assessment. Customer advances are liabilities rather than automatically earned revenue.

No feedback storage, training or fine-tuning is included.

## Supplied sample review

Local regression scenarios use anonymised facts derived from the supplied folder; the PDFs are not copied into the repository:

- Seafood supplier invoice: restaurant business context is a hypothetical evaluation input, not a fact inferred about the sample buyer. A trade purchase uses its matched supplier ledger or an explicitly marked AP control; Other Creditors must not substitute.
- Insurance debit note: coverage 13 January 2023–12 January 2024, total RM298.59; supporting pages belong to one policy. A future receipt promise does not establish payment. Coverage requires timing review and no schedule is generated.
- Supplier credit note: RM423.60 goods adjustment with an original invoice reference; debit AP control and credit the appropriate original asset/expense under the company policy.
- Transport/undercharge debit notes represent adjustments; an outgoing fish credit note adjusts a prior sale. Multiple unrelated invoice references must be reviewed as separate transactions.

Tests check the structured provider contract and local validation using mocked model output. Live Gemini classification accuracy on these PDFs has not been verified. Company identity, chart, policy, settlement and posting date remain necessary inputs; document samples alone cannot determine final accrual or prepayment treatment.

Malaysian currency aliases (`RM`, `Ringgit`, `Malaysian Ringgit`) are normalised to `MYR`; `extracted.source_currency` preserves the model value. Genuine foreign currencies still require review.

## Posting controls and item audit

A missing `posting_date` returns a proposal with `posting_date_required` and `is_postable: false`. The date is optional for extraction but required for posting readiness. No date is inferred from the invoice, economic event or server clock. Coverage assessment returns a null posting date when none is supplied. Gemini must not copy the invoice date into event_date without event evidence.

`cash_direction_unclear` is raised only for supported paid/partially-paid settlement without cash/bank journal treatment. Payment instructions do not trigger it. A proposed cash/bank entry without supported settlement evidence returns `cash_payment_not_supported` (internal cash/bank transfers are assessed separately).

`data.item_assessments` contains document-item description, amount, proposed treatment, reason, evidence, policy_assumptions and requires_policy_review. PPE proposals always return `capitalisation_policy_review` and `data.capitalisation_assessment.requires_manual_review: true`, even at confidence 1. Confirm company policy and whether repairs, improvements, preparation, painting or cleaning are directly attributable before approval; OCR does not invent thresholds or mark policy approval complete. Missing/invalid item assessments also require review. Other uncertain or assumption-dependent item treatments require review.

Matched journal entries sharing the exact account, requirement and debit/credit side are consolidated. Debits and credits are never netted against each other; unresolved account recommendations remain separate. Each output line includes source_lines and audit_support retaining every source amount, confidence, reason and evidence. Group confidence is the lowest source confidence. The original detailed proposal remains at `data.journal_entry.source_lines`, while `data.journal_entry.lines` contains the grouped proposal. Per-line validation fields point to the original source_lines so grouping cannot change their meaning. One-based source indices match audit_support.source_line.
