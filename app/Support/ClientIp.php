<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The visitor's address. The site sits behind Cloudflare and the app does
 * not trust proxies globally, so $request->ip() is a Cloudflare edge shared
 * by many visitors. CF-Connecting-IP is believed only when the connection
 * really comes from Cloudflare, so a direct request to the origin cannot
 * spoof it.
 */
class ClientIp
{
    // https://www.cloudflare.com/ips/
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function of(Request $request): string
    {
        $peer = (string)$request->server('REMOTE_ADDR');
        $forwarded = (string)$request->header('CF-Connecting-IP');

        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) && IpUtils::checkIp($peer, self::CLOUDFLARE_RANGES)) {
            return $forwarded;
        }

        return $peer;
    }
}
