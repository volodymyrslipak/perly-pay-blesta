<?php

/**
 * Class loading without Composer on the Blesta server: the gateway's own
 * classes under PerlyPay\Blesta\ (lib/), and Perly Pay's PHP SDK, copied
 * in at build time under its own prefix (PerlyPay\Blesta\Vendor\Perly\Pay\,
 * in vendor/perly-pay-sdk) so it can never clash with another extension
 * that ships the SDK too.
 */

if (!defined('DS')) {
    exit;
}

if (!defined('PERLYPAY_BLESTA_VERSION')) {
    define('PERLYPAY_BLESTA_VERSION', '1.0.0');

    spl_autoload_register(static function (string $class): void {
        $roots = [
            'PerlyPay\\Blesta\\Vendor\\Perly\\Pay\\' => __DIR__ . '/vendor/perly-pay-sdk/src/',
            'PerlyPay\\Blesta\\' => __DIR__ . '/lib/',
        ];
        foreach ($roots as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }

            return;
        }
    });
}
