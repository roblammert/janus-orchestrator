FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    python3 \
    python3-venv \
    python3-pip \
    default-mysql-client \
    netcat-openbsd \
    ca-certificates \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/janus

COPY app /var/www/janus/app
COPY public /var/www/janus/public
COPY sql /var/www/janus/sql
COPY scripts /var/www/janus/scripts
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/janus.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir -r /var/www/janus/app/python/requirements.txt \
    && chmod +x /entrypoint.sh /var/www/janus/scripts/*.sh /var/www/janus/scripts/*.py

ENV PATH="/opt/venv/bin:${PATH}"
ENV PYTHONPATH="/var/www/janus/app/python"
ENV PHP_FPM_LISTEN="127.0.0.1:9000"

EXPOSE 8811 8812

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
