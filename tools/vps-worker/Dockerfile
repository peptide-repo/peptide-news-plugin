FROM php:8.3-cli-alpine

# Install curl extension and ca-certificates for secure HTTPS requests
RUN apk add --no-cache curl ca-certificates && \
    docker-php-ext-install curl || true

WORKDIR /app
COPY worker.php /app/worker.php

# Default entrypoint runs the worker
CMD ["php", "/app/worker.php"]
