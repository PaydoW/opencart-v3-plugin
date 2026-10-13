# PayDo Payment Gateway for OpenCart 3

PayDo payment extension for OpenCart 3.x with two redirect-based payment flows:

- **PayDo Hosted Page** — PayDo shows all methods available to the customer. The invoice payload does not contain `paymentMethod`.
- **Specific payment method** — the customer chooses a method synchronized from PayDo, and the invoice contains its numeric `paymentMethod` ID.

Version **2.2.0** is fixed for this development cycle until release.

The extension never collects card number, CVV, or expiry data. Card entry and 3DS remain on PayDo-hosted pages.

## Requirements

- OpenCart 3.x
- PHP cURL extension
- HTTPS storefront and valid CA certificates recommended

## Installation and update

1. Back up the store files and database.
2. In OpenCart admin, open **Extensions → Installer** and upload `paydo_payment.ocmod.zip`.
3. Open **Extensions → Extensions → Payments**, install PayDo if needed, then edit it.
4. Existing installations are upgraded in place when the settings page opens. Existing keys and order statuses are preserved, and Hosted Page remains the default flow.
5. Open **Extensions → Modifications** and click **Refresh** if your installation uses modifications.

The package contains `upload/`, `install.xml`, this README, the changelog, and the license.

## Basic Hosted Page setup

1. Enter the **Public key** and **Secret key** from the PayDo project.
2. Keep **Payment method selection** disabled.
3. Select pending, successful, and failed order statuses.
4. Enable the extension and save.
5. Copy the generated **IPN URL** into the IPN settings of the PayDo project.

With method selection disabled, any method value posted by a browser is ignored and the invoice is always created without `paymentMethod`.

## Hosted Page plus specific methods

1. Enable **Payment method selection**.
2. Choose whether **PayDo Hosted Page** should remain available as its own checkout option.
3. Enter **Project ID** and **JWT token**.
4. Click **Sync from PayDo**. Saving the form first is not required.
5. Search by method title or ID, select the required methods, and save.

Newly synchronized methods are not enabled automatically. Previously selected methods remain selected if PayDo still returns them; removed methods cannot be used at checkout. Internet-acquiring IDs are never hardcoded and always come from the project-specific PayDo API response.

The JWT is used only for method synchronization. Invoice creation and invoice verification do not send it.

## Payment confirmation and retries

The customer-facing result and failure URLs are informational and never mark an order as paid. Before changing an order status, the extension reads the invoice from PayDo v3 and validates its invoice ID, order ID, amount, currency, and transaction identifier when available.

An invoice confirmed as paid takes precedence over a temporarily pending transaction state. Temporary network errors or unpaid invoices remain pending and use bounded increasing retry intervals. Exhausted checks are visible under the **Invoices** button on the PayDo settings page for manual review. Repeated callbacks and repeated checkout confirmations reuse the stored invoice and avoid duplicate status transitions. An active invoice keeps its original method; choosing another method shows an explanatory error instead of opening the wrong payment form. Failed invoices can be retried.

Both ordinary `invoice.txid` / `transaction.txid` and card `transaction.id` notifications are supported. Callback states alone never mark an order as failed; terminal changes require confirmation from PayDo. Once paid, an invoice cannot be downgraded by a delayed callback.

## API endpoints

- `POST https://api.paydo.com/v3/invoices/create`
- `GET https://api.paydo.com/v3/invoices/{invoiceId}`
- `GET https://api.paydo.com/v3/instrument-settings/payment-methods/available-for-application/{projectId}`
- Customer redirect: `https://checkout.paydo.com/{locale}/payment/invoice-preprocessing/{invoiceId}`

## Tests

Run from the repository root:

```sh
php tests/paydo_logic_test.php
php tests/paydo_admin_test.php
find upload -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Support

- [PayDo documentation](https://docs.paydo.com/)
- [Open an issue](https://github.com/PaydoW/opencart-v3-plugin/issues)
- [PayDo support](https://paydo.com/contacts-page-customer-support/)

## License

MIT. See [LICENSE](LICENSE).
