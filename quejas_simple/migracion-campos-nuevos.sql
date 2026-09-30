-- ============================================================================
--  Agregar los campos nuevos a la tabla `quejas`
-- ----------------------------------------------------------------------------
--  Cuándo se corre: UNA SOLA VEZ, sobre una base que ya tiene reportes.
--
--  Si la base es nueva, este archivo no hace falta: `instalacion.sql` ya trae
--  las columnas dentro del CREATE TABLE. Y aquí está la trampa que conviene
--  tener clara:
--
--      CREATE TABLE IF NOT EXISTS no toca una tabla que ya existe.
--
--  O sea que volver a importar instalacion.sql sobre la base actual NO agrega
--  nada. Por eso este archivo va aparte.
--
--  Cómo se corre. Primero entra a la carpeta donde está este archivo:
--
--      cd /root/proyectos/erpymex/reportes
--
--  Y luego, todo en una sola línea (si ya eres root, quítale el sudo):
--
--      sudo docker exec -i legacy_mysql56 mysql -u root -proot123 --default-character-set=utf8mb4 reportes_ciudadanos < migracion-campos-nuevos.sql
--
--  Va a imprimir el aviso "Using a password on the command line interface can
--  be insecure". Es normal, no es un error.
--
--  MySQL 5.6 no tiene "ADD COLUMN IF NOT EXISTS", así que este archivo NO se
--  puede correr dos veces: la segunda vez falla con "Duplicate column name".
--  Si eso pasa no se rompió nada; solo revisa con SHOW COLUMNS FROM quejas.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE quejas

    -- El folio que genera la API (CDMX-A1B2C3D4). Es distinto del folio del
    -- sitio (CDMX-2026-000123), que es el que ve el ciudadano. Se guarda para
    -- poder rastrear una clasificación hasta el ticket que la produjo.
    ADD COLUMN folio_api VARCHAR(40) DEFAULT NULL AFTER folio,

    -- Si el guardián marcó el texto como ajeno a las cinco categorías.
    ADD COLUMN posible_fuera_de_alcance TINYINT(1) NOT NULL DEFAULT 0 AFTER confianza,

    -- Cómo salió la clasificación. Es cosa distinta de `estado`, que es el
    -- avance del trámite:
    --     sin_clasificar     la API no contestó o falló
    --     automatico         el modelo estuvo por encima del umbral
    --     requiere_revision  confianza baja, o el guardián levantó la mano
    --     revisado_manual    una persona eligió la categoría a mano
    ADD COLUMN estado_revision VARCHAR(20) NOT NULL DEFAULT 'sin_clasificar' AFTER estado,

    -- Lo que tardó el modelo, en milisegundos. Con un decimal, igual que la API.
    ADD COLUMN tiempo_ms DECIMAL(8,1) DEFAULT NULL AFTER estado_revision,

    -- El bloque "metadatos" del API tal cual, como JSON. Va completo y no
    -- repartido en columnas porque cambia de contenido según qué API esté
    -- levantada: BETO manda dispositivo y temperatura_calibracion, el baseline
    -- no. No se consulta por SQL, solo se muestra.
    ADD COLUMN metadatos TEXT AFTER tiempo_ms,

    -- 1 cuando una persona eligió la categoría desde el panel. Las filas nacen
    -- en 0 (lo clasificó el modelo).
    ADD COLUMN clasificado_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER metadatos,

    -- Índice normal, no único: el folio del API es aleatorio y una colisión
    -- haría fallar el guardado del reporte. Con índice basta para buscarlo.
    ADD KEY idx_quejas_folio_api (folio_api),

    -- Para poder filtrar "los que requieren revisión" sin recorrer toda la
    -- tabla.
    ADD KEY idx_quejas_revision (estado_revision);

-- ----------------------------------------------------------------------------
--  Comprobar que quedó. Deben aparecer las seis columnas nuevas.
-- ----------------------------------------------------------------------------

SHOW COLUMNS FROM quejas;
