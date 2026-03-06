/**
 * ArgenChemical — Quote en Listado de Tienda
 * Archivo: assets/quote-loop.js
 * Maneja: +/- cantidad, selección de variación, envío AJAX al quote
 */

(function ($) {
    'use strict';

    // ─────────────────────────────────────────────
    // CONTROLES DE CANTIDAD +/-
    // ─────────────────────────────────────────────
    $(document).on('click', '.argen-qty-plus', function () {
        var $input = $(this).siblings('.argen-qty-input');
        var max    = parseInt($input.attr('max')) || 9999;
        var val    = parseInt($input.val()) || 1;
        if (val < max) {
            $input.val(val + 1).trigger('change');
        }
    });

    $(document).on('click', '.argen-qty-minus', function () {
        var $input = $(this).siblings('.argen-qty-input');
        var min    = parseInt($input.attr('min')) || 1;
        var val    = parseInt($input.val()) || 1;
        if (val > min) {
            $input.val(val - 1).trigger('change');
        }
    });

    // Evitar valores inválidos escritos a mano
    $(document).on('change keyup', '.argen-qty-input', function () {
        var $input = $(this);
        var val    = parseInt($input.val());
        var min    = parseInt($input.attr('min')) || 1;
        var max    = parseInt($input.attr('max')) || 9999;

        if (isNaN(val) || val < min) $input.val(min);
        if (val > max) $input.val(max);
    });


    // ─────────────────────────────────────────────
    // BOTÓN ADD TO QUOTE
    // ─────────────────────────────────────────────
    $(document).on('click', '.argen-add-quote-btn', function () {
        var $btn       = $(this);
        var $form      = $btn.closest('.argen-quote-loop-form');
        var $feedback  = $form.find('.argen-quote-feedback');
        var productId  = $btn.data('product-id');
        var nonce      = $btn.data('nonce');
        var quantity   = $form.find('.argen-qty-input').val() || 1;

        // Recolectar variaciones seleccionadas
        var data = {
            action:     argenQuote.action,
            product_id: productId,
            nonce:      nonce,
            quantity:   quantity,
        };

        var allSelected = true;

        $form.find('.argen-variation-select').each(function () {
            var $select    = $(this);
            var attrName   = $select.data('attribute');
            var attrVal    = $select.val();

            if (!attrVal) {
                allSelected = false;
                // Resaltar el select que falta completar
                $select.css('border-color', '#e74c3c');
                setTimeout(function () {
                    $select.css('border-color', '');
                }, 2000);
                return false; // break each
            }

            // Enviar como attribute_{name}
            data['attribute_' + attrName] = attrVal;
        });

        // Validación: debe seleccionarse una opción antes de agregar
        if (!allSelected) {
            showFeedback($feedback, argenQuote.i18n.selectOption, 'warning');
            return;
        }

        // Estado cargando
        $btn.addClass('is-loading').prop('disabled', true);
        $btn.find('svg').hide();
        $btn.append('<span class="argen-spinner"> ···</span>');
        showFeedback($feedback, argenQuote.i18n.adding, '');

        // Enviar AJAX
        $.post(argenQuote.ajaxUrl, data)
            .done(function (response) {
                if (response.success) {
                    showFeedback($feedback, argenQuote.i18n.added, 'success');

                    // Actualizar contador del quote/presupuesto en el header
                    // YITH muestra el count en elementos con estas clases típicas:
                    if (response.data && response.data.quote_count !== undefined) {
                        var count = response.data.quote_count;
                        // Clases que YITH usa para mostrar el contador en el header
                        $('.ywraq-add-to-quote-button .count, .ywraq_quote_items_number, .quote-items-number, .header-quote-count').text(count);
                        // También dispara el evento de YITH para que él mismo actualice su UI
                        $(document.body).trigger('ywraq_item_added_to_list', [response.data]);
                    }
                    // Resetear el form después del éxito
                    setTimeout(function () {
                        $form.find('.argen-variation-select').val('');
                        $form.find('.argen-qty-input').val(1);
                    }, 1500);
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : argenQuote.i18n.error;
                    showFeedback($feedback, msg, 'error');
                }
            })
            .fail(function () {
                showFeedback($feedback, argenQuote.i18n.error, 'error');
            })
            .always(function () {
                $btn.removeClass('is-loading').prop('disabled', false);
                $btn.find('svg').show();
                $btn.find('.argen-spinner').remove();
            });
    });


    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────
    function showFeedback($el, message, type) {
        $el.removeClass('is-success is-error is-warning')
           .text(message);

        if (type) {
            $el.addClass('is-' + type);
        } else {
            $el.css('opacity', '0.6');
        }

        // Auto-ocultar después de 4 segundos
        if (type === 'success' || type === 'error') {
            clearTimeout($el.data('feedbackTimer'));
            var timer = setTimeout(function () {
                $el.removeClass('is-success is-error is-warning').text('').css('opacity', '');
            }, 4000);
            $el.data('feedbackTimer', timer);
        }
    }

})(jQuery);
