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

		$this->model_checkout_order->addOrderHistory(
			$order_info['order_id'],
			$this->config->get('payment_paydo_order_status_wait')
		);

		$invoiceId = $this->makeRequest($request);

		if ($invoiceId === '') {
			$this->response->setOutput(json_encode(array(
				'error' => 'Invoice not created'
			)));
		} else {
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

		if ($callback && isset($callback['invoice'])) {
			$check = $this->callback_check($callback);

			if ($check === 'valid') {
				$this->load->model('checkout/order');

				$state   = isset($callback['transaction']['state']) ? (int)$callback['transaction']['state'] : null;
				$orderId = isset($callback['transaction']['order']['id']) ? (int)$callback['transaction']['order']['id'] : null;

				if ($orderId && $state !== null) {
					if ($state === 2) {
						$this->model_checkout_order->addOrderHistory(
							$orderId,
							$this->config->get('payment_paydo_order_status_success')
						);
					} elseif (in_array($state, array(3, 5), true)) {
						$this->model_checkout_order->addOrderHistory(
							$orderId,
							$this->config->get('payment_paydo_order_status_error')
						);
					}
				}
			}
		} else {
			if (is_array($callback)
				&& isset($callback['orderId'], $callback['amount'], $callback['currency'], $callback['status'], $callback['signature'])
			) {
				$signature = $this->generate_legacy_signature(
					$callback['orderId'],
					$callback['amount'],
					$callback['currency'],
					$this->config->get('payment_paydo_secret_key'),
					$callback['status']
				);

				if ($callback['signature'] === $signature) {
					$this->load->model('checkout/order');

					if ($callback['status'] === 'success') {
						$this->model_checkout_order->addOrderHistory(
							(int)$callback['orderId'],
							$this->config->get('payment_paydo_order_status_success')
						);
					} elseif ($callback['status'] === 'error') {
						$this->model_checkout_order->addOrderHistory(
							(int)$callback['orderId'],
							$this->config->get('payment_paydo_order_status_error')
						);
					}
				}
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
