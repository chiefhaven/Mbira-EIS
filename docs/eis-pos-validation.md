# EIS buyer and relief validation

All Sales (`/sells`) displays EIS submission status separately from payment:
Submitted, Pending, Failed, or Not submitted when no tracking exists.
The column reflects saved tracking; it does not initiate fiscal submissions.

## Relief supplies

Selecting Relief Supply in POS or Direct Sale immediately removes positive
configured VAT from item prices (including S 16.5% and A 17.5%). Exempt E and
zero-rated B prices remain unchanged. Prices come from the stored exclusive/
inclusive pair, not a browser reconstruction of the VAT rate. Tax classifications
are retained. Quantities, sub-units, fixed/percentage discounts and added rows
use the relief price; invoice/order VAT is cleared and disabled during relief.
Clearing Relief Supply restores ordinary item prices; invoice tax can be
selected again. Saved relief edits retain the catalog price ratio for restoring
taxed prices.

Shared sale persistence enforces relief pricing and preserves shipping and other
charges. EIS relief lines and summaries carry zero VAT, including combined line
and invoice discounts, without changing issued payloads or saved retry requests.
Unissued legacy relief sales containing saved VAT must be edited and revalidated
before submission; they are not silently reclassified as tax-free gross prices.

TC-RS-016 requires a buyer TIN, project number, VAT5 certificate number and positive
certificate quantity. Missing fields are rejected locally before API requests.
Relief requires online validation: MRA must confirm the certificate as valid and
return matching project, certificate and quantity details. Invalid certificates
prevent checkout; validated details are saved with the relief sale.

Run `node tests/Standalone/pos_percentage_discount_checks.js`,
`php tests/Standalone/sales_tax_persistence_checks.php`,
`php tests/Standalone/eis_discount_checks.php` and
`php tests/Standalone/eis_pos_validation_checks.php` for isolated TC-RS-015/016
regressions. Certificate responses are mocked, not live MRA certification.

## Terminal blocking

Every EIS invoice submission inspects `data.shouldBlockTerminal`, including
unsuccessful HTTP responses. A true flag durably blocks that terminal before
retrieving `utilities/get-terminal-blocking-message` with its own `terminalId`.
The returned `data.blockingReason` is shown to the taxpayer. If the lookup is
unavailable, the terminal stays blocked with an explicit reason-unavailable warning.
A successful invoice that also blocks the terminal remains submitted.

Blocked terminals cannot save local/offline fiscal transactions, restaurant
bills/checkouts, or submit queued sales and stock/return operations. Terminal
selection respects the original fiscal terminal, till assignment and location
default; other terminals/businesses remain independent. Authorized locations'
blocking messages appear in POS/business/restaurant pages and transaction errors.
Open pages refresh the scoped local blocking messages every 15 seconds, including
blocks received by background submission jobs; reasons are rendered as text.
Existing invoices remain readable.

False or missing invoice flags, configuration sync, and local activation do not
clear a block. Use the existing `check-terminal-unblock-status` utility; only an
accepted response with `data.isUnblocked: true` restores access. Run the new
terminal-block-state migration before using this feature.

`php tests/Standalone/eis_submission_retry_checks.php` verifies blocking,
reason retrieval, terminal isolation, offline guards and explicit unblocking
using SQLite and HTTP fakes only.

## Fiscal sales returns

ERP sale returns (full or partial) are disabled for any fully paid invoice,
including non-EIS sales. Sales/POS actions, direct return pages, invoice lookup
and shared return writes enforce this rule using the original sale payment status.
Existing returns remain viewable; unpaid/partially paid invoices retain the
existing permission and fiscal eligibility checks.

EIS receipt cancellation and void features are disabled. Cancellation actions,
void-status checks, columns and filters are removed from sales/POS screens.
The utility catalogue excludes `cancel-receipt` and `get-void-receipts`;
backend calls and previously prepared cancellation submissions are rejected
before sending or claiming requests. Void synchronization is also blocked.
Historical requests and void flags are preserved, not deleted or reset.

Automatic full-return cancellation is disabled. A full fiscal return is blocked
inside the local ledger transaction with an explicit error, so no return, stock
or customer-credit change is committed without a supported fiscal workflow.
Partial returns retain their existing credit/debit-note review workflow.
Existing unresolved cancellation requests still require outcome verification
before further adjustments; disabling a feature does not undo remote requests.

Run `php tests/Standalone/eis_void_disabled_checks.php` to verify disabled
entry points and historical-request preservation using HTTP fakes and SQLite.

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

Browser sale receipts show `# | Product | Quantity | UnitPrice |
Discount | Subtotal`. All three monetary product columns are VAT-inclusive.
UnitPrice is before the product discount; Discount is the gross monetary
discount for the line quantity; Subtotal is the discounted gross line total.
Undiscounted modifiers and returned quantities retain their applicable gross
line amounts.
Saved gross line totals and fiscal totals remain unchanged. All eight sale receipt layouts share this
product table; layout-specific tax and duplicated discounted-price columns are
replaced by the six-column format. Invoice-level tax and total sections remain.
Hidden-price receipts omit the monetary columns.
Browser receipt Subtotal is the sum of all displayed, tax-inclusive item line
subtotals. Taxable Amount is that same sum less total VAT, including zero-rated
and exempt items without filtering by rate.
VAT is calculated separately from saved fiscal summary data and shown with the
standard percentage from the original terminal's EIS configuration. Fiscal
receipt Subtotal sums `invoiceLineItems[].total`; Taxable Amount subtracts
`invoiceSummary.totalVAT` from that gross sum. VAT, Total, and payments remain
separate.
All sale receipt layouts omit the invoice-level Subtotal row; the product table
still shows each line Subtotal.
Taxable Amount and VAT use the same label/value columns and alignment as the
other receipt totals, including left-aligned labels in the slim2 layout.
The heading shows `***VAT Registered***` above the receipt title when
`taxpayerConfiguration.isVATRegistered` in that terminal configuration is true,
and `***Not VAT Registered***` when it is false. Boolean values, integers 0/1
and their string forms are supported. Missing or invalid flags print no
registration line. Sale prices,
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
total. Gross pre-discount prices use the configured VAT rate; the EIS monetary
discount is the difference between that gross value and the saved discounted
gross line total, rather than a VAT-exclusive discount subtracted from a gross price.
VAT and tax breakdowns for discounted lines are recomputed from that
total using the business's configured EIS rate. Stored POS prices, item tax,
payments and totals are not rewritten. Non-discounted lines retain stored VAT.
The business's "Enable inline tax in purchase and sell" setting controls sales
tax, including item VAT and order/invoice tax, rather than terminal registration
alone. When off, POS, Direct Sale, sales orders, and Connector/API sale
creation/edits use the exclusive price, zero item tax and no invoice tax.
The server normalizes supplied tax values and adjusts totals while preserving
discounts, shipping and other charges. When on, existing tax calculations apply.
POS and Direct Sale use the database's exclusive and inclusive price pair;
the browser does not reconstruct either price from the selected tax rate.
Both prices scale together for sub-units. Row tax selection is read-only,
matching the stored prices, including equal inclusive/exclusive prices for
non-VAT businesses. Existing saved sale/order lines retain their saved price
ratio rather than being repriced from the current tax dropdown.
Discount changes adjust the stored price pair proportionally for submitted
item VAT, including removal of a discount. A K100 net / K117.50 gross item
with 10% discount is K90 net + K15.75 VAT = K105.75 gross.
Fully discounted products remain in fiscal invoices with zero total and VAT.
When business inline tax is disabled, neither item nor invoice
discounts add VAT: original prices are not grossed up, line/summary VAT remains
zero, and tax breakdowns retain zero tax even if a standard rate is configured.
Activation and latest configuration set this business option from VAT
registration. Already issued invoices and returns against their original fiscal
payload retain the recorded tax; toggling the setting does not rewrite history.
Invoice-level fixed/percentage discounts are allocated proportionally across
the already line-discounted gross totals, with cumulative cent rounding.
VAT and rate breakdowns are rebuilt after allocation, including zero-rated lines.
Discounted fiscal line totals must reconcile to the saved invoice total;
unrepresented extra taxes/charges or rounding produce an explicit validation
error rather than sending inconsistent amounts.

EIS field-keyed validation errors are surfaced in the saved submission error
instead of being replaced by a generic HTTP 400 message. Response bodies and
credentials are not added to logs.

Run `php tests/Standalone/eis_discount_checks.php` for isolated discounted
payload and validation-error checks; no live EIS requests are sent.
Run `node tests/Standalone/pos_percentage_discount_checks.js` to test the actual
shared POS/classic calculation functions with fixed/percentage discounts,
zero/standard VAT rates, quantities, subunit multipliers and discount removal.
These checks also verify that `invoiceSummary.amountTendered` is numeric and
equals the paid sale's final total in the real HTTP client's outgoing JSON.
An invoice lookup returning a null tender value does not mean the field was
omitted from submission; compare it with the locally saved request payload.

## Terminal activation ownership

The header checks EIS health even when multiple permitted terminals exist.
Configured terminals show only "Online" or "Offline", regardless of count. The probe
uses an enabled permitted terminal with credentials; no usable terminal is
shown as offline, while no configured terminals remains "Not configured".

Receipt VAT registration uses the original sale terminal's saved registration
status. Legacy double-encoded activation configuration is also decoded when
reading receipt tax settings; new activations save configuration without double
encoding.

Activation sends confirmation automatically. After successful confirmation, the
result reports that the terminal was activated and automatically confirmed,
replacing the initial API's pending-confirmation remark. Failed confirmation
retains an explicit warning and does not mark the terminal confirmed.
Successful activation also refreshes the current business session and reloads
the whole page, so updated business details, inline tax and navigation take
effect without logging out. Failed activation leaves the page and session intact.

The first activated terminal establishes the ERP business account's EIS taxpayer
and trading-name identity; the initial ERP business name need not match.
Additional terminals and reactivations must match the saved taxpayer TIN,
taxpayer identifier and trading name, including identities on inactive terminals.
Different sites are permitted for the same identity. Trading-name comparisons
ignore casing and surrounding whitespace. Missing TIN or trading name blocks
activation because ownership cannot be verified.

Both VAT-registered and non-VAT-registered taxpayers can activate terminals.
EIS `isVATRegistered` accepts boolean values, 0/1, or their string forms
(`"false"`/`"true"` and `"0"`/`"1"`), and is saved as a boolean.
Missing or invalid registration status is rejected (HTTP 422, SweetAlert error)
before anything is saved locally. Product sync uses this status to calculate
VAT only for registered taxpayers; otherwise inclusive and exclusive prices
remain equal.

Successful activation sets "Enable inline tax in purchase and sell"
(`business.enable_inline_tax`) to the taxpayer's normalized `isVATRegistered`
status for the owning business: enabled when true and disabled when false,
including on reactivation. The refreshed business session reflects this immediately.
Latest-configuration sync applies the same rule and refreshes the current business
session, even when the EIS configuration has not changed.

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
- New offline signatures use the fiscal invoice's decoded taxpayer ID (not the
  seller TIN), terminal position and sequence. Its date is preserved with its
  timezone offset; the signed identity must equal the exact fiscal invoice number.
  New invoice numbers and headers share one fiscal creation timestamp, even for
  backdated POS sales. That timestamp is explicitly persisted as `eis_sales.created_at`;
  invoice allocation, the header and signing all use it rather than separate clock reads.
  Invalid identities, missing signing keys or mismatched dates
  fail explicitly rather than producing blank signatures.
- Offline signatures follow the MRA [Signing Offline Receipts guide](https://eis-api.mra.mw/docs/signing_offline_receipts.htm).
  Signed parameters are ordered `TI`, `N`, `I`, `V`, `T`; `N` is the invoice
  line-item count, not the sum of quantities. HMAC-SHA256 uses the activation
  secret key as UTF-8 bytes and unpadded Base64URL (`+` becomes `-`, `/` becomes `_`,
  trailing `=` is removed), followed by URL encoding. These rules are covered
  by independent synthetic tests.
  Validation links use the development portal for development/dev/sandbox and
  the production portal for production; unsupported environments fail explicitly.
- The HTTP client removes local `offlineSignatureData` and summary `validationURL`
  from outbound sales requests to match Swagger, retaining `offlineSignature`.
  Local tracking keeps QR/retry metadata. Existing persisted signatures are reused
  unchanged; this change does not rewrite or resubmit historical invoices.
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
`tests/Standalone/eis_offline_signature_checks.php` exercises the real signer
using synthetic keys, including RFC 4231 HMAC validation, timezone boundaries,
invoice identity and environment-specific URLs. Swagger describes the request
schema; the signing guide supplies the algorithm but no MRA signature test
vector. Cryptographic acceptance still requires MRA verification.