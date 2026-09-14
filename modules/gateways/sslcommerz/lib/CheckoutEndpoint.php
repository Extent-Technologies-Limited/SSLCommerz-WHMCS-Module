<?php

require_once __DIR__ . '/Support.php';

class SslCommerzCheckoutEndpoint
{
    public static function popup(array $request, array $gateway, $service, $now = null)
    {
        $request = self::normalizePopupRequest($request);
        if (SslCommerzSupport::checkoutMode(isset($gateway['ui_mode']) ? $gateway['ui_mode'] : '') !== 'legacy_popup'
            || !empty($request['_popup_payload_invalid'])
            || !self::authorized($request, $gateway, $now)) {
            return array('http_status' => 403, 'body' => array('status' => 'FAILED', 'data' => null, 'message' => 'Checkout request expired. Reload the invoice and try again.'));
        }
        try {
            $session = $service->initiate((int) $request['invoice_id']);
            return array('http_status' => 200, 'body' => array(
                'status' => 'success', 'data' => $session['gateway_url'],
                'logo' => isset($session['store_logo']) ? $session['store_logo'] : '',
            ));
        } catch (Throwable $error) {
            $diagnostic = self::diagnostic($error, $gateway, $service);
            error_log('[SSLCommerz Checkout] ' . $diagnostic['message']);
            return array(
                'http_status' => 502,
                'body' => array('status' => 'FAILED', 'data' => null, 'message' => 'Unable to start payment. Please try again or contact support.'),
                'diagnostic' => $diagnostic,
            );
        }
    }

    public static function hosted(array $request, array $gateway, $service, $now = null)
    {
        $mode = SslCommerzSupport::checkoutMode(isset($gateway['ui_mode']) ? $gateway['ui_mode'] : '');
        if (!in_array($mode, array('legacy_hosted', 'new_hosted'), true)
            || !self::authorized($request, $gateway, $now)) {
            return array('http_status' => 403, 'error' => 'Checkout request expired. Reload the invoice and try again.');
        }
        try {
            $session = $service->initiate((int) $request['invoice_id']);
            $location = $mode === 'new_hosted'
                ? SslCommerzSupport::newCheckoutUrl($session['gateway_url'], isset($gateway['testmode']) && $gateway['testmode'] === 'on')
                : $session['gateway_url'];
            return array('http_status' => 303, 'location' => $location);
        } catch (Throwable $error) {
            $diagnostic = self::diagnostic($error, $gateway, $service);
            error_log('[SSLCommerz Checkout] ' . $diagnostic['message']);
            return array(
                'http_status' => 502,
                'error' => 'Unable to start payment. Please try again or contact support.',
                'diagnostic' => $diagnostic,
            );
        }
    }

    private static function diagnostic(Throwable $error, array $gateway, $service)
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$error->getMessage());
        $password = isset($gateway['store_password']) ? (string)$gateway['store_password'] : '';
        if ($password !== '') { $message = str_replace($password, '[REDACTED]', $message); }
        $diagnostic = array(
            'type' => get_class($error),
            'message' => substr(trim($message), 0, 1000),
        );
        if (is_object($service) && method_exists($service, 'lastDiagnostic')) {
            $context = $service->lastDiagnostic();
            if (is_array($context) && $context) { $diagnostic['context'] = SslCommerzSupport::redact($context); }
        }
        return $diagnostic;
    }

    private static function authorized(array $request, array $gateway, $now)
    {
        return isset($request['invoice_id'], $request['expires'], $request['checkout_token'])
            && (int) $request['invoice_id'] > 0
            && SslCommerzSupport::checkoutTokenMatches(
                (int) $request['invoice_id'], (int) $request['expires'], (string) $request['checkout_token'],
                isset($gateway['store_password']) ? (string) $gateway['store_password'] : '', $now
            );
    }

    private static function normalizePopupRequest(array $request)
    {
        $wrapperSeen = false;
        $wrapperInvalid = false;
        foreach (array('postdata', 'cart_json') as $wrapper) {
            if (!array_key_exists($wrapper, $request) || $request[$wrapper] === '' || $request[$wrapper] === null) {
                continue;
            }
            $wrapperSeen = true;
            $postData = is_array($request[$wrapper])
                ? $request[$wrapper]
                : json_decode(html_entity_decode((string) $request[$wrapper], ENT_QUOTES, 'UTF-8'), true);
            if (!is_array($postData)
                || !isset($postData['invoice_id'], $postData['expires'], $postData['checkout_token'])) {
                $wrapperInvalid = true;
                continue;
            }
            $request = array_merge($request, $postData);
        }
        $request['_popup_payload_invalid'] = $wrapperInvalid;
        if (!$wrapperSeen && empty($request['invoice_id']) && !empty($request['order'])) {
            $request['invoice_id'] = $request['order'];
        }
        if (!$wrapperSeen && empty($request['checkout_token']) && !empty($request['token'])) {
            $request['checkout_token'] = $request['token'];
        }
        return $request;
    }
}
