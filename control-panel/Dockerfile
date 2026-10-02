FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public \
    TZ=Asia/Tehran

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev curl \
    && docker-php-ext-install pdo_sqlite mbstring \
    && a2enmod rewrite headers expires \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/app/public>\nAllowOverride All\nRequire all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' > /etc/apache2/conf-available/mirza-panel.conf \
    && a2enconf mirza-panel \
    && rm -rf /var/lib/apt/lists/*

COPY app /var/www/app

RUN chown -R root:root /var/www/app \
    && find /var/www/app -type d -exec chmod 0755 {} \; \
    && find /var/www/app -type f -exec chmod 0644 {} \;

WORKDIR /var/www/app
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=4 \
    CMD curl -fsS http://127.0.0.1/login.php >/dev/null || exit 1

CMD ["apache2-foreground"]
