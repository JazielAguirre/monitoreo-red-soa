/*
 * Único punto de acceso a la API: envía las peticiones HTTP + JSON a los servicios,
 * interpreta { ok, data, error } y redirige a login.html ante un 401.
 * Se implementa con la primera pantalla que consuma la API y se amplía con cada servicio.
 */
