<?php

class Controller {
	public $config;
	public $request;
}

class PaydoTestConfig {
	private $values;

	public function __construct($values) {
		$this->values = $values;
	}

	public function get($key) {
		return array_key_exists($key, $this->values) ? $this->values[$key] : null;
	}
}

require dirname(__DIR__) . '/upload/catalog/controller/extension/payment/paydo.php';

$assertions = 0;

function assertSameValue($expected, $actual, $message) {
	global $assertions;
	$assertions++;

	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function invokePrivate($object, $method, $arguments = array()) {
	$reflection = new ReflectionMethod($object, $method);

	if (PHP_VERSION_ID < 80100) {
		$reflection->setAccessible(true);
	}

	return $reflection->invokeArgs($object, $arguments);
}

function controllerWith($config, $post = array()) {
	$controller = new ControllerExtensionPaymentPaydo();
	$controller->config = new PaydoTestConfig($config);
	$controller->request = (object)array('post' => $post);

	return $controller;
}

$available = array(
	'204' => array('identifier' => '204', 'title' => 'Internet acquiring', 'logo' => 'https://example.test/card.svg'),
	'310' => array('identifier' => '310', 'title' => 'Bank transfer', 'logo' => '')
);

$off = controllerWith(array(
	'payment_paydo_methods_mode' => 0,
	'payment_paydo_hosted_page_enabled' => 0,
	'payment_paydo_available_methods' => $available,
	'payment_paydo_enabled_methods' => array('204')
), array('paydo_method' => '999'));
assertSameValue('hosted', invokePrivate($off, 'getRequestedPaymentMethod'), 'Mode off ignores a spoofed checkout method.');

$hosted = controllerWith(array(
	'payment_paydo_methods_mode' => 1,
	'payment_paydo_hosted_page_enabled' => 1,
	'payment_paydo_available_methods' => $available,
	'payment_paydo_enabled_methods' => array('204')
), array('paydo_method' => 'hosted'));
assertSameValue('hosted', invokePrivate($hosted, 'getRequestedPaymentMethod'), 'Hosted option is accepted when enabled.');

$hostedDisabled = controllerWith(array(
	'payment_paydo_methods_mode' => 1,
	'payment_paydo_hosted_page_enabled' => 0,
	'payment_paydo_available_methods' => $available,
	'payment_paydo_enabled_methods' => array('204')
), array('paydo_method' => 'hosted'));
assertSameValue(false, invokePrivate($hostedDisabled, 'getRequestedPaymentMethod'), 'Hosted option is rejected when disabled.');

$direct = controllerWith(array(
	'payment_paydo_methods_mode' => 1,
	'payment_paydo_hosted_page_enabled' => 1,
	'payment_paydo_available_methods' => $available,
	'payment_paydo_enabled_methods' => array('204')
), array('paydo_method' => '204'));
assertSameValue('204', invokePrivate($direct, 'getRequestedPaymentMethod'), 'Allowlisted numeric method is accepted.');

$spoofed = controllerWith(array(
	'payment_paydo_methods_mode' => 1,
	'payment_paydo_hosted_page_enabled' => 1,
	'payment_paydo_available_methods' => $available,
	'payment_paydo_enabled_methods' => array('204')
), array('paydo_method' => '310'));
assertSameValue(false, invokePrivate($spoofed, 'getRequestedPaymentMethod'), 'Available but disabled method is rejected.');

$baseRequest = array('publicKey' => 'public', 'paymentMethod' => 999);
$hostedRequest = invokePrivate($direct, 'addPaymentMethodToRequest', array($baseRequest, 'hosted'));
assertSameValue(false, array_key_exists('paymentMethod', $hostedRequest), 'Hosted payload omits paymentMethod completely.');

$directRequest = invokePrivate($direct, 'addPaymentMethodToRequest', array($baseRequest, '204'));
assertSameValue(204, $directRequest['paymentMethod'], 'Direct payload contains an integer paymentMethod.');

$invalidRequest = invokePrivate($direct, 'addPaymentMethodToRequest', array($baseRequest, '204x'));
assertSameValue(false, array_key_exists('paymentMethod', $invalidRequest), 'Invalid identifier cannot enter the payload.');

$signatureController = controllerWith(array('payment_paydo_secret_key' => 'secret'));
$order = array('id' => '123', 'amount' => '1.00', 'currency' => 'EUR');
assertSameValue(hash('sha256', '1.00:EUR:123:secret'), invokePrivate($signatureController, 'generate_order_signature', array($order)), 'Signature uses the exact JSON amount string.');

$controllerSource = file_get_contents(dirname(__DIR__) . '/upload/catalog/controller/extension/payment/paydo.php');
$adminSource = file_get_contents(dirname(__DIR__) . '/upload/admin/controller/extension/payment/paydo.php');
$adminModelSource = file_get_contents(dirname(__DIR__) . '/upload/admin/model/extension/payment/paydo.php');
assertSameValue(true, strpos($controllerSource, "'/invoices/create'") !== false, 'Invoice creation uses the shared v3 API base.');
assertSameValue(true, strpos($controllerSource . $adminSource, 'api.paydo.com/' . 'v1') === false, 'No obsolete PayDo v1 API endpoint remains.');
assertSameValue(true, strpos($adminSource, '/v3/instrument-settings/payment-methods/available-for-application/') !== false, 'Method sync uses the required v3 endpoint.');
assertSameValue(true, strpos($adminModelSource, "'payment_paydo_hosted_page_enabled' => 1") !== false, 'Hosted Page is enabled by default during upgrade.');
assertSameValue(true, strpos($controllerSource, 'CURLOPT_SSL_VERIFYPEER, true') !== false && strpos($adminSource, 'CURLOPT_SSL_VERIFYPEER, true') !== false, 'TLS certificate verification stays enabled.');

$orderInfo = array('order_id' => 123, 'total' => '1.00', 'currency_code' => 'EUR');
$stored = array('invoice_id' => 'invoice-123', 'amount' => '1.0000', 'currency' => 'EUR', 'txid' => '');
$remote = array('identifier' => 'invoice-123', 'orderIdentifier' => '123', 'amount' => '1.00', 'currency' => 'EUR', 'transactionIdentifier' => 'card-tx');
assertSameValue(true, invokePrivate($direct, 'isVerifiedInvoice', array($remote, $stored, $orderInfo, 'invoice-123', 'card-tx')), 'Matching card invoice is verified.');
foreach (array('identifier' => 'other-invoice', 'orderIdentifier' => '124', 'amount' => '2.00', 'currency' => 'USD', 'transactionIdentifier' => 'other-tx') as $key => $badValue) {
	$bad = $remote;
	$bad[$key] = $badValue;
	assertSameValue(false, invokePrivate($direct, 'isVerifiedInvoice', array($bad, $stored, $orderInfo, 'invoice-123', 'card-tx')), 'Reject mismatched ' . $key);
}
$cardCallback = array('invoice' => array('id' => 'invoice-123'), 'transaction' => array('id' => 'card-tx', 'state' => 2, 'order' => array('id' => '123')));
assertSameValue(true, invokePrivate($direct, 'isValidCallbackStructure', array($cardCallback)), 'Card callback structure is supported.');
assertSameValue('card-tx', invokePrivate($direct, 'extractCallbackTxid', array($cardCallback)), 'Card transaction.id is extracted.');
$normalCallback = array('invoice' => array('id' => 'invoice-123', 'txid' => 'normal-tx'), 'transaction' => array('state' => 2, 'order' => array('id' => '123')));
assertSameValue('normal-tx', invokePrivate($direct, 'extractCallbackTxid', array($normalCallback)), 'Ordinary invoice.txid is preserved.');
$paid = $stored + array('invoice_status' => 1);
assertSameValue('paid', invokePrivate($direct, 'verifyAndApply', array($paid, $orderInfo, 3, 'card-tx', true)), 'A delayed failure callback cannot downgrade a paid invoice.');
assertSameValue(false, invokePrivate($direct, 'canReuseInvoice', array($stored + array('invoice_status' => 3))), 'Failed invoice is not reused even without transaction state.');
assertSameValue(true, invokePrivate($direct, 'matchesInvoiceMethod', array($stored + array('payment_method' => '204'), '204')), 'Same method can reuse invoice.');
assertSameValue(false, invokePrivate($direct, 'matchesInvoiceMethod', array($stored + array('payment_method' => '204'), '310')), 'Cannot silently reuse invoice for a different method.');

class PaydoFixtureController extends ControllerExtensionPaymentPaydo {
	public $fixture;
	public $model_extension_payment_paydo;
	public $model_checkout_order;
	protected function sendApiRequest($url, $method, $payload = '') {
		return array('data' => $this->fixture);
	}
}
class PaydoFixtureInvoices {
	public $updates = array();
	public $cleared = false;
	public function updateInvoice($order, $invoice, $txid, $status, $state) { $this->updates[] = array($txid, $status, $state); }
	public function clearRetry($order) { $this->cleared = true; }
	public function getInvoiceByOrderId($order) { return array('retry_count' => 0); }
	public function scheduleRetry($order, $reason, $delay, $max) { return 1; }
}
class PaydoFixtureOrders {
	public $statuses = array();
	public function addOrderHistory($id, $status) { $this->statuses[] = $status; }
}
function verificationFixture($invoice) {
	$c = new PaydoFixtureController();
	$c->config = new PaydoTestConfig(array('payment_paydo_order_status_success' => 5, 'payment_paydo_order_status_error' => 10));
	$c->fixture = $invoice;
	$c->model_extension_payment_paydo = new PaydoFixtureInvoices();
	$c->model_checkout_order = new PaydoFixtureOrders();
	return $c;
}
$orderInfo['order_status_id'] = 1;
$c = verificationFixture($remote + array('status' => 1, 'transactionState' => 1));
assertSameValue('paid', invokePrivate($c, 'verifyAndApply', array($stored, $orderInfo, 3, 'card-tx', true)), 'Verified paid invoice wins over pending API transaction and failure callback.');
assertSameValue(array(5), $c->model_checkout_order->statuses, 'Verified payment applies success order status.');
$c = verificationFixture($remote + array('status' => 0));
assertSameValue('pending', invokePrivate($c, 'verifyAndApply', array($stored, $orderInfo, 3, 'card-tx', true)), 'Unverified failure callback keeps unpaid invoice pending.');
assertSameValue(array(), $c->model_checkout_order->statuses, 'Callback-only failure does not change order status.');
$c = verificationFixture($remote + array('status' => 3));
assertSameValue('failed', invokePrivate($c, 'verifyAndApply', array($stored, $orderInfo, 2, 'card-tx', true)), 'API-confirmed failure overrides success callback.');
assertSameValue(array(10), $c->model_checkout_order->statuses, 'Confirmed failure applies failed order status.');
$c = verificationFixture(array_diff_key($remote, array('transactionIdentifier' => true)) + array('status' => 0));
assertSameValue('pending', invokePrivate($c, 'verifyAndApply', array($stored, $orderInfo, 2, 'untrusted-tx', true)), 'Await API transaction identifier without trusting IPN.');
assertSameValue('', $c->model_extension_payment_paydo->updates[0][0], 'Unverified callback txid is never persisted.');

fwrite(STDOUT, "OK: {$assertions} PayDo logic assertions passed.\n");
