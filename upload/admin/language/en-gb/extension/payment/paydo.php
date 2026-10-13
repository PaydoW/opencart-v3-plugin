<?php

$_['heading_title'] = 'Paydo';
$_['heading_invoices'] = 'PayDo invoices';

$_['text_paydo'] = '<a href="https://paydo.com" target="_blank"><img style="max-width:150px;" src="view/image/payment/paydo.png"></a>';
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Success: You have modified Paydo settings!';
$_['text_edit'] = 'Edit Paydo settings';
$_['text_no_methods'] = 'No methods loaded yet. Enter Project ID and JWT token, then synchronize.';
$_['text_syncing'] = 'Loading payment methods...';
$_['text_sync_success'] = 'Loaded %d payment methods. Select the required methods and save the settings.';
$_['text_no_results'] = 'No invoice records found.';

$_['entry_public_id'] = 'Public key';
$_['entry_secret_key'] = 'Secret key';
$_['entry_methods_mode'] = 'Payment methods selection';
$_['entry_hosted_page'] = 'PayDo Hosted Page';
$_['entry_project_id'] = 'Project ID';
$_['entry_jwt_token'] = 'JWT token';
$_['entry_sync_methods'] = 'Sync payment methods';
$_['entry_enabled_methods'] = 'Enabled PayDo methods';
$_['entry_search_method'] = 'Search method...';
$_['entry_order_status_wait'] = 'Status for order on waiting payment';
$_['entry_order_status_success'] = 'Status for order on success payment';
$_['entry_order_status_error'] = 'Status for order on failed payment';
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort order';
$_['button_sync_methods'] = 'Sync from PayDo';
$_['button_invoices'] = 'Invoices';

$_['help_public_id'] = 'This parameter you can get in you Paydo project';
$_['help_secret_key'] = 'This parameter you can get in you Paydo project';
$_['help_methods_mode'] = 'Let the customer choose one of the enabled PayDo payment methods during checkout.';
$_['help_hosted_page'] = 'Offer the general PayDo Hosted Page with all methods available for the payer. No paymentMethod ID is sent for this option.';
$_['help_project_id'] = 'Used to request the available payment methods from PayDo.';
$_['help_jwt_token'] = 'Bearer token used only to sync payment methods from the PayDo API.';
$_['help_sync_methods'] = 'The current Project ID and JWT token fields are used; saving them first is not required.';
$_['help_enabled_methods'] = 'Only selected methods will be offered during checkout. Synced methods are not enabled automatically.';

$_['error_permission'] = 'Warning: You do not have permission to modify settings!';
$_['error_public_id'] = 'Field required';
$_['error_secret_key'] = 'Field required';
$_['error_enabled_methods'] = 'Enable PayDo Hosted Page or select at least one specific payment method.';
$_['error_sync_request'] = 'Could not sync payment methods. Please try again.';
$_['error_sync_credentials'] = 'Enter Project ID and JWT token before syncing payment methods.';
$_['error_sync_transport'] = 'Could not connect to PayDo. Please try again.';
$_['error_sync_api'] = 'PayDo API returned an invalid response (HTTP %d).';
$_['error_sync_auth'] = 'PayDo rejected the Project ID or JWT token (HTTP %d).';
$_['error_sync_not_found'] = 'The PayDo project or synchronization endpoint was not found (HTTP %d).';
$_['error_sync_validation'] = 'PayDo rejected the synchronization request (HTTP %d).';
$_['error_sync_server'] = 'PayDo is temporarily unavailable (HTTP %d). Please try again later.';
$_['error_sync_empty'] = 'PayDo did not return any enabled payment methods.';

$_['column_order_id'] = 'Order ID';
$_['column_invoice_id'] = 'Invoice ID';
$_['column_payment_method'] = 'Payment option';
$_['column_txid'] = 'Transaction ID';
$_['column_amount'] = 'Amount';
$_['column_currency'] = 'Currency';
$_['column_invoice_status'] = 'Invoice status';
$_['column_transaction_state'] = 'Transaction state';
$_['column_retry_count'] = 'Retries';
$_['column_last_error'] = 'Last verification note';
$_['column_date_added'] = 'Created';
$_['column_date_modified'] = 'Modified';
