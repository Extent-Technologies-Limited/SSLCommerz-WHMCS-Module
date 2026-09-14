<?php

class SslCommerzSupport
{
    private static $allowedGatewayHosts = array(
        'sandbox.sslcommerz.com',
        'securepay.sslcommerz.com',
        'seamless-epay.sslcommerz.com',
        'pay.sslcommerz.com',
        'epay-gw.sslcommerz.com',
        'dev-epay-gw.sslcommerz.com',
    );

    public static function checkoutMode($value)
    {
        $normalized = strtolower(trim((string) $value));
        $map = array(
            'legacy_popup' => 'legacy_popup',
            'legacy easycheckout popup' => 'legacy_popup',
            'easycheckout popup' => 'legacy_popup',
            'legacy_hosted' => 'legacy_hosted',
            'legacy hosted redirect' => 'legacy_hosted',
            'legacy (hosted redirect)' => 'legacy_hosted',
            'new_hosted' => 'new_hosted',
            'new hosted checkout' => 'new_hosted',
            'new checkout popup' => 'new_hosted',
        );
        return isset($map[$normalized]) ? $map[$normalized] : 'legacy_popup';
    }

    public static function transactionId($invoiceId, $randomHex = null)
    {
        $invoice = preg_replace('/[^0-9]/', '', (string) $invoiceId);
        if ($invoice === '') {
            throw new InvalidArgumentException('A numeric invoice ID is required.');
        }

        $random = $randomHex === null ? bin2hex(random_bytes(6)) : strtolower((string) $randomHex);
        $random = preg_replace('/[^a-f0-9]/', '', $random);
        if ($random === '') {
            throw new InvalidArgumentException('Transaction entropy is required.');
        }

        $prefix = 'INV' . $invoice . '-';
        return $prefix . substr($random, 0, max(1, 30 - strlen($prefix)));
    }

    public static function refundTransactionId($invoiceId, $randomHex = null)
    {
        $invoice = preg_replace('/[^0-9]/', '', (string) $invoiceId);
        $random = $randomHex === null ? bin2hex(random_bytes(6)) : preg_replace('/[^a-f0-9]/', '', strtolower((string) $randomHex));
        $prefix = 'REF' . $invoice . '-';
        return $prefix . substr($random, 0, max(1, 30 - strlen($prefix)));
    }

    public static function checkoutToken($invoiceId, $expires, $storePassword)
    {
        return hash_hmac('sha256', (int) $invoiceId . '|' . (int) $expires, (string) $storePassword);
    }

    public static function checkoutTokenMatches($invoiceId, $expires, $token, $storePassword, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $expires = (int) $expires;
        if ($expires < $now || $expires > $now + 3600) {
            return false;
        }
        return hash_equals(self::checkoutToken($invoiceId, $expires, $storePassword), (string) $token);
    }

    public static function redact(array $data)
    {
        $redacted = array();
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);
            if (preg_match('/pass|password|secret|authorization|api[_-]?key/', $normalized)
                || $normalized === 'token' || substr($normalized, -6) === '_token') {
                $redacted[$key] = '[REDACTED]';
            } elseif ($normalized === 'card_no' && is_scalar($value)) {
                $digits = preg_replace('/\D/', '', (string) $value);
                $redacted[$key] = strlen($digits) >= 4 ? '****' . substr($digits, -4) : '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = self::redact($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }

    public static function signatureMatches(array $payload, $storePassword)
    {
        if (empty($payload['verify_sign']) || empty($payload['verify_key'])) {
            return null;
        }

        $fields = array();
        foreach (explode(',', (string) $payload['verify_key']) as $field) {
            $field = trim($field);
            if ($field !== '' && array_key_exists($field, $payload)) {
                $fields[$field] = (string) $payload[$field];
            }
        }
        $fields['store_passwd'] = md5((string) $storePassword);
        ksort($fields);

        $parts = array();
        foreach ($fields as $field => $value) {
            $parts[] = $field . '=' . $value;
        }

        return hash_equals(strtolower((string) $payload['verify_sign']), md5(implode('&', $parts)));
    }

    public static function safeGatewayUrl($url)
    {
        $parts = parse_url((string) $url);
        if (!is_array($parts) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https') {
            return false;
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower(isset($parts['host']) ? rtrim($parts['host'], '.') : '');
        return in_array($host, self::$allowedGatewayHosts, true);
    }

    public static function newCheckoutUrl($url, $testMode)
    {
        if (!self::safeGatewayUrl($url)) {
            throw new InvalidArgumentException('SSLCommerz returned an unsafe gateway URL.');
        }
        if ($testMode) {
            return $url;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        if (in_array($host, array('securepay.sslcommerz.com', 'seamless-epay.sslcommerz.com', 'epay-gw.sslcommerz.com'), true)) {
            $parts['host'] = 'pay.sslcommerz.com';
        }
        return self::buildUrl($parts);
    }

    private static function buildUrl(array $parts)
    {
        $url = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $url .= ':' . (int) $parts['port'];
        }
        $url .= isset($parts['path']) ? $parts['path'] : '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }
}
