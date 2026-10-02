-- Sistema de Monitoreo de Red SOA
-- inicial.sql: lo mínimo para usar la aplicación. Se carga después de schema.sql.
-- La base de uso diario empieza así: solo el usuario admin, sin dispositivos,
-- monitoreos ni alertas. Los datos de ejemplo están aparte, en seed.sql.

SET NAMES utf8mb4;

-- Usuario administrador. Contraseña de desarrollo: admin123 (cámbiala si la
-- aplicación se usa fuera de tu computadora). Solo se guarda el hash, generado
-- con password_hash() del PHP de XAMPP.
INSERT INTO usuarios (usuario, nombre, contrasena_hash, fecha_registro) VALUES
    ('admin', 'Administrador', '$2y$10$btGdvE74lpXuAtWF97LMb.GPX5KOpH2t6sfx.La/eJG7NrEYPQ9D2', '2026-09-20 08:00:00');
