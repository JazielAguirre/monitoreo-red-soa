/*
 * Piezas de pantalla reutilizables (insignias de estado, tipos de dispositivo, íconos).
 * Todo se construye con createElement y textContent: los datos de la API nunca
 * pasan por innerHTML.
 */

export const TIPOS = {
    computadora: { texto: 'Computadora', icono: 'bi-pc-display' },
    servidor: { texto: 'Servidor', icono: 'bi-hdd-rack' },
    impresora: { texto: 'Impresora', icono: 'bi-printer' },
    router: { texto: 'Router', icono: 'bi-router' },
};

export const ESTADOS = {
    activo: { texto: 'Activo', insignia: 'insignia-estado--activo', punto: 'punto-estado--activo' },
    inactivo: { texto: 'Inactivo', insignia: 'insignia-estado--inactivo', punto: 'punto-estado--inactivo' },
    desconocido: { texto: 'Sin monitorear', insignia: '', punto: 'punto-estado--desconocido' },
    monitoreando: { texto: 'Monitoreando…', insignia: 'insignia-estado--monitoreando', punto: 'punto-estado--monitoreando punto-estado--pulso' },
};

/**
 * Crea un elemento con clase y, opcionalmente, un texto (siempre con textContent).
 */
export function elemento(etiqueta, clase = '', texto = null) {
    const nodo = document.createElement(etiqueta);

    if (clase) {
        nodo.className = clase;
    }
    if (texto !== null && texto !== undefined) {
        nodo.textContent = String(texto);
    }

    return nodo;
}

export function icono(clase) {
    const nodo = document.createElement('i');
    nodo.className = `bi ${clase}`;
    nodo.setAttribute('aria-hidden', 'true');

    return nodo;
}

export function crearPunto(estado, pequeno = false) {
    const datos = ESTADOS[estado] ?? ESTADOS.desconocido;

    return elemento('span', `punto-estado ${datos.punto}${pequeno ? ' punto-estado--sm' : ''}`);
}

/**
 * Badge "punto + texto" del estado de un dispositivo.
 */
export function crearInsigniaEstado(estado) {
    const datos = ESTADOS[estado] ?? ESTADOS.desconocido;
    const insignia = elemento('span', `insignia-estado ${datos.insignia}`.trim());
    insignia.append(crearPunto(estado), datos.texto);

    return insignia;
}

/**
 * Tipo con su ícono ("Router" con bi-router).
 */
export function crearTipo(tipo, clase = 'tabla-tipo') {
    const datos = TIPOS[tipo] ?? { texto: tipo, icono: 'bi-question-circle' };
    const nodo = elemento('span', clase);
    nodo.append(icono(datos.icono), datos.texto);

    return nodo;
}

export function iconoTipo(tipo) {
    return (TIPOS[tipo] ?? { icono: 'bi-question-circle' }).icono;
}

/**
 * Enlace al historial de un dispositivo en Monitoreos.
 */
export function urlHistorial(dispositivoId) {
    return `monitoreos.html?dispositivo=${encodeURIComponent(dispositivoId)}`;
}
