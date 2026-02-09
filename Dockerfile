# Usa a imagem oficial do PHP com Apache
FROM php:8.2-apache

# Habilita o módulo rewrite do Apache
RUN a2enmod rewrite

# Define o ServerName para evitar avisos no log (opcional, mas recomendado)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copia os arquivos do projeto
COPY . /var/www/html/

# Define as permissões corretas
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configuração Principal do Apache
# 1. DirectoryIndex: Define globalmente que index.php e index.html são os arquivos padrão.
# 2. Options -Indexes: O sinal de menos (-) BLOQUEIA a listagem de diretórios se não houver index.
# 3. AllowOverride All: Garante que se você criar um .htaccess específico, ele será lido.
RUN echo '<Directory /var/www/html/> \n\
    Options -Indexes +FollowSymLinks \n\
    AllowOverride All \n\
    Require all granted \n\
    DirectoryIndex index.php index.html \n\
</Directory>' > /etc/apache2/conf-available/docker-php.conf \
    && a2enconf docker-php

# Expõe a porta 80
EXPOSE 80
