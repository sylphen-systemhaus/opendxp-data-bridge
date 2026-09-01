FROM opendxp/opendxp:php8.4-debug-latest
RUN apt-get update && apt-get install -y autoconf build-essential \
    && docker-php-ext-install ftp \
    && apt-get install -y vim openssh-client wkhtmltopdf procps nodejs \
    && rm -rf /var/lib/apt/lists/*
RUN composer self-update --2
RUN mv /var/www/html /var/www/opendxp
WORKDIR /var/www/opendxp
