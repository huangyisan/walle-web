#!/usr/bin/env bash
set -e
php-fpm -D
exec nginx -g 'daemon off;'
