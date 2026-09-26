# Dónde vas

Actualizado el 20 de septiembre de 2026, después de importar las tablas.

---

## Ya está hecho

- [x] El código de la aplicación, en esta carpeta.
- [x] Los archivos de Docker: `docker-compose.yml`, `docker/Dockerfile`,
      `docker/nginx.conf`, `docker/php.ini`.
- [x] **La base de datos en el servidor.** `reportes_ciudadanos` existe en
      `192.168.100.3:3307` con las tablas `quejas` y `usuarios` puestas,
      en `utf8mb4`, con sus índices.

O sea que la parte de la base ya no hay que tocarla. Se puede volver a correr
`bash instalar-base.sh` si algún día hace falta, que no rompe nada.

---

## Lo que falta

Y todo esto se hace **en el servidor**, no en tu computadora.

### 1. Subir los archivos

El proyecto está en `/root/proyectos/erpymex`, junto al ERP. Conviene dejarlo en
**una subcarpeta propia**, `reportes/`, y no suelto junto a los archivos del ERP:
si los dos `docker-compose.yml` quedan en la misma carpeta, uno pisa al otro.

```bash
sudo mkdir -p /root/proyectos/erpymex/reportes
```

Y copiar ahí el contenido de esta carpeta.

Antes de seguir, comprueba que no se haya pisado el compose del ERP:

```bash
sudo find /root/proyectos/erpymex -name "docker-compose*.yml" -maxdepth 3
```

Si sale solo el nuestro, hay que recuperar el del ERP antes de levantar nada.

Ojo: como todo vive dentro de `/root`, **todos los comandos llevan `sudo`**. Lo
más cómodo es entrar una vez con `sudo su` y trabajar desde ahí. Se sale con
`exit`.

### 2. Poner el nombre de la red de Docker

Se averigua así:

```bash
docker inspect legacy_mysql56 --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
```

Lo que salga va en `docker-compose.yml`, donde dice `legacy_default`.

Más fácil todavía: entra a `http://192.168.100.3:9000` (Portainer), abre
**Networks** y ahí está el nombre.

### 3. Levantar los contenedores

```bash
cd /root/proyectos/erpymex/reportes
docker compose up -d --build
```

### 4. Crear el usuario del panel

**Solo después del paso 3.** El contenedor no existe hasta que corras
`docker compose up`. Si lo intentas antes, sale `No such container: reportes_php`.

```bash
docker exec -it reportes_php php crear-admin.php
```

### 5. Probar

Abre `http://192.168.100.3:8080/` en el navegador y manda un reporte de prueba.

---

## Si algo sale mal

Está todo en `DOCKER.md`, sección 6, con los mensajes de error escritos tal como
aparecen. Los tres que más se ven:

| Se ve | Qué es |
|---|---|
| Se queda en `>` y no pasa nada | Un comando partido con `\`. Va en una sola línea |
| `Unknown MySQL server host '<'` | Estás dentro del cliente de MySQL, no en la terminal. Escribe `exit` |
| `permission denied` al usar docker | Falta `sudo` delante |
