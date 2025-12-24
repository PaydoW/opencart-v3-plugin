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
	}
	public function uninstall() {
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('payment_paydo');
	}
}
