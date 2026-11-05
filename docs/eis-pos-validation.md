# EIS buyer and relief validation

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