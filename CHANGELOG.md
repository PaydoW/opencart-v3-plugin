# Changelog

## 2.2.0 — unreleased (version fixed until release)

- Added support for card `transaction.id` callbacks, both v3 method-list response formats, and migration of existing invoice amount/currency data.
- Prevented callback-only failure transitions, paid-order downgrades, and silent reuse of an invoice with a different payment method.
- Added optional checkout selection between PayDo Hosted Page and synchronized payment methods.
- Added PayDo v3 method synchronization using Project ID and JWT with admin permission checks, TLS verification, filtering, sorting, search, and localized errors.
- Migrated invoice creation and verification to PayDo Invoice API v3.
- Added server-side checkout allowlist validation and guaranteed omission of `paymentMethod` for Hosted Page payments.
- Added persistent payment method, transaction, amount, currency, invoice status, transaction state, retry, and audit fields.
- Added reuse of active invoices to prevent duplicate invoices for a single order.
- Added neutral result/failure pages and bounded invoice verification retries with manual-review visibility in admin.
- Hardened IPN handling with local invoice mapping and PayDo invoice/order/amount/currency/transaction verification.
- Added English and Russian localization and automated payment-selection/payload security tests.

## 2.1.0

- Previous public release.
