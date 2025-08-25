<?php
/**
 * LiqPay payment gateway module for FOSSBilling (https://fossbilling.org/)
 *
 * Written in 2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * Includes code from Liqpay Payment Module
 * @copyright       Copyright (c) 2014 Liqpay (https://github.com/liqpay/sdk-php)
 * @license         http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 *
 * Includes code from FOSSBilling default modules
 * Copyright 2022-2024 FOSSBilling (https://www.fossbilling.org)
 * Copyright 2011-2021 BoxBilling, Inc.
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

class Payment_Adapter_Liqpay implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    private $_api_url = 'https://www.liqpay.ua/api/';
    private $_checkout_url = 'https://www.liqpay.ua/api/3/checkout';
    protected $_supportedCurrencies = array(
        'EUR', 'USD', 'UAH');
    protected $_supportedLangs = ['uk', 'en'];
    private $_server_response_code = null;

    protected $_button_translations = array(
        'uk' => 'Сплатити',
        'en' => 'Pay'
    );
    protected $_actions = array(
        "pay", "hold", "subscribe", "paydonate"
    );
    public $curlRequester;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if ($this->config['test_mode']) {
            if (!isset($this->config['test_priv_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Liqpay', ':missing' => 'Sandbox Private key'], 4001);
            }
            if (!isset($this->config['test_pub_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Liqpay', ':missing' => 'Sandbox Public key'], 4001);
            }
        } else {
            if (!isset($this->config['priv_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Liqpay', ':missing' => 'Live Private key'], 4001);
            }
            if (!isset($this->config['pub_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Liqpay', ':missing' => 'Live Public key'], 4001);
            }
        }
        if (!isset($this->config['language']) || !in_array($this->config['language'], ['uk', 'en'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. The language must be "uk" for Ukrainian or "en" for English.', [':pay_gateway' => 'Liqpay'], 4001);
        }

        $this->curlRequester = new CurlRequester();
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'You authenticate to the Liqpay API by providing one of your API keys in the request. You can manage your API keys from your account.',
            'logo' => [
                'logo' => 'Liqpay/Liqpay.png',
                'height' => '25px',
                'width' => '100px',
            ],
            'form' => [
                'pub_key' => [
                    'text', [
                        'label' => 'Live Public key:',
                    ],
                ],
                'priv_key' => [
                    'text', [
                        'label' => 'Live Private key:',
                    ],
                ],
                'test_pub_key' => [
                    'text', [
                        'label' => 'Sandbox Public key:',
                        'required' => false,
                    ],
                ],
                'test_priv_key' => [
                    'text', [
                        'label' => 'Sandbox Private key:',
                        'required' => false,
                    ],
                ],
                'language' => [
                    'text', [
                        'label' => 'Language (uk for Ukrainian, en for English only):',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    public function getInvoiceTitle(Model_Invoice $invoice)
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', [':invoice_id' => $invoice->id]);

        $params = [
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'],
        ];
        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if ((is_countable($invoiceItems) ? count($invoiceItems) : 0) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        return $title;
    }

    public function logError($e, Model_Transaction $tx)
    {
        $body = $e->getJsonBody();
        $err = $body['error'];
        $tx->txn_status = $err['type'];
        $tx->error = $err['message'];
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if (DEBUG) {
            error_log(json_encode($e->getJsonBody()));
        }

        throw new Exception($tx->error);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $privKey = ($this->config['test_mode']) ? $this->config['test_priv_key'] : $this->config['priv_key'];

        $sign = base64_encode( sha1( 
        $privKey .  
        $data['post']['data'] . 
        $privKey 
        , 1 ));

        $decodedData = base64_decode($data['post']['data']);
        $charge = json_decode($decodedData, true);

        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        $order_id = $charge['order_id'];
        $invoice_id = explode('.', $order_id)[0]; // Extracts the part before the dot
        
        if ($data['post']['signature'] !== $sign) {
            $this->logError('The signature does not match. Payment verification failed.', $tx);
            throw new FOSSBilling\Exception('The signature does not match. Payment verification failed.');
        }

        $tx->invoice_id = $invoice_id;
        $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
        
        if (isset($charge['status']) && $charge['status'] === 'success' && $tx->status !== 'processed') {
            $tx->txn_status = $charge['status'];
            $tx->txn_id = $charge['liqpay_order_id'];
            $tx->amount = $charge['amount'];
            $tx->currency = $charge['currency'];
            $tx->type = 'Payment';

            $bd = [
                'amount' => $tx->amount,
                'description' => 'Liqpay transaction ' . $charge['liqpay_order_id'],
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ];

            // Instance the services we need
            $clientService = $this->di['mod_service']('client');
            $invoiceService = $this->di['mod_service']('Invoice');

            // Update the account funds
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

            // Now pay the invoice / batch pay if there's no invoice associated with the transaction
            if ($tx->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            } else {
                $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
            }
        } else {
            $this->logError('Payment failed or status is missing.', $tx);
            throw new FOSSBilling\Exception('There was an error when processing the transaction');
        }
        
        $paymentStatus = match ($charge['status']) {
            'success' => 'succeeded',
            'processing' => 'pending',
            'error', 'failure' => 'failed',
            default => 'pending',
        };

        $tx->status = $paymentStatus;
        $tx->updated_at = date('Y-m-d H:i:s');
        $tx->ip = $charge['ip'];
        $this->di['db']->store($tx);
    }
    
    protected function _generateForm(Model_Invoice $invoice): string
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $params = array();

        $params['language'] = $this->config['language'];
        $params['public_key'] = ($this->config['test_mode']) ? $this->config['test_pub_key'] : $this->config['pub_key'];
        $params['private_key'] = ($this->config['test_mode']) ? $this->config['test_priv_key'] : $this->config['priv_key'];
        $params['amount'] = $invoiceService->getTotalWithTax($invoice);
        $params['action'] = 'pay';
        $params['description'] = $this->getInvoiceTitle($invoice);
        $params['version'] = '3';
        $params['server_url'] = $this->config['notify_url'];
        $params['result_url'] = $this->config['return_url'];
        $params['order_id'] = $invoice->id . '.' . uniqid('', true);

        if (!isset($invoice->currency)) {
            throw new InvalidArgumentException('currency is null');
        }
        if (!in_array($invoice->currency, $this->_supportedCurrencies)) {
            throw new InvalidArgumentException('currency is not supported');
        }
        $params['currency'] = $invoice->currency;

        $data = $this->encode_params($params);
        $signature = $this->cnb_signature($params);

        $form = sprintf('
            <form method="POST" action="%s" accept-charset="utf-8">
                %s
                %s
                <script type="text/javascript" src="https://static.liqpay.ua/libjs/sdk_button.js"></script>
                <sdk-button label="%s" background="#77CC5D" onClick="submit()"></sdk-button>
            </form>
            ',
            $this->_checkout_url,
            sprintf('<input type="hidden" name="%s" value="%s" />', 'data', $data),
            sprintf('<input type="hidden" name="%s" value="%s" />', 'signature', $signature),
            $this->_button_translations[$params['language']]
        );

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Liqpay"');
        $bindings = [
            ':pub_key' => $params['public_key'],
            ':intent_secret' => $params['private_key'],
            ':amount' => $params['amount'],
            ':currency' => $invoice->currency,
            ':description' => $params['description'],
            ':buyer_email' => $invoice->buyer_email,
            ':buyer_name' => trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name),
            ':callbackUrl' => $payGatewayService->getCallbackUrl($payGateway, $invoice),
            ':redirectUrl' => $this->di['tools']->url('invoice/' . $invoice->hash),
            ':invoice_hash' => $invoice->hash,
        ];

        return strtr($form, $bindings);
    }

    /**
     * Call API
     *
     * @param string $path
     * @param array $params
     * @param int $timeout
     *
     * @return array|null
     */
    public function api($path, $params = array(), $timeout = 5)
    {
        $url = $this->_api_url . $path;
        $private_key = ($this->config['test_mode']) ? $this->config['test_priv_key'] : $this->config['priv_key'];
        $data = $this->encode_params($params);
        $signature = $this->str_to_sign($private_key . $data . $private_key);
        $postfields = http_build_query(array(
            'data' => $data,
            'signature' => $signature
        ));

        $server_output = $this->curlRequester->make_curl_request($url, $postfields, $timeout);
        if ($server_output == NULL) {
            return array('error' => 'Invalid URL or connection timeout');
        }
        return json_decode($server_output);
    }

    /**
     * Return last api response http code
     *
     * @return string|null
     */
    public function get_response_code()
    {
        return $this->_server_response_code;
    }

    /**
     * cnb_signature
     *
     * @param array $params
     *
     * @return string
     */
    public function cnb_signature($params)
    {
        $private_key = ($this->config['test_mode']) ? $this->config['test_priv_key'] : $this->config['priv_key'];

        $json = $this->encode_params($params);
        $signature = $this->str_to_sign($private_key . $json . $private_key);

        return $signature;
    }

    /**
     * encode_params
     *
     * @param array $params
     * @return string
     */
    protected function encode_params($params)
    {
        return base64_encode(json_encode($params));
    }

    /**
     * decode_params
     *
     * @param string $params
     * @return array
     */
    public function decode_params($params)
    {
        return json_decode(base64_decode($params), true);
    }

    /**
     * str_to_sign
     *
     * @param string $str
     *
     * @return string
     */
    public function str_to_sign($str)
    {
        $signature = base64_encode(sha1($str, 1));

        return $signature;
    }
}

class CurlRequester
{
    /**
     * make_curl_request
     * @param $url string
     * @param $postfields string
     * @param int $timeout
     * @return bool|string
     */
    public function make_curl_request($url, $postfields, $timeout = 5) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Avoid MITM vulnerability http://phpsecurity.readthedocs.io/en/latest/Input-Validation.html#validation-of-input-sources
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // Check the existence of a common name and also verify that it matches the hostname provided
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);   // The number of seconds to wait while trying to connect
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);          // The maximum number of seconds to allow cURL functions to execute
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        $this->_server_response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($server_output === false) {
            $server_output = json_encode(array('error' => 'Invalid URL or connection timeout'));
        }
        return $server_output;
    }
}
