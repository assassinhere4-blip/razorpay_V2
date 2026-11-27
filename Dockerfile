FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev pkg-config wget tar ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# curl-impersonate — multiple fallback sources
RUN set -e; \
    INSTALLED=0; \
    # Try GitHub releases
    wget -q --timeout=30 \
      "https://github.com/lwthiker/curl-impersonate/releases/download/v0.6.1/curl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz" \
      -O /tmp/ci.tar.gz 2>/dev/null \
    && tar -xzf /tmp/ci.tar.gz -C /usr/local/bin/ --wildcards 'curl_chrome*' \
    && chmod +x /usr/local/bin/curl_chrome* \
    && rm /tmp/ci.tar.gz \
    && INSTALLED=1 \
    || true; \
    # Fallback: build from apt (ubuntu ppa)
    if [ "$INSTALLED" = "0" ]; then \
      apt-get update && apt-get install -y --no-install-recommends \
        curl-impersonate 2>/dev/null || true; \
    fi; \
    # Verify
    ls /usr/local/bin/curl_chrome* 2>/dev/null || echo "WARN: curl-impersonate not installed"

# Apache Directory config
RUN printf '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/allow-html.conf \
    && a2enconf allow-html

# PHP config — allow shell_exec for curl-impersonate
RUN printf 'disable_functions =\n\
allow_url_fopen = On\n\
max_execution_time = 60\n\
memory_limit = 256M\n' > /usr/local/etc/php/conf.d/rzp.ini

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 755 /usr/local/bin/curl_chrome* 2>/dev/null || true

EXPOSE 80
