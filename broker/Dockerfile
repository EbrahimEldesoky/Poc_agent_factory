FROM php:8.3.14-cli-alpine3.20
WORKDIR /app
COPY index.php .
# Runs as root only to reach the Docker socket; this is the one service that has it.
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["php", "-S", "0.0.0.0:8100", "index.php"]
