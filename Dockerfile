FROM php:8.2-apache

# Install cURL and required extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    && docker-php-ext-install curl

# Enable Apache rewrite module if needed
RUN a2enmod rewrite

# Copy project files to Apache public directory
COPY . /var/www/html/

# Set permissions
RUN chown www-data:www-data /var/www/html -R

# Expose port 80 (Render automatically maps this)
EXPOSE 80
