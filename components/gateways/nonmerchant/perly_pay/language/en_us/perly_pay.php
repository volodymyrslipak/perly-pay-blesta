<?php
/**
 * Perly Pay for Blesta, in English. One line per string: build.mjs reads
 * this file and uk_ua/perly_pay.php and fails when either misses a string
 * the gateway uses or their placeholders differ.
 */

if (!defined('DS')) {
    exit;
}

// Basics
$lang['PerlyPay.name'] = 'Perly Pay';
$lang['PerlyPay.description'] = 'Bitcoin, USDC and USDT, paid straight to your own wallet. Perly Pay never holds the money.';

// Settings
$lang['PerlyPay.meta.api_key'] = 'Secret key';
$lang['PerlyPay.meta.api_key_help'] = 'Perly Pay → Developers → API keys. sk_test_… for test mode, sk_live_… to take real money. Save, then:';
$lang['PerlyPay.meta.test'] = 'Test connection';
$lang['PerlyPay.meta.webhook_secret'] = 'Webhook signing secret';
$lang['PerlyPay.meta.webhook_help'] = 'In Perly Pay → Developers → Webhooks, add this address with the invoice and refund events, then paste its secret (whsec_…) here:';
$lang['PerlyPay.meta.checkout_mode'] = 'Payment page';
$lang['PerlyPay.meta.checkout_mode_overlay'] = 'Over the Blesta page (overlay)';
$lang['PerlyPay.meta.checkout_mode_redirect'] = 'On pay.perly.io (redirect)';
$lang['PerlyPay.meta.checkout_mode_help'] = 'For the overlay, add this Blesta address in Perly Pay → Settings → "Sites that may embed the checkout". Phones always get the page itself.';
$lang['PerlyPay.meta.currencies_help'] = 'Perly Pay bills in USD or EUR only, so Blesta offers it on payments in those two currencies.';

// Errors
$lang['PerlyPay.!error.api_key.valid'] = 'Paste a secret key from Perly Pay → Developers → API keys: sk_test_… or sk_live_….';
$lang['PerlyPay.!error.webhook_secret.valid'] = 'A webhook signing secret starts with whsec_. Copy it again from Perly Pay → Developers → Webhooks.';
$lang['PerlyPay.!error.checkout_mode.valid'] = 'Choose where the payment page opens.';

// Payment
$lang['PerlyPay.pay.button'] = 'Pay with crypto';
$lang['PerlyPay.pay.note'] = 'Bitcoin, USDC or USDT from any wallet.';
$lang['PerlyPay.pay.test_mode'] = 'Test mode: nothing real moves.';
$lang['PerlyPay.pay.or'] = ' or ';
$lang['PerlyPay.pay.currency'] = 'Crypto payment takes %1$s only. Please choose another way to pay.';
$lang['PerlyPay.pay.not_set_up'] = 'Crypto payment is not set up yet. Please choose another way to pay.';
$lang['PerlyPay.pay.unavailable'] = 'Crypto payment is not available right now. Please try again, or choose another way to pay.';
$lang['PerlyPay.pay.credit'] = 'Account credit';
$lang['PerlyPay.pay.description'] = '%1$s at %2$s';

// Test connection
$lang['PerlyPay.test.no_key'] = 'Save a secret key first: sk_test_… or sk_live_….';
$lang['PerlyPay.test.refused'] = 'Perly Pay refused this key. Copy it again from Developers → API keys.';
$lang['PerlyPay.test.unreachable'] = 'Could not reach Perly Pay. Try again in a minute.';
$lang['PerlyPay.test.failed'] = 'Perly Pay: %1$s';
$lang['PerlyPay.test.live'] = 'Connected in live mode.';
$lang['PerlyPay.test.test'] = 'Connected in test mode.';
$lang['PerlyPay.test.no_webhook'] = 'This Blesta\'s webhook is not in Perly Pay yet: add the address below in Developers → Webhooks.';
$lang['PerlyPay.test.webhook_off'] = 'This Blesta\'s webhook is switched off in Perly Pay. Switch it on in Developers → Webhooks.';
$lang['PerlyPay.test.webhook_events'] = 'The webhook does not send invoice.fulfilled. Add the invoice events to it in Developers → Webhooks.';
$lang['PerlyPay.test.no_secret'] = 'The webhook is in Perly Pay, but its signing secret is not saved here.';
$lang['PerlyPay.test.ok'] = 'The webhook is set up.';
