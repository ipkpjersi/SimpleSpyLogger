<?php

namespace App\Helpers;

if (! function_exists('get_client_ip_address')) {
    function get_client_ip_address()
    {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
            $_SERVER['HTTP_CLIENT_IP'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        $client = @$_SERVER['HTTP_CLIENT_IP'];
        $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
        $remote = $_SERVER['REMOTE_ADDR'];
        if (filter_var($client, FILTER_VALIDATE_IP)) {
            $clientIp = $client;
        } elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
            $clientIp = $forward;
        } else {
            $clientIp = $remote;
        }

        return $clientIp;
    }
}

if (! function_exists('rot19')) {
    function rot19($string)
    {
        $result = '';
        foreach (str_split($string) as $char) {
            $ascii = ord($char);
            if ($ascii >= ord('a') && $ascii <= ord('z')) {
                $result .= chr((($ascii - ord('a') + 19) % 26) + ord('a'));
            } elseif ($ascii >= ord('A') && $ascii <= ord('Z')) {
                $result .= chr((($ascii - ord('A') + 19) % 26) + ord('A'));
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}

if (! function_exists('safe_json_encode')) {
    function safe_json_encode($data)
    {
        if (empty($data) || in_array($data, ['[]', '{}', 'null', 'NULL', ''])) {
            return null;
        }
        $encodedData = json_encode($data);
        if (in_array($encodedData, ['[]', '{}', '""', 'null', 'NULL'])) {
            return null;
        }

        return $encodedData;
    }
}

if (! function_exists('get_base_url')) {
    function get_base_url()
    {
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? null;

        return $port && ! in_array($port, [80, 443]) ? "$scheme://$host:$port" : "$scheme://$host";
    }
}
