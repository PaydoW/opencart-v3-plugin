<?php

/**
 * Class ModelExtensionPaymentPaydo
 *
 * @property Loader    $load
 * @property Config    $config
 * @property Language  $language
 * @property DB\MySQLi $db
 */
class ModelExtensionPaymentPaydo extends Model {
	private $invoice_table_ready = false;

	public function getMethod($address, $total) {
		$this->load->language('extension/payment/paydo');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_paydo_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('payment_paydo_total') > 0 && $this->config->get('payment_paydo_total') > $total) {
			$status = false;
		} elseif ($this->config->get('payment_paydo_methods_mode') && !$this->hasCheckoutOption()) {
			$status = false;
		} elseif (!$this->config->get('payment_paydo_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		if (!$status) {
			return array();
		}

		return array(
			'code'       => 'paydo',
			'title'      => $this->language->get('text_title'),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_paydo_sort_order')
		);
	}

	public function saveInvoice($order_info, $invoice_id, $payment_method) {
		$this->ensureInvoiceTable();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "paydo_invoice` SET
			`order_id` = '" . (int)$order_info['order_id'] . "',
			`invoice_id` = '" . $this->db->escape((string)$invoice_id) . "',
			`payment_method` = '" . $this->db->escape((string)$payment_method) . "',
			`amount` = '" . $this->db->escape(number_format((float)$order_info['total'], 4, '.', '')) . "',
			`currency` = '" . $this->db->escape((string)$order_info['currency_code']) . "',
			`retry_count` = '0',
			`next_retry_at` = NULL,
			`last_error` = '',
			`date_added` = NOW(),
			`date_modified` = NOW()
			ON DUPLICATE KEY UPDATE
			`invoice_id` = VALUES(`invoice_id`),
			`payment_method` = VALUES(`payment_method`),
			`txid` = '',
			`amount` = VALUES(`amount`),
			`currency` = VALUES(`currency`),
			`invoice_status` = NULL,
			`transaction_state` = NULL,
			`retry_count` = '0',
			`next_retry_at` = NULL,
			`last_error` = '',
			`date_modified` = NOW()");
	}

	public function getInvoiceByOrderId($order_id) {
		$this->ensureInvoiceTable();
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "paydo_invoice` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		return $query->num_rows ? $query->row : array();
	}

	public function getInvoiceByInvoiceId($invoice_id) {
		$this->ensureInvoiceTable();
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "paydo_invoice` WHERE `invoice_id` = '" . $this->db->escape((string)$invoice_id) . "' LIMIT 1");

		return $query->num_rows ? $query->row : array();
	}

	public function getInvoiceIdByOrderId($order_id) {
		$invoice = $this->getInvoiceByOrderId($order_id);

		return isset($invoice['invoice_id']) ? (string)$invoice['invoice_id'] : '';
	}

	public function updateInvoice($order_id, $invoice_id, $txid, $invoice_status, $transaction_state) {
		$this->ensureInvoiceTable();
		$invoice_status_sql = $invoice_status === null ? 'NULL' : (string)(int)$invoice_status;
		$transaction_state_sql = $transaction_state === null ? 'NULL' : (string)(int)$transaction_state;

		$this->db->query("UPDATE `" . DB_PREFIX . "paydo_invoice` SET
			`txid` = IF('" . $this->db->escape((string)$txid) . "' = '', `txid`, '" . $this->db->escape((string)$txid) . "'),
			`invoice_status` = " . $invoice_status_sql . ",
			`transaction_state` = " . $transaction_state_sql . ",
			`date_modified` = NOW()
			WHERE `order_id` = '" . (int)$order_id . "'
			AND `invoice_id` = '" . $this->db->escape((string)$invoice_id) . "'");
	}

	public function clearRetry($order_id) {
		$this->ensureInvoiceTable();
		$this->db->query("UPDATE `" . DB_PREFIX . "paydo_invoice` SET
			`retry_count` = '0', `next_retry_at` = NULL, `last_error` = '', `date_modified` = NOW()
			WHERE `order_id` = '" . (int)$order_id . "'");
	}

	public function scheduleRetry($order_id, $error, $delay_seconds, $max_retries) {
		$this->ensureInvoiceTable();
		$invoice = $this->getInvoiceByOrderId($order_id);
		$current_count = isset($invoice['retry_count']) ? (int)$invoice['retry_count'] : 0;
		$next_count = $current_count + 1;
		$error = substr((string)$error, 0, 255);

		if ($next_count >= (int)$max_retries) {
			$this->db->query("UPDATE `" . DB_PREFIX . "paydo_invoice` SET
				`retry_count` = '" . $next_count . "',
				`next_retry_at` = NULL,
				`last_error` = '" . $this->db->escape($error) . "',
				`date_modified` = NOW()
				WHERE `order_id` = '" . (int)$order_id . "'");
		} else {
			$this->db->query("UPDATE `" . DB_PREFIX . "paydo_invoice` SET
				`retry_count` = '" . $next_count . "',
				`next_retry_at` = DATE_ADD(NOW(), INTERVAL " . max(1, (int)$delay_seconds) . " SECOND),
				`last_error` = '" . $this->db->escape($error) . "',
				`date_modified` = NOW()
				WHERE `order_id` = '" . (int)$order_id . "'");
		}

		return $next_count;
	}

	private function hasCheckoutOption() {
		$value = $this->config->get('payment_paydo_hosted_page_enabled');

		if ($value === null || $value) {
			return true;
		}

		return (bool)$this->getCheckoutMethods();
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

			if (preg_match('/^[1-9][0-9]*$/D', $identifier) && isset($available[$identifier]) && is_array($available[$identifier]) && !empty($available[$identifier]['title'])) {
				$methods[$identifier] = $available[$identifier];
			}
		}

		return $methods;
	}

	private function ensureInvoiceTable() {
		if ($this->invoice_table_ready) {
			return;
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paydo_invoice` (
			`order_id` INT(11) NOT NULL,
			`invoice_id` VARCHAR(64) NOT NULL,
			`payment_method` VARCHAR(32) NOT NULL DEFAULT 'hosted',
			`txid` VARCHAR(64) NOT NULL DEFAULT '',
			`amount` DECIMAL(15,4) NOT NULL DEFAULT '0.0000',
			`currency` VARCHAR(8) NOT NULL DEFAULT '',
			`invoice_status` INT(11) DEFAULT NULL,
			`transaction_state` INT(11) DEFAULT NULL,
			`retry_count` INT(11) NOT NULL DEFAULT '0',
			`next_retry_at` DATETIME DEFAULT NULL,
			`last_error` VARCHAR(255) NOT NULL DEFAULT '',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`order_id`),
			UNIQUE KEY `invoice_id` (`invoice_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");

		$columns = array(
			'payment_method' => "VARCHAR(32) NOT NULL DEFAULT 'hosted' AFTER `invoice_id`",
			'txid' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER `payment_method`",
			'amount' => "DECIMAL(15,4) NOT NULL DEFAULT '0.0000' AFTER `txid`",
			'currency' => "VARCHAR(8) NOT NULL DEFAULT '' AFTER `amount`",
			'invoice_status' => "INT(11) DEFAULT NULL AFTER `currency`",
			'transaction_state' => "INT(11) DEFAULT NULL AFTER `invoice_status`",
			'retry_count' => "INT(11) NOT NULL DEFAULT '0' AFTER `transaction_state`",
			'next_retry_at' => "DATETIME DEFAULT NULL AFTER `retry_count`",
			'last_error' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER `next_retry_at`"
		);
		$existing = array();
		$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "paydo_invoice`");

		foreach ($query->rows as $column) {
			$existing[$column['Field']] = true;
		}

		$backfill = !isset($existing['amount']) || !isset($existing['currency']);

		foreach ($columns as $name => $definition) {
			if (!isset($existing[$name])) {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "paydo_invoice` ADD `" . $name . "` " . $definition);
			}
		}

		if ($backfill) {
			$this->db->query("UPDATE `" . DB_PREFIX . "paydo_invoice` i
				INNER JOIN `" . DB_PREFIX . "order` o ON o.order_id = i.order_id
				SET i.amount = o.total, i.currency = o.currency_code");
		}

		$this->invoice_table_ready = true;
	}
}
