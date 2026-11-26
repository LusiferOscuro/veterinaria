FROM php:8.2-apache

# Instalar extensiones PDO MySQL y PDO SQLite
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite
RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copiar el código de la aplicación
COPY . /var/www/html/

# Crear carpeta data con permisos para la base de datos integrada
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html

# Render usa el puerto definido en la variable PORT (por defecto 10000)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE 10000

CMD ["apache2-foreground"]
