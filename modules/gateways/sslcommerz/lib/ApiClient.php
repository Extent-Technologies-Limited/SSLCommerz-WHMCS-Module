<?php

require_once __DIR__ . '/Support.php';

class SslCommerzApiClient
{
    private $storeId;
    private $storePassword;
    private $testMode;
    private $transport;
    private $lastRequest = array();

    public function __construct(array $credentials, callable $transport = null)
    {
        $this->storeId = trim((string) $credentials['store_id']);
        $this->storePassword = trim((string) $credentials['store_password']);
        $this->testMode = !empty($credentials['testmode']) || !empty($credentials['sandbox']);
        $this->transport = $transport;
        if ($this->storeId === '' || $this->storePassword === '') {
            throw new InvalidArgumentException('SSLCommerz store credentials are required.');
        }
    }

    public function createSession(array $payload)
    {
        return $this->request('POST', '/gwprocess/v4/api.php', array_merge($payload, $this->credentials()));
    }

    public function validate($valId)
    {
        if (trim((string) $valId) === '') {
            throw new InvalidArgumentException('Validation ID is required.');
        }
        return $this->request('GET', '/validator/api/validationserverAPI.php', array_merge(
            array('val_id' => (string) $valId, 'v' => 1, 'format' => 'json'),
            $this->credentials()
        ));
    }

    public function query(array $selector)
    {
        $params = array();
        if (!empty($selector['sessionkey'])) {
            $params['sessionkey'] = (string) $selector['sessionkey'];
        } elseif (!empty($selector['tran_id'])) {
            $params['tran_id'] = (string) $selector['tran_id'];
        } else {
            throw new InvalidArgumentException('A transaction ID or session key is required.');
        }
        return $this->request('GET', '/validator/api/merchantTransIDvalidationAPI.php', array_merge(
            $params,
            $this->credentials(),
            array('v' => 1, 'format' => 'json')
        ));
    }

    public function refund(array $payload)
    {
        return $this->request('GET', '/validator/api/merchantTransIDvalidationAPI.php', array_merge(
            $payload,
            $this->credentials(),
            array('v' => 1, 'format' => 'json')
        ));
    }

    public function queryRefund($refundRefId)
    {
        if (trim((string) $refundRefId) === '') {
            throw new InvalidArgumentException('Refund reference ID is required.');
        }
        return $this->request('GET', '/validator/api/merchantTransIDvalidationAPI.php', array_merge(
            array('refund_ref_id' => (string) $refundRefId),
            $this->credentials(),
            array('v' => 1, 'format' => 'json')
        ));
    }

    public function lastRequest()
    {
        return $this->lastRequest;
    }

    private function credentials()
    {
        return array('store_id' => $this->storeId, 'store_passwd' => $this->storePassword);
    }

    private function request($method, $path, array $params)
    {
        $url = ($this->testMode ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com') . $path;
        $this->lastRequest = SslCommerzSupport::redact(array('method' => $method, 'url' => $url, 'params' => $params));

        try {
            $raw = $this->transport
                ? call_user_func($this->transport, $method, $url, $params)
                : $this->curlTransport($method, $url, $params);
        } catch (Throwable $error) {
            throw new RuntimeException('SSLCommerz request failed: ' . $error->getMessage(), 0, $error);
        }

        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('SSLCommerz returned malformed JSON.');
        }
        return $decoded;
    }

    private function curlTransport($method, $url, array $params)
    {
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            curl_setopt($handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            curl_setopt($handle, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        }

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException('cURL error: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status . ' from SSLCommerz.');
        }
        return $body;
    }
}
