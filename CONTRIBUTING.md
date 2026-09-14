# Contributing

Thank you for helping improve SSLCommerz for WHMCS.

## Before starting

- Search existing issues and pull requests for related work.
- Open an issue for a significant behavior or API-contract change before investing in a large implementation.
- Report security problems privately according to [SECURITY.md](SECURITY.md).
- Never post real store credentials, customer data, session cookies, full card details, or unredacted production logs.

## Development setup

1. Fork the repository and create a focused branch.
2. Install PHP 7.4 or a supported PHP 8.x version with cURL and JSON, plus the command-line `zip` and `unzip` tools for release packaging.
3. Lint the published module files with `find modules -type f -name '*.php' -print0 | xargs -0 -n1 php -l`.
4. Make the smallest compatible implementation and verify the affected WHMCS behavior.
5. Build the distributable archive with the same `zip` command used by GitHub Actions.

Keep production code compatible with PHP 7.4. Do not commit live WHMCS data, credentials, or production logs.

## Code and verification expectations

- Follow the existing plain-PHP style and preserve WHMCS module entry-point contracts.
- Treat callback, IPN, currency, refund, and idempotency logic as security-sensitive.
- Validate provider data before changing invoice or transaction state.
- Keep diagnostics actionable but redact credentials and personal data.
- Document behavior-focused verification for fixes, including failure and retry paths where relevant.
- Keep the release ZIP installable at the WHMCS root and free of tests, scripts, and development files.

## Pull requests

A pull request should:

- Explain the problem and the resulting behavior
- Reference the related issue when one exists
- Include verification evidence for functional changes
- Update README or CHANGELOG when operators need to act differently
- Pass the multi-version PHP syntax and packaging workflow
- Avoid unrelated formatting or refactoring

By contributing, you agree that your contribution is licensed under the repository's [MIT License](LICENSE).

Please be respectful, specific, and constructive in all project interactions.
