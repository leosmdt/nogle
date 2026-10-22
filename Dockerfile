FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY . .
EXPOSE 8000
CMD ["php", "bin/serve.php", "--host=0.0.0.0", "--seed"]
