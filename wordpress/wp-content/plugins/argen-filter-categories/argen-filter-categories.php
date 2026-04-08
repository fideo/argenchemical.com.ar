<?php
/**
 * Plugin Name: Argen Filter Categories
 * Plugin URI:  https://argenchemical.federicomazzei.com.ar
 * Description: Widget de sidebar con checkboxes para filtrar productos WooCommerce. Los resultados se muestran en el área principal de la tienda, sin recargar la página. Compatible con Astra + WooCommerce + argen-quote-loop.
 * Version:     1.2.0
 * Author:      Federico Mazzei
 * License:     GPL-2.0+
 * Text Domain: argen-filter-categories
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// CONSTANTES
// ─────────────────────────────────────────────
define( 'AFC_VERSION',     '1.2.0' );
define( 'AFC_URL',         plugin_dir_url( __FILE__ ) );
define( 'AFC_AJAX_ACTION', 'afc_filter_products' );


// ─────────────────────────────────────────────
// 1. REGISTRAR WIDGET
// ─────────────────────────────────────────────
add_action( 'widgets_init', function () {
    register_widget( 'AFC_Filter_Widget' );
} );


// ─────────────────────────────────────────────
// 2. CLASE DEL WIDGET
// ─────────────────────────────────────────────
class AFC_Filter_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'afc_filter_widget',
            __( 'Filtro de Categorías (Argen)', 'argen-filter-categories' ),
            [
                'description' => __( 'Checkboxes para filtrar productos WooCommerce. Los resultados aparecen en la zona principal de la tienda.', 'argen-filter-categories' ),
                'classname'   => 'afc-filter-widget',
            ]
        );
    }

    // ── Frontend ──────────────────────────────
    public function widget( $args, $instance ) {

        if ( ! class_exists( 'WooCommerce' ) ) return;

        $titulo        = ! empty( $instance['title'] )         ? $instance['title'] : __( 'Categorías', 'argen-filter-categories' );
        $mostrar_count = ! empty( $instance['mostrar_count'] );
        $mostrar_hijos = ! empty( $instance['mostrar_hijos'] );

        // Nueva opción: mostrar widget abierto o cerrado por defecto
        // 'open'  → lista visible al cargar la página
        // 'closed' → lista oculta, el usuario la abre manualmente
        $estado_inicial = ! empty( $instance['estado_inicial'] ) ? $instance['estado_inicial'] : 'open';
        $widget_abierto = ( $estado_inicial === 'open' );

        $categorias = get_terms( [
            'taxonomy'   => 'product_cat',
            'orderby'    => 'name',
            'order'      => 'ASC',
            'hide_empty' => true,
            'parent'     => 0,
        ] );

        if ( empty( $categorias ) || is_wp_error( $categorias ) ) return;

        echo $args['before_widget'];

        // ── Título con botón de colapso ────────
        // El título tiene un botón para que el usuario pueda
        // abrir/cerrar todo el widget (independiente de los checkboxes).
        if ( $titulo ) {
            echo $args['before_title'];
            /*echo '<button class="afc-widget-toggle" '
                . 'aria-expanded="' . ( $widget_abierto ? 'true' : 'false' ) . '" '
                . 'aria-controls="afc-widget-body-' . esc_attr( $this->id ) . '" '
                . 'aria-label="' . esc_attr__( 'Mostrar u ocultar filtros', 'argen-filter-categories' ) . '">'
                . apply_filters( 'widget_title', esc_html( $titulo ) )
                . '<span class="afc-widget-toggle-icon" aria-hidden="true"></span>'
                . '</button>';*/
            echo apply_filters( 'widget_title', esc_html( $titulo ) );
            echo $args['after_title'];
        }

        // ── Cuerpo colapsable del widget ───────
        // data-default-open lo lee el JS para saber el estado inicial
        // sin depender de clases que podrían ser sobrescritas por el tema.
        echo '<div class="afc-widget-body" '
            . 'id="afc-widget-body-' . esc_attr( $this->id ) . '" '
            . 'data-default-open="' . ( $widget_abierto ? '1' : '0' ) . '">';

        echo '<ul class="afc-cat-list" role="group" aria-label="'
            . esc_attr__( 'Categorías de productos', 'argen-filter-categories' ) . '">';

        foreach ( $categorias as $cat ) {

            $thumbnail_id = get_term_meta( $cat->term_id, 'thumbnail_id', true );
            $img_src      = $thumbnail_id
                ? wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' )
                : wc_placeholder_img_src( 'thumbnail' );

            $count_html  = $mostrar_count ? '<span class="afc-count">' . absint( $cat->count ) . '</span>' : '';
            $checkbox_id = 'afc-cat-' . $cat->term_id;

            $hijos = [];
            if ( $mostrar_hijos ) {
                $hijos = get_terms( [
                    'taxonomy'   => 'product_cat',
                    'parent'     => $cat->term_id,
                    'hide_empty' => true,
                ] );
            }
            $tiene_hijos = ! empty( $hijos ) && ! is_wp_error( $hijos );
            $item_class  = 'afc-cat-item' . ( $tiene_hijos ? ' has-children' : '' );

            echo '<li class="' . esc_attr( $item_class ) . ' is-open" data-cat-id="' . esc_attr( $cat->term_id ) . '">';
            echo '<div class="afc-cat-row">';

            // Label: checkbox + thumbnail + nombre + contador
            echo '<label class="afc-cat-label" for="' . esc_attr( $checkbox_id ) . '">';
            echo '<input type="checkbox"'
                . ' id="'         . esc_attr( $checkbox_id ) . '"'
                . ' class="afc-cat-checkbox"'
                . ' value="'      . esc_attr( $cat->term_id ) . '"'
                . ' data-slug="'  . esc_attr( $cat->slug ) . '">';
            echo '<span class="afc-checkbox-visual" aria-hidden="true"></span>';
            echo '<span class="afc-cat-thumb">'
                . '<img src="' . esc_url( $img_src ) . '" alt="" width="28" height="28" loading="lazy">'
                . '</span>';
            echo '<span class="afc-cat-name">' . esc_html( $cat->name ) . '</span>';
            echo $count_html;
            echo '</label>';

            // Botón toggle para subcategorías (acordeón)
            if ( $tiene_hijos ) {
                echo '<button class="afc-subcat-toggle" aria-expanded="true" '
                    . 'aria-label="' . esc_attr( sprintf( __( 'Expandir %s', 'argen-filter-categories' ), $cat->name ) ) . '">'
                    . '<span class="afc-toggle-icon" aria-hidden="true"></span>'
                    . '</button>';
            }

            echo '</div>'; // .afc-cat-row

            // Subcategorías
            if ( $tiene_hijos ) {
                echo '<ul class="afc-subcat-list">';
                foreach ( $hijos as $hijo ) {
                    $hijo_count_html = $mostrar_count ? '<span class="afc-count">' . absint( $hijo->count ) . '</span>' : '';
                    $hijo_id         = 'afc-cat-' . $hijo->term_id;

                    echo '<li class="afc-cat-item afc-subcat-item" data-cat-id="' . esc_attr( $hijo->term_id ) . '">';
                    echo '<div class="afc-cat-row">';
                    echo '<label class="afc-cat-label" for="' . esc_attr( $hijo_id ) . '">';
                    echo '<input type="checkbox"'
                        . ' id="'        . esc_attr( $hijo_id ) . '"'
                        . ' class="afc-cat-checkbox"'
                        . ' value="'     . esc_attr( $hijo->term_id ) . '"'
                        . ' data-slug="' . esc_attr( $hijo->slug ) . '"'
                        . ' data-parent="' . esc_attr( $cat->term_id ) . '">';
                    echo '<span class="afc-checkbox-visual" aria-hidden="true"></span>';
                    echo '<span class="afc-cat-name">' . esc_html( $hijo->name ) . '</span>';
                    echo $hijo_count_html;
                    echo '</label>';
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }

            echo '</li>';
        }

        echo '</ul>'; // .afc-cat-list

        // Botón limpiar (oculto hasta que haya alguna selección)
        echo '<button class="afc-clear-btn" style="display:none;" '
            . 'aria-label="' . esc_attr__( 'Limpiar todos los filtros', 'argen-filter-categories' ) . '">'
            . '<span aria-hidden="true">✕</span> '
            . esc_html__( 'Limpiar filtros', 'argen-filter-categories' )
            . '</button>';

        echo '</div>'; // .afc-widget-body
        echo $args['after_widget'];
    }

    // ── Backend: formulario de configuración ──
    public function form( $instance ) {
        $title          = ! empty( $instance['title'] )          ? $instance['title']                  : __( 'Categorías', 'argen-filter-categories' );
        $mostrar_count  = ! empty( $instance['mostrar_count'] )  ? '1'                                 : '0';
        $mostrar_hijos  = ! empty( $instance['mostrar_hijos'] )  ? '1'                                 : '0';
        $estado_inicial = ! empty( $instance['estado_inicial'] ) ? $instance['estado_inicial']         : 'open';
        $cols           = ! empty( $instance['columnas'] )       ? absint( $instance['columnas'] )     : 3;
        $per_page       = ! empty( $instance['por_pagina'] )     ? absint( $instance['por_pagina'] )   : 9;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Título:', 'argen-filter-categories' ); ?>
            </label>
            <input class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>

        <p>
            <input type="checkbox"
                id="<?php echo esc_attr( $this->get_field_id( 'mostrar_count' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'mostrar_count' ) ); ?>"
                value="1" <?php checked( $mostrar_count, '1' ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'mostrar_count' ) ); ?>">
                <?php esc_html_e( 'Mostrar cantidad de productos', 'argen-filter-categories' ); ?>
            </label>
        </p>

        <p>
            <input type="checkbox"
                id="<?php echo esc_attr( $this->get_field_id( 'mostrar_hijos' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'mostrar_hijos' ) ); ?>"
                value="1" <?php checked( $mostrar_hijos, '1' ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'mostrar_hijos' ) ); ?>">
                <?php esc_html_e( 'Mostrar subcategorías', 'argen-filter-categories' ); ?>
            </label>
        </p>

        <?php
        // ── Nueva opción: estado inicial del widget ────────────────
        // Determina si la lista de categorías se muestra abierta
        // o cerrada cuando el visitante llega a la página.
        ?>
        <p style="border-top:1px solid #ddd; padding-top:10px; margin-top:4px;">
            <strong><?php esc_html_e( 'Estado inicial del widget:', 'argen-filter-categories' ); ?></strong>
        </p>
        <p>
            <label>
                <input type="radio"
                    name="<?php echo esc_attr( $this->get_field_name( 'estado_inicial' ) ); ?>"
                    value="open"
                    <?php checked( $estado_inicial, 'open' ); ?>>
                <?php esc_html_e( 'Abierto (las categorías se ven al cargar la página)', 'argen-filter-categories' ); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="radio"
                    name="<?php echo esc_attr( $this->get_field_name( 'estado_inicial' ) ); ?>"
                    value="closed"
                    <?php checked( $estado_inicial, 'closed' ); ?>>
                <?php esc_html_e( 'Cerrado (el visitante debe hacer click para ver las categorías)', 'argen-filter-categories' ); ?>
            </label>
        </p>

        <p style="border-top:1px solid #ddd; padding-top:10px; margin-top:4px;">
            <strong><?php esc_html_e( 'Grilla de resultados:', 'argen-filter-categories' ); ?></strong>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'columnas' ) ); ?>">
                <?php esc_html_e( 'Columnas:', 'argen-filter-categories' ); ?>
            </label>
            <select class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'columnas' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'columnas' ) ); ?>">
                <?php foreach ( [ 2, 3, 4 ] as $n ) : ?>
                    <option value="<?php echo $n; ?>" <?php selected( $cols, $n ); ?>>
                        <?php echo $n; ?> <?php esc_html_e( 'columnas', 'argen-filter-categories' ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'por_pagina' ) ); ?>">
                <?php esc_html_e( 'Productos por página:', 'argen-filter-categories' ); ?>
            </label>
            <input class="widefat"
                id="<?php echo esc_attr( $this->get_field_id( 'por_pagina' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'por_pagina' ) ); ?>"
                type="number" min="1" max="48"
                value="<?php echo esc_attr( $per_page ); ?>">
        </p>
        <?php
    }

    // ── Guardar configuración ─────────────────
    public function update( $new_instance, $old_instance ) {
        $estado = sanitize_text_field( $new_instance['estado_inicial'] ?? 'open' );
        return [
            'title'          => sanitize_text_field( $new_instance['title'] ),
            'mostrar_count'  => ! empty( $new_instance['mostrar_count'] ) ? '1' : '0',
            'mostrar_hijos'  => ! empty( $new_instance['mostrar_hijos'] ) ? '1' : '0',
            'estado_inicial' => in_array( $estado, [ 'open', 'closed' ], true ) ? $estado : 'open',
            'columnas'       => max( 2, min( 4, absint( $new_instance['columnas'] ) ) ),
            'por_pagina'     => max( 1, min( 48, absint( $new_instance['por_pagina'] ) ) ),
        ];
    }
}


// ─────────────────────────────────────────────
// 3. INYECTAR CONTENEDOR EN EL CONTENIDO PRINCIPAL
// ─────────────────────────────────────────────
add_action( 'woocommerce_before_shop_loop', 'afc_inject_results_container', 5 );

function afc_inject_results_container() {
    if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) return;

    $cols     = 3;
    $per_page = 9;

    $sidebars = wp_get_sidebars_widgets();
    foreach ( $sidebars as $widget_ids ) {
        if ( empty( $widget_ids ) ) continue;
        foreach ( $widget_ids as $widget_id ) {
            if ( strpos( $widget_id, 'afc_filter_widget' ) === 0 ) {
                $number = str_replace( 'afc_filter_widget-', '', $widget_id );
                $opts   = get_option( 'widget_afc_filter_widget' );
                if ( isset( $opts[ $number ] ) ) {
                    $inst     = $opts[ $number ];
                    $cols     = ! empty( $inst['columnas'] )   ? absint( $inst['columnas'] )   : 3;
                    $per_page = ! empty( $inst['por_pagina'] ) ? absint( $inst['por_pagina'] ) : 9;
                }
                break 2;
            }
        }
    }
    ?>
    <div id="afc-results-area"
         data-cols="<?php echo esc_attr( $cols ); ?>"
         data-per-page="<?php echo esc_attr( $per_page ); ?>"
         aria-live="polite"
         aria-atomic="true"
         style="display:none;">

        <div class="afc-results-header">
            <span class="afc-results-count-text"></span>
            <button class="afc-results-close">
                <span aria-hidden="true">←</span>
                <?php esc_html_e( 'Ver todos los productos', 'argen-filter-categories' ); ?>
            </button>
        </div>

        <div class="afc-loader" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>

        <div class="afc-results-inner"></div>

    </div>
    <?php
}


// ─────────────────────────────────────────────
// 4. ENDPOINT AJAX
//    Genera exactamente la misma estructura HTML
//    que usa argen-quote-loop para las tarjetas,
//    de modo que quote-loop.css/js las controla.
// ─────────────────────────────────────────────
add_action( 'wp_ajax_'        . AFC_AJAX_ACTION, 'afc_ajax_filter_products' );
add_action( 'wp_ajax_nopriv_' . AFC_AJAX_ACTION, 'afc_ajax_filter_products' );

function afc_ajax_filter_products() {

    check_ajax_referer( AFC_AJAX_ACTION, 'nonce' );

    $cat_ids  = isset( $_POST['cat_ids'] )  ? array_map( 'absint', (array) $_POST['cat_ids'] ) : [];
    $cols     = isset( $_POST['cols'] )     ? max( 1, min( 5, absint( $_POST['cols'] ) ) )      : 3;
    $per_page = isset( $_POST['per_page'] ) ? max( 1, min( 48, absint( $_POST['per_page'] ) ) ) : 9;
    $paged    = isset( $_POST['paged'] )    ? max( 1, absint( $_POST['paged'] ) )                : 1;

    if ( empty( $cat_ids ) ) {
        wp_send_json_error( [ 'message' => 'No categories provided.' ] );
    }

    $query = new WP_Query( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => [ [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $cat_ids,
            'operator'         => 'IN',
            'include_children' => true,
        ] ],
    ] );

    if ( ! $query->have_posts() ) {
        wp_send_json_success( [
            'html'    => '<p class="afc-no-results">'
                . esc_html__( 'No se encontraron productos para las categorías seleccionadas.', 'argen-filter-categories' )
                . '</p>',
            'total'   => 0,
            'pages'   => 0,
            'current' => 1,
        ] );
    }

    // ── Nonce de argen-quote-loop (si está activo) ─────────────────
    // argen-quote-loop usa su propio nonce para el AJAX de agregar al presupuesto.
    // Lo generamos acá para incrustarlo en el data-nonce del botón.
    $quote_nonce_action = 'argen_add_to_quote'; // debe coincidir con el plugin hermano
    $quote_nonce        = wp_create_nonce( $quote_nonce_action );

    ob_start();

    // ── Wrapper: misma estructura que genera WooCommerce ──────────
    // ul.products con la clase de columnas que usa Astra/WooCommerce.
    // Así quote-loop.css y quote-loop.js funcionan sin modificaciones.
    echo '<ul class="products columns-' . esc_attr( $cols ) . ' afc-filtered-products">';

    while ( $query->have_posts() ) {
        $query->the_post();

        $product_id = get_the_ID();
        $product    = wc_get_product( $product_id );
        if ( ! $product ) continue;

        // ── Imagen para vista lista (argen-list-img-wrap) ──────────
        $img_src = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
        if ( ! $img_src ) {
            $img_src = wc_placeholder_img_src( 'thumbnail' );
        }

        // ── Variaciones del producto ───────────────────────────────
        $variations_html = '';
        if ( $product->is_type( 'variable' ) ) {
            $attributes = $product->get_variation_attributes();
            foreach ( $attributes as $attr_name => $options ) {
                $attr_label      = wc_attribute_label( $attr_name );
                $attr_name_clean = sanitize_title( $attr_name );

                $variations_html .= '<div class="argen-variation-row">';
                $variations_html .= '<label class="argen-variation-label">' . esc_html( $attr_label ) . '</label>';
                $variations_html .= '<select class="argen-variation-select" data-attribute="' . esc_attr( $attr_name_clean ) . '">';
                $variations_html .= '<option value="">' . esc_html__( 'Elegí una opción', 'argen-filter-categories' ) . '</option>';
                foreach ( $options as $option ) {
                    $variations_html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
                }
                $variations_html .= '</select>';
                $variations_html .= '</div>';
            }
        }

        // ── HTML de la tarjeta ─────────────────────────────────────
        // Estructura idéntica a la que genera argen-quote-loop.php,
        // para que quote-loop.css y quote-loop.js la controlen al 100%.
        ?>
        <li class="product type-product">
            <form class="argen-quote-loop-form">
                <div class="argen-form-inner">

                    <!-- Imagen (visible en vista lista) -->
                    <div class="argen-list-img-wrap">
                        <img class="argen-list-thumb"
                             src="<?php echo esc_url( $img_src ); ?>"
                             alt="<?php echo esc_attr( get_the_title() ); ?>"
                             width="64" height="43" loading="lazy">
                    </div>

                    <!-- Nombre (visible en vista lista) -->
                    <div class="argen-list-name">
                        <a href="<?php echo esc_url( get_permalink() ); ?>">
                            <?php echo esc_html( get_the_title() ); ?>
                        </a>
                    </div>

                    <!-- Variaciones -->
                    <?php if ( $variations_html ) : ?>
                        <div class="argen-variations-wrap">
                            <?php echo $variations_html; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Cantidad + botón -->
                    <div class="argen-qty-quote-row">
                        <div class="argen-qty-wrapper">
                            <button type="button" class="argen-qty-btn argen-qty-minus" aria-label="<?php esc_attr_e( 'Reducir cantidad', 'argen-filter-categories' ); ?>">−</button>
                            <input type="number"
                                   class="argen-qty-input"
                                   value="1" min="1" max="9999"
                                   aria-label="<?php esc_attr_e( 'Cantidad', 'argen-filter-categories' ); ?>">
                            <button type="button" class="argen-qty-btn argen-qty-plus" aria-label="<?php esc_attr_e( 'Aumentar cantidad', 'argen-filter-categories' ); ?>">+</button>
                        </div>

                        <div class="argen-add-quote-wrap">
                            <button type="button"
                                    class="argen-add-quote-btn"
                                    data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                    data-nonce="<?php echo esc_attr( $quote_nonce ); ?>">
                                <?php esc_html_e( 'Agregar', 'argen-filter-categories' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Feedback AJAX -->
                    <div class="argen-quote-feedback" aria-live="polite"></div>

                </div><!-- .argen-form-inner -->
            </form><!-- .argen-quote-loop-form -->

            <!-- Nombre e imagen para VISTA GRILLA (los genera el tema/WooCommerce) -->
            <!-- En grilla, argen-list-img-wrap y argen-list-name están display:none  -->
            <!-- y la imagen real la mostramos aquí para que el tema la estilice      -->
            <a href="<?php echo esc_url( get_permalink() ); ?>" class="woocommerce-loop-product__link">
                <?php echo get_the_post_thumbnail( $product_id, 'woocommerce_thumbnail' ); ?>
                <h2 class="woocommerce-loop-product__title"><?php echo esc_html( get_the_title() ); ?></h2>
            </a>

        </li>
        <?php
    }

    echo '</ul>'; // .products
    wp_reset_postdata();

    wp_send_json_success( [
        'html'    => ob_get_clean(),
        'total'   => (int) $query->found_posts,
        'pages'   => (int) $query->max_num_pages,
        'current' => $paged,
    ] );
}


// ─────────────────────────────────────────────
// 5. ASSETS
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'afc_enqueue_assets' );

function afc_enqueue_assets() {
    if ( ! class_exists( 'WooCommerce' ) ) return;

    // Solo cargamos nuestro CSS de sidebar + contenedor de resultados.
    // Los estilos de las tarjetas los maneja quote-loop.css del plugin hermano.
    wp_enqueue_style(
        'afc-style',
        AFC_URL . 'assets/afc-style.css',
        [],
        AFC_VERSION
    );

    wp_enqueue_script(
        'afc-script',
        AFC_URL . 'assets/afc-script.js',
        [],
        AFC_VERSION,
        true
    );

    wp_localize_script( 'afc-script', 'afcData', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( AFC_AJAX_ACTION ),
        'action'  => AFC_AJAX_ACTION,
        'i18n'    => [
            'error'   => __( 'Ocurrió un error. Intentá nuevamente.', 'argen-filter-categories' ),
            'found'   => __( 'productos encontrados', 'argen-filter-categories' ),
            'noFound' => __( 'No se encontraron productos para las categorías seleccionadas.', 'argen-filter-categories' ),
        ],
    ] );
}
