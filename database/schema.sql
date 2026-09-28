-- Sistema de Monitoreo de Red SOA
-- schema.sql: recrea desde cero la base de datos monitoreo_red_soa.
-- Fuente de verdad del modelo: docs/API.md, sección "Modelo de datos".
--
-- Cada tabla pertenece a un solo servicio:
--   dispositivos → Servicio de Dispositivos
--   monitoreos   → Servicio de Monitoreo
--   alertas      → Servicio de Alertas
--   usuarios     → Servicio de Autenticación
--
-- Las fechas las genera PHP (America/Mazatlan): ninguna columna usa
-- DEFAULT CURRENT_TIMESTAMP ni NOW().

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS monitoreo_red_soa;
CREATE DATABASE monitoreo_red_soa
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE monitoreo_red_soa;

CREATE TABLE dispositivos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre              VARCHAR(100) NOT NULL,
    ip                  VARCHAR(15)  NOT NULL,
    tipo                ENUM('computadora', 'servidor', 'impresora', 'router') NOT NULL,
    ubicacion           VARCHAR(100) NOT NULL,
    estado              ENUM('activo', 'inactivo', 'desconocido') NOT NULL DEFAULT 'desconocido',
    ultimo_tiempo_ms    DECIMAL(7,2) NULL,
    ultima_comprobacion DATETIME     NULL,
    fecha_registro      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_dispositivos_ip UNIQUE (ip)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE monitoreos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispositivo_id      INT UNSIGNED NOT NULL,
    ip                  VARCHAR(15)  NOT NULL,
    estado              ENUM('activo', 'inactivo') NOT NULL,
    tiempo_respuesta_ms DECIMAL(7,2) NULL,
    fecha               DATETIME     NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_monitoreos_dispositivo_fecha (dispositivo_id, fecha),
    INDEX idx_monitoreos_fecha (fecha),
    CONSTRAINT fk_monitoreos_dispositivo FOREIGN KEY (dispositivo_id)
        REFERENCES dispositivos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE alertas (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispositivo_id  INT UNSIGNED NOT NULL,
    tipo            ENUM('sin_respuesta') NOT NULL,
    mensaje         VARCHAR(255) NOT NULL,
    fecha           DATETIME     NOT NULL,
    atendida        TINYINT(1)   NOT NULL DEFAULT 0,
    fecha_atendida  DATETIME     NULL,
    PRIMARY KEY (id),
    INDEX idx_alertas_dispositivo_atendida (dispositivo_id, atendida),
    INDEX idx_alertas_atendida_fecha (atendida, fecha),
    CONSTRAINT fk_alertas_dispositivo FOREIGN KEY (dispositivo_id)
        REFERENCES dispositivos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE usuarios (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario         VARCHAR(50)  NOT NULL,
    nombre          VARCHAR(100) NOT NULL,
    contrasena_hash VARCHAR(255) NOT NULL,
    fecha_registro  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_usuarios_usuario UNIQUE (usuario)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
