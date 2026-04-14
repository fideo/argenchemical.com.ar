/**
 * quote-loop.js  v1.1.0
 * Maneja: toggle grilla/lista, stepper de cantidad, AJAX add-to-quote
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
    function initViewToggle() {
        var $toggle  = $('.argen-view-toggle');
        if ( ! $toggle.length ) return;

        // Envolver en wrapper para centrado via CSS si no está ya
        if ( ! $toggle.parent().hasClass('argen-view-toggle-wrap') ) {
            $toggle.wrap('<div class="argen-view-toggle-wrap"></div>');
        }

        var $products = $('ul.products');
        var $header   = null; // referencia al encabezado de lista

        // Leer preferencia guardada
        var savedView = localStorage.getItem(STORAGE_KEY) || 'grid';
        applyView(savedView, false);

        // Click en los botones
        $toggle.on('click', '.argen-toggle-btn', function() {
            var view = $(this).data('view');
            applyView(view, true);
        });

        function applyView(view, save) {
            // Actualizar botones
            $toggle.find('.argen-toggle-btn').each(function() {
                var isActive = $(this).data('view') === view;
                $(this).toggleClass('active', isActive);
                $(this).attr('aria-pressed', isActive ? 'true' : 'false');
            });

            if (view === 'list') {
                $products.addClass('argen-list-view');

                // Insertar encabezado si no existe
                if ( ! $products.prev('.argen-list-header').length ) {
                    $header = $(LIST_HEADER_HTML);
                    $products.before($header);
                }

            } else {
                $products.removeClass('argen-list-view');

                // Quitar encabezado si existe
                if ($header) {
                    $header.remove();
                    $header = null;
                }
                $('.argen-list-header').remove(); // por si acaso
            }

            if (save) {
                try { localStorage.setItem(STORAGE_KEY, view); } catch(e) {}
            }
        }
    }

    // ─── STEPPER DE CANTIDAD ───────────────────────────────────────
    function initQtyStepper() {
        // Delegar en document para que funcione con productos cargados via AJAX
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

        // Validar entrada manual
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
            var $selects = $form.find('.argen-variation-select');
            var allSelected = true;
            $selects.each(function() {
                if ($(this).val() === '') {
                    allSelected = false;
                    return false; // break
                }
            });

            if (!allSelected) {
                showFeedback($feedback, argenQuote.i18n.selectOption, 'error');
                return;
            }

            // Recolectar atributos seleccionados
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

            // Deshabilitar botón mientras procesa
            $btn.prop('disabled', true).text(argenQuote.i18n.adding);
            $feedback.removeClass('argen-success argen-error').text('');

            $.ajax({
                url:    argenQuote.ajaxUrl,
                type:   'POST',
                data:   data,
                success: function(response) {
                    if (response.success) {
                        showFeedback($feedback, argenQuote.i18n.added, 'success');

                        // Actualizar contador del carrito/quote en el header si existe
                        if (response.data && response.data.quote_count !== undefined) {
                            updateQuoteCount(response.data.quote_count);
                        }

                        // Disparar evento custom para que otros plugins puedan escuchar
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

    // ─── HELPER: mostrar feedback ──────────────────────────────────
    function showFeedback($el, msg, type) {
        $el.removeClass('argen-success argen-error')
           .addClass('argen-' + type)
           .text(msg);

        // Auto-ocultar el mensaje de éxito
        if (type === 'success') {
            setTimeout(function() {
                $el.text('').removeClass('argen-success');
            }, 3500);
        }
    }

    // ─── HELPER: actualizar contador del quote en el header ────────
    function updateQuoteCount(count) {
        // YITH suele usar estos selectores — ajustar según el tema
        var selectors = [
            '.yith-ywraq-add-to-quote-button .count',
            '.ywraq-count',
            '.quote-count',
            '[data-quote-count]',
        ];
        $(selectors.join(',')).text(count);

        // También actualizar atributo data si existe
        $('[data-quote-count]').attr('data-quote-count', count);
    }

    // ─── INIT ──────────────────────────────────────────────────────
    $(document).ready(function() {
        initViewToggle();
        initQtyStepper();
        initAddToQuote();
    });

})(jQuery);
