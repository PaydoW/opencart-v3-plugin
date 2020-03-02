<?php

/**
 * Class ModelExtensionPaymentPaydo
 *
 * @property Loader             $load
 * @property Config             $config
 * @property Language           $language
 * @property DB\MySQLi          $db
 */
class ModelExtensionPaymentPaydo extends Model {

	/**
	 * Call when display payments in checkout
	 *
	 * @param $address
	 * @param $total
	 * @return array
	 */
	public function getMethod($address, $total) {
		$this->load->language('extension/payment/paydo');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_paydo_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('payment_paydo_total') > 0 && $this->config->get('payment_paydo_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('payment_paydo_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'paydo',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_paydo_sort_order')
			);
		}

		return $method_data;
	}

	/**
	 * @param $data
	 */
	public function addTransaction($data) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "paydo_transactions` SET 
			txid = '" . $this->db->escape($data['txid']) . "',
			status = '" . $this->db->escape($data['status']) . "',
			publicKey = '" . $this->db->escape($data['publicKey']) . "',
			type = '" . $this->db->escape($data['type']) . "',
			amount = '" . $this->db->escape($data['amount']) . "',
			currency = '" . $this->db->escape($data['currency']) . "',
			signature = '" . $this->db->escape($data['signature']) . "',
			language = '" . $this->db->escape($data['language']) . "',
			date = '" . $this->db->escape($data['date']) . "',
			orderId = '" . $this->db->escape($data['orderId']) . "',
			email = '" . $this->db->escape($data['email']) . "',
			paydoId = '" . $this->db->escape($data['paydoId']) . "',
			error = '" . $this->db->escape($data['error']) . "'
		");
	}
}
