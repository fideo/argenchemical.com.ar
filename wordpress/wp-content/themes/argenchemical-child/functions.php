<?php
/**
 * Argenchemical Child Theme — functions.php
 * Tema padre: Astra
 *
 * NOTA SOBRE ASTRA:
 * Astra maneja sus propios estilos de forma diferente a otros temas.
 * NO usar wp_enqueue_style para el padre como en otros temas,
 * porque Astra ya lo hace internamente y duplicarlo causa conflictos.
 * Solo encolar el CSS del child y el JS del fix.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/* ============================================================
   1. CARGAR ESTILOS DEL CHILD THEME
      Con Astra NO se encola el padre manualmente.
      Astra se encola a sí mismo. Solo cargamos el child.
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'argenchemical_child_enqueue_styles' );
function argenchemical_child_enqueue_styles() {

    // Obtener versión del tema hijo para cache busting automático
    $child_version = wp_get_theme()->get( 'Version' );

    // Solo cargamos el CSS del CHILD (Astra carga el suyo solo)
    wp_enqueue_style(
        'argenchemical-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array(),          // Sin dependencias explícitas con Astra
        $child_version
    );
}


/* ============================================================
   2. SCRIPT: FIX BOTONES PRODUCTOS RELACIONADOS
      - Solo carga en páginas de producto individual
      - Depende de jQuery (ya incluido por WooCommerce)
      - Se carga en el footer para no bloquear el render
   ============================================================ */
/*add_action( 'wp_enqueue_scripts', 'argenchemical_enqueue_related_fix' );
function argenchemical_enqueue_related_fix() {

    // Solo en páginas de producto individual de WooCommerce
    if ( ! is_product() ) {
        return;
    }

    wp_enqueue_script(
        'argen-related-fix',
        get_stylesheet_directory_uri() . '/js/argen-related-fix.js',
        array( 'jquery' ),                      // jQuery ya está incluido por WC
        wp_get_theme()->get( 'Version' ),
        true                                    // TRUE = cargar en el footer
    );

    // Pasar datos de PHP a JS de forma segura
    // En JS se acceden como: argen_ajax.ajax_url
    wp_localize_script(
        'argen-related-fix',
        'argen_ajax',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
          )
    );
}*/


/* ============================================================
   3. ZONA LIBRE PARA TUS PERSONALIZACIONES FUTURAS
   ============================================================

   Ejemplos útiles:

   // Mostrar 4 productos relacionados en vez de 3
   add_filter( 'woocommerce_output_related_products_args', function( $args ) {
       $args['posts_per_page'] = 4;
       $args['columns']        = 4;
       return $args;
   });

   ============================================================ */

