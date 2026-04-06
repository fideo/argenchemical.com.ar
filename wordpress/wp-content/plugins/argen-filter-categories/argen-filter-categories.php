<?php
/**
 * Plugin Name: Argen Filter Categories
 * Plugin URI:  https://argenchemical.federicomazzei.com.ar
 * Description: Widget de sidebar con checkboxes para filtrar productos WooCommerce por categoría de forma dinámica (sin recargar la página). Compatible con el plugin Widget de Categorías Dinámico.
 * Version:     1.0.0
 * Author:      Federico Mazzei
 * License:     GPL-2.0+
 * Text Domain: argen-filter-categories
 * Requires Plugins: woocommerce
 */

// Seguridad: bloquear acceso directo al archivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
// CONSTANTES DEL PLUGIN
// ─────────────────────────────────────────────
define( 'AFC_VERSION',   '1.0.0' );
define( 'AFC_DIR',       plugin_dir_path( __FILE__ ) );
define( 'AFC_URL',       plugin_dir_url( __FILE__ ) );
define( 'AFC_AJAX_ACTION', 'afc_filter_products' );

// ─────────────────────────────────────────────
// 1. REGISTRAR EL WIDGET
// ─────────────────────────────────────────────
function afc_register_widget() {
    register_widget( 'AFC_Filter_Widget' );
}
add_action( 'widgets_init', 'afc_register_widget' );


// ─────────────────────────────────────────────
// 2. CLASE DEL WIDGET
// ─────────────────────────────────────────────
class AFC_Filter_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'afc_filter_widget',
            __( 'Filtro de Categorías (Argen)', 'argen-filter-categories' ),
            array(
                'description' => __( 'Filtra productos WooCommerce por categoría usando checkboxes dinámicos.', 'argen-filter-categories' ),
                'classname'   => 'afc-filter-widget',
            )
        );
    }

    // ── FRONTEND ──────────────────────────────
    public function widget( $args, $instance ) {

        // Verificar que WooCommerce esté activo
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $titulo        = ! empty( $instance['title'] )         ? $instance['title']         : __( 'Filtrar por Categoría', 'argen-filter-categories' );
        $mostrar_count = ! empty( $instance['mostrar_count'] ) ? true                        : false;
        $cols          = ! empty( $instance['columnas'] )      ? absint( $instance['columnas'] ) : 3;
        $posts_por_pag = ! empty( $instance['por_pagina'] )    ? absint( $instance['por_pagina'] ) : 9;

        $categorias = get_terms( array(
            'taxonomy'   => 'product_cat',
            'orderby'    => 'name',
            'order'      => 'ASC',
            'hide_empty' => true,
            'parent'     => 0,
        ) );

        if ( empty( $categorias ) || is_wp_error( $categorias ) ) {
            return;
        }

        echo $args['before_widget'];

        if ( $titulo ) {
            echo $args['before_title'] . apply_filters( 'widget_title', esc_html( $titulo ) ) . $args['after_title'];
        }

        // Contenedor principal del widget
        echo '<div class="afc-widget-inner" '
            . 'data-cols="' . esc_attr( $cols ) . '" '
            . 'data-per-page="' . esc_attr( $posts_por_pag ) . '">';

        // ── Lista de checkboxes ────────────────
        echo '<ul class="afc-cat-list">';

        foreach ( $categorias as $cat ) {
            $count_html = '';
            if ( $mostrar_count ) {
                $count_html = '<span class="afc-count">' . absint( $cat->count ) . '</span>';
            }

            // Obtener imagen de la categoría (thumbnail de WooCommerce)
            $thumbnail_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
            $img_src       = $thumbnail_id
                ? wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' )
                : wc_placeholder_img_src( 'thumbnail' );

            echo '<li class="afc-cat-item">';
            echo '<label class="afc-cat-label">';
            echo '<input type="checkbox" '
                . 'class="afc-cat-checkbox" '
                . 'value="' . esc_attr( $cat->term_id ) . '" '
                . 'data-slug="' . esc_attr( $cat->slug ) . '">';
            echo '<span class="afc-cat-thumb">'
                . '<img src="' . esc_url( $img_src ) . '" '
                . 'alt="' . esc_attr( $cat->name ) . '" '
                . 'width="32" height="32" loading="lazy">'
                . '</span>';
            echo '<span class="afc-cat-name">' . esc_html( $cat->name ) . '</span>';
            echo $count_html;
            echo '</label>';
            echo '</li>';
        }

        echo '</ul>'; // .afc-cat-list

        // ── Botón "Limpiar filtros" ────────────
        echo '<button class="afc-clear-btn" style="display:none;">'
            . '<span class="afc-clear-icon">✕</span> '
            . esc_html__( 'Limpiar filtros', 'argen-filter-categories' )
            . '</button>';

        echo '</div>'; // .afc-widget-inner

        // ── Área de resultados (fuera del widget, en el contenido principal) ──
        // Se renderiza solo una vez aunque haya múltiples widgets en la página.
        // El JS lo ubica si existe; sino lo inserta dinámicamente.
        if ( ! did_action( 'afc_results_rendered' ) ) {
            do_action( 'afc_results_rendered' );
            echo '<div id="afc-results-area" '
                . 'data-cols="' . esc_attr( $cols ) . '" '
                . 'data-per-page="' . esc_attr( $posts_por_pag ) . '" '
                . 'aria-live="polite" aria-atomic="true">'
                . '<div class="afc-results-inner"></div>'
                . '<div class="afc-loader" aria-label="' . esc_attr__( 'Cargando productos...', 'argen-filter-categories' ) . '">'
                . '<span></span><span></span><span></span>'
                . '</div>'
                . '</div>';
        }

        echo $args['after_widget'];
    }

    // ── BACKEND: formulario de configuración ──
    public function form( $instance ) {
        $title         = ! empty( $instance['title'] )         ? $instance['title']         : __( 'Filtrar por Categoría', 'argen-filter-categories' );
        $mostrar_count = ! empty( $instance['mostrar_count'] ) ? '1'                         : '0';
        $columnas      = ! empty( $instance['columnas'] )      ? absint( $instance['columnas'] ) : 3;
        $por_pagina    = ! empty( $instance['por_pagina'] )    ? absint( $instance['por_pagina'] ) : 9;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Título:', 'argen-filter-categories' ); ?>
            </label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $title ); ?>">
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
            <label for="<?php echo esc_attr( $this->get_field_id( 'columnas' ) ); ?>">
                <?php esc_html_e( 'Columnas en el grid de resultados:', 'argen-filter-categories' ); ?>
            </label>
            <select class="widefat"
                    id="<?php echo esc_attr( $this->get_field_id( 'columnas' ) ); ?>"
                    name="<?php echo esc_attr( $this->get_field_name( 'columnas' ) ); ?>">
                <?php foreach ( array( 2, 3, 4 ) as $n ) : ?>
                    <option value="<?php echo $n; ?>" <?php selected( $columnas, $n ); ?>>
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
                   value="<?php echo esc_attr( $por_pagina ); ?>">
        </p>
        <?php
    }

    // ── GUARDAR configuración ─────────────────
    public function update( $new_instance, $old_instance ) {
        $instance                  = array();
        $instance['title']         = sanitize_text_field( $new_instance['title'] );
        $instance['mostrar_count'] = ! empty( $new_instance['mostrar_count'] ) ? '1' : '0';
        $instance['columnas']      = absint( $new_instance['columnas'] );
        $instance['por_pagina']    = absint( $new_instance['por_pagina'] );
        return $instance;
    }
}


// ─────────────────────────────────────────────
// 3. ENDPOINT AJAX — Filtrar productos
// ─────────────────────────────────────────────
/**
 * Maneja la petición AJAX y devuelve HTML de productos.
 * Responde tanto a usuarios logueados como no logueados.
 */
function afc_ajax_filter_products() {

    // ── Seguridad: verificar nonce ─────────────
    check_ajax_referer( AFC_AJAX_ACTION, 'nonce' );

    // ── Sanitizar parámetros de entrada ────────
    $cat_ids    = isset( $_POST['cat_ids'] )    ? array_map( 'absint', (array) $_POST['cat_ids'] )    : array();
    $cols       = isset( $_POST['cols'] )       ? absint( $_POST['cols'] )                             : 3;
    $per_page   = isset( $_POST['per_page'] )   ? absint( $_POST['per_page'] )                         : 9;
    $paged      = isset( $_POST['paged'] )      ? absint( $_POST['paged'] )                            : 1;

    // Límites de seguridad
    $cols     = max( 1, min( 6, $cols ) );
    $per_page = max( 1, min( 48, $per_page ) );
    $paged    = max( 1, $paged );

    // ── Construir WP_Query ─────────────────────
    $query_args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
    );

    if ( ! empty( $cat_ids ) ) {
        $query_args['tax_query'] = array(
            array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $cat_ids,
                'operator'         => 'IN',
                'include_children' => true,
            ),
        );
    }

    $query = new WP_Query( $query_args );

    // ── Sin resultados ─────────────────────────
    if ( ! $query->have_posts() ) {
        wp_send_json_success( array(
            'html'       => '<p class="afc-no-results">' . esc_html__( 'No se encontraron productos para las categorías seleccionadas.', 'argen-filter-categories' ) . '</p>',
            'total'      => 0,
            'pages'      => 0,
            'current'    => 1,
        ) );
    }

    // ── Generar HTML de la grilla ──────────────
    ob_start();

    echo '<div class="afc-products-grid afc-cols-' . esc_attr( $cols ) . '">';

    while ( $query->have_posts() ) {
        $query->the_post();
        global $product;

        if ( ! $product instanceof WC_Product ) {
            $product = wc_get_product( get_the_ID() );
        }

        if ( ! $product ) {
            continue;
        }

        $permalink     = get_permalink();
        $img_html      = get_the_post_thumbnail( get_the_ID(), 'woocommerce_thumbnail', array( 'class' => 'afc-product-img' ) );
        $price_html    = $product->get_price_html();
        $on_sale       = $product->is_on_sale();
        $cats          = get_the_terms( get_the_ID(), 'product_cat' );
        $cat_name      = ( $cats && ! is_wp_error( $cats ) ) ? esc_html( $cats[0]->name ) : '';
        $badge         = $on_sale ? '<span class="afc-badge afc-badge-sale">' . esc_html__( 'Oferta', 'argen-filter-categories' ) . '</span>' : '';

        echo '<article class="afc-product-card">';
        echo '<a href="' . esc_url( $permalink ) . '" class="afc-product-img-wrap" tabindex="-1" aria-hidden="true">';
        echo $img_html ?: '<div class="afc-product-img-placeholder"></div>';
        echo $badge;
        echo '</a>';

        echo '<div class="afc-product-body">';
        if ( $cat_name ) {
            echo '<span class="afc-product-cat">' . $cat_name . '</span>';
        }
        echo '<h3 class="afc-product-title">';
        echo '<a href="' . esc_url( $permalink ) . '">' . esc_html( get_the_title() ) . '</a>';
        echo '</h3>';

        if ( $price_html ) {
            echo '<div class="afc-product-price">' . wp_kses_post( $price_html ) . '</div>';
        }

        echo '<a href="' . esc_url( $permalink ) . '" class="afc-product-btn">'
            . esc_html__( 'Ver producto', 'argen-filter-categories' )
            . '</a>';
        echo '</div>'; // .afc-product-body

        echo '</article>';
    }

    echo '</div>'; // .afc-products-grid

    wp_reset_postdata();

    $html = ob_get_clean();

    wp_send_json_success( array(
        'html'    => $html,
        'total'   => (int) $query->found_posts,
        'pages'   => (int) $query->max_num_pages,
        'current' => $paged,
    ) );
}
add_action( 'wp_ajax_'        . AFC_AJAX_ACTION, 'afc_ajax_filter_products' );
add_action( 'wp_ajax_nopriv_' . AFC_AJAX_ACTION, 'afc_ajax_filter_products' );


// ─────────────────────────────────────────────
// 4. CARGAR ESTILOS Y SCRIPTS
// ─────────────────────────────────────────────
function afc_enqueue_assets() {

    // Solo cargar si WooCommerce está activo
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    wp_enqueue_style(
        'afc-style',
        AFC_URL . 'assets/afc-style.css',
        array(),
        AFC_VERSION
    );

    wp_enqueue_script(
        'afc-script',
        AFC_URL . 'assets/afc-script.js',
        array(),          // Sin dependencias (vanilla JS)
        AFC_VERSION,
        true              // Footer
    );

    // Pasar variables de PHP a JS de forma segura
    wp_localize_script( 'afc-script', 'afcData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( AFC_AJAX_ACTION ),
        'action'  => AFC_AJAX_ACTION,
        'i18n'    => array(
            'loading'   => __( 'Cargando productos...', 'argen-filter-categories' ),
            'noResults' => __( 'No se encontraron productos.', 'argen-filter-categories' ),
            'error'     => __( 'Ocurrió un error. Intentá nuevamente.', 'argen-filter-categories' ),
            'all'       => __( 'todos', 'argen-filter-categories' ),
            'results'   => __( 'productos encontrados', 'argen-filter-categories' ),
        ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'afc_enqueue_assets' );
