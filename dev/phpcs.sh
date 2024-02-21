#!/bin/bash
docker compose exec wordpress /var/www/html/vendor/bin/phpcs "$@"