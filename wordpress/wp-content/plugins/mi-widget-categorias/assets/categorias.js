/**
 * Widget de Categorías Dinámico
 * Archivo: assets/categorias.js
 * Versión: 1.1.0
 *
 * Lógica de acordeón:
 * - La primera categoría arranca abierta (lo marca el PHP con clase "open")
 * - Click en el botón toggle: cierra todas las demás y abre la clickeada
 * - Click en una ya abierta: la cierra (comportamiento toggle)
 * - El link de la categoría navega normalmente sin interferencia
 */

(function () {
    'use strict';

    /**
     * Inicializar el acordeón sobre un widget específico.
     * Se llama para cada instancia del widget en la página.
     */
    function initAcordeon(widget) {
        var toggles = widget.querySelectorAll('.mwc-toggle');

        toggles.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var itemActual = btn.closest('.mwc-item');
                var estaAbierto = itemActual.classList.contains('open');

                // Cerrar TODOS los ítems del mismo widget
                var todosLosItems = widget.querySelectorAll('.mwc-lista-categorias > .mwc-item');
                todosLosItems.forEach(function (item) {
                    item.classList.remove('open');
                    var toggleDeEsteItem = item.querySelector('.mwc-toggle');
                    if (toggleDeEsteItem) {
                        toggleDeEsteItem.setAttribute('aria-expanded', 'false');
                    }
                });

                // Si NO estaba abierto, abrirlo; si YA estaba abierto, queda cerrado (toggle)
                if (!estaAbierto) {
                    itemActual.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
    }

    /**
     * Punto de entrada: buscar todos los widgets en la página.
     * Funciona con múltiples instancias del widget en la misma página.
     */
    function init() {
        var widgets = document.querySelectorAll('.mwc-categorias-widget, .mwc-shortcode');

        widgets.forEach(function (widget) {
            initAcordeon(widget);
        });
    }

    // Esperar a que el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOMContentLoaded ya disparó (script en footer con defer)
        init();
    }

})();
