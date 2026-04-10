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
		$defaults['payment_paydo_sort_order'] = 0;
		$defaults['payment_paydo_order_status_wait'] = $this->config->get('config_order_status_id'); // Pending
		$defaults['payment_paydo_order_status_success'] = $this->config->get('config_complete_status_id'); 
		$defaults['payment_paydo_order_status_error'] = $this->config->get('config_order_status_id');

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('payment_paydo', $defaults);
		$this->createInvoiceTable();
	}

	public function uninstall() {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "paydo_invoice`");
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('payment_paydo');
	}

	private function createInvoiceTable() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paydo_invoice` (
			`order_id` INT(11) NOT NULL,
			`invoice_id` VARCHAR(64) NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`order_id`),
			UNIQUE KEY `invoice_id` (`invoice_id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
	}
}
