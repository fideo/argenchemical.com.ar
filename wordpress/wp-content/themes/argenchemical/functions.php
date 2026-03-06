<?php
/**
 * Argenchemical functions and definitions
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function argenchemical_setup() {
    // Soporte para WooCommerce (Crucial)
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );
    
    // Soporte para títulos dinámicos y miniaturas
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'argenchemical_setup' );

// Encolar estilos y scripts
function argenchemical_scripts() {
    wp_enqueue_style( 'argenchemical-style', get_stylesheet_uri(), array(), '1.0.0' );
    wp_enqueue_script( 'argenchemical-main', get_template_directory_uri() . '/assets/js/main.js', array(), '1.0.0', true );
}
add_action( 'wp_enqueue_scripts', 'argenchemical_scripts' );

// Cargar archivos de configuración de WooCommerce si existen
if ( class_exists( 'WooCommerce' ) ) {
    require get_template_directory() . '/inc/woocommerce.php';
}
