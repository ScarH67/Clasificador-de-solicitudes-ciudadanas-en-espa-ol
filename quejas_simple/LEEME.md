# Reportes ciudadanos — versión simple

Un formulario donde la gente reporta problemas de la ciudad, un clasificador
que decide a qué área le toca cada reporte, y un panel para que el personal los
atienda.

PHP puro. Sin framework, sin Composer, sin nada que instalar aparte. Corre en
**PHP 5.6 y MySQL 5.6**, el mismo entorno que ya tienes.

> **Si vas a montarlo en tu servidor CentOS, lee `DOCKER.md` y no este
> archivo.** Tu servidor tiene todo en contenedores y la instalación de aquí
> abajo no aplica: te haría perder tiempo con `dnf` y con SELinux cuando lo
> que hace falta es otra cosa. Lo de aquí sirve para probarlo en tu computadora
> y queda como apéndice por si algún día lo montas sin Docker.

---

## Los archivos

Son doce de la aplicación, más los cinco de Docker y el script de instalación.
Todo al mismo nivel, sin jerarquías raras.

| Archivo | Para qué |
|---|---|
| `config.php` | **Lo único que hay que cambiar.** Base de datos y dirección del clasificador |
| `funciones.php` | Conexión a la base, escapado de texto, llamada al clasificador, sesión |
| `plantilla.php` | El encabezado y el pie, para no repetir el HTML en cada página |
| `index.php` | El formulario público. Muestra y procesa el envío |
| `reporte.php` | Muestra un reporte y su folio |
| `consulta.php` | Buscar un reporte por folio |
| `entrar.php` | Acceso al panel |
| `panel.php` | Lista de reportes, detalle, cambio de estado |
| `salir.php` | Cerrar sesión |
| `crear-admin.php` | Crea el usuario del panel (se corre por consola) |
| `instalacion.sql` | Las tablas |
| `instalar-base.sh` | Crea la base e importa las tablas, en un solo paso |
| `estilos.css` | Los estilos |
| `DOCKER.md` | **La guía para el servidor**, que es lo que tú necesitas |
| `docker-compose.yml` | Los contenedores del servidor |
| `docker/Dockerfile` | La imagen de PHP 5.6 |
| `docker/php.ini` | Los ajustes de PHP en el servidor |
| `docker/nginx.conf` | La configuración de nginx en el servidor |

Los cuatro últimos solo hacen falta en el servidor. Si vas a probar en tu
computadora, se pueden ignorar.

---

## Probarlo en esta computadora

```bash
# 1. Las tablas (una sola vez)
mysql -u root -p -e "CREATE DATABASE reportes_ciudadanos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p reportes_ciudadanos < instalacion.sql

# 2. En config.php, comentar las líneas de Docker y descomentar las de XAMPP

# 3. Crear el usuario del panel
php crear-admin.php

# 4. Levantar el servidor
php -S 127.0.0.1:8080
```

Y ya: el formulario en `http://127.0.0.1:8080` y el panel en
`http://127.0.0.1:8080/entrar.php`.

`config.php` trae las dos configuraciones. Arriba están los datos de Docker,
que son los que van en el servidor; abajo, comentadas, las de XAMPP. Se
descomentan unas y se comentan las otras según dónde estés trabajando.

Si el clasificador no está prendido todavía, no importa: los reportes se
guardan igual y quedan marcados como **pendientes**. Después, desde el panel,
se les da a "volver a intentar clasificar".

---

## Montarlo en el servidor CentOS

Con todo en Docker, que es tu caso. El proyecto va en una subcarpeta propia
dentro de `/root/proyectos/erpymex`, para no mezclarlo con el ERP:

```bash
sudo mkdir -p /root/proyectos/erpymex/reportes
cd /root/proyectos/erpymex/reportes
# subir aquí los archivos

sudo docker compose up -d --build
```

Pero antes hay que confirmar el nombre de la red de Docker. **Está todo
explicado paso a paso en `DOCKER.md`**, junto con el diagnóstico de los errores
que te salieron.

Resumen de lo que cambia respecto a una instalación normal:

| | Sin Docker | Con Docker |
|---|---|---|
| `BD_HOST` | `127.0.0.1` | `legacy_mysql56` |
| Entrar a la base | `mysql -u root -p` | `docker exec -it legacy_mysql56 mysql -u root -p` |
| Permisos | `chown nginx:nginx` | No hace falta |
| SELinux | Hay que ajustarlo | Docker se encarga |
| Abrir el puerto | `firewall-cmd` | Se publica en `docker-compose.yml` |
| Dónde vive el código | `/var/www/reportes` | `/root/proyectos/erpymex/reportes` |
| Cómo se corre | `systemctl` | `sudo docker compose` |

La base ya está creada y con las tablas puestas, así que ese paso se puede
saltar.

---

## Dos cosas que conviene saber

**El clasificador puede estar caído y no se pierde nada.** El reporte se guarda
primero y se clasifica después. Si no contesta, queda como pendiente y desde el
panel se reintenta. Esto está a propósito.

**Las contraseñas se cifran con bcrypt y eso solo lo hace PHP.** Por eso el
usuario del panel no se crea desde el `.sql`, sino con `crear-admin.php`. No hay
usuario ni contraseña por defecto.

---

## Lo que este sistema no tiene

A propósito, para mantenerlo simple. Si algún día hace falta, se agrega:

- **Roles.** Ahora cualquiera que entre al panel puede hacer todo.
- **Tokens CSRF** en los formularios del panel.
- **Bitácora de quién cambió qué.** No se guarda el historial.
- **Bloqueo por intentos fallidos** en el acceso al panel.
- **Exportar a Excel.**

Lo que sí trae, porque no cuesta nada y evita problemas serios:

- Consultas con parámetros en toda la base de datos (nada de inyección SQL).
- Todo el texto que escribe el usuario se escapa antes de mostrarlo (nada de
  XSS).
- El identificador de sesión se renueva al entrar.
- Límite de reportes por hora, para que nadie llene la base.
- Los errores nunca se muestran al visitante.

---

## Si algo sale mal

| Qué se ve | Qué revisar |
|---|---|
| **ERROR 2002 ... socket '/run/mysqld/mysqld.sock' (2)** | Estás usando el cliente `mysql` del servidor y tu base está en un contenedor. Usa `docker exec`. Ver `DOCKER.md` |
| **ERROR 2002 ... on '127.0.0.1' (115)** | El puerto del contenedor de MySQL no está publicado, así que nadie contesta. No hay que publicarlo: usa `docker exec` |
| **Error 502** | El contenedor de PHP. `docker compose logs reportes_php`, o `systemctl status php56-php-fpm` sin Docker |
| **Error 500** | El log de PHP: `docker compose logs reportes_php` |
| No se ve la página, sale la de nginx | Falta el `try_files`, o el `root` está mal |
| La página sale sin estilos | Revisar que `estilos.css` esté junto a los demás archivos |
| **No puedo entrar al panel** | Crear el usuario otra vez: `docker exec -it reportes_php php crear-admin.php` |
| La app dice "No se pudo conectar a la base de datos" | Que `BD_HOST` sea el nombre del contenedor, y que los dos estén en la misma red |
| Los reportes se guardan sin categoría | El clasificador. Ver la sección 3 de `DOCKER.md` |
| Salen caracteres raros en las tildes | Que `config.php` use `utf8mb4` y la tabla también |

### Sobre el error 2002

Sale de dos maneras y las dos significan lo mismo: **no hay un MySQL nativo en
el servidor**. Está en un contenedor.

- El `(2)` es "no existe el archivo". El cliente busca un socket local y no hay.
- El `(115)` es "se agotó el tiempo". Los paquetes salieron y nadie contestó,
  porque el puerto del contenedor no está publicado.

La solución en los dos casos es entrar por el contenedor:

```bash
docker exec -it legacy_mysql56 mysql -u root -p
```

O, sin abrir sesión, para un comando suelto:

```bash
docker exec -i legacy_mysql56 mysql -u root -proot123 -e "SHOW DATABASES;"
```

A la aplicación esto no le afecta, porque se conecta por la red interna de
Docker usando el nombre del contenedor.

---

## Apéndice — instalación sin Docker

Solo por si algún día se monta en un servidor limpio, sin contenedores. En tu
caso actual **no aplica**.

### 1. Los paquetes

```bash
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm

sudo dnf install -y --enablerepo=remi --enablerepo=remi-php56 \
    php56-php php56-php-cli php56-php-fpm php56-php-mysqlnd \
    php56-php-mbstring php56-php-json php56-php-curl php56-php-pdo

sudo dnf install -y nginx mariadb-server
sudo systemctl enable --now mariadb nginx
```

Si `dnf` no conecta a nada, es porque CentOS 8 llegó a su fin de vida. Se
arregla reapuntando los repositorios al archivo histórico:

```bash
sudo sed -i 's|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/CentOS-*.repo
sudo sed -i 's|^#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/CentOS-*.repo
sudo dnf clean all
```

Un atajo para no escribir la ruta larga de PHP:

```bash
sudo ln -s /opt/remi/php56/root/usr/bin/php /usr/local/bin/php56
```

### 2. La base de datos

```bash
sudo mysql
```

```sql
CREATE DATABASE reportes_ciudadanos
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'reportes'@'localhost' IDENTIFIED BY 'una-contraseña-larga';
GRANT ALL PRIVILEGES ON reportes_ciudadanos.* TO 'reportes'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

El usuario se crea para `localhost` y no para `%`: la base no tiene por qué ser
alcanzable desde la red.

### 3. El código

```bash
sudo mkdir -p /var/www/reportes
# subir los archivos aquí (scp, rsync, git, como prefieras)

cd /var/www/reportes
sudo mysql -u root -p --default-character-set=utf8mb4 reportes_ciudadanos < instalacion.sql
```

Y editar `config.php`:

```php
define('BD_HOST',    '127.0.0.1');
define('BD_NOMBRE',  'reportes_ciudadanos');
define('BD_USUARIO', 'reportes');
define('BD_CLAVE',   'una-contraseña-larga');

define('CLASIFICADOR_URL', 'http://192.168.100.10:8000');
```

### 4. El usuario del panel

```bash
cd /var/www/reportes
sudo php56 crear-admin.php
```

### 5. nginx

Crear `/etc/nginx/conf.d/reportes.conf`:

```nginx
server {
    listen 80;
    server_name reportes.midominio.mx;

    root /var/www/reportes;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include       fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass  unix:/var/opt/remi/php56/run/php-fpm/www.sock;
    }

    location ~ \.(sql|md|ini)$ {
        deny all;
        return 404;
    }
}
```

Cambiar `server_name` por el dominio o la IP real. Y arrancar:

```bash
sudo nginx -t
sudo systemctl enable --now nginx php56-php-fpm
sudo systemctl status nginx php56-php-fpm
```

Si el socket de php-fpm no está en esa ruta, se averigua con:

```bash
sudo grep -r "^listen" /etc/opt/remi/php56/php-fpm.d/
```

### 6. Permisos

```bash
sudo chown -R nginx:nginx /var/www/reportes
sudo find /var/www/reportes -type d -exec chmod 755 {} \;
sudo find /var/www/reportes -type f -exec chmod 644 {} \;
sudo chmod 640 /var/www/reportes/config.php
```

`config.php` lleva la contraseña de la base, así que se deja más cerrado que el
resto.

### 7. SELinux

Si `getenforce` dice `Enforcing`, hay que hacer esto o PHP no va a poder
conectarse al clasificador:

```bash
# Que nginx pueda leer el código
sudo semanage fcontext -a -t httpd_sys_content_t "/var/www/reportes(/.*)?"
sudo restorecon -Rv /var/www/reportes

# Que PHP pueda hacer conexiones de red salientes (¡importante!)
sudo setsebool -P httpd_can_network_connect 1
```

Si `semanage` no existe: `sudo dnf install -y policycoreutils-python-utils`.

Sin ese `setsebool`, los reportes se guardan pero nunca se clasifican, y el
error que sale no explica por qué. Es el problema que más tiempo hace perder en
una instalación sin Docker.
