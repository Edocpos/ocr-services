# Accounting OCR integration

`POST /api/ocr/accounting` remains a stateless multipart endpoint. Upload one logical transaction and `accounts` as a JSON array. Existing clients can omit the new fields.

## Business and account context

Optional fields:

- `msic_code`: primary company business code as a five-digit **string**, e.g. `01111`. `00000` provides no industry context.
- `additional_msic_codes`: JSON array of up to ten other code strings.
- `business_description`: actual business operations, up to 2,000 characters.
- `posting_date`: valid `YYYY-MM-DD` accounting date. Coverage assessment falls back to the extracted transaction date; it never uses the server's current date.

Codes are validated against `resources/accounting/msic.json`, imported from the supplied MSIC 2008 list with its duplicate 16211 removed. Gemini receives only selected codes and resolved descriptions, not the complete catalogue. Unknown codes return HTTP 422. Names/descriptions and all document content remain untrusted reference data.

Each submitted account can also include `purpose` (up to 1,000 characters) and `usage_examples` (up to three strings, each up to 500 characters). Subtypes accept the existing cash/bank/accounts_receivable/accounts_payable/input_tax/output_tax/other values and trade_receivable, trade_payable, prepayment, supplier_advance, customer_advance, refundable_deposit, accrual, other_payable, income_tax_asset and income_tax_payable.

The AI receives all 80 system categories with meanings, examples, exclusions, types and normal balances. Submitted account codes link to categories through a server-derived `category_prefix`; category guidance is supplied once rather than repeated for every account. Custom codes remain usable through their name/type/purpose/examples.

Journal lines now return `account_guidance` from the dictionary, plus `account_purpose` and `account_usage_examples` from the submitted matched account. Unknown custom prefixes return null system guidance. `GET /api/ocr/accounting/accounts` returns the whole versioned dictionary and shares the accounting rate limit.

## PDF convention and compatibility

The ACC01 v2.0 hierarchy tables on PDF pages 25–34 define canonical new-account recommendations:

- Operating revenue: `PL/TI/TIN/*`.
- Operating expenses: `PL/TE/TEX/*`.
- Incidental income: `PL/OI/OIN/*`.
- Administration/general expenses: `PL/OE/OEX/*` (DPRC, GNEX).

Exact codes of matched submitted accounts are preserved, including recognised older prefixes. New recommendations use canonical PDF parents even when existing accounts use an older alias. Consumers must accept these canonical parent codes; OCR does not migrate existing accounts or allocate leaf numbers.

The PDF contains conflicts between hierarchy tables and presets. The dictionary uses asset/liability location and transaction direction to distinguish supplier deposits paid, refundable deposits paid and customer advances received. It keeps other creditors separate from trade creditors, and income-tax assets separate from recoverable supplier input tax. Ambiguous uses are identified in exclusions. No tax rates, capitalisation thresholds or tax deductibility assumptions are imported as automatic rules.

## Prepayment and period review

Gemini extracts `service_periods` per item: description, amount, start/end dates, period kind, evidence and possible prepayment. Period kinds distinguish service coverage, billing periods, payment terms and unknown periods. Annual durations without exact dates are returned with null dates. Payment status is paid/unpaid/partially_paid/unknown and requires evidence.

`data.prepayment_assessment` returns the normalised periods, their relation to the posting date, payment evidence, review status, and `schedule_generated: false`. Future or overlapping coverage, incomplete coverage information, malformed dates/ranges, and prepayment/deposit/advance/accrual account use require manual review. Past coverage is not automatically prepaid. Payment terms alone do not indicate prepayment. A prepaid account without coverage or full-payment evidence produces additional warnings.

The initial journal remains a proposal. No monthly allocation, future journal, account creation or posting is executed. Invalid/unbalanced proposals are returned for review rather than forcibly balanced. A period does not prove a paid advance; an unpaid future-service invoice needs an accountant's recognition assessment. Customer advances are liabilities rather than automatically earned revenue.

No feedback storage, training or fine-tuning is included.
