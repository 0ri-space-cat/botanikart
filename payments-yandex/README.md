# Оплата BotanikArt в Yandex Cloud Functions

Сам сайт остаётся на GitHub Pages. Эта функция создаёт платёж и проверяет подтверждение банка.

## Настройки версии

- Среда: Node.js 22, точка входа `index.handler`.
- Память: 256 МБ. Тайм-аут: 20 секунд. Подготовленные экземпляры: 0.
- Загрузить ZIP из `outputs/botanikart-payments-yandex.zip`.
- Для вызова из браузера функция должна быть публичной. Ограничить количество одновременных экземпляров и настроить бюджет в платёжном аккаунте.
- В секреты версии добавить `TBANK_TERMINAL_KEY` и `TBANK_PASSWORD`. Сначала только тестовый терминал DEMO. Рабочие ключи не сохранять в коде, GitHub, чате или ZIP.
- Оставить `TBANK_RECEIPTS_READY=false` до подключения кассы к этому интернет-магазину и подтверждения настроек чека.
- Для рабочего режима подтвердить ставку `TBANK_TAX`, признак расчёта `TBANK_PAYMENT_METHOD` и готовность кассы. УСН «Доходы» уже подтверждена: `TBANK_TAXATION=usn_income`.
- При предоплате до выдачи товара предусмотрен `full_prepayment`; порядок закрывающего чека при передаче товара нужно настроить с кассовым сервисом.

Не переопределять `NODE_EXTRA_CA_CERTS`: Yandex Cloud устанавливает её при запуске Node.js с собственным бандлом доверенных CA. Проверка TLS остаётся включённой.

## Адреса

`https://functions.yandexcloud.net/ID?action=health`

`https://functions.yandexcloud.net/ID?action=create-payment`

`https://functions.yandexcloud.net/ID?action=payment-status`

Два последних адреса вписать в `data/payment-config.js`. Публичную кнопку включить только после успешного теста.

## Что проверено в коде

Цена и наличие берутся из серверного каталога. Секрет остаётся на сервере. Страница результата показывает успешную оплату только после ответа CheckOrder с CONFIRMED и совпадения суммы. Ссылка заказа подписана HMAC и истекает через три дня. Без подтверждённой кассовой конфигурации рабочий терминал не принимает новые заказы.

Документация: https://yandex.cloud/ru/docs/functions/concepts/runtime/environment-variables и https://developer.tbank.ru/eacq/api/check-order
