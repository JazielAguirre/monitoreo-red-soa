-- Sistema de Monitoreo de Red SOA
-- seed.sql: datos de EJEMPLO. Se carga después de schema.sql e inicial.sql, solo en
-- la base de pruebas (monitoreo_red_soa_pruebas, la recrean las suites) o con
-- preparar-demo.php --datos-de-ejemplo, que antes respalda tu base y pide confirmación.
-- La base de uso diario empieza sin estos datos (herramientas/crear-base.php).
--
-- Dispositivos pensados para comportarse de forma predecible en la red de desarrollo:
--   Mi PC, Router principal y PC Recepción responden de verdad.
--   Las IPs 192.0.2.x (rango reservado para documentación) nunca responden:
--     Servidor principal:    inactivo, con una alerta pendiente.
--     Impresora Laboratorio: activo en el seed; el primer monitoreo real la pasa
--                            a inactivo y genera una alerta en vivo (demo).
--     Servidor de respaldo:  desconocido, registrado después de la última ronda.
--
-- La última ronda de "Monitorear todos" fue el 2026-09-27 a las 09:45.
-- Todas las fechas son explícitas (hora local America/Mazatlan), nunca NOW().

SET NAMES utf8mb4;

-- Dispositivos: ultimo_tiempo_ms y ultima_comprobacion copian su último monitoreo.
INSERT INTO dispositivos (id, nombre, ip, tipo, ubicacion, estado, ultimo_tiempo_ms, ultima_comprobacion, fecha_registro) VALUES
    (1, 'Mi PC', '127.0.0.1', 'computadora', 'Laboratorio', 'activo', 0.06, '2026-09-27 09:45:00', '2026-09-20 09:00:00'),
    (2, 'Router principal', '192.168.0.1', 'router', 'Cuarto de telecomunicaciones', 'activo', 32.90, '2026-09-27 09:45:00', '2026-09-20 09:05:00'),
    (3, 'PC Recepción', '192.168.0.27', 'computadora', 'Recepción', 'activo', 0.07, '2026-09-27 09:45:01', '2026-09-20 09:10:00'),
    (4, 'Servidor principal', '192.0.2.10', 'servidor', 'Sala de servidores', 'inactivo', NULL, '2026-09-27 09:45:01', '2026-09-20 09:15:00'),
    (5, 'Impresora Laboratorio', '192.0.2.20', 'impresora', 'Laboratorio', 'activo', 2.75, '2026-09-27 09:45:03', '2026-09-21 10:30:00'),
    (6, 'Servidor de respaldo', '192.0.2.11', 'servidor', 'Sala de servidores', 'desconocido', NULL, NULL, '2026-09-27 09:50:00');

-- Monitoreos de días anteriores: la caída que originó cada alerta atendida y la
-- comprobación activa posterior, antes de la hora en que se atendió.
INSERT INTO monitoreos (dispositivo_id, ip, estado, tiempo_respuesta_ms, fecha) VALUES
    (4, '192.0.2.10', 'inactivo', NULL, '2026-09-22 08:15:01'),
    (4, '192.0.2.10', 'activo', 0.92, '2026-09-22 08:45:01'),
    (5, '192.0.2.20', 'inactivo', NULL, '2026-09-24 11:05:02'),
    (5, '192.0.2.20', 'activo', 2.40, '2026-09-24 11:25:02'),
    (2, '192.168.0.1', 'inactivo', NULL, '2026-09-25 14:10:00'),
    (2, '192.168.0.1', 'activo', 6.30, '2026-09-25 14:20:00');

-- Monitoreos de hoy: 10 rondas cada 5 minutos, de 09:00 a 09:45.
-- Servidor principal deja de responder a partir de las 09:30.
INSERT INTO monitoreos (dispositivo_id, ip, estado, tiempo_respuesta_ms, fecha) VALUES
    -- Ronda de las 09:00
    (1, '127.0.0.1', 'activo', 0.05, '2026-09-27 09:00:00'),
    (2, '192.168.0.1', 'activo', 12.40, '2026-09-27 09:00:00'),
    (3, '192.168.0.27', 'activo', 0.07, '2026-09-27 09:00:01'),
    (4, '192.0.2.10', 'activo', 0.85, '2026-09-27 09:00:01'),
    (5, '192.0.2.20', 'activo', 2.10, '2026-09-27 09:00:02'),
    -- Ronda de las 09:05
    (1, '127.0.0.1', 'activo', 0.04, '2026-09-27 09:05:00'),
    (2, '192.168.0.1', 'activo', 3.85, '2026-09-27 09:05:00'),
    (3, '192.168.0.27', 'activo', 0.05, '2026-09-27 09:05:01'),
    (4, '192.0.2.10', 'activo', 1.20, '2026-09-27 09:05:01'),
    (5, '192.0.2.20', 'activo', 1.35, '2026-09-27 09:05:02'),
    -- Ronda de las 09:10
    (1, '127.0.0.1', 'activo', 0.12, '2026-09-27 09:10:00'),
    (2, '192.168.0.1', 'activo', 8.20, '2026-09-27 09:10:00'),
    (3, '192.168.0.27', 'activo', 0.09, '2026-09-27 09:10:01'),
    (4, '192.0.2.10', 'activo', 0.92, '2026-09-27 09:10:01'),
    (5, '192.0.2.20', 'activo', 4.80, '2026-09-27 09:10:02'),
    -- Ronda de las 09:15
    (1, '127.0.0.1', 'activo', 0.06, '2026-09-27 09:15:00'),
    (2, '192.168.0.1', 'activo', 45.10, '2026-09-27 09:15:00'),
    (3, '192.168.0.27', 'activo', 0.04, '2026-09-27 09:15:01'),
    (4, '192.0.2.10', 'activo', 2.40, '2026-09-27 09:15:01'),
    (5, '192.0.2.20', 'activo', 1.90, '2026-09-27 09:15:02'),
    -- Ronda de las 09:20
    (1, '127.0.0.1', 'activo', 0.03, '2026-09-27 09:20:00'),
    (2, '192.168.0.1', 'activo', 5.60, '2026-09-27 09:20:00'),
    (3, '192.168.0.27', 'activo', 0.11, '2026-09-27 09:20:01'),
    (4, '192.0.2.10', 'activo', 1.05, '2026-09-27 09:20:01'),
    (5, '192.0.2.20', 'activo', 5.60, '2026-09-27 09:20:02'),
    -- Ronda de las 09:25
    (1, '127.0.0.1', 'activo', 0.08, '2026-09-27 09:25:00'),
    (2, '192.168.0.1', 'activo', 22.30, '2026-09-27 09:25:00'),
    (3, '192.168.0.27', 'activo', 0.06, '2026-09-27 09:25:01'),
    (4, '192.0.2.10', 'activo', 0.98, '2026-09-27 09:25:01'),
    (5, '192.0.2.20', 'activo', 2.25, '2026-09-27 09:25:02'),
    -- Ronda de las 09:30
    (1, '127.0.0.1', 'activo', 0.05, '2026-09-27 09:30:00'),
    (2, '192.168.0.1', 'activo', 4.10, '2026-09-27 09:30:00'),
    (3, '192.168.0.27', 'activo', 0.20, '2026-09-27 09:30:01'),
    (4, '192.0.2.10', 'inactivo', NULL, '2026-09-27 09:30:01'),
    (5, '192.0.2.20', 'activo', 1.60, '2026-09-27 09:30:03'),
    -- Ronda de las 09:35
    (1, '127.0.0.1', 'activo', 0.20, '2026-09-27 09:35:00'),
    (2, '192.168.0.1', 'activo', 54.70, '2026-09-27 09:35:00'),
    (3, '192.168.0.27', 'activo', 0.05, '2026-09-27 09:35:01'),
    (4, '192.0.2.10', 'inactivo', NULL, '2026-09-27 09:35:01'),
    (5, '192.0.2.20', 'activo', 3.40, '2026-09-27 09:35:03'),
    -- Ronda de las 09:40
    (1, '127.0.0.1', 'activo', 0.04, '2026-09-27 09:40:00'),
    (2, '192.168.0.1', 'activo', 9.80, '2026-09-27 09:40:00'),
    (3, '192.168.0.27', 'activo', 0.08, '2026-09-27 09:40:01'),
    (4, '192.0.2.10', 'inactivo', NULL, '2026-09-27 09:40:01'),
    (5, '192.0.2.20', 'activo', 1.15, '2026-09-27 09:40:03'),
    -- Ronda de las 09:45
    (1, '127.0.0.1', 'activo', 0.06, '2026-09-27 09:45:00'),
    (2, '192.168.0.1', 'activo', 32.90, '2026-09-27 09:45:00'),
    (3, '192.168.0.27', 'activo', 0.07, '2026-09-27 09:45:01'),
    (4, '192.0.2.10', 'inactivo', NULL, '2026-09-27 09:45:01'),
    (5, '192.0.2.20', 'activo', 2.75, '2026-09-27 09:45:03');

-- Alertas: tres atendidas de días anteriores y una pendiente (Servidor principal).
-- Cada una coincide con la fecha del monitoreo inactivo que la originó.
INSERT INTO alertas (dispositivo_id, tipo, mensaje, fecha, atendida, fecha_atendida) VALUES
    (4, 'sin_respuesta', 'El dispositivo Servidor principal no respondió al monitoreo.', '2026-09-22 08:15:01', 1, '2026-09-22 09:00:00'),
    (5, 'sin_respuesta', 'El dispositivo Impresora Laboratorio no respondió al monitoreo.', '2026-09-24 11:05:02', 1, '2026-09-24 11:40:00'),
    (2, 'sin_respuesta', 'El dispositivo Router principal no respondió al monitoreo.', '2026-09-25 14:10:00', 1, '2026-09-25 14:32:00'),
    (4, 'sin_respuesta', 'El dispositivo Servidor principal no respondió al monitoreo.', '2026-09-27 09:30:01', 0, NULL);

-- El usuario admin está en inicial.sql.
