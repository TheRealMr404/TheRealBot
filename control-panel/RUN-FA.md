# راه‌اندازی پنل مستقل مدیریت ربات‌ها

## پیش‌نیاز

- سرور Ubuntu یا Debian با دسترسی `root`
- حداقل 2 گیگابایت RAM
- یک دامنه یا زیردامنه مانند `manager.example.com`
- رکورد `A` دامنه باید قبل از نصب به IP سرور متصل شده باشد
- پورت‌های `80` و `443` باید از فایروال سرور باز باشند

## نصب

فایل ZIP را روی سرور آپلود و اجرا کنید:

```bash
unzip mirza-control-panel-standalone-fixed.zip
cd mirza-control-panel
sudo bash install.sh --domain manager.example.com
```

به‌جای `manager.example.com` دامنه واقعی پنل خودتان را وارد کنید.

نصب‌کننده به‌صورت خودکار این موارد را انجام می‌دهد:

1. نصب Docker و ابزارهای لازم
2. راه‌اندازی HTTPS و دریافت SSL
3. نصب Agent محدودشده مدیریت سرور
4. راه‌اندازی پنل در `/opt/mirza-control-panel`
5. آماده‌کردن قالب ربات در `/opt/mirza-control-panel/bot-source`
6. ساخت نام کاربری و رمز اولیه مدیر

رمز اولیه فقط یک بار در پایان نصب نمایش داده می‌شود. آن را نگهداری کنید و پس
از اولین ورود از قسمت تنظیمات تغییر دهید.

## جداسازی مسیرها

پنل و ربات‌ها کاملاً در مسیرهای جدا قرار می‌گیرند:

```text
/opt/mirza-control-panel       پنل مدیریت و دیتابیس آن
/opt/mirza/instances           ربات‌های مستقل مشتری‌ها
/opt/mirza/backups             بکاپ ربات‌های مشتری‌ها
/var/lib/mirza-panel-agent     صف امن عملیات Agent
```

هر مشتری دو کانتینر مستقل برای برنامه و دیتابیس و یک شبکه داخلی جدا دارد.

## فرمان‌های مدیریت

نمایش وضعیت پنل:

```bash
sudo bash install.sh status
```

ساخت رمز جدید برای مدیر:

```bash
sudo bash install.sh reset-password --username admin
```

نصب مجدد یا بروزرسانی پنل با حفظ اطلاعات:

```bash
sudo bash install.sh --domain manager.example.com
```

حذف فقط پنل مدیریت بدون حذف ربات‌های مشتری‌ها:

```bash
sudo bash install.sh remove
```

پس از نصب، همین فرمان‌ها از طریق دستور `mirza` نیز در دسترس هستند:

```bash
mirza panel-status
mirza panel-reset-password --panel-user admin
mirza panel-remove
```

## استفاده از سورس ربات دیگر

قالب فعلی ربات داخل پوشه مستقل `bot-template` بسته قرار دارد. برای استفاده از
یک سورس دیگر که فایل‌های `index.php` و `table.php` دارد:

```bash
sudo bash install.sh --domain manager.example.com --bot-source /root/my-bot-source
```

توکن ربات‌های مشتری از پنل وب دریافت می‌شود و در دیتابیس پنل ذخیره نمی‌شود.
