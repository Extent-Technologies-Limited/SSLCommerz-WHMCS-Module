# Changelog

All notable public changes are documented here.

## 1.0.1 - 2026-09-27

### Added

- Added a gateway setting that selects SSLCommerz's current conversion rate or the configured WHMCS rate for non-BDT invoices.

### Changed

- Locked the original invoice amount and gateway processing amount separately for callback validation, reconciliation, and refunds.
- Documented that checkout converts the current outstanding invoice balance without adding a module-level surcharge or convenience fee.
- Updated the gateway configuration documentation and screenshot for the new conversion setting.

## 1.0.0 - 2026-09-14

First public production release.

### Added

- EasyCheckout popup, Legacy Hosted, and New Hosted checkout modes.
- Secure server-side invoice reload and signed checkout requests.
- Shared browser callback and IPN validation with idempotent settlement.
- Customer-currency and settled-BDT reconciliation.
- Full and partial refunds based on the captured exchange rate.
- Durable refund reservations and cumulative captured-balance limits.
- Administrator transaction lookup, recent-transaction selector, focused detail views, refund-status queries, and CSV export.
- Redacted diagnostic logging for session and provider failures.
- Multi-version PHP syntax checks, release packaging, and GitHub Release workflow.

### Security

- Restricted gateway redirects to documented exact SSLCommerz HTTPS hosts.
- Added strict signature, transaction identity, amount, currency, status, and risk checks.
- Prevented callback requests from replacing the customer's WHMCS session cookie.
- Added atomic settlement claims and duplicate WHMCS transaction guards.
- Masked card data, redacted credentials, and neutralized spreadsheet formulas in exports.

### Fixed

- Accepted SSLCommerz's live EasyCheckout `cart_json` callback wrapper.
- Correctly routed the live New Hosted checkout while retaining Legacy Hosted behavior.
- Avoided rejecting empty optional customer address fields before the provider can evaluate a session.
- Corrected foreign-currency records so BDT settlements are never displayed as the invoice currency.
- Recovered invoice identity from legacy merchant transaction IDs when possible.
- Replaced custom lookup token rotation with WHMCS native administrator CSRF validation.
