<?php

class SslCommerzLookupPageState
{
    public static function screen(array $request)
    {
        if (isset($request['view_detail'])) { return 'detail'; }
        if (isset($request['search_txn'])) { return 'search'; }
        if (isset($request['query_refund'])) { return 'refund'; }
        return 'home';
    }

    public static function recentLimit($value)
    {
        $limit = (int)$value;
        return in_array($limit, array(10, 20, 50, 100), true) ? $limit : 20;
    }
}
