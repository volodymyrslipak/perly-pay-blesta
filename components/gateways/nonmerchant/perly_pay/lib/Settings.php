<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

use PerlyPay\Blesta\Vendor\Perly\Pay\Http\Transport;
use PerlyPay\Blesta\Vendor\Perly\Pay\PerlyPay;

if (!defined('DS')) {
    exit;
}

/**
 * The gateway's settings (the meta Blesta hands to setMeta(), decrypted)
 * and the few facts about this Blesta the module needs, read in one place:
 *
 *   company_id      Configure::get('Blesta.company_id'): a Blesta install can run several companies,
 *                   each with its own instance of the gateway and its own callback address
 *   callback_base   Configure::get('Blesta.gw_callback_url'), ".../callback/gw/"
 *   language        Configure::get('Blesta.language'), "en_us"
 *
 *   https://docs.blesta.com/developers/gateways/non-merchant-gateways
 */
final class Settings
{
    /** The gateway's directory name: Blesta's callback route and the class name (PerlyPay) both come from it. */
    public const GATEWAY = 'perly_pay';
    public const DEFAULT_BASE = 'https://pay.perly.io';
    public const MODES = ['overlay', 'redirect'];

    /** Tests (and only tests) answer the API from here instead of the network. */
    public static ?Transport $transport = null;

    /**
     * @param array<string, mixed> $meta
     * @param array{company_id?: int, callback_base?: string, language?: string} $env
     */
    public function __construct(private readonly array $meta, private readonly array $env = [])
    {
    }

    /** @return array<string, mixed> the settings as Blesta passes them, for a check that needs another instance */
    public function meta(): array
    {
        return $this->meta;
    }

    public function apiKey(): string
    {
        return trim($this->string('api_key'));
    }

    public function webhookSecret(): string
    {
        return trim($this->string('webhook_secret'));
    }

    /** "test" or "live" from the key's prefix; null while no valid key is saved. */
    public function mode(): ?string
    {
        return Keys::mode($this->apiKey());
    }

    /** Ready to take payments: a secret key and the webhook's signing secret. */
    public function ready(): bool
    {
        return $this->mode() !== null && $this->webhookSecret() !== '';
    }

    public function overlay(): bool
    {
        return $this->string('checkout_mode') !== 'redirect';
    }

    public function companyId(): int
    {
        return (int) ($this->env['company_id'] ?? 0);
    }

    /** Blesta's language code, "en_us", "uk_ua". */
    public function language(): string
    {
        return strtolower((string) ($this->env['language'] ?? 'en_us'));
    }

    /** The language Perly Pay's page opens in. */
    public function locale(): string
    {
        return str_starts_with($this->language(), 'uk') ? 'uk' : 'en';
    }

    /** Where Perly Pay sends events: Blesta's nonmerchant callback for this company and gateway. */
    public function callbackUrl(): string
    {
        $base = (string) ($this->env['callback_base'] ?? '');

        return rtrim($base, '/') . '/' . $this->companyId() . '/' . self::GATEWAY . '/';
    }

    /** This Blesta's host name, for the invoice's description and metadata. */
    public function host(): string
    {
        return strtolower((string) parse_url($this->callbackUrl(), PHP_URL_HOST));
    }

    /**
     * Perly Pay's address. Locally (Perly Pay's own tests) PERLY_PAY_API_BASE is
     * defined in Blesta's config/blesta.php; on a real Blesta it is pay.perly.io.
     */
    public static function apiBase(): string
    {
        $base = defined('PERLY_PAY_API_BASE') && is_string(PERLY_PAY_API_BASE) ? PERLY_PAY_API_BASE : self::DEFAULT_BASE;

        return rtrim(Html::url($base) ?? self::DEFAULT_BASE, '/');
    }

    /** An API client for the saved key, or null without one. */
    public function client(int $maxRetries = 2): ?PerlyPay
    {
        $key = $this->apiKey();
        if (Keys::mode($key) === null) {
            return null;
        }
        $options = ['base_url' => self::apiBase(), 'max_retries' => $maxRetries, 'timeout' => 20];
        if (self::$transport !== null) {
            $options['transport'] = self::$transport;
            $options['sleep'] = static function (int $ms): void {
            };
        }

        return new PerlyPay($key, $options);
    }

    private function string(string $key): string
    {
        $value = $this->meta[$key] ?? '';

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
