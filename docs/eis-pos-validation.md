# EIS buyer and relief validation

All Sales (`/sells`) displays EIS submission status separately from payment and
void status: Submitted, Pending, Failed, or Not submitted when no tracking exists.
The column reflects saved tracking; it does not initiate fiscal submissions.

## Fiscal sales returns

Saving a new full return of a submitted EIS sale prepares a receipt cancellation
atomically with the local return and sends it automatically after commit (web and
Connector API). A full return must match every original quantity and the original
invoice total. Cancellation requires the existing `sell.delete` or
`business_settings.access` permission in addition to return/location access.
The reason identifies the local return and original sale.

Partial returns keep the prepared credit/debit-note review workflow. A full
return is blocked if a fiscal note chain already exists or a historical local
return has not been fiscally reconciled; review these with MRA rather than
crediting and cancelling the same invoice.

Cancellation acceptance means the request was received, not that MRA approved
the void. The return already updates local stock/customer credit. Rejected,
unsent, or uncertain cancellations are reported separately after saving, and
uncertain outcomes are never automatically resent. Review saved requests in
Settings > MRA EIS > EIS Sales. Rollback sends no cancellation.

## Dashboard fiscal VAT

The dashboard replaces all three purchase summary cards (purchase total, due,
and returns) with Total EIS Sales. It sums fiscal `invoiceSummary.invoiceTotal`
for the same submitted sales and accepted notes and uses the same submission-date,
business, location and creator filters as Total VAT. Pending cancellations are
not deducted. Other ERP sales and expense cards remain unchanged.

Home dashboard VAT matches the portal gross submission total: it adds
`invoiceSummary.totalVAT` from submitted, fully paid EIS sales and accepted
credit/debit notes rather than summing POS per-unit tax. This is not a net VAT
liability calculation; accepted notes contribute their reported VAT, not a
locally inferred signed difference. Prepared, rejected and uncertain notes
are excluded. Pending cancellation requests do not deduct VAT; request
acceptance is not cancellation approval.
A saved invoice lookup response
is preferred; otherwise the submitted request is used. Date filters use the
saved EIS submission date (`eis_sales.submitted_at`, or the accepted note's
`submitted_at`), not the transaction date
or fiscal invoice date. Backdated sales contribute VAT on the day they were
submitted. The stored submission calendar date is used without reinterpreting
the invoice timestamp or its timezone. Other dashboard figures still use ERP
sale dates and amounts. Location and user filters remain enforced.

This reports the quantity-aware, discounted fiscal VAT without changing saved
sales, receipts, ERP transaction dates, or EIS records. Incomplete submission date
or VAT data raises an explicit error requiring invoice synchronization.
Run `php tests/Standalone/dashboard_fiscal_vat_checks.php` for isolated checks.

## Fiscal receipt address

Fiscal receipt seller addresses come exclusively from `addressLines` in the
original sale terminal's saved EIS configuration, scoped by business, location
and terminal. Legacy district/city fields are not appended. Browser receipts
escape each line and thermal receipts use plain-text newlines. Missing or
malformed address configuration requires a configuration refresh before
printing; non-EIS receipt addresses remain unchanged.
Run `php tests/Standalone/eis_receipt_address_checks.php` for isolated checks.

## Product discounts

Browser and thermal sale receipts show `# | Product | Quantity | UnitPrice |
Discount | Subtotal`. Browser receipt UnitPrice is VAT-exclusive and before the product discount,
Discount is the monetary discount for the line quantity, and Subtotal remains
the saved discounted line total. All eight sale receipt layouts share this
product table; layout-specific tax and duplicated discounted-price columns are
replaced by the six-column format. Invoice-level tax and total sections remain.
Hidden-price receipts omit the monetary columns.
Browser receipts add Taxable Amount (the sum of VAT-exclusive amounts for
rates greater than zero, excluding zero-rated and exempt amounts) and
VAT with the standard percentage from the original terminal's EIS
configuration. Fiscal VAT uses the saved submitted invoice summary, not an
estimate from the displayed unit prices. Fiscal Taxable Amount sums
`invoiceSummary.taxBreakDown[].taxableAmount` only where `rateId` matches a
positive rate in the terminal configuration; missing breakdowns or unknown
rates must be synchronized/refreshed before printing.
Fiscal receipts display Subtotal as fiscal invoice total less VAT (including
zero-rated and exempt bases), followed by Taxable Amount, VAT, Total, and
payments. Total remains VAT-inclusive; Taxable Amount includes only positive-rate bases.
Taxable Amount and VAT use the same label/value columns and alignment as the
other receipt totals, including left-aligned labels in the slim2 layout.
The heading shows VAT Registered
above the receipt title only when `taxpayerConfiguration.isVATRegistered`
in that terminal configuration is true (or the supported integer `1`).
False or missing flags print no registration line. Sale prices,
discounts, gross subtotals and payments are not rewritten.
The external ESC/POS print-server implementation is not present in this
checkout; deploy matching print-server changes separately for raw thermal output.
Run `php tests/Standalone/receipt_vat_display_checks.php` for isolated VAT
summary, registration, rate-label and VAT-exclusive display checks.
Identical product, variation-group and variation names are printed only once;
distinct variation names remain visible on browser and thermal receipts.

EIS product lines send the tax-inclusive price before the line discount,
the total monetary discount for the quantity (percentage discounts are
converted using the saved pre-discount unit price), and the discounted line
total. VAT and tax breakdowns for discounted lines are recomputed from that
total using the business's configured EIS rate. Stored POS prices, item tax,
payments and totals are not rewritten. Non-discounted lines retain stored VAT.

EIS field-keyed validation errors are surfaced in the saved submission error
instead of being replaced by a generic HTTP 400 message. Response bodies and
credentials are not added to logs.

Run `php tests/Standalone/eis_discount_checks.php` for isolated discounted
payload and validation-error checks; no live EIS requests are sent.
These checks also verify that `invoiceSummary.amountTendered` is numeric and
equals the paid sale's final total in the real HTTP client's outgoing JSON.
An invoice lookup returning a null tender value does not mean the field was
omitted from submission; compare it with the locally saved request payload.

## Terminal activation ownership

The first activated terminal establishes the ERP business account's EIS taxpayer
and trading-name identity; the initial ERP business name need not match.
Additional terminals and reactivations must match the saved taxpayer TIN,
taxpayer identifier and trading name, including identities on inactive terminals.
Different sites are permitted for the same identity. Trading-name comparisons
ignore casing and surrounding whitespace. Missing TIN or trading name blocks
activation because ownership cannot be verified.

Only VAT-registered taxpayers can activate terminals. If EIS reports
`isVATRegistered` as false or omits it, activation is rejected (HTTP 422,
SweetAlert error) before anything is saved locally.

Successful activation turns on "Enable inline tax in purchase and sell"
(`business.enable_inline_tax`) for the owning business.

A taxpayer trading name (matched by TIN or taxpayer identifier) belongs to only
one ERP business account. Once any account has registered it, including on
inactive terminals, other accounts cannot activate its terminals. A terminal ID
already assigned to another account is also rejected.

The business's first terminal (no non-deleted terminals exist) updates the
default business location (the oldest one) unless a location already has that
EIS site ID. That location takes the EIS site ID, site name, phone, email and
address lines (stored as the landmark) instead of a new location being created. Later
activations reuse the location with the matching site ID or create one for a
new site.

Terminals can be soft-deleted (`deleted_at` on `eis_settings`,
`eis_configurations` and `eis_terminal_configurations`). Soft-deleted terminals
are ignored by POS routing, offline limits, ownership checks and the
inactive-terminal stock cleanup, so their device and business identity can be
activated again in any account. Historical receipts keep their EIS details.

Deactivating a terminal (deactivate or toggle off) removes `product_locations`
listings of EIS-mapped products at its location, unless another active terminal
still serves that location. Manual (unmapped) products stay listed.

Product sync reconciles each terminal location against the API catalog. EIS-mapped
products listed at the location but missing from the catalog are unlinked from it;
those no longer listed at any location are soft-deleted with their EIS map.
Products that return in a later sync are restored with the same product, map and
variation and relisted at the location. A successful empty catalog is applied the
same way (every EIS product at the location is unlinked or soft-deleted); invalid,
unsuccessful or incomplete responses abort the sync before anything changes, and
products without an EIS map are never touched.

Checks run before local locations, credentials, configurations or business names
are changed, within the business-locked activation transaction. A rejected
activation does not send activation confirmation or dispatch product sync.
The terminal management page and business settings show rejected activations in
a SweetAlert error dialog with the server's message, as well as the inline error.
The activation code is retained so the administrator can correct it and retry.
The remote activation response is required to verify identity, so this check
does not undo any action already performed by the remote activation endpoint.
Existing mixed-identity accounts must be reconciled before adding terminals;
existing terminals are not deleted or reassigned automatically.

Successful activation and every latest-configuration fetch set the business name
to the EIS trading name and the business tax number (`tax_number_1`) to the EIS
TIN. The tax label defaults to `TIN` only when it is blank.

The first terminal activated at a location becomes that location's default
terminal. A later activation replaces the default only when the location has none
or its default terminal is deleted or belongs to another location.

`tests/Standalone/eis_terminal_isolation_checks.php` covers first activation,
matching identities across sites, foreign TIN/taxpayer/trading-name rejection,
reactivations, inactive-terminal ownership and unchanged local state on rejection.
`node tests/Standalone/eis_terminal_activation_alert_checks.js` exercises the real
activation script with mocked AJAX, DOM and SweetAlert, including HTTP 422 errors,
failed JSON results, validation errors and successful activation behavior.

## Receipt identifiers

Browser invoice/receipt layouts and the ESC/POS print server show both
**Transaction Number** (the original POS invoice number) and **EIS Number**
only for fully paid orders when an EIS Number is available. Unpaid and
partially paid orders omit the EIS number and its label, even if legacy EIS
tracking exists. The original number, barcode and print
title remain unchanged. The EIS number comes from the saved request's
`invoiceHeader.invoiceNumber`, not the validation URL's receipt/verification code.
Existing deployments with a dedicated EIS invoice-number column are also supported.
No schema migration is required for this display change.

EIS submission is queued. A receipt printed before its EIS number is generated
must be reprinted afterward to show both numbers; no number is fabricated for
non-EIS sales or sales without a saved EIS request. Deploy the updated print
server code as well when using raw ESC/POS printing.

`tests/Standalone/eis_receipt_number_checks.php` covers number resolution,
escaping, all ten Blade layouts, original-number preservation and thermal output
without a live database or printer.

POS create and update validate before opening the sale's write transaction or
uploading its document. EIS must have active settings for the current business.
Ordinary sales for businesses without active EIS settings retain their existing
flow; relief sales require active settings and a buyer TIN.

The selected business-scoped contact supplies the buyer TIN and authorization
code. Request overrides do not replace that identity.

- `POST /api/v1/utilities/check-tin-authorization-requirement`: sends `tin`;
  confirms that the TIN exists and whether an authorization code is required.
- `POST /api/v1/utilities/validate-authorization-code`: sends only
  `authorizationCode`. Every supplied contact code is checked, including codes
  supplied without a TIN. This endpoint does not prove that a code belongs to a TIN.
- `POST /api/v1/utilities/validate-vat5-certificate`: sends `projectNumber`,
  `certificateNumber`, and numeric `quantity` for relief sales.

The utility client uses the business JWT and supports a configured base URL
ending either at the host or at `/api/v1`. HTTP failures, unavailable EIS,
malformed envelopes, response identity mismatches, and non-boolean/false validity
flags block saving. No validation credentials, codes, certificates or response
bodies are logged by the new services.

POS and classic sales (`/sells/create` and classic sale edits) share the same
relief checkbox and VAT5 fields. Classic sales submit `is_relief=0` when unchecked
and `is_relief=1` when checked through the POS store/update controller. Relief is
independent of the export setting; sales orders do not show these controls.
Editing a relief sale restores its saved flag and certificate fields.
Sales navigation matches the mbira-upgrade project: All Sales and Add Sale are
available alongside POS, drafts, quotations, returns, shipments, discounts,
subscriptions and sales imports, subject to the existing module and permission
checks. Sales Order appears when enabled in POS settings for authorized users,
including users with only sales-order permissions. Sales-order creation highlights
Sales Order rather than Add Sale.
Classic sales also always show the separate Is Export checkbox, regardless of
the business's Enable Export setting. The official
[EIS InvoiceHeader schema](https://eis-api.mra.mw/swagger/index.html) defines only
the boolean `isExport`; no additional export-specific fields are defined.
Classic sale forms therefore omit the six legacy generic export fields.
The saved sale flag is sent as `invoiceHeader.isExport` rather than a hardcoded
false. Edits restore the saved flag, and omitted export metadata is preserved
(including legacy custom-field data). Explicitly unchecking sends `is_export=0`.
Export does not automatically alter ERP tax calculations.
Sales orders continue to omit export controls.
The Enable Export checkbox is visible under Business Settings > Business;
it still controls the export defaults shown on customer create/edit forms.
Business registration defaults Enable Export to checked. The first successful
EIS terminal activation also enables it for an existing business, preserving
other common settings. Subsequent activations do not overwrite a manual change,
including when earlier terminals have been soft-deleted.

The relief checkbox reveals project number, certificate number, and positive
quantity inputs. Successful validation stores those details as JSON on the sale;
the EIS header includes them only when `isReliefSupply` is true. No certificate
ID is invented. Unchecking relief clears the stored certificate. This feature
does not change existing tax-rate calculations.

Payment-only edits ignore submitted buyer/relief metadata and validate the saved
identity and certificate. General edits preserve omitted certificate fields only
for the same buyer. A locked-boundary snapshot check rejects concurrent changes
to the existing buyer, relief flag, or certificate. Existing saved-sale split
fences remain in place.

Storage migration: `2026_10_14_000003_add_vat5_certificate_details_to_transactions_table`.
The additive migration was applied locally. Other environments must deploy it
before using the updated sale utility.

### Browser migration deployment

Open `/admin/eis-migrations` while signed in with the `manage_modules`
permission, or use **Manage Modules → EIS Database Migrations**. Review the
applied/pending list, back up the database, stop sales/payment activity, and
confirm before pressing **Run EIS Migrations**. Merely opening the page does not
run migrations. The CSRF-protected POST runs only the three listed additive
migrations, with a shared file-cache lock and rate limiting. It does not run
other pending migrations, reset the database, seed data, or enable debug mode.
Demo access is disabled. Existing migrations are skipped. On failure, some
earlier migrations may have completed; review deployment/logs before retrying.

`tests/Standalone/browser_eis_migration_checks.php` covers privileged access,
confirmation, exact command scope, lock contention/release, error handling, and
POST/CSRF routing using mocked commands only.

Standalone validation fixture: `tests/Standalone/eis_pos_validation_checks.php`.
108 checks use SQLite memory and HTTP fakes, including contracts, failures,
tenant isolation, actual create/update rejection, persistence, header mapping,
concurrent-state rejection, and rendered edit/payment-only controls. No live EIS
validation has been performed.

## Payment completion

The web POS list's **Clear All Bills** action initiates EIS submission for every
invoice it fully settles, using the same shared after-commit rule as individual
payments. It calculates the remaining balance net of returned change. A failed
bulk payment transaction discards all scheduled submissions; already paid bills
are not paid or submitted again by this action.

Adding or editing an invoice payment initiates EIS submission when an unpaid or
partially paid final sale becomes `paid` (remaining balance zero). Customer-due
payments do the same for each fully settled invoice after child payments are
inserted. Submission uses the existing `SaleCompleted` / `eis-sales` queue flow
after the outer database transaction commits. Partial payments, purchases,
returns and draft sales do not initiate submission; rollback discards callbacks.
The EIS queue worker must be running. The hourly unsubmitted-sales job remains
the fallback. Existing fiscal submission deduplication and retry behavior applies.

Web POS and desktop POS (Connector API) both initiate submission for final sales
paid in full, regardless of payment method. The shared submission rule reloads
the business-scoped sale after the outer transaction commits: a stale unpaid
model must not skip a committed paid sale, and a stale paid model must not submit
a sale that is now unpaid or draft. Desktop sale creation also returns the
calculated payment status rather than its pre-payment snapshot. Existing queue
configuration, direct/synchronous behavior and fiscal retry handling are unchanged.

`tests/Standalone/eis_added_payment_checks.php` executes 52 isolated checks of
the real add/edit payment controllers, customer-due allocation, after-commit
timing, paid retries, partial exclusions and rollback safety. No EIS HTTP calls
or live payments are made by this fixture.

## Submission payloads and provisional fiscal numbers

- The first submission running synchronously inside the original web/desktop
  sale-creation request omits `invoiceSummary.offlineSignature`, the offline
  validation URL and `offlineSignatureData` entirely (not empty strings or nulls).
- Every submission outside that original checkout uses offline metadata even on
  its first attempt: later payment completion, edits, bulk clearing, manual
  submission, scheduled work and queue workers. Checkout jobs executed by an
  asynchronous worker are offline too. A retry always remains offline.
  A new fiscal invoice uses the current `eis_sales.created_at` timestamp, not the
  original POS transaction date. Its offline signature uses the same invoice
  timestamp. Retries retain the saved payload's invoice timestamp (including
  legacy invoices) and signature; the POS transaction date is never changed.
  Offline mode does not correct an incorrect system clock.
  Synchronize the application server/desktop clock if
  EIS still reports clock skew. No automatic unsigned retry is sent.
- Before the HTTP request, pending tracking saves an offline-ready copy of the
  exact invoice, including `offlineSignatureData`, the summary signature and
  validation URL, and `qr_code`. This local copy does not change the first online
  request. Interrupted pending attempts and failed submissions retain those values
  for future retries; retries reuse them rather than generating a new signature.
- Retries include offline signature data. Existing failed tracking, a saved
  request from an interrupted pending attempt, or failed-transaction history
  identify a retry. A pending record without prior request/history is still new.
- Pending/failed tracking stores the generated invoice number in
  `eis_sales.fiscal_invoice_number`. Retries reuse the saved invoice identity
  rather than allocating another number. Existing records are updated when retried;
  no historical database backfill is run.
- Pending/failed tracking also uses that same number in `eis_sales.receipt_number`
  as a provisional receipt identifier. It does not mark the sale fiscalized.
  Successful submission or verified recovery replaces it only when an official
  EIS receipt number is returned. Null/missing receipt numbers and validation URLs
  preserve their saved database values, including the provisional receipt and
  offline QR URL. Invoice lookup synchronization follows the same rule.
- Successful submission retains the existing confirmed fiscal receipt handling;
  the generated invoice identity remains in `request_payload.invoiceHeader.invoiceNumber`.
- Transport/API failures are persisted and raised to the queue. HTTP calls do not
  silently retry an unsigned payload; later attempts pass through sale tracking.

`tests/Standalone/eis_submission_retry_checks.php` tests these paths using SQLite
memory, a fixture signature generator and HTTP fakes, without real EIS calls.