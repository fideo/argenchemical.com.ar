<?php
/**
 * Ajustes específicos para WooCommerce
 */

// Eliminar los contenedores por defecto de WC
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

// Agregar tus propios contenedores de Argenchemical
add_action('woocommerce_before_main_content', 'argenchemical_wrapper_start', 10);
add_action('woocommerce_after_main_content', 'argenchemical_wrapper_end', 10);

function argenchemical_wrapper_start() {
    echo '<main id="primary" class="site-main container">';
}

function argenchemical_wrapper_end() {
    echo '</main>';
}
