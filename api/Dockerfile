FROM php:8.3.14-cli-alpine3.20
RUN apk add --no-cache postgresql-libs \
 && apk add --no-cache --virtual .build postgresql-dev \
 && docker-php-ext-install pdo_pgsql \
 && apk del .build \
 && adduser -D -u 10001 app
WORKDIR /app
COPY src ./src
COPY public ./public
USER app
ENV PHP_CLI_SERVER_WORKERS=8
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
