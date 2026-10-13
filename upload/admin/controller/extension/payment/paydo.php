<?php

/**
 * Class ControllerExtensionPaymentPaydo
 *
 * @property Loader                             $load
 * @property Document                           $document
 * @property ModelSettingSetting                $model_setting_setting
 * @property Request                            $request
 * @property Response                           $response
 * @property Session                            $session
 * @property Language                           $language
 * @property Url                                $url
 * @property Config                             $config
 * @property ModelLocalisationGeoZone           $model_localisation_geo_zone
 * @property ModelLocalisationOrderStatus       $model_localisation_order_status
 * @property ModelExtensionPaymentPaydo         $model_extension_payment_paydo
 * @property Cart\User                          $user
 */
class ControllerExtensionPaymentPaydo extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/payment/paydo');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');
		$this->load->model('extension/payment/paydo');
		$this->model_extension_payment_paydo->upgrade();

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$settings = $this->request->post;
			$available_methods = $this->getAvailableMethods();
			$enabled_methods = $this->normalizeMethodIdentifiers(
				isset($settings['payment_paydo_enabled_methods']) ? $settings['payment_paydo_enabled_methods'] : array()
			);
			$settings['payment_paydo_methods_mode'] = !empty($settings['payment_paydo_methods_mode']) ? 1 : 0;
			$settings['payment_paydo_hosted_page_enabled'] = !empty($settings['payment_paydo_hosted_page_enabled']) ? 1 : 0;
			$settings['payment_paydo_enabled_methods'] = array_values(array_intersect($enabled_methods, array_keys($available_methods)));
			$settings['payment_paydo_available_methods'] = $available_methods;

			$this->model_setting_setting->editSetting('payment_paydo', $settings);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension',
				'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		$data['error_public_id']  = '';
		$data['error_secret_key'] = '';
		$data['error_enabled_methods'] = '';

		foreach ($this->error as $f => $v) {
			$data['error_' . $f] = $v;
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension',
				'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/paydo',
				'user_token=' . $this->session->data['user_token'],
				true)
		);

		$data['action'] = $this->url->link('extension/payment/paydo',
			'user_token=' . $this->session->data['user_token'], true);

		$data['cancel'] = $this->url->link('marketplace/extension',
			'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
		$data['invoices'] = $this->url->link('extension/payment/paydo/invoices',
			'user_token=' . $this->session->data['user_token'], true);

		$fields = array(
			'payment_paydo_public_id',
			'payment_paydo_secret_key',
			'payment_paydo_methods_mode',
			'payment_paydo_hosted_page_enabled',
			'payment_paydo_project_id',
			'payment_paydo_jwt_token',
			'payment_paydo_enabled_methods',
			'payment_paydo_order_status_wait',
			'payment_paydo_order_status_success',
			'payment_paydo_order_status_error',
			'payment_paydo_status'
		);

		foreach ($fields as $f) {
			if (isset($this->request->post[$f])) {
				$data[$f] = $this->request->post[$f];
			} else {
				$data[$f] = $this->config->get($f);
			}
		}

		if ($data['payment_paydo_hosted_page_enabled'] === null) {
			$data['payment_paydo_hosted_page_enabled'] = 1;
		}

		$data['payment_paydo_enabled_methods'] = $this->normalizeMethodIdentifiers($data['payment_paydo_enabled_methods']);
		$data['available_methods'] = $this->getAvailableMethods();
		$data['sync_methods_url'] = $this->url->link(
			'extension/payment/paydo/syncMethods',
			'user_token=' . $this->session->data['user_token'],
			true
		);

		$catalog_url = $this->config->get('config_secure') ? HTTPS_CATALOG : HTTP_CATALOG;
		$data['ipn_url'] = $catalog_url . 'index.php?route=extension/payment/paydo/callback';

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		if (isset($this->request->post['payment_paydo_geo_zone_id'])) {
			$data['payment_paydo_geo_zone_id'] = $this->request->post['payment_paydo_geo_zone_id'];
		} else {
			$data['payment_paydo_geo_zone_id'] = $this->config->get('payment_paydo_geo_zone_id');
		}
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		if (isset($this->request->post['payment_paydo_sort_order'])) {
			$data['payment_paydo_sort_order'] = $this->request->post['payment_paydo_sort_order'];
		} else {
			$data['payment_paydo_sort_order'] = $this->config->get('payment_paydo_sort_order');
		}

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/paydo', $data));
	}

	public function syncMethods() {
		$this->load->language('extension/payment/paydo');
		$this->response->addHeader('Content-Type: application/json');

		if (!$this->hasValidUserToken() || !$this->user->hasPermission('modify', 'extension/payment/paydo')) {
			$this->jsonResponse(array('error' => $this->language->get('error_permission')));
			return;
		}

		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->jsonResponse(array('error' => $this->language->get('error_sync_request')));
			return;
		}

		$project_id = isset($this->request->post['project_id']) ? trim((string)$this->request->post['project_id']) : '';
		$jwt_token = isset($this->request->post['jwt_token']) ? trim((string)$this->request->post['jwt_token']) : '';

		if ($project_id === '' || $jwt_token === '') {
			$this->jsonResponse(array('error' => $this->language->get('error_sync_credentials')));
			return;
		}

		$url = 'https://api.paydo.com/v3/instrument-settings/payment-methods/available-for-application/' . rawurlencode($project_id);
		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'Accept: application/json',
			'Authorization: Bearer ' . $jwt_token
		));

		$response = curl_exec($curl);
		$http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($response === false) {
			$this->jsonResponse(array('error' => $this->language->get('error_sync_transport')));
			return;
		}

		$json = json_decode($response, true);

		if ($http_code !== 200 || !is_array($json)) {
			$error_key = in_array($http_code, array(401, 403), true) ? 'error_sync_auth'
				: ($http_code === 404 ? 'error_sync_not_found'
				: ($http_code === 422 ? 'error_sync_validation'
				: ($http_code >= 500 ? 'error_sync_server' : 'error_sync_api')));
			$this->jsonResponse(array('error' => sprintf($this->language->get($error_key), $http_code)));
			return;
		}

		$rows = isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
		$methods = $this->normalizeAvailableMethods($rows);

		if (!$methods) {
			$this->jsonResponse(array('error' => $this->language->get('error_sync_empty')));
			return;
		}

		uasort($methods, function($left, $right) {
			return strnatcasecmp($left['title'], $right['title']);
		});

		$this->load->model('setting/setting');
		$settings = $this->model_setting_setting->getSetting('payment_paydo');
		$settings['payment_paydo_available_methods'] = $methods;
		$this->model_setting_setting->editSetting('payment_paydo', $settings);

		$this->jsonResponse(array(
			'success' => sprintf($this->language->get('text_sync_success'), count($methods)),
			'count' => count($methods),
			'methods' => array_values($methods)
		));
	}

	public function invoices() {
		$this->load->language('extension/payment/paydo');
		$this->document->setTitle($this->language->get('heading_invoices'));
		$this->load->model('extension/payment/paydo');

		$data['breadcrumbs'] = array(
			array(
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
			),
			array(
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
			),
			array(
				'text' => $this->language->get('heading_invoices'),
				'href' => $this->url->link('extension/payment/paydo/invoices', 'user_token=' . $this->session->data['user_token'], true)
			)
		);

		$data['back'] = $this->url->link('extension/payment/paydo', 'user_token=' . $this->session->data['user_token'], true);
		$data['invoice_records'] = $this->model_extension_payment_paydo->getInvoiceRecords();
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/paydo_invoices', $data));
	}

	private function validate() {
		if (!$this->hasValidUserToken() || !$this->user->hasPermission('modify', 'extension/payment/paydo')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		$required_fields = array(
			'public_id',
			'secret_key'
		);

		foreach ($required_fields as $f) {
			if (empty($this->request->post['payment_paydo_' . $f])) {
				$this->error[$f] = $this->language->get('error_' . $f);
			}
		}

		$methods_mode = !empty($this->request->post['payment_paydo_methods_mode']);
		$hosted_page_enabled = !empty($this->request->post['payment_paydo_hosted_page_enabled']);
		$available_methods = $this->getAvailableMethods();
		$enabled_methods = $this->normalizeMethodIdentifiers(
			isset($this->request->post['payment_paydo_enabled_methods']) ? $this->request->post['payment_paydo_enabled_methods'] : array()
		);
		$enabled_methods = array_values(array_intersect($enabled_methods, array_map('strval', array_keys($available_methods))));

		if ($methods_mode && !$hosted_page_enabled && !$enabled_methods) {
			$this->error['enabled_methods'] = $this->language->get('error_enabled_methods');
		}

		return !$this->error;
	}

	private function getAvailableMethods() {
		$methods = $this->config->get('payment_paydo_available_methods');

		if (is_string($methods)) {
			$decoded = json_decode($methods, true);
			$methods = is_array($decoded) ? $decoded : array();
		}

		if (!is_array($methods)) {
			return array();
		}

		$result = array();

		foreach ($methods as $identifier => $method) {
			if (!is_array($method)) {
				continue;
			}

			$identifier = isset($method['identifier']) ? (string)$method['identifier'] : (string)$identifier;

			if (!$this->isValidMethodIdentifier($identifier)) {
				continue;
			}

			$result[$identifier] = array(
				'identifier' => $identifier,
				'title' => isset($method['title']) ? (string)$method['title'] : ('Method #' . $identifier),
				'logo' => isset($method['logo']) && $this->isSafeRemoteUrl($method['logo']) ? (string)$method['logo'] : ''
			);
		}

		return $result;
	}

	private function normalizeAvailableMethods($rows) {
		$methods = array();

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$method = isset($row['paymentMethod']) && is_array($row['paymentMethod']) ? $row['paymentMethod'] : $row;
			$identifier = isset($method['identifier']) ? trim((string)$method['identifier']) : '';
			$title = isset($method['title']) ? trim(strip_tags((string)$method['title'])) : '';

			if (empty($method['isEnabled']) || !$this->isValidMethodIdentifier($identifier) || $title === '') {
				continue;
			}

			$logo = isset($method['logo']) ? trim((string)$method['logo']) : '';

			if ($logo !== '' && (!$this->isSafeRemoteUrl($logo))) {
				$logo = '';
			}

			$methods[$identifier] = array(
				'identifier' => $identifier,
				'title' => $title,
				'logo' => $logo
			);
		}

		return $methods;
	}

	private function normalizeMethodIdentifiers($identifiers) {
		if (!is_array($identifiers)) {
			return array();
		}

		$result = array();

		foreach ($identifiers as $identifier) {
			$identifier = trim((string)$identifier);

			if ($this->isValidMethodIdentifier($identifier)) {
				$result[] = $identifier;
			}
		}

		return array_values(array_unique($result));
	}

	private function isValidMethodIdentifier($identifier) {
		return preg_match('/^[1-9][0-9]*$/D', (string)$identifier) === 1;
	}

	private function isSafeRemoteUrl($url) {
		if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}

		return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true);
	}

	private function hasValidUserToken() {
		$provided = isset($this->request->get['user_token']) ? (string)$this->request->get['user_token'] : '';
		$expected = isset($this->session->data['user_token']) ? (string)$this->session->data['user_token'] : '';

		return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
	}

	private function jsonResponse($data) {
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	public function install() {
		$this->load->model('extension/payment/paydo');
		$this->model_extension_payment_paydo->install();
	}

	public function uninstall() {
		$this->load->model('extension/payment/paydo');
		$this->model_extension_payment_paydo->uninstall();
	}
}
