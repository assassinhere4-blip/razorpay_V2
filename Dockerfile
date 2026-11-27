FROM php:8.2-apache

# ── System packages ───────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    wget \
    tar \
    ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# ── Apache modules ────────────────────────────────────────────
RUN a2enmod rewrite headers

# ── curl-impersonate ──────────────────────────────────────────
# Try v0.8.x first (has chrome124) → fallback v0.6.1 (has chrome116)
# Neither has chrome131 — PHP code uses chrome116 as best available
RUN set -e; \
    INSTALLED=0; \
    for VERSION in \
        "v0.8.1/curl-impersonate-v0.8.1.x86_64-linux-gnu.tar.gz" \
        "v0.8.0/curl-impersonate-v0.8.0.x86_64-linux-gnu.tar.gz" \
        "v0.6.1/curl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz" \
    ; do \
        URL="https://github.com/lwthiker/curl-impersonate/releases/download/${VERSION}"; \
        echo "Trying: ${URL}"; \
        if wget -q --timeout=30 "${URL}" -O /tmp/ci.tar.gz 2>/dev/null; then \
            tar -xzf /tmp/ci.tar.gz -C /usr/local/bin/ \
                --wildcards 'curl_chrome*' 2>/dev/null || true; \
            rm -f /tmp/ci.tar.gz; \
            INSTALLED=1; \
            echo "Installed from ${VERSION}"; \
            break; \
        fi; \
    done; \
    if [ "$INSTALLED" = "1" ]; then \
        chmod +x /usr/local/bin/curl_chrome* 2>/dev/null || true; \
        echo "=== curl-impersonate binaries ==="; \
        ls -la /usr/local/bin/curl_chrome* 2>/dev/null; \
    else \
        echo "WARN: curl-impersonate not installed — will use libcurl fallback"; \
    fi

# ── Apache Directory config ───────────────────────────────────
# Fix "403 Forbidden" — Debian apache default denies /var/www/html
RUN printf '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks MultiViews\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<IfModule mod_headers.c>\n\
    Header always set X-Content-Type-Options "nosniff"\n\
</IfModule>\n' \
    > /etc/apache2/conf-available/allow-html.conf \
    && a2enconf allow-html

# ── PHP config ────────────────────────────────────────────────
# disable_functions blank  = allow shell_exec (needed for curl-impersonate)
# max_execution_time 60    = enough for full Razorpay flow
# memory_limit 256M        = safe for curl cookie jar ops
RUN printf \
    'disable_functions =\n\
allow_url_fopen = On\n\
max_execution_time = 60\n\
memory_limit = 256M\n\
upload_max_filesize = 16M\n\
post_max_size = 16M\n' \
    > /usr/local/etc/php/conf.d/rzp.ini

# ── Apache ServerName (suppress AH00558 warning in logs) ─────
RUN echo "ServerName localhost" \
    >> /etc/apache2/apache2.conf

# ── Project files ─────────────────────────────────────────────
COPY . /var/www/html/

# ── Permissions ───────────────────────────────────────────────
# /var/www/html  — www-data owns, 755 dirs / 644 files
# curl binaries  — root owns, 755 (www-data can execute)
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod 755 /usr/local/bin/curl_chrome* 2>/dev/null || true

# ── Smoke test — verify www-data can run curl-impersonate ─────
RUN BEST_BIN=""; \
    for B in \
        /usr/local/bin/curl_chrome124 \
        /usr/local/bin/curl_chrome116 \
        /usr/local/bin/curl_chrome110 \
        /usr/local/bin/curl_chrome107 \
    ; do \
        if [ -x "$B" ]; then BEST_BIN="$B"; break; fi; \
    done; \
    if [ -n "$BEST_BIN" ]; then \
        su -s /bin/sh www-data -c \
            "${BEST_BIN} --version 2>&1 | head -1" \
        && echo "OK: www-data can execute $(basename ${BEST_BIN})"; \
    else \
        echo "WARN: no curl-impersonate binary found — libcurl fallback active"; \
    fi

EXPOSE 80
