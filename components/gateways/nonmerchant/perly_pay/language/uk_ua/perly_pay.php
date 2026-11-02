<?php
/**
 * Perly Pay for Blesta, українською. Ключі ті самі, що в en_us/perly_pay.php
 * (build.mjs звіряє обидва файли).
 */

if (!defined('DS')) {
    exit;
}

// Basics
$lang['PerlyPay.name'] = 'Perly Pay';
$lang['PerlyPay.description'] = 'Біткоїн, USDC і USDT прямо на ваш власний гаманець. Perly Pay ніколи не тримає гроші.';

// Settings
$lang['PerlyPay.meta.api_key'] = 'Секретний ключ';
$lang['PerlyPay.meta.api_key_help'] = 'Perly Pay → Developers → API keys. sk_test_… для тестового режиму, sk_live_… для справжніх грошей. Збережіть, потім:';
$lang['PerlyPay.meta.test'] = 'Перевірити зʼєднання';
$lang['PerlyPay.meta.webhook_secret'] = 'Секрет підпису вебхука';
$lang['PerlyPay.meta.webhook_help'] = 'У Perly Pay → Developers → Webhooks додайте цю адресу з подіями рахунків і повернень, потім вставте сюди її секрет (whsec_…):';
$lang['PerlyPay.meta.checkout_mode'] = 'Сторінка оплати';
$lang['PerlyPay.meta.checkout_mode_overlay'] = 'Поверх сторінки Blesta (оверлей)';
$lang['PerlyPay.meta.checkout_mode_redirect'] = 'На pay.perly.io (перехід)';
$lang['PerlyPay.meta.checkout_mode_help'] = 'Для оверлею додайте адресу цього Blesta у Perly Pay → Settings → «Sites that may embed the checkout». На телефонах завжди відкривається сама сторінка.';
$lang['PerlyPay.meta.currencies_help'] = 'Perly Pay виставляє рахунки лише в USD або EUR, тож Blesta пропонує його для оплат у цих двох валютах.';

// Errors
$lang['PerlyPay.!error.api_key.valid'] = 'Вставте секретний ключ із Perly Pay → Developers → API keys: sk_test_… або sk_live_….';
$lang['PerlyPay.!error.webhook_secret.valid'] = 'Секрет підпису вебхука починається з whsec_. Скопіюйте його ще раз у Perly Pay → Developers → Webhooks.';
$lang['PerlyPay.!error.checkout_mode.valid'] = 'Оберіть, де відкривається сторінка оплати.';

// Payment
$lang['PerlyPay.pay.button'] = 'Сплатити криптовалютою';
$lang['PerlyPay.pay.note'] = 'Біткоїн, USDC або USDT з будь-якого гаманця.';
$lang['PerlyPay.pay.test_mode'] = 'Тестовий режим: справжні гроші не рухаються.';
$lang['PerlyPay.pay.or'] = ' або ';
$lang['PerlyPay.pay.currency'] = 'Криптовалютою можна сплатити лише в %1$s. Будь ласка, оберіть інший спосіб оплати.';
$lang['PerlyPay.pay.not_set_up'] = 'Оплату криптовалютою ще не налаштовано. Будь ласка, оберіть інший спосіб оплати.';
$lang['PerlyPay.pay.unavailable'] = 'Оплата криптовалютою зараз недоступна. Спробуйте ще раз або оберіть інший спосіб оплати.';
$lang['PerlyPay.pay.credit'] = 'Поповнення рахунку';
$lang['PerlyPay.pay.description'] = '%1$s у %2$s';

// Test connection
$lang['PerlyPay.test.no_key'] = 'Спершу збережіть секретний ключ: sk_test_… або sk_live_….';
$lang['PerlyPay.test.refused'] = 'Perly Pay не прийняв цей ключ. Скопіюйте його ще раз у Developers → API keys.';
$lang['PerlyPay.test.unreachable'] = 'Не вдалося звʼязатися з Perly Pay. Спробуйте за хвилину.';
$lang['PerlyPay.test.failed'] = 'Perly Pay: %1$s';
$lang['PerlyPay.test.live'] = 'Зʼєднано в робочому режимі.';
$lang['PerlyPay.test.test'] = 'Зʼєднано в тестовому режимі.';
$lang['PerlyPay.test.no_webhook'] = 'Вебхука цього Blesta ще немає в Perly Pay: додайте адресу нижче в Developers → Webhooks.';
$lang['PerlyPay.test.webhook_off'] = 'Вебхук цього Blesta вимкнено в Perly Pay. Увімкніть його в Developers → Webhooks.';
$lang['PerlyPay.test.webhook_events'] = 'Вебхук не надсилає invoice.fulfilled. Додайте йому події рахунків у Developers → Webhooks.';
$lang['PerlyPay.test.no_secret'] = 'Вебхук є в Perly Pay, але його секрет підпису тут не збережено.';
$lang['PerlyPay.test.ok'] = 'Вебхук налаштовано.';
