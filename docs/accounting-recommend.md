# Structured accounting recommendation

`POST /api/accounting/recommend` accepts `application/json` for a confirmed Customer or Supplier commercial document. It does not accept a file. `POST /api/ocr/accounting` remains the separate scan workflow.

## Request

Root fields: `document_kind`, matching `workflow_context`, `currency` (`MYR`), `counterparty`, `document`, and 1–500 `accounts`. Optional fields are `company`, `msic_code`, `additional_msic_codes`, and `hints`. The counterparty role must be `customer` for customer kinds and `supplier` for supplier kinds. Each account needs `code`, `name`, `type`, and `subtype`; `aliases` are optional. Hint codes must be present in `accounts`. Internal database IDs are not part of this contract.

Supported kinds: `customer_invoice`, `customer_credit_note`, `customer_debit_note`, `customer_receipt`, `customer_refund`, `supplier_bill`, `supplier_credit_note`, `supplier_debit_note`, `supplier_payment`, `supplier_refund`.

Invoices, bills, credit notes, and debit notes require `document_date`, `subtotal`, `tax`, `total`, and at least one commercial line (`line_no`, `description`, `quantity`, `unit_price`, `amount`). Commercial line amounts must sum to `subtotal`; `subtotal + tax` must equal `total`. Receipts require `receipt_date`; payments require `payment_date`; refunds require `refund_date`. These money movements require `amount`. Receipts and payments also require an `allocations` array, which may be empty. `unapplied_amount`, when supplied, plus allocations must equal `amount`. Allocations cannot exceed the document amount. Dates use `YYYY-MM-DD`; monetary values are decimal strings with at most two fractional digits.

## Response

HTTP 200 returns `data`, `validation`, and `meta`. `data.extracted` echoes the authoritative commercial date, reference, party, currency and amount. `data.classification` echoes the document kind and gives `journal_voucher`, `receipt_voucher` or `payment_voucher`. `data.journal_entry` includes lines, totals, and `is_balanced`. Matched line codes are exact submitted codes; unmatched lines have a null code and a creation recommendation. `validation.is_postable` is a structural readiness signal for a draft, not authorization to post the GL. `meta` identifies Gemini and the structured input mode.

The service rejects a provider proposal if it uses a code outside the submitted chart, gives incompatible account type/known ACC01 role, omits an unmatched-account recommendation, or fails to balance and reconcile to `document.total` or `document.amount`. It never creates chart accounts or posts a ledger entry.

## Errors

Invalid requests return 422 and endpoint rate limits return 429. Provider timeouts return 504. Provider rejection, an unavailable model, malformed provider JSON, or a journal proposal that fails server-side checks returns 502 with a specific `error_code` and `request_id`. Provider credentials or upstream rate limits return 503.
