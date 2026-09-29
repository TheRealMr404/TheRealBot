# Mirza Telegram Commerce API

سرویس مستقل FastAPI برای فروش Telegram Stars و Telegram Premium است. ربات PHP فقط کلاینت این سرویس است و هیچ cookie، seed یا کلید TON را نگهداری نمی‌کند.

## معماری

- API عمومی مستقل و نسخه‌بندی‌شده در `/v1`
- دیتابیس مستقل برای محصول، سفارش، پرداخت، تلاش provider و outbox
- worker جدا برای خرید و ارسال webhook
- کلید API هش‌شده و scopeدار؛ bootstrap key فقط در environment
- idempotency اجباری برای ساخت سفارش
- webhook با HMAC-SHA256، timestamp و event id
- secretهای webhook به‌صورت رمز‌شده در دیتابیس
- providerهای قابل تعویض: `fragment`، `telegram_bot` و `mock`
- provider Fragment فقط در حالت KYC و مستقیماً با Fragment.com کار می‌کند. `marketapp_token` در کد پذیرفته یا استفاده نمی‌شود.

## نصب روی سرور ربات

```bash
cd services/telegram-commerce-api
sudo bash install-service.sh
```

نصب‌کننده Python، محیط مجازی، تمام پکیج‌های Fragment، API، worker، دیتابیس، کلیدهای داخلی و سرویس‌های systemd را خودکار نصب و راه‌اندازی می‌کند. فایل امن داخلی با مالک `root:www-data` و سطح دسترسی `640` نیز خودکار ساخته می‌شود و کاربر نیازی به ویرایش آن ندارد.

پس از نصب، در پنل مدیریت ربات وارد `خدمات مجازی > فروش خودکار استارز و پریمیوم` شوید. کلید کیف پول، شماره ورود تلگرام، نسخه کیف پول، کلید TON RPC و حد هشدار موجودی همگی از همان صفحه ثبت می‌شوند. سپس اتصال را بررسی کنید، پلن‌ها را بسازید و فروش را فعال کنید.

پیام حاوی seed، شماره تلفن یا کلید TON بلافاصله حذف می‌شود. API این مقادیر را با کلید نصب‌شده روی سرور رمزنگاری می‌کند، آن‌ها را در پاسخ‌ها برنمی‌گرداند و داشبورد فقط وضعیت ثبت‌شدنشان را نمایش می‌دهد.

## Providerها

### Fragment مستقیم

`provider=fragment` برای Stars و Premium قابل استفاده است و به cookieهای `stel_ssid`، `stel_dt`، `stel_token`، `stel_ton_token`، seed کیف پول و کلید TON RPC نیاز دارد. این مسیر unofficial است و تغییرات Fragment می‌تواند آن را مختل کند؛ به همین دلیل تمام جزئیات آن پشت adapter قرار دارد.

### Telegram Bot API رسمی

`provider=telegram_bot` فقط Premium را با متد رسمی `giftPremiumSubscription` می‌فرستد. گیرنده باید شناسه عددی Telegram باشد و موجودی Stars ربات کافی باشد. هزینه رسمی فعلی توسط Bot API برای ۳، ۶ و ۱۲ ماه به‌ترتیب ۱۰۰۰، ۱۵۰۰ و ۲۵۰۰ Stars است.

### Mock

فقط برای تست است و نباید برای فروش واقعی فعال شود.

## Endpointهای اصلی

- `GET /v1/products`
- `POST /v1/quotes`
- `POST /v1/orders` با header اجباری `Idempotency-Key`
- `GET /v1/orders/{id}`
- `POST /v1/orders/{id}/payments`
- `GET /v1/payments/{id}`
- CRUD محدود webhookها
- مدیریت محصولات و API keyها زیر `/v1/admin`
- ثبت امن تنظیمات Fragment در `PATCH /v1/admin/provider-config`
- شروع و پیگیری ورود تلگرام در `POST /v1/admin/fragment/auth` و `GET /v1/admin/fragment/auth/{id}`

OpenAPI در `/docs` در دسترس است. در deployment عمومی، API را پشت reverse proxy و TLS قرار دهید یا مانند نصب پیش‌فرض فقط روی `127.0.0.1` نگه دارید.

## وضعیت سفارش

`awaiting_payment -> queued -> processing -> fulfilled`

خطای قطعی قبل از خرید به `failed` می‌رود و adapter ربات وجه را بازمی‌گرداند. timeout یا نتیجه نامعلوم provider به `reconciliation_required` می‌رود و خودکار retry یا refund نمی‌شود تا خرید تکراری رخ ندهد.

## تست

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements-dev.txt
pytest -q
```

اسناد رسمی مرتبط: [Telegram Bot API](https://core.telegram.org/bots/api#giftpremiumsubscription) و [Telegram Bot Developer Terms](https://telegram.org/tos/bot-developers).

