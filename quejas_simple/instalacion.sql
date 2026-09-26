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
--  estado puede ser:
--      pendiente   todavía no se clasificó, o el modelo no estaba seguro
--      canalizado  ya se sabe a qué área le toca
--      en_proceso  alguien lo está atendiendo
--      atendido    ya se resolvió
--
--  confianza es lo seguro que estuvo el modelo, de 0 a 1. Se guarda con cuatro
--  decimales, que es más que suficiente.
--
--  nota es para el personal: a quién se turnó, qué se hizo. El ciudadano no
--  la ve.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quejas (
    id          INT(11)      NOT NULL AUTO_INCREMENT,
    folio       VARCHAR(30)  DEFAULT NULL,
    nombre      VARCHAR(120) NOT NULL DEFAULT '',
    colonia     VARCHAR(120) NOT NULL DEFAULT '',
    texto       TEXT         NOT NULL,
    categoria   VARCHAR(60)  DEFAULT NULL,
    confianza   DECIMAL(5,4) DEFAULT NULL,
    estado      VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
    nota        TEXT,
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    creado_en   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_quejas_folio (folio),
    KEY idx_quejas_estado (estado),
    KEY idx_quejas_categoria (categoria),
    KEY idx_quejas_ip_fecha (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ----------------------------------------------------------------------------
--  Nota: el usuario del panel NO se crea desde aquí.
--
--  La contraseña tiene que cifrarse con bcrypt y eso solo se puede hacer desde
--  PHP. Para el primer usuario:
--
--      php crear-admin.php
--
-- ----------------------------------------------------------------------------
