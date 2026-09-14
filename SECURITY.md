# Security Policy

## Supported versions

Security fixes are provided for the current `1.x` release line. Upgrade to the latest published release before reporting an issue that may already be resolved.

## Reporting a vulnerability

You may report a security concern by opening a GitHub issue. Provide enough information to identify and reproduce the problem, but remove credentials, personal data, payment details, and other sensitive information first.

For vulnerabilities that could expose sensitive data, enable unauthorized payments, or provide a practical exploit, you may instead use the repository's **Security > Report a vulnerability** option to open a private GitHub Security Advisory. If private reporting is unavailable, open an issue containing only a high-level description and ask the maintainer for a secure communication channel.

Include:

- The affected module version, WHMCS version, PHP version, and checkout mode
- A concise impact assessment and reproducible steps
- Relevant request/response structure with secrets and personal data removed
- Whether the issue is reproducible in sandbox, production, or both
- Any mitigation already attempted

Never include store passwords, checkout tokens, full card data, customer personal data, session cookies, database exports, or unredacted server logs.

## Security scope

Reports concerning signature validation, authentication, callback/IPN processing, redirect validation, duplicate settlement, refund authorization, credential exposure, CSRF, CSV injection, or sensitive-data handling are in scope.

Provider account policy, SSLCommerz service availability, WHMCS core behavior, and unsupported third-party modifications are normally outside this project's control, but integration-specific evidence is still welcome.

## Operational guidance

- Use HTTPS for the WHMCS System URL and callback/IPN endpoints.
- Protect production credentials and rotate them if exposure is suspected.
- Restrict lookup add-on access to trusted administrator roles.
- Register only the required fixed outbound IP with SSLCommerz for refund access.
- Keep WHMCS, PHP, this module, and server dependencies supported and patched.
- Review **Billing > Gateway Log** after deployment and remove exported logs when no longer needed.
