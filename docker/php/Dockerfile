FROM php:5.6-cli

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /usr/src/app
ENTRYPOINT [ "php", "./cli.php" ]