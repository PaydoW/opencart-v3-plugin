<?php

/**
 * Class ModelExtensionPaymentPaydo
 *
 * @property Loader              $load
 * @property ModelSettingSetting $model_setting_setting
 * @property DB\MySQLi           $db
 */
class ModelExtensionPaymentPaydo extends Model {

	/**
	 *
	 */
	public function install() {

		$this->db->query('CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'paydo_transactions` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`txid` varchar(50),
			`status` varchar(50),
			`publicKey` varchar(50),
			`type` varchar(50),
			`amount` decimal(15,4),
			`currency` varchar(50),
			`signature` varchar(50),
			`language` varchar(50),
			`date` datetime,
			`orderId` varchar(50),
			`email` varchar(50),
			`paydoId` varchar(50),
			`error` text,
			PRIMARY KEY (`id`),
			INDEX `txid` (`txid`)
        )');

		$defaults['payment_paydo_sort_order'] = 0;
		$defaults['payment_paydo_order_status_wait'] = $this->config->get('config_order_status_id');
		$defaults['payment_paydo_order_status_success'] = $this->config->get('config_order_status_id');
		$defaults['payment_paydo_order_status_error'] = $this->config->get('config_order_status_id');		

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('payment_paydo', $defaults);
	}

	/**
	 *
	 */
	public function uninstall() {
	}	
}
