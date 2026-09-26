# Montarlo en CentOS con Docker

Esta guía reemplaza a la sección de instalación nativa del `LEEME.md`. Tu
servidor ya tiene todo en contenedores, así que instalar PHP y MySQL con `dnf`
no aplica: lo que hace falta es meter la aplicación en la misma red que tu
MySQL y apuntarla al contenedor correcto.

---

## 1. Por qué salían tus dos errores

Los dos son el mismo malentendido visto desde dos lados: **dentro de un
contenedor, `127.0.0.1` es el contenedor mismo**, no el servidor.

### El error del socket

```
ERROR 2002 (HY000): Can't connect to local server through socket '/run/mysqld/mysqld.sock' (2)
```

Ese comando lo corriste en la **terminal del servidor**, no dentro de un
contenedor. El cliente `mysql` de CentOS busca un servidor MySQL instalado en
el propio sistema operativo, y ahí no hay ninguno: tu MySQL vive en un
contenedor. El `(2)` es el código de error del sistema y significa "no existe
el archivo" — o sea, no hay socket porque no hay MySQL nativo.

### El error del 115

```
ERROR 2002 (HY000): Can't connect to server on '127.0.0.1' (115)
```

Aquí ya le dijiste que fuera por TCP, y el `(115)` es la pista importante.
Es el código `EINPROGRESS`, que en la práctica significa **se agotó el tiempo
de espera**. Los paquetes salieron y no los contestó nadie: no hay nada
escuchando en el 3306 del servidor, porque el puerto del contenedor de MySQL
nunca se publicó hacia afuera.

Si el puerto estuviera publicado pero el servicio caído, el error habría sido
`(111)`, que es "conexión rechazada". La diferencia entre `111` y `115` es
justo la que separa "llegué y me rechazaron" de "no llegué a ningún lado".

### Lo que esto significa para ti

| | |
|---|---|
| El cliente `mysql` del servidor | **No sirve.** No hay MySQL nativo. |
| Cómo se entra a la base | `docker exec -it legacy_mysql56 mysql -u root -p` |
| `BD_HOST` en la app | El **nombre del contenedor**, que ya es lo que tienes |
| Publicar el 3306 | **No hace falta.** Y es mejor no hacerlo |

Tu configuración anterior ya tenía la respuesta correcta:

```php
define('DB_HOST', 'legacy_mysql56');   // nombre del contenedor, no una IP
```

Eso es exactamente lo que quedó puesto en `config.php`.

---

## Datos del servidor (ya confirmados)

Estos datos se comprobaron desde la red local el 20 de septiembre de 2026. Si
algún día algo deja de responder, lo primero es volver a comprobar esto.

| Dato | Valor |
|---|---|
| IP del servidor | `192.168.100.3` |
| MySQL desde tu computadora | `192.168.100.3`, **puerto 3307** |
| MySQL desde dentro de Docker | `legacy_mysql56`, puerto 3306 |
| Versión de MySQL | 5.6.51 |
| Usuario | `root@%` — sí acepta conexiones remotas |
| Bases de datos | `legacydb`, `mysql`, `information_schema`, `performance_schema` |
| Portainer (ver los contenedores) | `http://192.168.100.3:9000` |
| La app de reportes | `http://192.168.100.3/` |
| El ERP Maggis | `http://192.168.100.3:8080` |

### Por qué el puerto es 3307 y no 3306

El contenedor de MySQL escucha en el 3306 **por dentro**, pero hacia afuera se
publica en el **3307** del servidor. Es una decisión normal: deja libre el 3306
del servidor por si algún día se instala un MySQL nativo.

De ahí salían los dos errores. En el servidor, `mysql -h 127.0.0.1` usa el 3306
por defecto y ahí no hay nada escuchando: por eso el `(115)`, que es un tiempo
agotado y no un rechazo.

Desde tu computadora el comando correcto es:

```bash
mysql -h 192.168.100.3 -P 3307 -u root -p
```

La `P` mayúscula es la del puerto; la `p` minúscula es la de la contraseña. Es
fácil confundirlas y el error que sale no ayuda.

Y si algún día el 192.168.100.3 deja de responder, casi siempre es que el
servidor cambió de IP por DHCP. Se comprueba en un segundo desde la consola del
servidor:

```bash
hostname -I
```

### Ver los contenedores sin entrar por SSH

El servidor tiene **Portainer** en `http://192.168.100.3:9000`. Ahí se ve cada
contenedor con su IP interna, sus puertos publicados y sus redes. Es la forma
más rápida de responder "¿en qué puerto está publicada la base?" sin tocar la
consola, y también sirve para sacar el nombre de la red de Docker que hace
falta en `docker-compose.yml`.

---

## 2. Confirmar tres datos antes de empezar

### El nombre de la red

Los contenedores solo se ven entre ellos si están en la misma red. Tu MySQL ya
está en una, y la app tiene que entrar a esa misma:

```bash
docker inspect legacy_mysql56 \
  --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
```

Va a imprimir algo como `legacy_default`. **Ese nombre es el que va en
`docker-compose.yml`**, en la parte de hasta abajo:

```yaml
networks:
  legacy:
    external: true
    name: legacy_default     # <-- aquí
```

Si no coincide, Docker se queja con `network legacy_default not found` y no
levanta nada. Es un error claro y se corrige cambiando esa línea.

### Los contenedores que ya tienes

```bash
docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
```

Interesa ver si ya hay un contenedor con PHP 5.6 sirviendo tu proyecto
anterior. Si lo hay, tienes la opción A de abajo, que es más corta.

### La contraseña de MySQL

```bash
docker inspect legacy_mysql56 \
  --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -i mysql
```

Si sale `MYSQL_ROOT_PASSWORD=...`, esa es. En `config.php` quedó `root123`
porque es la que me pasaste; si aquí sale otra, se corrige ahí.

---

## Opción A — reusar el contenedor de PHP que ya tienes

**Solo si el paso anterior mostró un contenedor con PHP.** Es la más rápida:
no se construye nada, solo se copian los archivos.

```bash
# 1. Ver qué contenedor sirve PHP y dónde tiene su carpeta web
docker exec -it NOMBRE_DEL_CONTENEDOR ls /var/www/html

# 2. Copiar la aplicación adentro
docker cp ./quejas_simple/. NOMBRE_DEL_CONTENEDOR:/var/www/html/reportes/

# 3. Listo. Entrar en http://IP-DEL-SERVIDOR/reportes/
```

Lo único que hay que revisar es que ese contenedor **esté en la misma red que
MySQL**. Si tu proyecto anterior ya funcionaba así, lo está.

La desventaja: la app queda viviendo dentro de otro proyecto. Si mañana
actualizas ese contenedor, se lleva los archivos consigo. Por eso la opción B
suele convenir más.

---

## Opción B — stack propio (la recomendada)

Dos contenedores nuevos —nginx y PHP— que se conectan a tu MySQL existente.
No se crea una segunda base de datos ni se duplica nada.

### Paso 1. Subir los archivos

El proyecto vive en `/root/proyectos/erpymex`, junto al ERP anterior. Está bien
dejarlo ahí para no mover la configuración que ya funciona, pero **en una
subcarpeta propia**:

```bash
sudo mkdir -p /root/proyectos/erpymex/reportes
cd /root/proyectos/erpymex/reportes
# subir aquí todo el contenido de quejas_simple/
```

#### Por qué en una subcarpeta y no suelto en `erpymex/`

Dos razones, y la primera es la importante:

1. **No pisar el `docker-compose.yml` del ERP.** Si en esa carpeta ya había uno
   —y es probable, porque el ERP corre en contenedores— al copiar el nuestro
   encima, el del ERP se pierde. Y no hay forma de recuperarlo salvo que esté en
   git o en un respaldo.
2. **Docker usa el nombre de la carpeta como nombre del proyecto.** Dos
   `docker-compose.yml` en la misma carpeta comparten proyecto, y un
   `docker compose down` en uno se lleva los contenedores del otro por delante.

Antes de seguir, conviene comprobar que no pasó lo primero:

```bash
sudo find /root/proyectos/erpymex -name "docker-compose*.yml" -maxdepth 3
```

Si sale más de uno, todo bien. Si sale **solo el nuestro**, el del ERP se
sobrescribió y hay que recuperarlo antes de levantar nada.

#### Trabajar dentro de `/root` significa usar `sudo` en todo

`/root` es la carpeta personal del administrador y nadie más puede entrar. Eso
cambia un par de cosas:

| Si estuviera fuera de `/root` | Estando dentro de `/root` |
|---|---|
| `cd /root/proyectos/erpymex/reportes` | Igual, pero después de entrar con `sudo su` |
| `ls` | `sudo ls` |
| `docker ps` | `sudo docker ps`, si tu usuario no está en el grupo `docker` |
| `bash instalar-base.sh` | `sudo bash instalar-base.sh` |

Lo más cómodo para no estar escribiendo `sudo` todo el rato: pasar a ser root
con `sudo su` y trabajar desde ahí. Se sale escribiendo `exit`.

Y un detalle de SELinux: los archivos dentro de `/root` llevan una etiqueta que
los contenedores no pueden leer. Si al levantar sale `permission denied`, se
arregla agregando `:z` al volumen en `docker-compose.yml`:

```yaml
- ./:/var/www/reportes:z
```

Si el ERP ya corre desde esa misma carpeta sin problemas, es que SELinux no
está estorbando y no hace falta.

Conviene que quede así:

```
/root/proyectos/erpymex/reportes/
├── config.php
├── funciones.php
├── index.php
├── ... los demás .php
├── instalacion.sql
├── instalar-base.sh
├── docker-compose.yml
└── docker/
    ├── Dockerfile
    ├── php.ini
    └── nginx.conf
```

### Paso 2. Ajustar el nombre de la red

En `docker-compose.yml`, cambiar `legacy_default` por el que salió en el paso 2
de arriba.

### Paso 3. Crear la base de datos

**La forma fácil**, y la que conviene usar: un script que hace los tres pasos y
avisa si algo falta.

```bash
cd /root/proyectos/erpymex/reportes
bash instalar-base.sh
```

No hay que darle permisos de ejecución; con `bash` delante ya corre. Se puede
correr las veces que haga falta.

**Si prefieres a mano**, cada comando va en **una sola línea**. Los `\` de
continuación son la causa de que "no pase nada": si al pegarlos queda un espacio
detrás de la barra, el shell se queda esperando y muestra un `>` en vez de
ejecutar. Mejor no usarlos.

```bash
docker exec -i legacy_mysql56 mysql -u root -proot123 -e "CREATE DATABASE IF NOT EXISTS reportes_ciudadanos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```bash
docker exec -i legacy_mysql56 mysql -u root -proot123 --default-character-set=utf8mb4 reportes_ciudadanos < instalacion.sql
```

```bash
docker exec -it legacy_mysql56 mysql -u root -proot123 reportes_ciudadanos -e "SHOW TABLES;"
```

Deben salir dos: `quejas` y `usuarios`.

Tres cosas que hacen falta para que la segunda línea funcione:

1. **Estar parado en la carpeta donde está `instalacion.sql`.** El archivo se
   lee desde el servidor, no desde tu computadora. Se comprueba con `ls`.
2. **Que la base ya exista.** Si no, el error es `Unknown database` y no dice
   gran cosa. Por eso va primero el comando de arriba.
3. **Que tu usuario pueda usar docker.** Si sale `permission denied`, corre todo
   con `sudo` delante.

> El aviso `Using a password on the command line interface can be insecure` es
> normal y no es un error. Lo imprime mysql siempre que la contraseña va pegada
> al `-p`. Se puede ignorar.

### Paso 4. Levantar los contenedores

```bash
cd /root/proyectos/erpymex/reportes
docker compose up -d --build
```

La primera vez tarda un poco porque compila las extensiones de PHP. Después ya
no, porque la imagen queda guardada.

Si tu servidor tiene la versión vieja del comando, va sin espacio:

```bash
docker-compose up -d --build
```

Para ver que arrancaron bien:

```bash
docker compose ps
docker compose logs reportes_php
```

### Paso 5. Crear el usuario del panel

```bash
docker exec -it reportes_php php crear-admin.php
```

Pregunta el usuario, el nombre y la contraseña. Se puede correr las veces que
haga falta, incluso para cambiar una contraseña olvidada.

### Paso 6. Comprobar

```bash
curl -I http://localhost:8080/
```

Debe contestar `HTTP/1.1 200 OK`. Luego, desde el navegador:

1. `http://IP-DEL-SERVIDOR:8080/` — escribir un reporte de prueba.
2. Debe salir la pantalla con el folio.
3. `http://IP-DEL-SERVIDOR:8080/entrar.php` — entrar al panel.
4. Abrir el reporte y ver si trae categoría.

Si trae categoría, ya quedó todo.

---

## 3. Comprobar que el contenedor alcanza al clasificador

Este es el punto que más se atora, y el error no dice por qué. Se prueba desde
dentro del contenedor de PHP, que es donde corre la aplicación:

```bash
docker exec -it reportes_php php -r '$c=curl_init("http://192.168.100.10:8000/salud"); curl_setopt($c,CURLOPT_RETURNTRANSFER,true); curl_setopt($c,CURLOPT_TIMEOUT,5); curl_setopt($c,CURLOPT_PROXY,""); $r=curl_exec($c); echo $r ? $r : "FALLA: ".curl_error($c); echo "\n";'
```

Si contesta con la lista de categorías, hay comunicación. Si dice `FALLA`, el
problema está en la red, no en el código: revisar que la IP en `config.php` sea
la de la computadora que corre el modelo y que esa computadora tenga el puerto
8000 abierto.

Un caso aparte: si el clasificador corre **en el mismo servidor** que Docker,
desde el contenedor no se llega por `127.0.0.1`. Se usa la IP de la red local
del servidor, o `host.docker.internal` si el motor de Docker lo soporta.

---

## 4. Actualizar el código después

El código está montado desde el disco, no copiado dentro de la imagen. Así que
para corregir un archivo basta con subirlo:

```bash
scp index.php usuario@servidor:/root/proyectos/erpymex/reportes/
```

No hay que reconstruir ni reiniciar nada. PHP lee el archivo en cada petición.

Solo hay que reconstruir cuando se toque algo de `docker/`:

```bash
docker compose up -d --build
```

---

## 5. Cosas de Docker que conviene saber

**`127.0.0.1` no significa lo que parece.** Dentro de un contenedor es ese
contenedor y nada más. Para llegar a otro contenedor se usa su nombre, y para
llegar al servidor se usa la IP de la red local. Esta sola idea explica la
mayoría de los errores raros de Docker.

**Docker se salta a firewalld.** Cuando se publica un puerto en
`docker-compose.yml`, Docker escribe sus propias reglas de red, y el firewall
de CentOS no las ve. O sea que **no hace falta** un `firewall-cmd --add-port`
para el 8080: si el contenedor está arriba, el puerto ya está abierto. Esto es
al revés de lo que uno espera y sorprende a mucha gente.

**Publicar el 3306 es un riesgo innecesario.** La aplicación entra a la base por
la red interna de Docker, sin salir a la red local. Si algún día alguien
descomenta un `ports: - "3306:3306"` en el contenedor de MySQL, está dejando la
base al alcance de toda la red. No hace falta para nada.

**No cambiar el contenedor de MySQL a la versión 8.** MySQL 8 usa un método de
autenticación (`caching_sha2_password`) que PHP 5.6 no entiende. La app
empezaría a fallar al conectar sin un mensaje claro. El 5.6 que ya tienes es el
correcto, y es el mismo que el entorno de desarrollo.

**Si nginx no puede leer los archivos, es SELinux.** Con `getenforce` en
`Enforcing`, un volumen montado desde el disco puede quedar bloqueado. Se
arregla agregando `:z` al volumen:

```yaml
- ./:/var/www/reportes:z
```

**La sesión se pierde al reiniciar el contenedor de PHP.** Las sesiones viven
en el `/tmp` de adentro. Para un panel interno no molesta. Si molesta, se le
monta un volumen a `/var/lib/php/sessions`.

**`restart: unless-stopped` ya está puesto.** Si el servidor se reinicia, los
contenedores vuelven solos. No hay que hacer nada a mano.

---

## 6. Si algo sale mal

| Qué se ve | Qué revisar |
|---|---|
| **`No such container: reportes_php`** | Todavía no levantaste los contenedores. Primero `docker compose up -d --build`, y después el `docker exec` |
| **Se queda en `>` y no pasa nada** | Pegaste un comando partido con `\`. Cópialo en una sola línea, o corre `bash instalar-base.sh` |
| **`Unknown MySQL server host '<' (4)`** | Estás dentro del cliente de MySQL, no en la terminal del servidor. Escribe `exit` y vuelve a empezar. Ver abajo |
| **`Unknown database 'reportes_ciudadanos'`** | La base todavía no existe. Primero el `CREATE DATABASE` del paso 3 |
| **`permission denied` al usar docker** | Tu usuario no está en el grupo `docker`. Corre el script con `sudo bash instalar-base.sh` |
| **`No such file or directory` con `instalacion.sql`** | No estás parado en la carpeta del archivo. Comprueba con `ls` y entra con `cd` |
| `network legacy_default not found` | El nombre real de la red. Paso 2 de arriba |
| **Error 502 Bad Gateway** | `docker compose logs reportes_php`. Y que `fastcgi_pass` apunte a `reportes_php:9000`, que es el nombre del servicio |
| **Error 500** | `docker compose logs reportes_php`. Los errores van al log del contenedor, no a la pantalla |
| La página sale sin estilos | Que `estilos.css` se haya subido junto a los demás |
| "No se pudo conectar a la base de datos" | Que `BD_HOST` sea `legacy_mysql56` y que los dos contenedores estén en la misma red: `docker network inspect NOMBRE_DE_LA_RED` |
| Los reportes se guardan sin categoría | El clasificador. Ver la sección 3 |
| No puedo entrar al panel | Crear el usuario otra vez: `docker exec -it reportes_php php crear-admin.php` |
| El puerto 8080 ya está ocupado | Cambiar el número de la izquierda en `ports:`. Ver qué lo ocupa con `ss -ltnp \| grep 8080` |
| Salen caracteres raros en las tildes | Que la base se haya creado con `utf8mb4`. Ver el paso 3 |
| Los cambios en `docker/` no se aplican | Faltó `--build`: `docker compose up -d --build` |

### Dos sitios distintos donde se escribe

Es la confusión que más veces aparece, porque los dos aceptan lo que escribas y
los dos se ven parecidos.

| Al principio de la línea se ve | Dónde estás | Qué se escribe ahí |
|---|---|---|
| `oscar@oscar:~$` | La **terminal del servidor** | Comandos de Linux y de Docker |
| `mysql>` | **Dentro del cliente de MySQL** | Frases SQL, terminadas en `;` |
| `->` | Dentro del cliente, esperando el `;` de algo a medias | Igual: SQL |

Si estás en `mysql>` o en `->` y pegas un comando de Docker, no va a funcionar:
el cliente intenta leerlo como SQL. De ahí sale el error raro del
`Unknown MySQL server host '<'`.

Y la razón es esta: **dentro del cliente de MySQL, la barra invertida al
principio de la línea significa "comando del cliente"**, no "sigo en la línea
siguiente" como en la terminal. Y `\r` es el atajo de `\connect`, que sirve para
reconectarse. Así que al pegar `\reportes_ciudadanos < instalacion.sql`, el
cliente leyó `\r` como "reconéctate", tomó `eportes_ciudadanos` como nombre de la
base y `<` como nombre del servidor. Por eso decía que no conocía el servidor
`'<'`.

**Para salir del cliente de MySQL:**

```
exit
```

Si algo quedó a medias, antes conviene un `Ctrl+C`.

Cuando vuelvas a ver tu nombre y el `$` al principio de la línea, ya estás en la
terminal y sí puedes correr los comandos de Docker.

---

## 7. Si algún día lo montas sin Docker

Se puede, y está documentado en `LEEME.md`, en la sección "Montarlo en el
servidor CentOS". Ahí está la instalación con `dnf`, el pool de php-fpm y los
ajustes de SELinux.

Solo ten presente que en ese escenario hay dos cosas que aquí no existen:

- `BD_HOST` cambia a `127.0.0.1`.
- Hace falta `setsebool -P httpd_can_network_connect 1`, o los reportes se
  guardan pero nunca se clasifican.
