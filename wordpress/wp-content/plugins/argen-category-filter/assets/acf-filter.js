/**
 * Argen Category Filter — acf-filter.js  v2.0.0
 *
 * Funcionalidades:
 * 1. Checkboxes múltiples en widget propio (una o varias categorías)
 * 2. AJAX sin recarga: reemplaza ul.products con resultados filtrados
 * 3. Oculta TODOS los elementos nativos del loop al filtrar
 * 4. Restaura el estado original al limpiar filtros
 * 5. Acordeón de subcategorías
 * 6. Mantiene la vista Grilla/Lista de argen-quote-loop
 * 7. Paginación AJAX dentro del filtro
 */

(function ($) {
    'use strict';

    var DEBOUNCE_MS = 300;

    // ── Estado ─────────────────────────────────────────────────────
    var state = {
        termIds:  [],      // IDs de categorías marcadas
        paged:    1,
        perPage:  0,
        loading:  false,
        filtered: false,
    };

    // ── Referencias capturadas UNA VEZ al iniciar ──────────────────
    var $woo;                      // .woocommerce — contenedor principal del loop
    var $nativeProducts;           // ul.products original
    var $nativePagination;         // .woocommerce-pagination original
    var $nativeResultCount;        // .woocommerce-result-count
    var $nativeOrdering;           // .woocommerce-ordering
    var $nativeViewToggle;         // .argen-view-toggle-wrap o .argen-view-toggle
    var $nativeResultCountHTML;    // HTML original del conteo (para restaurar)

    // Elementos inyectados por este plugin (para limpiar fácil)
    var $filteredProducts   = null;
    var $filteredPagination = null;

    var debounceTimer = null;

    // ──────────────────────────────────────────────────────────────
    // A. INIT
    // ──────────────────────────────────────────────────────────────
    function init() {
        $woo = $('.woocommerce');
        if ( ! $woo.length ) return;

        // Guardar referencias exactas antes de cualquier modificación
        $nativeProducts        = $woo.find('ul.products').first();
        $nativePagination      = $woo.find('.woocommerce-pagination').first();
        $nativeResultCount     = $('.woocommerce-result-count').first();
        $nativeOrdering        = $('.woocommerce-ordering').first();
        $nativeViewToggle      = $('.argen-view-toggle-wrap').length
                                    ? $('.argen-view-toggle-wrap').first()
                                    : $('.argen-view-toggle').first();
        $nativeResultCountHTML = $nativeResultCount.html();

        state.perPage = $nativeProducts.data('per-page') || 0;

        initAccordion();
        bindCheckboxes();
        bindClearButton();
        bindPagination();
    }

    // ──────────────────────────────────────────────────────────────
    // B. ACORDEÓN DE SUBCATEGORÍAS
    // ──────────────────────────────────────────────────────────────
    function initAccordion() {
        $(document).on('click', '.acf-toggle-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn  = $(this);
            var $item = $btn.closest('.acf-cat-item');
            var open  = $item.hasClass('acf-is-open');

            $item.toggleClass('acf-is-open', !open);
            $btn.attr('aria-expanded', open ? 'false' : 'true');
        });
    }

    // ──────────────────────────────────────────────────────────────
    // C. CHECKBOXES — detectar cambios y disparar fetch con debounce
    // ──────────────────────────────────────────────────────────────
    function bindCheckboxes() {
        $(document).on('change', '.acf-checkbox', function () {
            syncCheckedState();
            state.paged = 1;

            clearTimeout( debounceTimer );
            debounceTimer = setTimeout( function () {
                if ( state.termIds.length === 0 ) {
                    clearFilter();
                } else {
                    fetchProducts();
                }
            }, DEBOUNCE_MS );
        });
    }

    /**
     * Lee todos los checkboxes marcados y actualiza state.termIds
     * y las clases visuales .acf-is-checked de cada li.
     */
    function syncCheckedState() {
        state.termIds = [];

        $('.acf-checkbox').each(function () {
            var $cb   = $(this);
            var $item = $cb.closest('.acf-cat-item');
            var checked = $cb.is(':checked');

            $item.toggleClass('acf-is-checked', checked);

            if ( checked ) {
                state.termIds.push( parseInt( $cb.val(), 10 ) );
            }
        });

        // Mostrar/ocultar botón "Limpiar filtros"
        var $clearBtn = $('.acf-clear-btn');
        $clearBtn.toggle( state.termIds.length > 0 );
    }

    // ──────────────────────────────────────────────────────────────
    // D. BOTÓN LIMPIAR
    // ──────────────────────────────────────────────────────────────
    function bindClearButton() {
        $(document).on('click', '.acf-clear-btn', function () {
            // Desmarcar todos los checkboxes
            $('.acf-checkbox').prop('checked', false);
            syncCheckedState();
            clearFilter();
        });
    }

    // ──────────────────────────────────────────────────────────────
    // E. PAGINACIÓN AJAX
    // ──────────────────────────────────────────────────────────────
    function bindPagination() {
        $woo.on('click', '.acf-page:not([disabled])', function () {
            var page = parseInt( $(this).data('page'), 10 );
            if ( !isNaN(page) && page > 0 ) {
                state.paged = page;
                fetchProducts();
                $('html, body').animate(
                    { scrollTop: $woo.offset().top - 80 }, 300
                );
            }
        });
    }

    // ──────────────────────────────────────────────────────────────
    // F. FETCH AJAX
    // ──────────────────────────────────────────────────────────────
    function fetchProducts() {
        if ( state.loading ) return;
        state.loading = true;
        setLoading( true );

        var postData = {
            action:  acfData.action,
            nonce:   acfData.nonce,
            paged:   state.paged,
        };

        // Enviar cada term_id como term_ids[]
        $.each( state.termIds, function ( i, id ) {
            postData['term_ids[' + i + ']'] = id;
        });

        if ( state.perPage > 0 ) postData.per_page = state.perPage;

        $.ajax({
            url:  acfData.ajaxUrl,
            type: 'POST',
            data: postData,
            success: function (response) {
                if ( response.success ) {
                    renderProducts( response.data );
                } else {
                    showError();
                }
            },
            error: showError,
            complete: function () {
                state.loading = false;
                setLoading( false );
            },
        });
    }

    // ──────────────────────────────────────────────────────────────
    // G. RENDERIZAR RESULTADOS
    // ──────────────────────────────────────────────────────────────
    function renderProducts( data ) {

        // 1. Ocultar loop nativo y todos sus elementos asociados
        hideNativeLoop();

        // 2. Limpiar resultados anteriores
        if ( $filteredProducts )   { $filteredProducts.remove();   $filteredProducts   = null; }
        if ( $filteredPagination ) { $filteredPagination.remove(); $filteredPagination = null; }

        // 3. Insertar nuevos productos antes del ul nativo (que está oculto)
        $filteredProducts = $( data.html ).addClass('acf-results-enter');
        $nativeProducts.before( $filteredProducts );

        // 4. Insertar paginación filtrada
        if ( data.pagination ) {
            $filteredPagination = $( data.pagination );
            $filteredProducts.after( $filteredPagination );
        }

        // 5. Actualizar conteo
        updateResultCount( data.total, data.current, state.perPage || data.total );

        // 6. Aplicar vista Grilla/Lista guardada en localStorage
        applyCurrentView();

        state.filtered = true;
    }

    // ──────────────────────────────────────────────────────────────
    // H. OCULTAR / MOSTRAR TODOS LOS ELEMENTOS NATIVOS
    // ──────────────────────────────────────────────────────────────
    function hideNativeLoop() {
        $nativeProducts.hide();
        $nativePagination.hide();
        $nativeResultCount.hide();
        $nativeOrdering.hide();
        $nativeViewToggle.hide();
        $nativeProducts.prev('.argen-list-header').hide();
    }

    function showNativeLoop() {
        $nativeProducts.show();
        $nativePagination.show();
        $nativeResultCount.show();
        $nativeOrdering.show();
        $nativeViewToggle.show();
        $nativeProducts.prev('.argen-list-header').show();
    }

    // ──────────────────────────────────────────────────────────────
    // I. LIMPIAR FILTRO — volver al estado original
    // ──────────────────────────────────────────────────────────────
    function clearFilter() {
        if ( $filteredProducts )   { $filteredProducts.remove();   $filteredProducts   = null; }
        if ( $filteredPagination ) { $filteredPagination.remove(); $filteredPagination = null; }

        // Limpiar encabezados de lista huérfanos
        $woo.find('.argen-list-header').not( $nativeProducts.prev() ).remove();

        showNativeLoop();

        // Restaurar conteo original
        $nativeResultCount.html( $nativeResultCountHTML );

        state.termIds  = [];
        state.paged    = 1;
        state.filtered = false;
    }

    // ──────────────────────────────────────────────────────────────
    // J. APLICAR VISTA GRILLA / LISTA A LOS RESULTADOS FILTRADOS
    //    Lee la preferencia que guarda argen-quote-loop en localStorage
    // ──────────────────────────────────────────────────────────────
    function applyCurrentView() {
        if ( !$filteredProducts ) return;

        var view = 'grid';
        try { view = localStorage.getItem('argenViewMode') || 'grid'; } catch(e) {}

        var $ul = $filteredProducts.is('ul.products')
            ? $filteredProducts
            : $filteredProducts.find('ul.products');

        if ( !$ul.length ) return;

        if ( view === 'list' ) {
            $ul.addClass('argen-list-view');

            if ( !$ul.prev('.argen-list-header').length ) {
                $([
                    '<div class="argen-list-header">',
                    '<span></span>',
                    '<span>Producto</span>',
                    '<span>Presentación</span>',
                    '<span style="text-align:center">Cantidad</span>',
                    '<span></span>',
                    '</div>'
                ].join('')).insertBefore( $ul );
            }
        } else {
            $ul.removeClass('argen-list-view');
            $ul.prev('.argen-list-header').remove();
        }
    }

    // ──────────────────────────────────────────────────────────────
    // K. HELPERS
    // ──────────────────────────────────────────────────────────────
    function setLoading( on ) {
        if ( on ) {
            if ( !$woo.find('.acf-loading-overlay').length ) {
                $woo.css('position', 'relative').prepend(
                    '<div class="acf-loading-overlay" aria-label="Cargando productos" role="status">'
                    + '<span class="acf-spinner"></span>'
                    + '</div>'
                );
            }
        } else {
            $woo.find('.acf-loading-overlay').remove();
        }
    }

    function updateResultCount( total, current, perPage ) {
        // Mostrar siempre el conteo aunque esté oculto el nativo
        // Lo mostramos como texto simple encima de los resultados filtrados
        var $existing = $woo.find('.acf-result-count-filtered');
        if ( !$existing.length ) {
            $existing = $('<p class="woocommerce-result-count acf-result-count-filtered"></p>');
            if ( $filteredProducts ) {
                $filteredProducts.before( $existing );
            }
        }

        if ( total === 0 ) {
            $existing.text( acfData.i18n.noResult );
            return;
        }

        var from = ( current - 1 ) * perPage + 1;
        var to   = Math.min( current * perPage, total );
        $existing.text( 'Mostrando ' + from + '\u2013' + to + ' de ' + total + ' resultados' );
    }

    function showError() {
        if ( $filteredProducts ) $filteredProducts.remove();
        $filteredProducts = $( '<p class="woocommerce-info acf-no-results">' + acfData.i18n.error + '</p>' );
        $nativeProducts.before( $filteredProducts );
    }

    // Cuando el usuario cambia entre Grilla/Lista, re-aplicar a los filtrados
    $(document).on('click', '.argen-toggle-btn', function () {
        if ( state.filtered ) setTimeout( applyCurrentView, 50 );
    });

    // ──────────────────────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────────────────────
    $(document).ready( init );

})(jQuery);
