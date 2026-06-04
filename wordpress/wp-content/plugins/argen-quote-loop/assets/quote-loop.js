/**
 * quote-loop.js  v1.3.0
 * Maneja: toggle grilla/lista, stepper de cantidad, AJAX add-to-quote, toast notifications
 */
(function($) {
    'use strict';

    var STORAGE_KEY = 'argenViewMode'; // 'grid' | 'list'

    // ─── CONSTANTES DE TEXTO ───────────────────────────────────────
    var LIST_HEADER_HTML = [
        '<div class="argen-list-header">',
        '  <span></span>',                 // imagen
        '  <span>Producto</span>',
        '  <span>Presentación</span>',
        '  <span style="text-align:center">Cantidad</span>',
        '  <span></span>',                 // botón
        '</div>'
    ].join('');

    // ─── TOGGLE GRILLA / LISTA ─────────────────────────────────────
    var _observer = null; // MutationObserver global para el loop de productos

    function initViewToggle() {
        var $toggle = $('.argen-view-toggle');
        if ( ! $toggle.length ) return;

        if ( ! $toggle.parent().hasClass('argen-view-toggle-wrap') ) {
            $toggle.wrap('<div class="argen-view-toggle-wrap"></div>');
        }

        // Aplicar vista guardada al cargar
        applyView(localStorage.getItem(STORAGE_KEY) || 'grid', false);

        // Click manual en los botones
        $toggle.on('click', '.argen-toggle-btn', function() {
            applyView($(this).data('view'), true);
        });

        // ── Capas de detección de cambios en el DOM ───────────────

        // 1. Eventos WooCommerce/AJAX estándar
        $(document.body).on(
            'wc_fragments_refreshed wc_fragments_loaded woocommerce_update_product_list ' +
            'filtered_ajax_get_products berocket_ajax_products_loaded ' +
            'woof_ajax_done yith_wcaf_ajax_done',
            function() { reapplyView(); }
        );

        // 2. Cualquier llamada AJAX completada (cubre plugins de filtro sin eventos custom)
        $(document).ajaxComplete(function() {
            reapplyView();
        });

        // 3. MutationObserver sobre el contenedor del loop:
        //    detecta cuando WooCommerce/filtros reemplazan el ul.products en el DOM.
        //    Esto cubre navegación SPA, infinite scroll y cualquier otro método.
        attachObserver();
    }

    // Conecta el MutationObserver al contenedor padre de ul.products
    function attachObserver() {
        if (_observer) { _observer.disconnect(); }

        // El contenedor es habitualmente .woocommerce o main#main o el parent de ul.products
        var target = document.querySelector(
            '.woocommerce-products-header ~ ul.products, ' +
            '.woocommerce ul.products, ' +
            'main ul.products, ' +
            '#primary ul.products'
        );
        // Si no encontramos ul.products, observamos el body completo como fallback
        var observeTarget = (target ? target.parentNode : null) || document.body;

        _observer = new MutationObserver(function(mutations) {
            var relevant = mutations.some(function(m) {
                // Nos interesa si se agregaron/quitaron nodos (no cambios de atributos internos)
                return m.type === 'childList' && m.addedNodes.length > 0;
            });
            if (relevant) { reapplyView(); }
        });

        _observer.observe(observeTarget, { childList: true, subtree: true });
    }

    // Wrapper que evita ejecutar applyView múltiples veces en el mismo ciclo
    var _reapplyTimer = null;
    function reapplyView() {
        clearTimeout(_reapplyTimer);
        _reapplyTimer = setTimeout(function() {
            $('.argen-list-header').remove();
            applyView(localStorage.getItem(STORAGE_KEY) || 'grid', false);
            // Re-conectar el observer al nuevo ul.products si fue reemplazado
            attachObserver();
        }, 50);
    }

    // ─── applyView: siempre lee el DOM en vivo ─────────────────────
    function applyView(view, save) {
        var $products = $('ul.products');
        var $toggle   = $('.argen-view-toggle');

        // Sincronizar botones
        $toggle.find('.argen-toggle-btn').each(function() {
            var isActive = $(this).data('view') === view;
            $(this).toggleClass('active', isActive);
            $(this).attr('aria-pressed', isActive ? 'true' : 'false');
        });

        if ( ! $products.length ) { return; } // no hay loop visible, nada que hacer

        if (view === 'list') {
            $products.addClass('argen-list-view');
            if ( ! $products.prev('.argen-list-header').length ) {
                $products.before($(LIST_HEADER_HTML));
            }
        } else {
            $products.removeClass('argen-list-view');
            $('.argen-list-header').remove();
        }

        if (save) {
            try { localStorage.setItem(STORAGE_KEY, view); } catch(e) {}
        }
    }

    // ─── STEPPER DE CANTIDAD ───────────────────────────────────────
    function initQtyStepper() {
        $(document).on('click', '.argen-qty-minus, .argen-qty-plus', function(e) {
            e.preventDefault();
            var $btn   = $(this);
            var $input = $btn.closest('.argen-qty-wrapper').find('.argen-qty-input');
            var val    = parseInt($input.val(), 10) || 1;
            var min    = parseInt($input.attr('min'), 10) || 1;
            var max    = parseInt($input.attr('max'), 10) || 9999;

            if ($btn.hasClass('argen-qty-minus')) {
                val = Math.max(min, val - 1);
            } else {
                val = Math.min(max, val + 1);
            }
            $input.val(val);
        });

        $(document).on('change blur', '.argen-qty-input', function() {
            var $input = $(this);
            var val    = parseInt($input.val(), 10);
            var min    = parseInt($input.attr('min'), 10) || 1;
            var max    = parseInt($input.attr('max'), 10) || 9999;
            if (isNaN(val) || val < min) $input.val(min);
            if (val > max) $input.val(max);
        });
    }

    // ─── AJAX ADD TO QUOTE ─────────────────────────────────────────
    function initAddToQuote() {
        $(document).on('click', '.argen-add-quote-btn', function(e) {
            e.preventDefault();
            var $btn       = $(this);
            var $form      = $btn.closest('.argen-quote-loop-form');
            var $feedback  = $form.find('.argen-quote-feedback');
            var productId  = $btn.data('product-id');
            var nonce      = $btn.data('nonce');
            var quantity   = $form.find('.argen-qty-input').val() || 1;

            // Validar que se eligió variación (si hay selects)
            var $selects    = $form.find('.argen-variation-select');
            var allSelected = true;
            $selects.each(function() {
                if ($(this).val() === '') {
                    allSelected = false;
                    return false;
                }
            });

            if (!allSelected) {
                showFeedback($feedback, argenQuote.i18n.selectOption, 'error');
                return;
            }

            var data = {
                action:     argenQuote.action,
                product_id: productId,
                nonce:      nonce,
                quantity:   quantity,
            };

            $selects.each(function() {
                var attrName = 'attribute_' + $(this).data('attribute');
                data[attrName] = $(this).val();
            });

            $btn.prop('disabled', true).text(argenQuote.i18n.adding);
            $feedback.removeClass('argen-success argen-error').text('');

            $.ajax({
                url:    argenQuote.ajaxUrl,
                type:   'POST',
                data:   data,
                success: function(response) {
                    if (response.success) {
                        var productName = (response.data && response.data.product) ? response.data.product : '';
                        var toastMsg    = productName ? productName + ' agregado al presupuesto.' : argenQuote.i18n.added;
                        showFeedback($feedback, toastMsg, 'success');

                        if (response.data && response.data.quote_count !== undefined) {
                            updateQuoteCount(response.data.quote_count);
                        }

                        $(document.body).trigger('argen_quote_added', [response.data]);

                    } else {
                        var msg = (response.data && response.data.message)
                            ? response.data.message
                            : argenQuote.i18n.error;
                        showFeedback($feedback, msg, 'error');
                    }
                },
                error: function() {
                    showFeedback($feedback, argenQuote.i18n.error, 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Agregar');
                }
            });
        });
    }

    // ─── HELPER: mostrar feedback (delega al toast) ────────────────
    function showFeedback($el, msg, type) {
        var title = type === 'success' ? '¡Agregado!' : 'Atención';
        showToast(title, msg, type);
    }

    // ─── TOAST NOTIFICATION ────────────────────────────────────────
    function showToast(title, msg, type, duration) {
        duration = duration || 3500;

        var $container = $('#argen-toast-container');
        if (!$container.length) {
            $container = $('<div id="argen-toast-container"></div>');
            $('body').append($container);
        }

        var iconSuccess = '<svg class="argen-toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        var iconError   = '<svg class="argen-toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        var icon        = type === 'success' ? iconSuccess : iconError;

        var $toast = $(
            '<div class="argen-toast argen-toast-' + type + '">' +
                icon +
                '<div class="argen-toast-body">' +
                    '<div class="argen-toast-title">' + $('<span>').text(title).html() + '</div>' +
                    '<div class="argen-toast-msg">'   + $('<span>').text(msg).html()   + '</div>' +
                '</div>' +
                '<button class="argen-toast-close" aria-label="Cerrar">&#x2715;</button>' +
                '<div class="argen-toast-progress" style="--toast-duration:' + duration + 'ms"></div>' +
            '</div>'
        );

        $container.append($toast);

        // Cerrar manualmente
        $toast.find('.argen-toast-close').on('click', function() {
            dismissToast($toast);
        });

        // Auto-cerrar
        var timer = setTimeout(function() {
            dismissToast($toast);
        }, duration);

        // Pausar progress bar al hacer hover
        $toast.on('mouseenter', function() {
            clearTimeout(timer);
            $toast.find('.argen-toast-progress').css('animation-play-state', 'paused');
        }).on('mouseleave', function() {
            $toast.find('.argen-toast-progress').css('animation-play-state', 'running');
            timer = setTimeout(function() {
                dismissToast($toast);
            }, 800);
        });
    }

    function dismissToast($toast) {
        $toast.addClass('argen-toast-hiding');
        setTimeout(function() {
            $toast.remove();
        }, 230);
    }

    // ─── HELPER: actualizar contador del quote en el header ────────
    function updateQuoteCount(count) {
        var selectors = [
            '.yith-ywraq-add-to-quote-button .count',
            '.ywraq-count',
            '.quote-count',
            '[data-quote-count]',
        ];
        $(selectors.join(',')).text(count);
        $('[data-quote-count]').attr('data-quote-count', count);
    }

    // ─── INIT ──────────────────────────────────────────────────────
    $(document).ready(function() {
        initViewToggle();
        initQtyStepper();
        initAddToQuote();
    });

})(jQuery);
