<?php

class SslCommerzCallbackResponse
{
    public static function browser(array $result, $systemUrl)
    {
        $base = rtrim((string) $systemUrl, '/');
        $invoiceId = isset($result['invoice_id']) ? (int) $result['invoice_id'] : 0;
        $success = in_array(isset($result['code']) ? $result['code'] : '', array('paid', 'duplicate'), true);
        if ($invoiceId > 0) {
            $location = $base . '/viewinvoice.php?id=' . $invoiceId;
        } else {
            $location = $base . '/clientarea.php?action=invoices';
        }
        $location .= '&' . ($success ? 'paymentsuccess=true' : 'paymentfailed=true');

        return array('status' => 303, 'location' => $location);
    }

    public static function ipn(array $result)
    {
        $code = isset($result['code']) ? strtolower((string) $result['code']) : 'rejected';
        return array(
            'status' => $code === 'retry' ? 500 : 200,
            'body' => strtoupper($code),
        );
    }
}
