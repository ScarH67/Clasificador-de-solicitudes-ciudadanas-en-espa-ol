-- ============================================================================
--  Reportes ciudadanos — tablas
-- ----------------------------------------------------------------------------
--  Cómo se ejecuta:
--
--      mysql -u root -p reportes_ciudadanos < instalacion.sql
--
--  Se puede correr las veces que haga falta: usa CREATE TABLE IF NOT EXISTS,
--  así que si las tablas ya existen no las toca ni borra nada.
--
--  Charset utf8mb4 para que las tildes, la "ñ" y los emojis se guarden bien.
--
--  Sobre los índices: todas las columnas que llevan índice son cortas (60
--  caracteres o menos). Es a propósito. MySQL 5.6 con InnoDB y utf8mb4 tiene
--  un límite de 767 bytes por índice, y un VARCHAR(255) en utf8mb4 ocupa 1020
--  bytes. Con índices cortos esto funciona igual en MySQL 5.6, en MariaDB 10.3
--  y en versiones más nuevas, sin tocar la configuración del servidor.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
--  Usuarios del panel
-- ----------------------------------------------------------------------------
--  Solo para el personal. El ciudadano no tiene cuenta.
--
--  clave_hash guarda el resultado de password_hash(), que es un hash bcrypt.
--  La contraseña en sí no se guarda en ningún lado y no se puede recuperar:
--  si alguien la olvida, se le asigna otra con crear-admin.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id          INT(11)      NOT NULL AUTO_INCREMENT,
    usuario     VARCHAR(60)  NOT NULL,
    nombre      VARCHAR(150) NOT NULL,
    clave_hash  VARCHAR(255) NOT NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ----------------------------------------------------------------------------
--  Reportes
-- ----------------------------------------------------------------------------
--  estado es el ciclo del trámite, lo que ve el ciudadano:
--      pendiente   todavía no se clasificó, o el modelo no estaba seguro
--      canalizado  ya se sabe a qué área le toca
--      en_proceso  alguien lo está atendiendo
--      atendido    ya se resolvió
--
--  estado_revision es otra cosa: la calidad de la clasificación. Se separó de
--  "estado" a propósito, para no mezclar el avance del trámite con si el modelo
--  acertó o no:
--      sin_clasificar     la API no contestó o falló
--      automatico         el modelo estuvo por encima del umbral
--      requiere_revision  confianza baja, o el guardián levantó la mano
--      revisado_manual    una persona eligió la categoría desde el panel
--
--  confianza es lo seguro que estuvo el modelo, de 0 a 1. Se guarda con cuatro
--  decimales, que es más que suficiente.
--
--  folio es el del sitio (CDMX-2026-000123) y lo ve el ciudadano. folio_api es
--  el que genera la API (CDMX-A1B2C3D4) y sirve para rastrear de dónde salió
--  cada clasificación. Son distintos y por eso van en columnas distintas.
--
--  metadatos guarda el bloque del API tal cual, como JSON. Va completo y no
--  repartido en columnas porque cambia de contenido según qué API esté
--  levantada: BETO manda dispositivo y temperatura_calibracion, el baseline no.
--
--  nombre se conserva por compatibilidad con los reportes que ya están
--  guardados, pero el formulario ya no lo pide: por confidencialidad no se
--  capturan datos personales. Las filas nuevas lo dejan en blanco.
--
--  nota es para el personal: a quién se turnó, qué se hizo. El ciudadano no
--  la ve.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quejas (
    id                       INT(11)      NOT NULL AUTO_INCREMENT,
    folio                    VARCHAR(30)  DEFAULT NULL,
    folio_api                VARCHAR(40)  DEFAULT NULL,
    nombre                   VARCHAR(120) NOT NULL DEFAULT '',
    colonia                  VARCHAR(120) NOT NULL DEFAULT '',
    texto                    TEXT         NOT NULL,
    categoria                VARCHAR(60)  DEFAULT NULL,
    confianza                DECIMAL(5,4) DEFAULT NULL,
    posible_fuera_de_alcance TINYINT(1)   NOT NULL DEFAULT 0,
    estado                   VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
    estado_revision          VARCHAR(20)  NOT NULL DEFAULT 'sin_clasificar',
    tiempo_ms                DECIMAL(8,1) DEFAULT NULL,
    metadatos                TEXT,
    clasificado_manual       TINYINT(1)   NOT NULL DEFAULT 0,
    nota                     TEXT,
    ip                       VARCHAR(45)  NOT NULL DEFAULT '',
    creado_en                DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_quejas_folio (folio),
    KEY idx_quejas_estado (estado),
    KEY idx_quejas_categoria (categoria),
    KEY idx_quejas_folio_api (folio_api),
    KEY idx_quejas_revision (estado_revision),
    KEY idx_quejas_ip_fecha (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

