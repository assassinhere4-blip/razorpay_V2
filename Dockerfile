FROM php:8.2-apache

# Install curl + required extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    wget \
    tar \
    ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install curl-impersonate Chrome binaries
RUN wget -q \
    https://github.com/lwthiker/curl-impersonate/releases/download/v0.6.1/curl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz \
    -O /tmp/curl-impersonate.tar.gz \
    && tar -xzf /tmp/curl-impersonate.tar.gz \
       -C /usr/local/bin/ \
       --wildcards 'curl_chrome*' \
    && chmod +x /usr/local/bin/curl_chrome* \
    && rm /tmp/curl-impersonate.tar.gz \
    && echo "curl-impersonate installed:" \
    && ls -la /usr/local/bin/curl_chrome* 2>/dev/null || echo "WARNING: no chrome binaries found"

# Fix Apache — allow .htaccess + serve files from /var/www/html
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/allow-html.conf \
    && a2enconf allow-html

# Allow shell_exec in PHP — needed for curl-impersonate
# (disable_functions default blocks it in some images)
RUN echo 'disable_functions =' >> /usr/local/etc/php/conf.d/docker-php-rzp.ini \
    && echo 'allow_url_fopen = On' >> /usr/local/etc/php/conf.d/docker-php-rzp.ini \
    && echo 'max_execution_time = 60' >> /usr/local/etc/php/conf.d/docker-php-rzp.ini \
    && echo 'memory_limit = 256M' >> /usr/local/etc/php/conf.d/docker-php-rzp.ini

# Copy project files
COPY . /var/www/html/

# Fix permissions — www-data needs read + execute on curl binaries
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chown root:www-data /usr/local/bin/curl_chrome* 2>/dev/null \
    && chmod 755 /usr/local/bin/curl_chrome* 2>/dev/null \
    || true

# Verify curl-impersonate is accessible by www-data
RUN su -s /bin/sh www-data -c \
    'curl_chrome131 --version 2>&1 || echo "curl_chrome131 not in PATH for www-data"' \
    || true

EXPOSE 80
