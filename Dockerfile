# Dockerfile mein add karo:
FROM php:8.2-apache

RUN apt-get update && apt-get install -y wget tar \
 && wget -q https://github.com/lwthiker/curl-impersonate/releases/download/v0.6.1/curl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz \
 && tar -xzf curl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz -C /usr/local/bin/ \
 && chmod +x /usr/local/bin/curl_chrome* \
 && rm curl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz
 
