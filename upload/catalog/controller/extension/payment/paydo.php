<?php

/**
 * Class ControllerExtensionPaymentPaydo
 *
 * @property Language                           $language
 * @property \Cart\Currency                     $currency
 * @property Config                             $config
 * @property Url                                $url
 * @property Loader                             $load
 * @property Session                            $session
 * @property Request                            $request
 * @property Response                           $response
 *
 * @property ModelCheckoutOrder                 $model_checkout_order
 * @property ModelExtensionPaymentPaydo         $model_extension_payment_paydo
 */
class ControllerExtensionPaymentPaydo extends Controller {
    /** @var  resource */
    private $curl;
    public function index() {
        $this->load->language('extension/payment/paydo');
        $lang_code = $this->language->get('code');
        switch ($lang_code) {
            case 'ru':
                $widget_lang = 'ru-RU';
                break;
            default:
                $widget_lang = 'en-US';
        }
        $data = array(
            'button_pay'=> $this->language->get('button_pay'),
            'paydo_url' => $this->url->link('extension/payment/paydo/pay')
        );
        return $this->load->view('extension/payment/paydo', $data);
    }

    public function pay() {
        $this->response->addHeader('Content-Type: application/json');
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $order_products = $this->model_checkout_order->getOrderProducts($this->session->data['order_id']);
        $paydo_order_items = array();
        foreach ($order_products as $product) {
            $item = array(
                'id' => $product['order_product_id'],
                'name' => trim($product['name'] . ' ' . $product['model']),
                'price' => $product['price']
            );
            array_push($paydo_order_items, $item);
        }
        $request = array(
            'publicKey' => $this->config->get('payment_paydo_public_id'),
            'order' => array(
                'id' => $order_info['order_id'],
                'amount' => number_format($this->currency->format($order_info['total'], $order_info['currency_code'], '', false), 4, ".", ""),
                'currency' => $order_info['currency_code'],
                'description' => sprintf($this->language->get('order_description'), $order_info['order_id']),
                'items' => $paydo_order_items,
            ),
            'payer' => array(
                'email' => $order_info['email'],
                'phone' => $order_info['telephone'],
                'name' => $order_info['firstname'] . ' ' . $order_info['lastname']
            ),
            'resultUrl' => $this->url->link('checkout/success'),
            'failPath' => $this->url->link('checkout/failure'),
            'language' => $this->language->get('code')
        );
        $request['signature'] = $this->generate_signature($request['order']['id'], $request['order']['amount'], $request['order']['currency'], $this->config->get('payment_paydo_secret_key'), false);
        $this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('payment_paydo_order_status_wait'));
        $invoiceId = $this->makeRequest($request);
        if ($invoiceId === '') {
            $this->log->write('Invoice was not created');
        } else {
            $redirectUrl = "https://app.stage.paydo.com/{$this->language->get('code')}/payment/invoice-preprocessing/{$invoiceId}";
            $this->response->setOutput(json_encode($redirectUrl));
        }
    }

    public function callback() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $callback = json_decode(file_get_contents('php://input'));
            $callback = json_encode($callback);
            $callback = json_decode($callback, false);
            if (is_object($callback)) {
                if(isset($callback->invoice)) {
                    if ($this->callback_check($callback) === 'valid'){
                        $this->load->model('checkout/order');
                        if($callback->transaction->state === 2) {
                            $this->model_checkout_order->addOrderHistory($callback->transaction->order->id, $this->config->get('payment_paydo_order_status_success'));
                        } elseif ($callback->transaction->state === 3 or $callback->transaction->state === 5) {
                            $this->model_checkout_order->addOrderHistory($callback->transaction->order->id, $this->config->get('payment_paydo_order_status_error'));
                        }
                    } else {
                        $this->log->write('Error callback: '. $this->callback_check($callback));
                    }
                } else {
                    $signature = $this->generate_signature($callback->orderId, $callback->amount, $callback->currency, $this->config->get('payment_paydo_secret_key'), $callback->status);
                    if ($callback->signature == $signature) {
                        $this->load->model('checkout/order');
                        if ($callback->status === 'success') {
                            $this->model_checkout_order->addOrderHistory($callback->orderId, $this->config->get('payment_paydo_order_status_success'));
                        } else if ($callback->status === 'error') {
                            $this->model_checkout_order->addOrderHistory($callback->orderId, $this->config->get('payment_paydo_order_status_error'));
                        } else {
                            $this->log->write("Paydo Callback invalid signature\n" . print_r(file_get_contents('php://input'), true));
                        }
                    } else {
                        $this->log->write("Paydo Callback is not valid");
                    }
                }
            } else {
                $this->log->write('Error. Callback is not an object');
            }
        } else {
            $this->log->write('Invalid server request');
        }
    }

    /**
     * @param string $location
     * @param array  $request
     * @return bool|array
     */
    private function makeRequest($request = array()) {
        $request = json_encode($request);
        if (!$this->curl) {
            $this->curl = curl_init();
            curl_setopt($this->curl, CURLOPT_URL, 'https://app.stage.paydo.com/v1/invoices/create');
            curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl, CURLOPT_HEADER, true);
        }
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json',
        ));
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $request);
        $response = curl_exec($this->curl);
        if ($response === false || curl_getinfo($this->curl, CURLINFO_HTTP_CODE) != 200) {
            $this->log->write('Paydo Failed API request' . "\n" .
              ' Request: ' . print_r($request, true) . "\n" .
              ' Response: ' . print_r($response, true) . "\n" .
              ' HTTP Code: ' . curl_getinfo($this->curl, CURLINFO_HTTP_CODE) . "\n" .
              ' Error: ' . curl_error($this->curl). "\n"
            );
        }
        $header_size = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        curl_close($this->curl);
        $headers = substr($response, 0, $header_size);
        $headers = explode("\r\n", $headers);
        $invoice_identifier = preg_grep("/^identifier/", $headers);
        $invoice_identifier = implode(',' , $invoice_identifier);
        $invoice_identifier = substr($invoice_identifier, strrpos($invoice_identifier, ':')+2);
        return $invoice_identifier;
    }

    /**
     * @param object $callback
     * @return boolean
     */
    private function callback_check($callback)
    {
        $invoiceId = !empty($callback->invoice->id) ? $callback->invoice->id : null;
        $txid = !empty($callback->invoice->txid) ? $callback->invoice->txid : null;
        $orderId = !empty($callback->transaction->order->id) ? $callback->transaction->order->id : null;
        $state = !empty($callback->transaction->state) ? $callback->transaction->state : null;

        if (!$invoiceId) {
            return 'Empty invoice id';
        }
        if (!$txid) {
            return 'Empty transaction id';
        }
        if (!$orderId) {
            return 'Empty order id';
        }
        if (!(1 <= $state && $state <= 5)) {
            return 'State is not valid';
        }
        return 'valid';
    }

    private function generate_signature($orderId, $amount, $currency, $secretKey, $status)
    {
        $sign_str = ['id' => $orderId, 'amount' => $amount, 'currency' => $currency];
        ksort($sign_str, SORT_STRING);
        $sign_data = array_values($sign_str);
        if ($status) {
            array_push($sign_data, $status);
        }
        array_push($sign_data, $secretKey);
        return hash('sha256', implode(':', $sign_data));
    }
}



