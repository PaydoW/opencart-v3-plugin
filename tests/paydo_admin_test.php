<?php
class Controller {}
require dirname(__DIR__) . '/upload/admin/controller/extension/payment/paydo.php';
$controller = new ControllerExtensionPaymentPaydo();
$method = new ReflectionMethod($controller, 'normalizeAvailableMethods');
if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
$card = array('identifier' => 204, 'title' => 'Cards', 'isEnabled' => true, 'logo' => 'https://example.test/card.svg');
$bank = array('identifier' => 310, 'title' => 'Bank', 'isEnabled' => true, 'logo' => 'javascript:alert(1)');
$rows = array($card, array('paymentMethod' => $bank), array('identifier' => 999, 'title' => 'Disabled', 'isEnabled' => false), 'invalid', array('identifier' => '204x', 'title' => 'Invalid', 'isEnabled' => true));
$result = $method->invoke($controller, $rows);
if (array_keys($result) !== array(204, 310) || $result[204]['title'] !== 'Cards' || $result[310]['logo'] !== '') {
	fwrite(STDERR, "FAIL: method synchronization must support both API formats and filter invalid methods/logos.\n");
	exit(1);
}
fwrite(STDOUT, "OK: admin method synchronization fixtures passed.\n");
