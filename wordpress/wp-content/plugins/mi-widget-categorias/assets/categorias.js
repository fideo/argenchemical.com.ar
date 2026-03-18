/**
 * Widget de Categorías Dinámico
 * Archivo: assets/categorias.js
 * Versión: 1.2.0
 *
 * Fixes:
 * - Sincroniza aria-expanded con el estado open al cargar
 * - Manejo robusto del closest con fallback manual
 * - Logs de debug (se pueden desactivar cambiando DEBUG = false)
 */
(function () {
    'use strict';

    var DEBUG = true; // ← Cambiar a false en producción

    function log() {
        if (DEBUG && window.console) {
            console.log.apply(console, ['[MWC]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    /**
     * Obtener el .mwc-item padre del botón de forma robusta
     */
    function getMwcItem(btn) {
        // Primero intentamos closest (moderno)
        if (btn.closest) {
            var item = btn.closest('.mwc-item');
            if (item) return item;
        }
        // Fallback manual para casos raros
        var el = btn.parentElement;
        while (el) {
            if (el.classList && el.classList.contains('mwc-item')) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    /**
     * Sincronizar aria-expanded de todos los botones
     * con el estado .open de su .mwc-item al cargar la página
     */
    function sincronizarEstadoInicial(widget) {
        var items = widget.querySelectorAll('.mwc-item');
        items.forEach(function (item) {
            var btn = item.querySelector('.mwc-toggle');
            if (!btn) return;

            var estaAbierto = item.classList.contains('open');
            btn.setAttribute('aria-expanded', estaAbierto ? 'true' : 'false');

            log('Estado inicial —', item.className, '| open:', estaAbierto);
        });
    }

    /**
     * Inicializar el acordeón sobre un widget específico
     */
    function initAcordeon(widget) {

        // 1. Sincronizar estado visual con el HTML que entregó el PHP
        sincronizarEstadoInicial(widget);

        var toggles = widget.querySelectorAll('.mwc-toggle');
        log('Toggles encontrados en widget:', toggles.length);

        toggles.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var itemActual = getMwcItem(btn);

                if (!itemActual) {
                    log('ERROR: No se encontró .mwc-item padre del botón', btn);
                    return;
                }

                var estaAbierto = itemActual.classList.contains('open');
                log('Click en:', itemActual.className, '| estaba abierto:', estaAbierto);

                // Cerrar TODOS los ítems del mismo nivel en este widget
                var todosLosItems = widget.querySelectorAll('.mwc-lista-categorias > .mwc-item');
                todosLosItems.forEach(function (item) {
                    item.classList.remove('open');
                    var toggleDeEsteItem = item.querySelector('.mwc-toggle');
                    if (toggleDeEsteItem) {
                        toggleDeEsteItem.setAttribute('aria-expanded', 'false');
                    }
                });

                // Si NO estaba abierto → abrirlo
                // Si YA estaba abierto → queda cerrado (toggle)
                if (!estaAbierto) {
                    itemActual.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                    log('Abriendo:', itemActual.className);
                } else {
                    log('Cerrando (era toggle):', itemActual.className);
                }
            });
        });
    }

    /**
     * Punto de entrada
     */
    function init() {
        var widgets = document.querySelectorAll('.mwc-categorias-widget, .mwc-shortcode');
        log('Widgets encontrados:', widgets.length);

        if (widgets.length === 0) {
            log('ADVERTENCIA: No se encontraron widgets .mwc-categorias-widget ni .mwc-shortcode en el DOM');
            return;
        }

        widgets.forEach(function (widget) {
            initAcordeon(widget);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
