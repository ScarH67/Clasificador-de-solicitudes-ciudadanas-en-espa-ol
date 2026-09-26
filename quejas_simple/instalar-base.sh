#!/usr/bin/env bash
# ---------------------------------------------------------------------------
#  Crea la base de datos e importa las tablas.
# ---------------------------------------------------------------------------
#  Se corre en el servidor, desde la carpeta donde están estos archivos:
#
#      cd /root/proyectos/erpymex/reportes
#      sudo bash instalar-base.sh
#
#  Lleva sudo porque el proyecto vive dentro de /root, que es la carpeta del
#  administrador y nadie más puede entrar.
#
#  No hace falta darle permisos de ejecución: con "bash" delante funciona.
#
#  Se puede correr las veces que haga falta. El archivo de tablas usa
#  CREATE TABLE IF NOT EXISTS, así que si ya existen no las toca ni borra nada.
#
#  Si tu contenedor de MySQL o la contraseña se llaman distinto, se cambian
#  aquí abajo y ya.
# ---------------------------------------------------------------------------

set -u

CONTENEDOR="legacy_mysql56"
BASE="reportes_ciudadanos"
CLAVE="root123"
ARCHIVO="instalacion.sql"

# ---------------------------------------------------------------------------
#  Comprobaciones previas. Están para que, si algo falta, salga un mensaje que
#  se entienda en vez de un error críptico de Docker o de MySQL.
# ---------------------------------------------------------------------------

DOCKER="docker"

if ! docker ps >/dev/null 2>&1; then
    # Puede ser que Docker esté bien pero tu usuario no tenga permiso para
    # usarlo sin sudo.
    if sudo -n docker ps >/dev/null 2>&1; then
        DOCKER="sudo docker"
    else
        echo "ERROR: el comando docker no responde."
        echo ""
        echo "Lo más probable es que tu usuario no esté en el grupo 'docker'."
        echo "Prueba corriendo el script con sudo:"
        echo ""
        echo "    sudo bash $0"
        exit 1
    fi
fi

if ! $DOCKER ps --format '{{.Names}}' | grep -qx "$CONTENEDOR"; then
    echo "ERROR: no hay ningún contenedor corriendo que se llame '$CONTENEDOR'."
    echo ""
    echo "Estos son los que sí están corriendo:"
    $DOCKER ps --format '    - {{.Names}}   ({{.Image}})'
    echo ""
    echo "Si el de MySQL se llama distinto, abre este archivo y cambia la línea"
    echo "que dice CONTENEDOR= por el nombre correcto."
    exit 1
fi

if [ ! -f "$ARCHIVO" ]; then
    echo "ERROR: no encuentro el archivo '$ARCHIVO' en esta carpeta."
    echo ""
    echo "Estás parado en: $(pwd)"
    echo "Lo que hay aquí:"
    ls -1 | sed 's/^/    /'
    echo ""
    echo "Entra a la carpeta donde esté el archivo y vuelve a correr el script."
    exit 1
fi

echo "Contenedor : $CONTENEDOR"
echo "Base       : $BASE"
echo "Archivo    : $ARCHIVO"
echo ""

# ---------------------------------------------------------------------------
#  Paso 1. Crear la base.
# ---------------------------------------------------------------------------
#  Esto es lo que faltaba en el comando que estabas usando. Si la base no
#  existe, el paso de importar falla con "Unknown database" y no queda claro
#  por qué.
# ---------------------------------------------------------------------------

echo "1/3  Creando la base $BASE si no existe..."

SALIDA=$($DOCKER exec -i "$CONTENEDOR" mysql -u root -p"$CLAVE" -e \
    "CREATE DATABASE IF NOT EXISTS $BASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1)
CODIGO=$?

if [ $CODIGO -ne 0 ]; then
    echo "$SALIDA" | grep -v "Using a password on the command line"
    echo ""
    echo "No se pudo crear la base."
    echo "Revisa que la contraseña sea '$CLAVE' y que el contenedor de MySQL"
    echo "esté corriendo."
    exit 1
fi
echo "     listo"

# ---------------------------------------------------------------------------
#  Paso 2. Importar las tablas.
# ---------------------------------------------------------------------------
#  El archivo .sql entra por la entrada estándar del contenedor. Por eso lleva
#  -i (de "interactive"): sin esa opción Docker no le pasa el archivo a mysql.
# ---------------------------------------------------------------------------

echo "2/3  Importando las tablas..."

SALIDA=$($DOCKER exec -i "$CONTENEDOR" mysql -u root -p"$CLAVE" \
    --default-character-set=utf8mb4 "$BASE" < "$ARCHIVO" 2>&1)
CODIGO=$?

if [ $CODIGO -ne 0 ]; then
    echo "$SALIDA" | grep -v "Using a password on the command line"
    echo ""
    echo "No se pudieron importar las tablas."
    exit 1
fi
echo "     listo"

# ---------------------------------------------------------------------------
#  Paso 3. Comprobar.
# ---------------------------------------------------------------------------

echo "3/3  Comprobando..."
echo ""

$DOCKER exec -i "$CONTENEDOR" mysql -u root -p"$CLAVE" "$BASE" \
    -e "SHOW TABLES;" 2>/dev/null

echo ""

# ---------------------------------------------------------------------------
#  Nota: el aviso "Using a password on the command line interface can be
#  insecure" lo imprime mysql siempre que la contraseña va pegada al -p. Es
#  normal y no es un error. Aquí se filtra para que no confunda.
# ---------------------------------------------------------------------------

echo "Listo. Si arriba salieron 'quejas' y 'usuarios', ya quedó."
echo ""
echo "El siguiente paso es crear el usuario del panel:"
echo ""
echo "    docker exec -it reportes_php php crear-admin.php"
