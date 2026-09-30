# Reportes ciudadanos — versión simple

Un formulario donde la gente reporta problemas de la ciudad, un clasificador
que decide a qué área le toca cada reporte, y un panel para que el personal los
atienda.

PHP puro. Sin framework, sin Composer, sin nada que instalar aparte. Corre en
**PHP 5.6 y MySQL 5.6**, el mismo entorno que ya tienes.

> **Esta es la carpeta del sitio.** Todo lo nuevo se agrega aquí.
> La carpeta de arriba (`quejas_simple/*.php`) es la versión vieja y quedó como
> respaldo: no se toca. El aviso completo está en `../HISTORICO.md`.

---

## Los archivos

Son catorce. No hay carpetas ni jerarquías raras: todo está al mismo nivel.

| Archivo | Para qué |
|---|---|
| `config.php` | **Lo único que hay que cambiar.** Base de datos, dirección del clasificador, umbral de revisión y los textos del sitio |
| `funciones.php` | Conexión a la base, escapado de texto, llamada al clasificador, sesión |
| `plantilla.php` | El encabezado y el pie, para no repetir el HTML en cada página |
| `index.php` | El formulario público. Muestra y procesa el envío |
| `reporte.php` | Muestra un reporte y su folio |
| `consulta.php` | Buscar un reporte por folio |
| `entrar.php` | Acceso al panel |
| `panel.php` | Lista de reportes, detalle, clasificación y cambio de estado |
| `salir.php` | Cerrar sesión |
| `crear-admin.php` | Crea el usuario del panel (se corre por consola) |
| `instalacion.sql` | Las tablas, para una base nueva |
| `migracion-campos-nuevos.sql` | Agrega las columnas nuevas a una base que ya está en uso |
| `migracion-hora-utc.sql` | Corrige la hora de los reportes guardados antes del arreglo de zona horaria |
| `estilos.css` | Los estilos |

---

## Probarlo en esta computadora

```bash
# 1. Las tablas (una sola vez)
mysql -u root -p -e "CREATE DATABASE reportes_ciudadanos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p reportes_ciudadanos < instalacion.sql

# 2. Revisar config.php y poner bien la contraseña de la base

# 3. Crear el usuario del panel
php crear-admin.php

# 4. Levantar el servidor
php -S 127.0.0.1:8080
```

Y ya: el formulario en `http://127.0.0.1:8080` y el panel en
`http://127.0.0.1:8080/entrar.php`.

Si el clasificador no está prendido todavía, no importa: los reportes se
guardan igual y quedan marcados como **sin clasificar**. Después, desde el
panel, se les da a **Reclasificar**.

---

## Qué se guarda de cada reporte

### Las tablas

Dos: `usuarios` (el personal del panel) y `quejas` (los reportes).

### Las columnas de `quejas`

| Columna | Qué es |
|---|---|
| `folio` | El del sitio: `CDMX-2026-000123`. Es el que ve el ciudadano |
| `folio_api` | El que genera el clasificador: `CDMX-A1B2C3D4`. Sirve para rastrear de dónde salió cada clasificación |
| `colonia` | Lo que escribió el ciudadano. Opcional |
| `texto` | El reporte tal cual |
| `categoria` | Una de las cinco, o `fuera_de_alcance` si la eligió una persona |
| `confianza` | Qué tan seguro estuvo el modelo, de 0 a 1 |
| `posible_fuera_de_alcance` | 1 si el guardián marcó el texto como ajeno a las cinco categorías |
| `estado` | El avance del trámite. Ver abajo |
| `estado_revision` | La calidad de la clasificación. Ver abajo |
| `tiempo_ms` | Lo que tardó el modelo, en milisegundos |
| `metadatos` | El bloque de metadatos del clasificador, tal cual, como JSON |
| `clasificado_manual` | 1 si una persona eligió la categoría desde el panel |
| `nota` | Nota interna del personal. El ciudadano no la ve |
| `ip` | Para el límite de reportes por hora |
| `creado_en` | Cuándo llegó |
| `nombre` | **Ya no se usa.** Se conserva solo por los reportes viejos |

### `estado` y `estado_revision` no son lo mismo

Es lo que más se confunde, así que va aparte:

- **`estado`** es el avance del trámite, y lo ve el ciudadano: `pendiente`,
  `canalizado`, `en_proceso`, `atendido`.
- **`estado_revision`** es qué tan confiable salió la clasificación, y es cosa
  del personal:
  - `sin_clasificar` — el clasificador no contestó
  - `automatico` — salió bien, nadie tiene que revisarlo
  - `requiere_revision` — la confianza no llegó al umbral, o el guardián
    levantó la mano
  - `revisado_manual` — una persona eligió la categoría

Un reporte puede estar `canalizado` y a la vez `requiere_revision`. No se
contradicen: ya tiene área asignada, pero conviene que alguien lo confirme.

---

## Qué manda el clasificador

El clasificador contesta con un ticket así:

```json
{
  "folio": "CDMX-A1B2C3D4",
  "texto_original": "llevamos cinco días sin agua en la Portales",
  "categoria": "fuga_de_agua",
  "categoria_legible": "Agua y drenaje",
  "confianza": 0.987,
  "posible_fuera_de_alcance": false,
  "estado_revision": "automatico",
  "fecha_hora": "2026-09-27T22:45:00",
  "tiempo_ms": 45.8,
  "metadatos": {
    "modelo": "beto_finetunado_calibrado",
    "dispositivo": "cpu",
    "temperatura_calibracion": 0.363,
    "umbral_abstencion": 0.9
  }
}
```

El sitio guarda la categoría, la confianza, el folio del API, el tiempo y los
metadatos completos. El resto no hace falta.

**Sobre el umbral.** La API ya trae su propio `estado_revision`, pero no usa el
mismo umbral en los dos modelos: BETO corta en 0.90 y el baseline en 0.60. Para
que el sitio se comporte igual sin importar cuál esté levantado, aquí se vuelve
a calcular con `UMBRAL_REVISION` (0.90, en `config.php`) y se toma el criterio
más estricto de los dos. Si se quiere cambiar, se cambia ahí y ya.

**Sobre los metadatos.** Van en una sola columna de texto, como JSON, y no
repartidos en columnas. Es a propósito: el baseline no manda `dispositivo` ni
`temperatura_calibracion`, así que el contenido cambia según qué modelo esté
corriendo. Guardarlos completos evita que se pierda algo.

---

## Actualizar una base que ya está en uso

`instalacion.sql` usa `CREATE TABLE IF NOT EXISTS`, y eso **no toca una tabla
que ya existe**. O sea que volver a importarlo sobre la base que ya tiene
reportes no agrega nada.

Para eso está `migracion-campos-nuevos.sql`. Se corre una sola vez. Primero
entra a la carpeta donde están los archivos:

```bash
cd /root/proyectos/erpymex/reportes
```

Y luego, todo en una sola línea (si ya eres root, quítale el `sudo`):

```bash
sudo docker exec -i legacy_mysql56 mysql -u root -proot123 --default-character-set=utf8mb4 reportes_ciudadanos < migracion-campos-nuevos.sql
```

El aviso `Using a password on the command line interface can be insecure` es
normal, no es un error.

Para comprobar que quedó:

```bash
sudo docker exec -i legacy_mysql56 mysql -u root -proot123 reportes_ciudadanos -e "SHOW COLUMNS FROM quejas;"
```

MySQL 5.6 no tiene `ADD COLUMN IF NOT EXISTS`, así que ese archivo no se puede
correr dos veces: la segunda vez falla con `Duplicate column name`. Si eso pasa
no se rompió nada, solo revisa con el `SHOW COLUMNS` de arriba.

---

## La página del folio

Después de mandar el formulario, el ciudadano cae en `reporte.php`, que le muestra
su folio. Abajo de todo va una tarjeta de **Resumen técnico** con tres datos:

| Dato | De dónde sale |
|---|---|
| Qué tan seguro estuvo el sistema | `confianza`, como `90% (0.9)` |
| Tiempo de respuesta | `tiempo_ms`, redondeado: `32 ms` |
| Modelo usado | El campo `modelo` de `metadatos`, traducido |

El porcentaje y el valor van juntos a propósito: el valor entre paréntesis es el
número tal cual lo manda el clasificador, y así se puede comparar contra el umbral
sin hacer cuentas.

**El modelo se traduce.** Lo que manda la API es el nombre técnico
(`beto_finetunado_calibrado`, `tfidf_logreg_calibrado`), que al ciudadano no le dice
nada. La función `nombre_modelo()` de `funciones.php` lo convierte en `BETO (BERT en
español)`, `TF-IDF + regresión logística` o `Ninguno` si no hubo clasificación. Para
agregar un modelo nuevo se mete otra condición ahí; si el nombre no coincide con
ninguna, se muestra tal cual vino.

Si el reporte no se pudo clasificar, la tarjeta dice `Sin clasificar`, `—` y
`Ninguno`, que es exactamente lo que pasó.

---

## El panel

### Los dos botones de clasificación

En la lista y en el detalle de cada reporte hay dos:

- **Reclasificar** — le vuelve a mandar el texto al modelo. Sirve cuando el
  clasificador estaba apagado o cuando se cambió de modelo.
- **Clasificar a mano** — abre el detalle para elegir la categoría entre las
  cinco más "Fuera de categoría".

Cuando se usa el segundo, el reporte queda con `clasificado_manual = 1` y su
`estado_revision` pasa a `revisado_manual`, así que sale del filtro de
"Requiere revisión".

Si después alguien le da a **Reclasificar**, la categoría que había elegido la
persona se sobrescribe y `clasificado_manual` vuelve a 0. Es a propósito: ese
campo dice quién eligió la categoría que está guardada **ahora**, no quién la
tocó alguna vez.

### Los filtros

Arriba de la tabla hay dos filas: la primera filtra por estado del trámite, la
segunda por estado de revisión.

- **Requiere revisión** muestra los que necesitan que los mire alguien, con el
  número entre paréntesis. Es el filtro que más se va a usar.
- **Clasificados a mano** y **Sin clasificar** sirven para ver qué tanto está
  resolviendo el modelo y qué tanto el personal.

### Los botones

Hay un solo sistema de botones, definido en `estilos.css`:

| Clase | Cuándo se usa | Cómo se ve |
|---|---|---|
| `.boton` | El botón que guarda algo, en cualquier formulario | Relleno azul |
| `.boton-secundario` | Para navegar (volver, ir a otra página) | Blanco con borde |
| `.boton-mini` | La versión chica, para dentro de la tabla | Igual que el secundario |

Los tres comparten la misma base, así que miden y se ven iguales. Antes cada uno
traía su propio relleno y su propio tamaño de letra, y al verlos juntos se notaba
la diferencia.

En la tabla, los dos botones de cada renglón van dentro de `.acciones-fila`, que
los pone en el mismo renglón y con la misma separación.

---

## Sobre el aviso del sitio

El sitio **no es un servicio del gobierno**. Es un proyecto universitario, y así
se dice en el pie de todas las páginas, en la cabecera y en el formulario.

Antes decía "Gobierno de la Ciudad de México" y se quitó a propósito, por dos
razones: no conviene que alguien crea que está haciendo un trámite oficial, y
el trabajo se presenta como lo que es.

Por lo mismo, **el formulario ya no pide el nombre**. Los reportes nuevos se
guardan sin identificar a nadie. La columna `nombre` sigue en la tabla para no
perder los reportes viejos, pero ya no se llena ni se muestra.

Los textos se cambian desde `config.php`, en `SITIO_AVISO` y `SITIO_LEYENDA`.

---

## Montarlo en el servidor CentOS

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

Y editar `config.php` con los datos del servidor:

```php
define('BD_HOST',    '127.0.0.1');
define('BD_NOMBRE',  'reportes_ciudadanos');
define('BD_USUARIO', 'reportes');
define('BD_CLAVE',   'una-contraseña-larga');

define('CLASIFICADOR_URL', 'http://192.168.100.10:8000');
```

La IP del clasificador es la de la computadora que corre el modelo.

### 4. El usuario del panel

```bash
cd /var/www/reportes
sudo php56 crear-admin.php
```

Pregunta el usuario, el nombre y la contraseña. Se puede correr las veces que
haga falta.

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

    # Estos dos no tienen por qué bajarse desde el navegador.
    location ~ \.(sql|md)$ {
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
error que sale no explica por qué. Es el problema que más tiempo hace perder.

### 8. Comprobar

Abrir en el navegador:

1. `http://tu-dominio/` — escribir un reporte de prueba.
2. Debe salir la pantalla con el folio.
3. `http://tu-dominio/entrar.php` — entrar con el usuario que creaste.
4. En el panel, abrir el reporte y ver si trae categoría.

Si trae categoría, ya quedó todo. Si dice "sin clasificar", el problema es la
conexión con el clasificador: revisar la IP en `config.php` y el `setsebool` de
arriba.

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
| **Error 502** | `systemctl status php56-php-fpm`. Y que la ruta del socket en nginx sea la correcta |
| **Error 500** | El log de PHP: `journalctl -u php56-php-fpm -n 50` |
| No se ve la página, sale la de nginx | Falta el `try_files`, o el `root` está mal |
| La página sale sin estilos | Revisar que `estilos.css` esté junto a los demás archivos |
| **No puedo entrar al panel** | Crear el usuario otra vez: `php56 crear-admin.php` |
| Los reportes se guardan sin categoría | El clasificador. Ver la sección de SELinux |
| Salen caracteres raros en las tildes | Que `config.php` use `utf8mb4` y la tabla también |
| **La hora de los reportes sale adelantada** | Que `BD_ZONA_HORARIA` esté en `config.php` (ver *La hora del sitio*). Si solo pasa con los reportes viejos, falta correr `migracion-hora-utc.sql` |
