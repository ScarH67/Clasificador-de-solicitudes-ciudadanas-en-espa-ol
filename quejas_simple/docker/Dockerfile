# ---------------------------------------------------------------------------
#  Imagen de PHP para el sistema de reportes ciudadanos.
# ---------------------------------------------------------------------------
#  Se parte de la imagen oficial de PHP 5.6 con php-fpm. Es la misma rama de
#  PHP que ya usas (5.6), así que el código corre tal cual, sin adaptar nada.
#
#  Se eligió la variante de Debian y no la de Alpine porque esta trae el
#  compilador puesto de fábrica, y aquí hace falta para compilar dos
#  extensiones. A cambio, la imagen pesa más.
#
#  Un aviso honesto: PHP 5.6 dejó de recibir parches de seguridad hace años.
#  Esto se usa porque es lo que pide el entorno existente, no porque sea
#  recomendable para un proyecto nuevo. Lo que ayuda es que el contenedor
#  solo habla con la base y con el clasificador, y que nginx le corta el
#  acceso a los archivos que no son páginas.
# ---------------------------------------------------------------------------

FROM php:5.6-fpm

# Las dos extensiones que el código necesita y que no vienen activadas:
#
#   pdo_mysql  -> para hablar con la base. En funciones.php se usa PDO.
#   mbstring   -> para mb_strlen y mb_substr, que cuentan letras y no bytes.
#                 Sin esto, un texto con tildes o con "ñ" se corta a la mitad
#                 de un carácter y sale basura en pantalla.
#
# No hace falta apt-get: la imagen oficial ya trae las herramientas de
# compilación, y las dos extensiones se compilan contra librerías que vienen
# dentro del propio PHP. Se evita apt-get a propósito, porque los
# repositorios de Debian de esta versión ya están retirados y fallarían.
#
# curl, json, session, pdo, hash y openssl ya vienen activadas de fábrica.
# La función password_hash() que usa crear-admin.php viene en el núcleo de
# PHP desde la versión 5.5, así que también funciona sin hacer nada.
RUN docker-php-ext-install pdo_mysql mbstring

# Configuración propia. El nombre empieza con "zz" para que se lea al final
# y sobrescriba lo que la imagen trae por defecto.
COPY php.ini /usr/local/etc/php/conf.d/zz-reportes.ini

# Desde aquí trabaja el comando que se ejecute dentro del contenedor.
# Así "docker exec reportes_php php crear-admin.php" encuentra el archivo
# sin tener que escribir la ruta completa.
WORKDIR /var/www/reportes
