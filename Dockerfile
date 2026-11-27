FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev pkg-config wget tar ca-certificates \
    build-essential cmake ninja-build python3 \
    libssl-dev libnghttp2-dev libbrotli-dev \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Build curl-impersonate from source — links directly, no shell_exec needed
RUN wget -q "https://github.com/lwthiker/curl-impersonate/archive/refs/tags/v0.6.1.tar.gz" \
    -O /tmp/ci-src.tar.gz \
    && tar -xzf /tmp/ci-src.tar.gz -C /tmp/ \
    && cd /tmp/curl-impersonate-0.6.1 \
    && make chrome-build \
    && cp /tmp/curl-impersonate-0.6.1/chrome/out/curl_chrome116 /usr/local/bin/ \
    && chmod +x /usr/local/bin/curl_chrome116 \
    && rm -rf /tmp/curl-impersonate-0.6.1 /tmp/ci-src.tar.gz

# Apache + PHP config
RUN printf '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/allow-html.conf \
    && a2enconf allow-html

RUN printf 'disable_functions =\nallow_url_fopen = On\nmax_execution_time = 60\nmemory_limit = 256M\n' \
    > /usr/local/etc/php/conf.d/rzp.ini

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 755 /usr/local/bin/curl_chrome* 2>/dev/null || true

EXPOSE 80
