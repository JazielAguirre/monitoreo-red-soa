/*
 * Abrir y cerrar modales de Bootstrap de forma segura:
 * - cerrarModal() funciona aunque el modal todavía se esté abriendo (Bootstrap
 *   ignora hide() durante la animación de apertura).
 * - Al cerrarse, el foco vuelve al botón que lo abrió; si ese botón ya no existe
 *   (por ejemplo, la tabla se volvió a dibujar), a la alternativa indicada.
 * Escape cierra el modal (comportamiento de Bootstrap).
 */

const estados = new WeakMap(); // modal → { abriendo, origen, alternativa }

function estadoDe(modal) {
    if (!estados.has(modal)) {
        estados.set(modal, { abriendo: false, origen: null, alternativa: null });

        modal.addEventListener('show.bs.modal', () => { estadoDe(modal).abriendo = true; });
        modal.addEventListener('shown.bs.modal', () => { estadoDe(modal).abriendo = false; });
        modal.addEventListener('hidden.bs.modal', () => {
            const { origen, alternativa } = estadoDe(modal);
            const destino = origen?.isConnected ? origen : alternativa?.();
            destino?.focus();
            estadoDe(modal).origen = null;
        });
    }

    return estados.get(modal);
}

/**
 * Abre el modal. origen: el botón que lo abre (recibe el foco al cerrar).
 * alternativa: función que devuelve a quién enfocar si origen ya no existe.
 */
export function abrirModal(modal, { origen = null, alternativa = null } = {}) {
    const estado = estadoDe(modal);
    estado.origen = origen ?? (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    estado.alternativa = alternativa;
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
}

/**
 * Cierra el modal, también si todavía se está abriendo.
 */
export function cerrarModal(modal) {
    const instancia = window.bootstrap.Modal.getOrCreateInstance(modal);

    if (estadoDe(modal).abriendo) {
        modal.addEventListener('shown.bs.modal', () => instancia.hide(), { once: true });
    } else {
        instancia.hide();
    }
}

/**
 * Cambia a quién enfocar al cerrar (por ejemplo, después de guardar y redibujar).
 */
export function enfocarAlCerrar(modal, alternativa) {
    const estado = estadoDe(modal);
    estado.origen = null;
    estado.alternativa = alternativa;
}
