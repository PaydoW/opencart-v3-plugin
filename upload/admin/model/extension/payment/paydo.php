<?php

/**
 * Class ModelExtensionPaymentPaydo
 *
 * @property Loader              $load
 * @property ModelSettingSetting $model_setting_setting
 * @property DB\MySQLi           $db
 */
class ModelExtensionPaymentPaydo extends Model {
	public function install() {
		$this->load->model('setting/setting');

		$defaults = array(
			'payment_paydo_sort_order' => 0,
			'payment_paydo_methods_mode' => 0,
			'payment_paydo_hosted_page_enabled' => 1,
			'payment_paydo_project_id' => '',
			'payment_paydo_jwt_token' => '',
			'payment_paydo_available_methods' => array(),
			'payment_paydo_enabled_methods' => array(),
			'payment_paydo_order_status_wait' => $this->config->get('config_order_status_id'),
			'payment_paydo_order_status_success' => $this->config->get('config_complete_status_id'),
			'payment_paydo_order_status_error' => $this->config->get('config_order_status_id')
		);

		$this->model_setting_setting->editSetting('payment_paydo', $defaults);
		$this->upgradeInvoiceTable();
	}

	public function upgrade() {
		$this->load->model('setting/setting');
		$settings = $this->model_setting_setting->getSetting('payment_paydo');
		$changed = false;

		$defaults = array(
			'payment_paydo_methods_mode' => 0,
			'payment_paydo_hosted_page_enabled' => 1,
			'payment_paydo_project_id' => '',
			'payment_paydo_jwt_token' => '',
			'payment_paydo_available_methods' => array(),
			'payment_paydo_enabled_methods' => array()
		);

		foreach ($defaults as $key => $value) {
			if (!array_key_exists($key, $settings)) {
				$settings[$key] = $value;
				$changed = true;
			}
		}

		if ($changed) {
			$this->model_setting_setting->editSetting('payment_paydo', $settings);
		}

		$this->upgradeInvoiceTable();
	}

	public function uninstall() {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "paydo_invoice`");
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('payment_paydo');
	}

	public function getInvoiceRecords($limit = 100) {
		$this->upgradeInvoiceTable();
		$limit = max(1, min(500, (int)$limit));

		$query = $this->db->query("SELECT `order_id`, `invoice_id`, `payment_method`, `txid`, `amount`, `currency`,
			`invoice_status`, `transaction_state`, `retry_count`, `last_error`, `date_added`, `date_modified`
			FROM `" . DB_PREFIX . "paydo_invoice`
			ORDER BY `date_modified` DESC LIMIT " . $limit);

		return isset($query->rows) && is_array($query->rows) ? $query->rows : array();
	}

	public function upgradeInvoiceTable() {
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

	}
}
