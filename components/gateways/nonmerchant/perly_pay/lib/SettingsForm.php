<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

if (!defined('DS')) {
    exit;
}

/**
 * The gateway's settings page in Blesta's admin (getSettings, editSettings):
 * the secret key, the webhook's signing secret, where the payment page opens.
 * Blesta wraps these fields in its own form and stores what editSettings
 * returns; both secrets are stored encrypted (encryptableFields).
 *
 * "Test connection" (a link under the key) reloads this page with
 * perlypay_test=1; only then is Perly Pay asked, about the saved settings.
 * The page is Blesta 6's admin theme (Bootstrap 5), which Blesta 5's admin
 * also lays out.
 */
final class SettingsForm
{
    /**
     * @param Settings $settings the saved settings (or what was just posted, on an error)
     * @param string|null $requestUri this admin page's address, for the "Test connection" link
     */
    public static function html(Settings $settings, ?string $requestUri, bool $test = false): string
    {
        $meta = $settings->meta();
        $value = static fn (string $key): string => is_scalar($meta[$key] ?? null) ? (string) $meta[$key] : '';
        $mode = $value('checkout_mode') === 'redirect' ? 'redirect' : 'overlay';

        $html = self::field('api_key', 'password', Lang::t('PerlyPay.meta.api_key'), $value('api_key'),
            Html::e(Lang::t('PerlyPay.meta.api_key_help')) . ' ' . self::testLink($requestUri));
        if ($test) {
            $html .= '<div class="alert alert-info perlypay-test" role="status">' . Html::e(ConnectionTest::run($settings)) . '</div>';
        }
        $html .= self::field('webhook_secret', 'password', Lang::t('PerlyPay.meta.webhook_secret'), $value('webhook_secret'),
            Html::e(Lang::t('PerlyPay.meta.webhook_help')) . '<br><code>' . Html::e($settings->callbackUrl()) . '</code>');

        $html .= '<div class="mb-3"><label class="form-label" for="perlypay_checkout_mode">' . Html::e(Lang::t('PerlyPay.meta.checkout_mode')) . '</label>'
            . '<select class="form-select" name="checkout_mode" id="perlypay_checkout_mode">';
        $options = ['overlay' => Lang::t('PerlyPay.meta.checkout_mode_overlay'), 'redirect' => Lang::t('PerlyPay.meta.checkout_mode_redirect')];
        foreach ($options as $option => $label) {
            $html .= '<option value="' . $option . '"' . ($option === $mode ? ' selected' : '') . '>' . Html::e($label) . '</option>';
        }
        $html .= '</select><div class="form-text">' . Html::e(Lang::t('PerlyPay.meta.checkout_mode_help')) . '</div></div>';
        $html .= '<p class="form-text">' . Html::e(Lang::t('PerlyPay.meta.currencies_help')) . '</p>';

        return $html;
    }

    /**
     * The settings to store, trimmed, and what is wrong with them (Blesta's Input errors, by field).
     *
     * @param array<string, mixed> $meta what was posted
     * @return array{meta: array<string, string>, errors: array<string, array<string, string>>}
     */
    public static function validate(array $meta): array
    {
        $clean = [
            'api_key' => trim(is_scalar($meta['api_key'] ?? null) ? (string) $meta['api_key'] : ''),
            'webhook_secret' => trim(is_scalar($meta['webhook_secret'] ?? null) ? (string) $meta['webhook_secret'] : ''),
            'checkout_mode' => is_string($meta['checkout_mode'] ?? null) ? $meta['checkout_mode'] : 'overlay',
        ];
        $errors = [];
        if (Keys::mode($clean['api_key']) === null) {
            $errors['api_key'] = ['valid' => Lang::t('PerlyPay.!error.api_key.valid')];
        }
        // The secret may wait: the webhook needs this Blesta's address first. Until it is saved there is no button.
        if ($clean['webhook_secret'] !== '' && !Keys::isWebhookSecret($clean['webhook_secret'])) {
            $errors['webhook_secret'] = ['valid' => Lang::t('PerlyPay.!error.webhook_secret.valid')];
        }
        if (!in_array($clean['checkout_mode'], Settings::MODES, true)) {
            $errors['checkout_mode'] = ['valid' => Lang::t('PerlyPay.!error.checkout_mode.valid')];
        }

        return ['meta' => $clean, 'errors' => $errors];
    }

    private static function field(string $name, string $type, string $label, string $value, string $helpHtml): string
    {
        $id = 'perlypay_' . $name;

        return '<div class="mb-3"><label class="form-label" for="' . $id . '">' . Html::e($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" name="' . $name . '" id="' . $id . '" value="' . Html::e($value) . '" autocomplete="off" spellcheck="false">'
            . '<div class="form-text">' . $helpHtml . '</div></div>';
    }

    /** This same page with perlypay_test=1: a relative link, so nothing from the request but its path is printed. */
    private static function testLink(?string $requestUri): string
    {
        $path = is_string($requestUri) ? (string) parse_url($requestUri, PHP_URL_PATH) : '';
        if ($path === '' || !str_starts_with($path, '/')) {
            return '';
        }

        return '<a href="' . Html::e($path . '?perlypay_test=1') . '">' . Html::e(Lang::t('PerlyPay.meta.test')) . '</a>';
    }
}
