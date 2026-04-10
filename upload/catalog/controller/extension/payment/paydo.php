<?php

/**
 * Class ControllerExtensionPaymentPaydo
 *
 * @property Language		   $language
 * @property \Cart\Currency	 $currency
 * @property Config			 $config
 * @property Url				$url
 * @property Loader			 $load
 * @property Session			$session
 * @property Request			$request
 * @property Response		   $response
 *
 * @property ModelCheckoutOrder $model_checkout_order
 * @property ModelExtensionPaymentPaydo $model_extension_payment_paydo
 */
class ControllerExtensionPaymentPaydo extends Controller {
	/** @var resource|null */
	private $curl = null;
	private $paydoApiBase = 'https://api.paydo.com/v1';

	public function index() {
		$this->load->language('extension/payment/paydo');

		$data = array(
			'button_pay' => $this->language->get('button_pay'),
			'paydo_url'  => $this->url->link('extension/payment/paydo/pay')
		);

		return $this->load->view('extension/payment/paydo', $data);
	}

	public function pay() {
		$this->response->addHeader('Content-Type: application/json');

		if (empty($this->session->data['order_id'])) {
			$this->response->setOutput(json_encode(array(
				'error' => 'Order not found'
			)));
			return;
		}

		$order_id = (int)$this->session->data['order_id'];

		$this->load->model('checkout/order');
		$this->load->model('extension/payment/paydo');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->response->setOutput(json_encode(array(
				'error' => 'Order not found'
			)));
			return;
		}

		$order_products = $this->model_checkout_order->getOrderProducts($order_id);

		$paydo_order_items = array();

		foreach ($order_products as $product) {
			$paydo_order_items[] = array(
				'id'	=> (string)$product['order_product_id'],
				'name'  => trim($product['name'] . ' ' . $product['model']),
				'price' => (float)$product['price'],
			);
		}

		$amount = (float)$order_info['total'];
		$amount = number_format($amount, 2, '.', '');

		$request = array(
			'publicKey' => $this->config->get('payment_paydo_public_id'),
			'order'	 => array(
				'id'		  => $order_info['order_id'],
				'amount'	  => $amount,
				'currency'	=> $order_info['currency_code'],
				'description' => sprintf($this->language->get('order_description'), $order_info['order_id']),
				'items'	   => $paydo_order_items,
			),
			'payer'	 => array(
				'email' => $order_info['email'],
				'phone' => $order_info['telephone'],
				'name'  => $order_info['firstname'] . ' ' . $order_info['lastname']
			),
			'resultUrl' => $this->url->link('checkout/success'),
			'failPath'  => $this->url->link('checkout/failure'),
			'language'  => $this->language->get('code')
		);

		$request['signature'] = $this->generate_order_signature($request['order']);

		$invoiceId = $this->makeRequest($request);

		if ($invoiceId === '') {
			$this->response->setOutput(json_encode(array(
				'error' => 'Invoice not created'
			)));
		} else {
			$this->model_extension_payment_paydo->saveInvoice($order_info['order_id'], $invoiceId);

			$this->model_checkout_order->addOrderHistory(
				$order_info['order_id'],
				$this->config->get('payment_paydo_order_status_wait')
			);

			$redirectUrl = "https://checkout.paydo.com/{$this->language->get('code')}/payment/invoice-preprocessing/{$invoiceId}";
			$this->response->setOutput(json_encode($redirectUrl));
		}
	}

	public function callback() {
		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			return;
		}

		$raw = file_get_contents('php://input');
		$callback = json_decode($raw, true);

		if (!is_array($callback)) {
			$this->logCallback('Rejected callback: invalid JSON payload');
			return;
		}

		if (isset($callback['invoice'])) {
			$check = $this->callback_check($callback);

			if ($check !== 'valid') {
				$this->logCallback('Rejected callback: invalid new-format payload', $callback);
				return;
			}

			$this->load->model('checkout/order');
			$this->load->model('extension/payment/paydo');

			$state = (int)$callback['transaction']['state'];
			$orderId = (int)$callback['transaction']['order']['id'];
			$invoiceId = (string)$callback['invoice']['id'];

			$order_info = $this->model_checkout_order->getOrder($orderId);

			if (!$this->isValidPaydoOrder($order_info)) {
				$this->logCallback('Rejected callback: target order is not a Paydo order', $callback);
				return;
			}

			$storedInvoiceId = $this->model_extension_payment_paydo->getInvoiceIdByOrderId($orderId);

			if ($storedInvoiceId === '' || !hash_equals($storedInvoiceId, $invoiceId)) {
				$this->logCallback('Rejected callback: invoice is not linked to order', $callback);
				return;
			}

			$invoice = $this->getInvoice($invoiceId);

			if (!$this->isVerifiedInvoice($invoice, $order_info, $callback)) {
				$this->logCallback('Rejected callback: invoice verification failed', $callback);
				return;
			}

			if ($state === 2) {
				$this->model_checkout_order->addOrderHistory(
					$orderId,
					$this->config->get('payment_paydo_order_status_success')
				);
				$this->logCallback('Processed successful Paydo callback', $callback);
			} elseif (in_array($state, array(3, 5), true)) {
				$this->model_checkout_order->addOrderHistory(
					$orderId,
					$this->config->get('payment_paydo_order_status_error')
				);
				$this->logCallback('Processed failed Paydo callback', $callback);
			}
		} else {
			if (!isset($callback['orderId'], $callback['amount'], $callback['currency'], $callback['status'], $callback['signature'])) {
				$this->logCallback('Rejected callback: unsupported payload format', $callback);
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
				$this->logCallback('Rejected callback: invalid legacy signature', $callback);
				return;
			}

			$this->load->model('checkout/order');
			$order_info = $this->model_checkout_order->getOrder((int)$callback['orderId']);

			if (!$this->isValidPaydoOrder($order_info)) {
				$this->logCallback('Rejected callback: legacy callback targets non-Paydo order', $callback);
				return;
			}

			if (!$this->isMatchingLegacyOrder($order_info, $callback)) {
				$this->logCallback('Rejected callback: legacy callback payload does not match order', $callback);
				return;
			}

			if ($callback['status'] === 'success') {
				$this->model_checkout_order->addOrderHistory(
					(int)$callback['orderId'],
					$this->config->get('payment_paydo_order_status_success')
				);
				$this->logCallback('Processed successful legacy Paydo callback', $callback);
			} elseif ($callback['status'] === 'error') {
				$this->model_checkout_order->addOrderHistory(
					(int)$callback['orderId'],
					$this->config->get('payment_paydo_order_status_error')
				);
				$this->logCallback('Processed failed legacy Paydo callback', $callback);
			}
		}
	}

	/**
	 * Validates the callback structure for the new format (invoice + transaction).
	 *
	 * @param array $callback Callback request data.
	 * @return string Returns "valid" if the validation passes, otherwise an error message.
	 */
	private function callback_check($callback) {
		$invoiceId = isset($callback['invoice']['id']) ? $callback['invoice']['id'] : null;
		$txid	  = isset($callback['invoice']['txid']) ? $callback['invoice']['txid'] : null;
		$orderId   = isset($callback['transaction']['order']['id']) ? $callback['transaction']['order']['id'] : null;
		$state	 = isset($callback['transaction']['state']) ? $callback['transaction']['state'] : null;

		if (!$invoiceId) {
			return 'Empty invoice id';
		}
		if (!$txid) {
			return 'Empty transaction id';
		}
		if (!$orderId) {
			return 'Empty order id';
		}
		if (!is_numeric($state) || (int)$state < 1 || (int)$state > 5) {
			return 'State is not valid: ' . var_export($state, true);
		}

		return 'valid';
	}

	private function getInvoice($invoiceId) {
		if (!$this->curl) {
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->curl, CURLOPT_HEADER, false);
			curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
		}

		curl_setopt($this->curl, CURLOPT_URL, $this->paydoApiBase . '/invoices/' . rawurlencode($invoiceId));
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);

		$response = curl_exec($this->curl);

		if ($response === false) {
			curl_close($this->curl);
			$this->curl = null;
			return array();
		}

		$code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		curl_close($this->curl);
		$this->curl = null;

		if ($code < 200 || $code >= 300) {
			return array();
		}

		$json = json_decode($response, true);

		if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
			return array();
		}

		return $json['data'];
	}

	private function isVerifiedInvoice($invoice, $order_info, $callback) {
		if (!$invoice || !$order_info) {
			return false;
		}

		$invoiceId = isset($callback['invoice']['id']) ? (string)$callback['invoice']['id'] : '';
		$callbackOrderId = isset($callback['transaction']['order']['id']) ? (string)$callback['transaction']['order']['id'] : '';
		$callbackState = isset($callback['transaction']['state']) ? (int)$callback['transaction']['state'] : null;

		$remoteInvoiceId = isset($invoice['identifier']) ? (string)$invoice['identifier'] : $invoiceId;
		$remoteOrderId = isset($invoice['orderIdentifier']) ? (string)$invoice['orderIdentifier']
			: (isset($invoice['order']['id']) ? (string)$invoice['order']['id'] : '');
		$remoteAmount = isset($invoice['amount']) ? $this->normalizeAmount($invoice['amount']) : '';
		$remoteCurrency = isset($invoice['currency']) ? (string)$invoice['currency'] : '';
		$remoteStatus = isset($invoice['status']) ? (int)$invoice['status'] : null;
		$remoteTxid = isset($invoice['txid']) ? (string)$invoice['txid']
			: (isset($invoice['transaction']['txid']) ? (string)$invoice['transaction']['txid'] : '');

		if ($remoteInvoiceId === '' || !hash_equals($remoteInvoiceId, $invoiceId)) {
			return false;
		}

		if ($remoteOrderId === '' || !hash_equals($remoteOrderId, $callbackOrderId)) {
			return false;
		}

		if (!hash_equals($remoteOrderId, (string)$order_info['order_id'])) {
			return false;
		}

		if ($remoteAmount === '' || !hash_equals($remoteAmount, $this->normalizeAmount($order_info['total']))) {
			return false;
		}

		if ($remoteCurrency === '' || strtoupper($remoteCurrency) !== strtoupper($order_info['currency_code'])) {
			return false;
		}

		if ($remoteTxid !== '' && isset($callback['invoice']['txid']) && !hash_equals($remoteTxid, (string)$callback['invoice']['txid'])) {
			return false;
		}

		if ($callbackState === 2) {
			return $remoteStatus === 1;
		}

		if (in_array($callbackState, array(3, 5), true)) {
			return $remoteStatus !== 1;
		}

		return false;
	}

	private function isValidPaydoOrder($order_info) {
		$paymentCode = isset($order_info['payment_code']) ? (string)$order_info['payment_code'] : '';

		return is_array($order_info)
			&& !empty($order_info['order_id'])
			&& $paymentCode !== ''
			&& strpos($paymentCode, 'paydo') === 0;
	}

	private function isMatchingLegacyOrder($order_info, $callback) {
		if (!$order_info) {
			return false;
		}

		return $this->normalizeAmount($order_info['total']) === $this->normalizeAmount($callback['amount'])
			&& strtoupper($order_info['currency_code']) === strtoupper((string)$callback['currency']);
	}

	private function normalizeAmount($amount) {
		return number_format((float)$amount, 2, '.', '');
	}

	private function logCallback($message, $context = array()) {
		$line = '[Paydo callback] ' . $message;

		if ($context) {
			$line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
		}

		$this->log->write($line);
	}

	/**
	 * Creates a Paydo invoice and returns its identifier
	 *
	 * @param array $request
	 * @return string
	 */
	private function makeRequest($request = array()) {
		$payload = json_encode($request, JSON_UNESCAPED_UNICODE);

		if (!$this->curl) {
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_URL, 'https://api.paydo.com/v1/invoices/create');
			curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->curl, CURLOPT_HEADER, false);
		}

		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, $payload);

		$response = curl_exec($this->curl);

		if ($response === false) {
			curl_close($this->curl);
			$this->curl = null;
			return '';
		}

		$code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		curl_close($this->curl);
		$this->curl = null;

		if ($code < 200 || $code >= 300) {
			return '';
		}

		$json = json_decode($response, true);

		if (!is_array($json)) {
			return '';
		}

		if (isset($json['data']) && is_string($json['data']) && $json['data'] !== '') {
			return $json['data'];
		}

		$id = isset($json['data']['invoice']['identifier']) ? $json['data']['invoice']['identifier']
			: (isset($json['invoice']['identifier']) ? $json['invoice']['identifier']
			: (isset($json['identifier']) ? $json['identifier'] : ''));

		if ($id !== '') {
			return (string)$id;
		}

		return '';
	}

	/**
	 * Signature for invoice creation
	 *
	 * @param array $order
	 * @return string
	 */
	private function generate_order_signature($order) {
		$sign_str = array(
			'amount'   => (string)$order['amount'],
			'currency' => (string)$order['currency'],
			'id'	   => (string)$order['id'],
		);

		ksort($sign_str, SORT_STRING);
		$sign_data = array_values($sign_str);
		$sign_data[] = (string)$this->config->get('payment_paydo_secret_key');

		return hash('sha256', implode(':', $sign_data));
	}

	/**
	 * Legacy signature for the old callback format (orderId + amount + currency + status)
	 *
	 * @param string|int $orderId
	 * @param string|float $amount
	 * @param string $currency
	 * @param string $secretKey
	 * @param string $status
	 * @return string
	 */
	private function generate_legacy_signature($orderId, $amount, $currency, $secretKey, $status) {
		$sign_str = array(
			'id'	   => (string)$orderId,
			'amount'   => (string)$amount,
			'currency' => (string)$currency,
		);

		ksort($sign_str, SORT_STRING);
		$sign_data = array_values($sign_str);

		if ($status) {
			$sign_data[] = (string)$status;
		}

		$sign_data[] = (string)$secretKey;

		return hash('sha256', implode(':', $sign_data));
	}
}
