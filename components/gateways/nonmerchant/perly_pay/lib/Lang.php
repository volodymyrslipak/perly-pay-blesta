<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

if (!defined('DS')) {
    exit;
}

/**
 * The module's words, through Blesta's own Language class: the gateway's
 * constructor loads language/<code>/perly_pay.php (en_us and uk_ua here;
 * Blesta falls back to en_us), and every string is
 * Language::_('PerlyPay.…', true, ...$values), filled with sprintf.
 *
 * Only what clients and admins read on Blesta's pages is translated. The
 * gateway log and refund messages stay in English, like Blesta's own.
 *
 *   https://docs.blesta.com/developers/translating-blesta
 */
final class Lang
{
    public static function t(string $key, string ...$values): string
    {
        $text = \Language::_($key, true, ...$values);

        return is_string($text) && $text !== '' ? $text : $key;
    }
}
