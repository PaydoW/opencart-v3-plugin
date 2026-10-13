<?php

/**
 * Class ControllerExtensionPaymentPaydo
 *
 * @property Language                   $language
 * @property Config                     $config
 * @property Url                        $url
 * @property Loader                     $load
 * @property Session                    $session
 * @property Request                    $request
 * @property Response                   $response
 * @property ModelCheckoutOrder         $model_checkout_order
 * @property ModelExtensionPaymentPaydo $model_extension_payment_paydo
 */
class ControllerExtensionPaymentPaydo extends Controller {
	/** @var resource|null */
	private $curl = null;
	private $paydoApiBase = 'https://api.paydo.com/v3';
	private $lastApiError = '';
	private $maxVerificationRetries = 4;
	private $retryDelays = array(30, 120, 600, 1800);

	public function index() {
		$this->load->language('extension/payment/paydo');
		$methods_mode = (bool)$this->config->get('payment_paydo_methods_mode');
		$payment_methods = $methods_mode ? $this->getCheckoutMethods() : array();
		$hosted_page_enabled = $this->isHostedPageEnabled();

		$data = array(
			'button_pay' => $this->language->get('button_pay'),
			'paydo_url' => $this->url->link('extension/payment/paydo/pay', '', true),
			'methods_mode' => $methods_mode,
			'hosted_page_enabled' => $hosted_page_enabled,
			'payment_methods' => $payment_methods,
			'option_count' => $methods_mode ? count($payment_methods) + ($hosted_page_enabled ? 1 : 0) : 0,
			'result_url' => $this->url->link('extension/payment/paydo/result', '', true),
			'text_choose_method' => $this->language->get('text_choose_method'),
			'text_hosted_page' => $this->language->get('text_hosted_page'),
			'text_hosted_page_description' => $this->language->get('text_hosted_page_description'),
			'error_method_required' => $this->language->get('error_method_required'),
			'error_payment' => $this->language->get('error_payment')
		);

		return $this->load->view('extension/payment/paydo', $data);
	}

	public function pay() {
		$this->load->language('extension/payment/paydo');
		$this->response->addHeader('Content-Type: application/json');

		if (!isset($this->request->server['REQUEST_METHOD']) || $this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->jsonResponse(array('error' => $this->language->get('error_payment')));
			return;
		}

		if (empty($this->session->data['order_id'])) {
			$this->jsonResponse(array('error' => $this->language->get('error_order')));
			return;
		}

		$order_id = (int)$this->session->data['order_id'];
		$this->load->model('checkout/order');
		$this->load->model('extension/payment/paydo');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->jsonResponse(array('error' => $this->language->get('error_order')));
			return;
		}

		if (!$this->isValidPaydoOrder($order_info)) {
			$this->jsonResponse(array('error' => $this->language->get('error_payment')));
			return;
		}

		$selected_method = $this->getRequestedPaymentMethod();

		if ($selected_method === false) {
			$this->jsonResponse(array('error' => $this->language->get('error_method_required')));
			return;
		}

		$existing_invoice = $this->model_extension_payment_paydo->getInvoiceByOrderId($order_id);

		if ($this->canReuseInvoice($existing_invoice)) {
			if (isset($existing_invoice['invoice_status']) && (int)$existing_invoice['invoice_status'] === 1) {
				$this->jsonResponse(array('redirect' => $this->url->link('extension/payment/paydo/result', '', true), 'reused' => true));
				return;
			}

			$verification = $this->verifyAndApply($existing_invoice, $order_info, null, '', false);

			if ($verification === 'paid') {
				$this->jsonResponse(array('redirect' => $this->url->link('extension/payment/paydo/result', '', true)));
				return;
			}

			if ($verification === 'manual') {
				$this->jsonResponse(array('redirect' => $this->url->link('extension/payment/paydo/result', '', true), 'reused' => true));
				return;
			}

			if ($verification === 'failed') {
				$this->jsonResponse(array('redirect' => $this->url->link('extension/payment/paydo/result', '', true)));
				return;
			}

			if (!$this->matchesInvoiceMethod($existing_invoice, $selected_method)) {
				$this->jsonResponse(array('error' => $this->language->get('error_active_method')));
				return;
			}

			$redirect_url = $this->buildCheckoutUrl($existing_invoice['invoice_id']);

			if ($redirect_url !== '') {
				$this->jsonResponse(array('redirect' => $redirect_url, 'reused' => true));
				return;
			}
		}

		$order_products = $this->model_checkout_order->getOrderProducts($order_id);
		$items = array();

		foreach ($order_products as $product) {
			$items[] = array(
				'id' => (string)$product['order_product_id'],
				'name' => trim($product['name'] . ' ' . $product['model']),
				'price' => (float)$product['price']
			);
		}

		$amount = $this->normalizeAmount($order_info['total']);
		$request = array(
			'publicKey' => (string)$this->config->get('payment_paydo_public_id'),
			'order' => array(
				'id' => (string)$order_info['order_id'],
				'amount' => $amount,
				'currency' => (string)$order_info['currency_code'],
				'description' => sprintf($this->language->get('order_description'), $order_info['order_id']),
				'items' => $items
			),
			'payer' => array(
				'email' => (string)$order_info['email'],
				'phone' => (string)$order_info['telephone'],
				'name' => trim((string)$order_info['firstname'] . ' ' . (string)$order_info['lastname'])
			),
			'language' => $this->getPaydoLanguageCode(),
			'productUrl' => $this->url->link('common/home', '', true),
			'resultUrl' => $this->url->link('extension/payment/paydo/result', '', true),
			'failPath' => $this->url->link('extension/payment/paydo/failure', '', true)
		);

		$request['signature'] = $this->generate_order_signature($request['order']);


		$request = $this->addPaymentMethodToRequest($request, $selected_method);

		$invoice_id = $this->makeRequest($request);

		if ($invoice_id === '') {
			$this->jsonResponse(array('error' => $this->language->get('error_invoice')));
			return;
		}

		$this->model_extension_payment_paydo->saveInvoice($order_info, $invoice_id, $selected_method);
		$this->setOrderStatusIfChanged($order_info, (int)$this->config->get('payment_paydo_order_status_wait'));

		$redirect_url = $this->buildCheckoutUrl($invoice_id);

		if ($redirect_url === '') {
			$this->logCallback('Rejected invoice redirect', array('order_id' => $order_id, 'invoice_id' => $invoice_id));
			$this->jsonResponse(array('error' => $this->language->get('error_invoice')));
			return;
		}

		$this->jsonResponse(array('redirect' => $redirect_url));
	}

	public function result() {
		$this->load->language('extension/payment/paydo');

		$data = array(
			'heading_title' => $this->language->get('heading_payment_result'),
			'text_status_wait' => $this->language->get('text_status_wait'),
			'text_status_paid' => $this->language->get('text_status_paid'),
			'text_status_manual' => $this->language->get('text_status_manual'),
			'text_status_failed' => $this->language->get('text_payment_failure'),
			'verify_url' => $this->url->link('extension/payment/paydo/verify', '', true),
			'success_url' => $this->url->link('checkout/success', '', true),
			'continue' => $this->url->link('common/home', '', true),
			'button_continue' => $this->language->get('button_continue'),
			'header' => $this->load->controller('common/header'),
			'column_left' => $this->load->controller('common/column_left'),
			'column_right' => $this->load->controller('common/column_right'),
			'content_top' => $this->load->controller('common/content_top'),
			'content_bottom' => $this->load->controller('common/content_bottom'),
			'footer' => $this->load->controller('common/footer')
		);

		$this->response->setOutput($this->load->view('extension/payment/paydo_result', $data));
	}

	public function failure() {
		$this->load->language('extension/payment/paydo');

		$data = array(
			'heading_title' => $this->language->get('heading_payment_failure'),
			'text_message' => $this->language->get('text_payment_failure'),
			'continue' => $this->url->link('checkout/checkout', '', true),
			'button_continue' => $this->language->get('button_continue'),
			'header' => $this->load->controller('common/header'),
			'column_left' => $this->load->controller('common/column_left'),
			'column_right' => $this->load->controller('common/column_right'),
			'content_top' => $this->load->controller('common/content_top'),
			'content_bottom' => $this->load->controller('common/content_bottom'),
			'footer' => $this->load->controller('common/footer')
		);

		$this->response->setOutput($this->load->view('extension/payment/paydo_failure', $data));
	}

	public function verify() {
		$this->load->language('extension/payment/paydo');
		$this->response->addHeader('Content-Type: application/json');

		if (!isset($this->request->server['REQUEST_METHOD']) || $this->request->server['REQUEST_METHOD'] !== 'POST' || empty($this->session->data['order_id'])) {
			$this->jsonResponse(array('status' => 'manual', 'message' => $this->language->get('text_status_manual')));
			return;
		}

		$order_id = (int)$this->session->data['order_id'];
		$this->load->model('checkout/order');
		$this->load->model('extension/payment/paydo');
		$order_info = $this->model_checkout_order->getOrder($order_id);
		$stored_invoice = $this->model_extension_payment_paydo->getInvoiceByOrderId($order_id);

		if (!$this->isValidPaydoOrder($order_info) || !$stored_invoice) {
			$this->jsonResponse(array('status' => 'manual', 'message' => $this->language->get('text_status_manual')));
			return;
		}

		$state = isset($stored_invoice['transaction_state']) && $stored_invoice['transaction_state'] !== null
			? (int)$stored_invoice['transaction_state'] : null;
		$status = $this->verifyAndApply($stored_invoice, $order_info, $state, isset($stored_invoice['txid']) ? $stored_invoice['txid'] : '', false);

		if ($status === 'paid') {
			$this->jsonResponse(array('status' => 'paid', 'redirect' => $this->url->link('checkout/success', '', true)));
		} elseif ($status === 'manual' || $status === 'invalid') {
			$this->jsonResponse(array('status' => 'manual', 'message' => $this->language->get('text_status_manual')));
		} elseif ($status === 'failed') {
			$this->jsonResponse(array('status' => 'failed', 'message' => $this->language->get('text_payment_failure')));
		} else {
			$this->jsonResponse(array('status' => 'pending', 'message' => $this->language->get('text_status_wait')));
		}
	}

	public function callback() {
		if (!isset($this->request->server['REQUEST_METHOD']) || $this->request->server['REQUEST_METHOD'] !== 'POST') {
			return;
		}

		$raw = file_get_contents('php://input');
		$callback = json_decode($raw, true);

		if (!is_array($callback)) {
			$this->logCallback('Rejected callback: invalid JSON');
			return;
		}

		$this->load->model('checkout/order');
		$this->load->model('extension/payment/paydo');

		if (isset($callback['invoice'])) {
			if (!$this->isValidCallbackStructure($callback)) {
				$this->logCallback('Rejected callback: invalid structure');
				return;
			}

			$invoice_id = $this->normalizeInvoiceId($callback['invoice']['id']);
			$callback_order_id = (int)$callback['transaction']['order']['id'];
			$state = (int)$callback['transaction']['state'];
			$callback_txid = $this->extractCallbackTxid($callback);
			$stored_invoice = $this->model_extension_payment_paydo->getInvoiceByInvoiceId($invoice_id);

			if (!$stored_invoice || (int)$stored_invoice['order_id'] !== $callback_order_id) {
				$this->logCallback('Rejected callback: invoice mapping mismatch', array('order_id' => $callback_order_id, 'invoice_id' => $invoice_id));
				return;
			}

			$order_info = $this->model_checkout_order->getOrder((int)$stored_invoice['order_id']);

			if (!$this->isValidPaydoOrder($order_info)) {
				$this->logCallback('Rejected callback: non-PayDo order', array('order_id' => $callback_order_id, 'invoice_id' => $invoice_id));
				return;
			}

			if (!empty($stored_invoice['txid']) && $callback_txid !== '' && !hash_equals((string)$stored_invoice['txid'], $callback_txid)) {
				$this->logCallback('Rejected callback: transaction mismatch', array('order_id' => $callback_order_id, 'invoice_id' => $invoice_id, 'state' => $state));
				return;
			}

			$result = $this->verifyAndApply($stored_invoice, $order_info, $state, $callback_txid, true);
			$this->logCallback('Processed callback: ' . $result, array('order_id' => $callback_order_id, 'invoice_id' => $invoice_id, 'state' => $state));

			if ($result === 'pending' || $result === 'error') {
				$this->response->addHeader('HTTP/1.1 503 Service Unavailable');
			}

			return;
		}

		$this->processLegacyCallback($callback);
	}

	private function processLegacyCallback($callback) {
		if (!isset($callback['orderId'], $callback['amount'], $callback['currency'], $callback['status'], $callback['signature'])) {
			$this->logCallback('Rejected callback: unsupported legacy structure');
			return;
		}

		$signature = $this->generate_legacy_signature(
			$callback['orderId'],
			$callback['amount'],
			$callback['currency'],
			$this->config->get('payment_paydo_secret_key'),
			$callback['status']
		);

		if (!hash_equals($signature, (string)$callback['signature'])) {
			$this->logCallback('Rejected callback: invalid legacy signature', array('order_id' => (int)$callback['orderId']));
			return;
		}

		$order_id = (int)$callback['orderId'];
		$order_info = $this->model_checkout_order->getOrder($order_id);
		$stored_invoice = $this->model_extension_payment_paydo->getInvoiceByOrderId($order_id);

		if (!$this->isValidPaydoOrder($order_info) || !$stored_invoice || !$this->isMatchingLegacyOrder($order_info, $callback)) {
			$this->logCallback('Rejected callback: legacy order mismatch', array('order_id' => $order_id));
			return;
		}

		$state = $callback['status'] === 'success' ? 2 : ($callback['status'] === 'error' ? 3 : null);
		$result = $this->verifyAndApply($stored_invoice, $order_info, $state, '', true);
		$this->logCallback('Processed legacy callback: ' . $result, array('order_id' => $order_id));

		if ($result === 'pending' || $result === 'error') {
			$this->response->addHeader('HTTP/1.1 503 Service Unavailable');
		}
	}

	private function verifyAndApply($stored_invoice, $order_info, $state, $callback_txid, $force) {
		// Delayed callbacks must never downgrade a confirmed payment.
		if (isset($stored_invoice['invoice_status']) && (int)$stored_invoice['invoice_status'] === 1) {
			return 'paid';
		}

		$invoice_id = isset($stored_invoice['invoice_id']) ? $this->normalizeInvoiceId($stored_invoice['invoice_id']) : '';

		if ($invoice_id === '') {
			return 'invalid';
		}

		if (!$force) {
			if (isset($stored_invoice['retry_count']) && (int)$stored_invoice['retry_count'] >= $this->maxVerificationRetries) {
				return 'manual';
			}

			if (!empty($stored_invoice['next_retry_at']) && strtotime($stored_invoice['next_retry_at']) > time()) {
				return 'pending';
			}
		}

		$invoice = $this->getInvoice($invoice_id);

		if (!$invoice) {
			return $this->scheduleVerificationRetry((int)$order_info['order_id'], $this->lastApiError !== '' ? $this->lastApiError : 'invoice_unavailable');
		}

		if (!$this->isVerifiedInvoice($invoice, $stored_invoice, $order_info, $invoice_id, $callback_txid)) {
			return 'invalid';
		}

		$invoice_status = isset($invoice['status']) ? (int)$invoice['status'] : null;
		$remote_state = $this->extractInvoiceState($invoice);

		// Terminal decisions require API confirmation, not the callback's state.
		$state = $remote_state;
		$remote_txid = $this->extractInvoiceTxid($invoice);
		// Persist only identifiers independently returned by PayDo.
		$txid = $remote_txid;
		$this->model_extension_payment_paydo->updateInvoice((int)$order_info['order_id'], $invoice_id, $txid, $invoice_status, $state);

		if ($invoice_status === 1) {
			$this->setOrderStatusIfChanged($order_info, (int)$this->config->get('payment_paydo_order_status_success'));
			$this->model_extension_payment_paydo->clearRetry((int)$order_info['order_id']);
			return 'paid';
		}

		if (in_array($invoice_status, array(3, 5), true) || in_array((int)$state, array(3, 5), true)) {
			$this->setOrderStatusIfChanged($order_info, (int)$this->config->get('payment_paydo_order_status_error'));
			$this->model_extension_payment_paydo->clearRetry((int)$order_info['order_id']);
			return 'failed';
		}

		return $this->scheduleVerificationRetry((int)$order_info['order_id'], 'invoice_not_paid');
	}

	private function scheduleVerificationRetry($order_id, $reason) {
		$stored = $this->model_extension_payment_paydo->getInvoiceByOrderId($order_id);
		$current_count = isset($stored['retry_count']) ? (int)$stored['retry_count'] : 0;
		$delay_index = min($current_count, count($this->retryDelays) - 1);
		$count = $this->model_extension_payment_paydo->scheduleRetry(
			$order_id,
			$reason,
			$this->retryDelays[$delay_index],
			$this->maxVerificationRetries
		);

		return $count >= $this->maxVerificationRetries ? 'manual' : ($reason === 'invoice_not_paid' ? 'pending' : 'error');
	}

	private function isVerifiedInvoice($invoice, $stored_invoice, $order_info, $invoice_id, $callback_txid) {
		$remote_invoice_id = isset($invoice['identifier']) ? (string)$invoice['identifier'] : '';
		$remote_order_id = isset($invoice['orderIdentifier']) ? (string)$invoice['orderIdentifier']
			: (isset($invoice['order']['id']) ? (string)$invoice['order']['id'] : '');
		$remote_amount = isset($invoice['amount']) ? $this->normalizeAmount($invoice['amount']) : '';
		$remote_currency = isset($invoice['currency']) ? strtoupper((string)$invoice['currency']) : '';
		$remote_txid = $this->extractInvoiceTxid($invoice);
		$stored_txid = isset($stored_invoice['txid']) ? (string)$stored_invoice['txid'] : '';
		$stored_amount = isset($stored_invoice['amount']) ? $this->normalizeAmount($stored_invoice['amount']) : '';
		$stored_currency = isset($stored_invoice['currency']) ? strtoupper((string)$stored_invoice['currency']) : '';

		if ($stored_amount !== '' && !hash_equals($stored_amount, $this->normalizeAmount($order_info['total']))) {
			return false;
		}

		if ($stored_currency !== '' && $stored_currency !== strtoupper((string)$order_info['currency_code'])) {
			return false;
		}

		if ($remote_invoice_id === '' || !hash_equals($remote_invoice_id, (string)$invoice_id)) {
			return false;
		}

		if ($remote_order_id === '' || !hash_equals($remote_order_id, (string)$order_info['order_id'])) {
			return false;
		}

		if ($remote_amount === '' || !hash_equals($remote_amount, $this->normalizeAmount($order_info['total']))) {
			return false;
		}

		if ($remote_currency === '' || $remote_currency !== strtoupper((string)$order_info['currency_code'])) {
			return false;
		}

		if ($callback_txid !== '' && $remote_txid !== '' && !hash_equals($remote_txid, (string)$callback_txid)) {
			return false;
		}

		if ($remote_txid !== '' && $stored_txid !== '' && !hash_equals($remote_txid, $stored_txid)) {
			return false;
		}

		return true;
	}

	private function isValidCallbackStructure($callback) {
		$invoice_id = isset($callback['invoice']['id']) ? $this->normalizeInvoiceId($callback['invoice']['id']) : '';
		$order_id = isset($callback['transaction']['order']['id']) ? (int)$callback['transaction']['order']['id'] : 0;
		$state = isset($callback['transaction']['state']) ? $callback['transaction']['state'] : null;

		return $invoice_id !== '' && $order_id > 0 && is_numeric($state) && (int)$state >= 1 && (int)$state <= 5;
	}

	private function extractCallbackTxid($callback) {
		$candidates = array(
			isset($callback['invoice']['txid']) ? $callback['invoice']['txid'] : '',
			isset($callback['transaction']['txid']) ? $callback['transaction']['txid'] : '',
			isset($callback['transaction']['id']) ? $callback['transaction']['id'] : '',
			isset($callback['transaction']['identifier']) ? $callback['transaction']['identifier'] : '',
			isset($callback['transaction']['transactionIdentifier']) ? $callback['transaction']['transactionIdentifier'] : ''
		);

		foreach ($candidates as $candidate) {
			$candidate = trim((string)$candidate);

			if ($candidate !== '' && strlen($candidate) <= 64) {
				return $candidate;
			}
		}

		return '';
	}

	private function extractInvoiceTxid($invoice) {
		$candidates = array(
			isset($invoice['txid']) ? $invoice['txid'] : '',
			isset($invoice['transactionIdentifier']) ? $invoice['transactionIdentifier'] : '',
			isset($invoice['transaction']['txid']) ? $invoice['transaction']['txid'] : '',
			isset($invoice['transaction']['transactionIdentifier']) ? $invoice['transaction']['transactionIdentifier'] : ''
		);

		foreach ($candidates as $candidate) {
			$candidate = trim((string)$candidate);

			if ($candidate !== '' && strlen($candidate) <= 64) {
				return $candidate;
			}
		}

		return '';
	}

	private function extractInvoiceState($invoice) {
		$candidates = array(
			isset($invoice['transactionState']) ? $invoice['transactionState'] : null,
			isset($invoice['transaction']['state']) ? $invoice['transaction']['state'] : null
		);

		if (isset($invoice['transactions']) && is_array($invoice['transactions']) && $invoice['transactions']) {
			$last = end($invoice['transactions']);
			$candidates[] = is_array($last) && isset($last['state']) ? $last['state'] : null;
		}

		foreach ($candidates as $candidate) {
			if (is_numeric($candidate) && (int)$candidate >= 1 && (int)$candidate <= 5) {
				return (int)$candidate;
			}
		}

		return null;
	}

	private function canReuseInvoice($stored_invoice) {
		if (!$stored_invoice || empty($stored_invoice['invoice_id']) || $this->normalizeInvoiceId($stored_invoice['invoice_id']) === '') {
			return false;
		}

		if (isset($stored_invoice['invoice_status']) && (int)$stored_invoice['invoice_status'] === 1) {
			return true;
		}

		$state = isset($stored_invoice['transaction_state']) ? (int)$stored_invoice['transaction_state'] : 0;

		$invoice_status = isset($stored_invoice['invoice_status']) ? (int)$stored_invoice['invoice_status'] : 0;

		return !in_array($state, array(3, 5), true) && !in_array($invoice_status, array(3, 5), true);
	}

	private function matchesInvoiceMethod($invoice, $selected_method) {
		$method = isset($invoice['payment_method']) ? (string)$invoice['payment_method'] : 'hosted';

		return $method === (string)$selected_method;
	}

	private function getCheckoutMethods() {
		$available = $this->config->get('payment_paydo_available_methods');
		$enabled = $this->config->get('payment_paydo_enabled_methods');

		if (!is_array($available) || !is_array($enabled)) {
			return array();
		}

		$methods = array();

		foreach ($enabled as $identifier) {
			$identifier = trim((string)$identifier);

			if (!preg_match('/^[1-9][0-9]*$/D', $identifier) || !isset($available[$identifier]) || !is_array($available[$identifier])) {
				continue;
			}

			$method = $available[$identifier];
			$title = isset($method['title']) ? trim((string)$method['title']) : '';

			if ($title === '') {
				continue;
			}

			$logo = isset($method['logo']) ? trim((string)$method['logo']) : '';

			if ($logo !== '' && !$this->isSafeRemoteUrl($logo)) {
				$logo = '';
			}

			$methods[$identifier] = array('identifier' => $identifier, 'title' => $title, 'logo' => $logo);
		}

		return $methods;
	}

	private function getRequestedPaymentMethod() {
		if (!$this->config->get('payment_paydo_methods_mode')) {
			return 'hosted';
		}

		$selected = isset($this->request->post['paydo_method']) ? trim((string)$this->request->post['paydo_method']) : '';

		if ($selected === 'hosted' && $this->isHostedPageEnabled()) {
			return 'hosted';
		}

		$methods = $this->getCheckoutMethods();

		if ($selected === '' || !isset($methods[$selected])) {
			return false;
		}

		return $selected;
	}

	private function addPaymentMethodToRequest($request, $selected_method) {
		unset($request['paymentMethod']);

		if ($selected_method !== 'hosted' && preg_match('/^[1-9][0-9]*$/D', (string)$selected_method)) {
			$request['paymentMethod'] = (int)$selected_method;
		}

		return $request;
	}

	private function isHostedPageEnabled() {
		$value = $this->config->get('payment_paydo_hosted_page_enabled');

		return $value === null ? true : (bool)$value;
	}

	private function isSafeRemoteUrl($url) {
		if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}

		return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true);
	}

	private function getPaydoLanguageCode() {
		$language = strtolower((string)$this->config->get('config_language'));

		return strpos($language, 'ru') === 0 ? 'ru' : 'en';
	}

	private function getInvoice($invoice_id) {
		$invoice_id = $this->normalizeInvoiceId($invoice_id);

		if ($invoice_id === '') {
			$this->lastApiError = 'invalid_invoice_identifier';
			return array();
		}

		$response = $this->sendApiRequest($this->paydoApiBase . '/invoices/' . rawurlencode($invoice_id), 'GET');

		if (!isset($response['data']) || !is_array($response['data'])) {
			return array();
		}

		return $response['data'];
	}

	private function makeRequest($request) {
		$payload = json_encode($request, JSON_UNESCAPED_UNICODE);

		if (!is_string($payload)) {
			return '';
		}

		$response = $this->sendApiRequest($this->paydoApiBase . '/invoices/create', 'POST', $payload);

		if (isset($response['data']) && is_string($response['data'])) {
			return $this->normalizeInvoiceId($response['data']);
		}

		$id = isset($response['data']['invoice']['identifier']) ? $response['data']['invoice']['identifier']
			: (isset($response['invoice']['identifier']) ? $response['invoice']['identifier']
			: (isset($response['identifier']) ? $response['identifier'] : ''));

		return $this->normalizeInvoiceId($id);
	}

	protected function sendApiRequest($url, $method, $payload = '') {
		$this->lastApiError = '';
		$this->curl = curl_init();
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->curl, CURLOPT_HEADER, false);
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($this->curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, $method === 'POST' ? $payload : null);

		$response = curl_exec($this->curl);

		if ($response === false) {
			$this->lastApiError = 'transport_error';
			curl_close($this->curl);
			$this->curl = null;
			return array();
		}

		$http_code = (int)curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		curl_close($this->curl);
		$this->curl = null;

		if ($http_code < 200 || $http_code >= 300) {
			$this->lastApiError = 'http_' . $http_code;
			return array();
		}

		$json = json_decode($response, true);

		if (!is_array($json)) {
			$this->lastApiError = 'invalid_json';
			return array();
		}

		return $json;
	}

	private function isValidPaydoOrder($order_info) {
		$payment_code = isset($order_info['payment_code']) ? strtolower((string)$order_info['payment_code']) : '';

		return is_array($order_info) && !empty($order_info['order_id']) && strpos($payment_code, 'paydo') === 0;
	}

	private function isMatchingLegacyOrder($order_info, $callback) {
		return $this->normalizeAmount($order_info['total']) === $this->normalizeAmount($callback['amount'])
			&& strtoupper((string)$order_info['currency_code']) === strtoupper((string)$callback['currency']);
	}

	private function setOrderStatusIfChanged($order_info, $status_id) {
		$status_id = (int)$status_id;

		if ($status_id > 0 && (int)$order_info['order_status_id'] !== $status_id) {
			$this->model_checkout_order->addOrderHistory((int)$order_info['order_id'], $status_id);
		}
	}

	private function normalizeAmount($amount) {
		return number_format((float)$amount, 2, '.', '');
	}

	private function normalizeInvoiceId($invoice_id) {
		$invoice_id = trim((string)$invoice_id);

		return $this->isValidInvoiceId($invoice_id) ? $invoice_id : '';
	}

	private function isValidInvoiceId($invoice_id) {
		return $invoice_id !== '' && strlen($invoice_id) <= 64 && preg_match('/^[A-Za-z0-9-]+$/D', $invoice_id);
	}

	private function buildCheckoutUrl($invoice_id) {
		$invoice_id = $this->normalizeInvoiceId($invoice_id);

		if ($invoice_id === '') {
			return '';
		}

		return 'https://checkout.paydo.com/' . rawurlencode($this->getPaydoLanguageCode()) . '/payment/invoice-preprocessing/' . rawurlencode($invoice_id);
	}

	private function generate_order_signature($order) {
		$values = array(
			'amount' => (string)$order['amount'],
			'currency' => (string)$order['currency'],
			'id' => (string)$order['id']
		);
		ksort($values, SORT_STRING);
		$data = array_values($values);
		$data[] = (string)$this->config->get('payment_paydo_secret_key');

		return hash('sha256', implode(':', $data));
	}

	private function generate_legacy_signature($order_id, $amount, $currency, $secret_key, $status) {
		$values = array('id' => (string)$order_id, 'amount' => (string)$amount, 'currency' => (string)$currency);
		ksort($values, SORT_STRING);
		$data = array_values($values);

		if ($status) {
			$data[] = (string)$status;
		}

		$data[] = (string)$secret_key;

		return hash('sha256', implode(':', $data));
	}

	private function logCallback($message, $context = array()) {
		$allowed = array();

		foreach (array('order_id', 'invoice_id', 'state') as $key) {
			if (isset($context[$key])) {
				$allowed[$key] = (string)$context[$key];
			}
		}

		$line = '[PayDo] ' . (string)$message;

		if ($allowed) {
			$line .= ' | ' . json_encode($allowed, JSON_UNESCAPED_UNICODE);
		}

		$this->log->write($line);
	}

	private function jsonResponse($data) {
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}
