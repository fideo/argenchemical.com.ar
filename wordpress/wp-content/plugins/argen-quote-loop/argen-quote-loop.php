<?php
/**
 * Plugin Name: Quote en Listado de Tienda
 * Plugin URI:  https://argechemical.com
 * Description: Agrega selector de variaciones (Presentaciones), cantidad y botón "Add to Quote"
 *              directamente en el listado de productos. Incluye toggle de vista Grilla / Lista
 *              y texto introductorio editable en la cabecera de la tienda.
 * Version:     1.3.0
 * Author:      ArgenChemical Dev
 * License:     GPL-2.0+
 * Text Domain: argen-quote-loop
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// GUARD: Solo ejecutar si WooCommerce está activo
// ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Quote en Listado:</strong> Requiere WooCommerce activo.</p></div>';
        });
        return;
    }
    new Argen_Quote_Loop();
});


// ─────────────────────────────────────────────────────────────────
// CLASE PRINCIPAL
// ─────────────────────────────────────────────────────────────────
class Argen_Quote_Loop {

    public function __construct() {
        // 1. Botón toggle Grilla / Lista antes del loop
        add_action( 'woocommerce_before_shop_loop', array( $this, 'render_view_toggle' ), 30 );

        // 2. Inyectar el formulario de variación en las cards del loop
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_quote_form_in_loop' ), 5 );

        // 3. Ocultar el botón nativo de WooCommerce/Catalog Mode en el loop
        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'maybe_hide_native_button' ), 999, 2 );

        // 4. Encolar scripts y estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 5. AJAX: agregar al quote desde el loop
        add_action( 'wp_ajax_argen_add_to_quote_loop',        array( $this, 'ajax_add_to_quote' ) );
        add_action( 'wp_ajax_nopriv_argen_add_to_quote_loop', array( $this, 'ajax_add_to_quote' ) );

        // 6. Bypass YITH Catalog Mode para nuestra acción AJAX propia
        add_action( 'wp_ajax_argen_add_to_quote_loop',        array( $this, 'bypass_catalog_mode' ), 1 );
        add_action( 'wp_ajax_nopriv_argen_add_to_quote_loop', array( $this, 'bypass_catalog_mode' ), 1 );

        // 7. Corregir el contador de resultados — reemplaza el template de WooCommerce
        remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
        add_action(    'woocommerce_before_shop_loop', array( $this, 'render_result_count' ), 20 );

        // 8. Texto introductorio de la tienda (entre el breadcrumb y los resultados)
        add_action( 'woocommerce_archive_description', array( $this, 'render_shop_intro_text' ), 5 );

        // 9. Página de ajustes en el admin (submenú de WooCommerce)
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }


    // ─────────────────────────────────────────────────────────────
    // BYPASS: Desactiva temporalmente los filtros de Catalog Mode
    // ─────────────────────────────────────────────────────────────
    public function bypass_catalog_mode() {
        remove_all_filters( 'woocommerce_add_to_cart_validation' );
        if ( class_exists( 'YITH_WC_Catalog_Mode' ) ) {
            $instance = YITH_WC_Catalog_Mode();
            remove_action( 'wp_ajax_woocommerce_add_to_cart',        array( $instance, 'block_ajax_add_to_cart' ) );
            remove_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', array( $instance, 'block_ajax_add_to_cart' ) );
        }
    }


    // ─────────────────────────────────────────────────────────────
    // RESULT COUNT: Reemplaza el contador nativo de WooCommerce
    // ─────────────────────────────────────────────────────────────
    public function render_result_count() {
        global $wp_query;

        if ( ! $wp_query || ! isset( $wp_query->found_posts ) ) {
            woocommerce_result_count();
            return;
        }

        $total    = (int) $wp_query->found_posts;
        $per_page = (int) get_option( 'posts_per_page', 12 );
        $paged    = max( 1, (int) get_query_var( 'paged' ) );

        $first = ( $per_page * ( $paged - 1 ) ) + 1;
        $last  = min( $total, $per_page * $paged );

        if ( $total <= 0 ) {
            return;
        }

        if ( 1 === $total ) {
            $message = 'Mostrando 1 resultado';
        } elseif ( $total <= $per_page ) {
            $message = 'Mostrando ' . $total . ' resultados';
        } else {
            $message = 'Mostrando ' . $first . '–' . $last . ' de ' . $total . ' resultados';
        }

        echo '<p class="woocommerce-result-count argen-result-count">' . esc_html( $message ) . '</p>';
    }


    // ─────────────────────────────────────────────────────────────
    // TEXTO INTRODUCTORIO: Se muestra entre el breadcrumb y el loop
    // Hook: woocommerce_archive_description (prioridad 5)
    // Solo se renderiza en la página principal de la tienda.
    // ─────────────────────────────────────────────────────────────
    public function render_shop_intro_text() {
        if ( ! is_shop() ) {
            return;
        }

        $texto = get_option( 'argen_shop_intro_text', '' );

        if ( empty( trim( $texto ) ) ) {
            return;
        }

        echo '<div class="argen-shop-intro">' . wp_kses_post( $texto ) . '</div>';
    }


    // ─────────────────────────────────────────────────────────────
    // ADMIN — Registrar submenú bajo WooCommerce
    // ─────────────────────────────────────────────────────────────
    public function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            'ArgenChemical — Texto Intro Tienda',
            'Texto Intro Tienda',
            'manage_options',
            'argen-shop-settings',
            array( $this, 'render_settings_page' )
        );
    }


    // ─────────────────────────────────────────────────────────────
    // ADMIN — Registrar la opción en la base de datos
    // ─────────────────────────────────────────────────────────────
    public function register_settings() {
        register_setting(
            'argen_shop_settings_group',
            'argen_shop_intro_text',
            array(
                'sanitize_callback' => 'wp_kses_post',
                'default'           => '',
            )
        );
    }


    // ─────────────────────────────────────────────────────────────
    // ADMIN — Renderizar la página de ajustes
    // ─────────────────────────────────────────────────────────────
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Mensaje de guardado
        $saved = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'];
        ?>
        <div class="wrap">

            <h1 style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:22px;">🧪</span>
                ArgenChemical — Texto introductorio de la Tienda
            </h1>

            <p style="color:#555; margin-bottom:20px; max-width:700px;">
                Este texto se muestra en la página de la tienda, justo debajo del breadcrumb
                y antes del listado de productos. Podés usar formato enriquecido: negrita,
                itálica, listas, links, etc.
                Si el campo está vacío, no se muestra nada.
            </p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
                    <p><strong>✅ Texto guardado correctamente.</strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'argen_shop_settings_group' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row" style="width:180px; vertical-align:top; padding-top:12px;">
                            <label for="argen_shop_intro_text">
                                <strong>Texto / HTML</strong>
                            </label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                get_option( 'argen_shop_intro_text', '' ),
                                'argen_shop_intro_text',
                                array(
                                    'textarea_name' => 'argen_shop_intro_text',
                                    'textarea_rows' => 7,
                                    'media_buttons' => false,
                                    'teeny'         => false,
                                    'quicktags'     => true,
                                )
                            );
                            ?>
                            <p class="description" style="margin-top:8px;">
                                Aparece entre el breadcrumb
                                (<em>Inicio » Tienda</em>) y el contador de resultados.
                                Dejá el campo vacío para ocultarlo.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top:10px;">
                    <?php submit_button( 'Guardar texto', 'primary', 'submit', false ); ?>
                </p>

            </form>

            <hr style="margin-top:30px;">
            <p style="color:#aaa; font-size:12px;">
                Quote en Listado de Tienda v1.3.0 — ArgenChemical Dev
            </p>

        </div>
        <?php
    }


    // ─────────────────────────────────────────────────────────────
    // TOGGLE: Botones para cambiar entre vista Grilla y Lista
    // ─────────────────────────────────────────────────────────────
    public function render_view_toggle() {
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
            return;
        }
        ?>
        <div class="argen-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Cambiar vista', 'argen-quote-loop' ); ?>">
            <button class="argen-toggle-btn argen-toggle-grid active"
                    data-view="grid"
                    title="<?php esc_attr_e( 'Vista grilla', 'argen-quote-loop' ); ?>"
                    aria-pressed="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                </svg>
                <span><?php _e( 'Grilla', 'argen-quote-loop' ); ?></span>
            </button>
            <button class="argen-toggle-btn argen-toggle-list"
                    data-view="list"
                    title="<?php esc_attr_e( 'Vista lista', 'argen-quote-loop' ); ?>"
                    aria-pressed="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
                <span><?php _e( 'Lista', 'argen-quote-loop' ); ?></span>
            </button>
        </div>
        <?php
    }


    // ─────────────────────────────────────────────────────────────
    // RENDERIZAR el formulario en cada card del loop
    // ─────────────────────────────────────────────────────────────
    public function render_quote_form_in_loop() {
        global $product;

        if ( ! $product ) return;

        $product_id = $product->get_id();
        $thumbnail  = get_the_post_thumbnail( $product_id, array( 64, 43 ), array(
            'class'   => 'argen-list-thumb',
            'loading' => 'lazy',
            'alt'     => esc_attr( $product->get_name() ),
        ) );

        if ( $thumbnail ) {
            $image_html = '<div class="argen-list-img-wrap">' . $thumbnail . '</div>';
        } else {
            $image_html = '<div class="argen-list-img-wrap argen-list-img-placeholder"></div>';
        }

        if ( ! $product->is_type( 'variable' ) ) {
            $this->render_simple_quote_form( $product, $image_html );
            return;
        }

        $variations = $product->get_available_variations();
        $attributes = $product->get_variation_attributes();

        if ( empty( $variations ) ) return;

        ?>
        <div class="argen-quote-loop-form" data-product-id="<?php echo esc_attr( $product_id ); ?>">

            <?php echo $image_html; ?>

            <div class="argen-form-inner">

                <?php
                echo '<div class="argen-list-name"><a href="' . esc_url( get_permalink( $product_id ) ) . '">' . esc_html( $product->get_name() ) . '</a></div>';
                ?>

                <div class="argen-variations-wrap">
                <?php foreach ( $attributes as $attr_name => $attr_values ) :
                    $attr_key   = sanitize_title( $attr_name );
                    $attr_label = wc_attribute_label( $attr_name );
                    ?>
                    <div class="argen-variation-row">
                        <span class="argen-variation-label"><?php echo esc_html( $attr_label ); ?></span>
                        <select class="argen-variation-select"
                                data-attribute="<?php echo esc_attr( $attr_key ); ?>"
                                aria-label="<?php echo esc_attr( $attr_label ); ?>">
                            <option value="">— Elija <?php echo esc_html( $attr_label ); ?> —</option>
                            <?php foreach ( $attr_values as $value ) :
                                $term  = get_term_by( 'slug', $value, $attr_name );
                                $label = $term ? $term->name : $value;
                                ?>
                                <option value="<?php echo esc_attr( $value ); ?>">
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                </div>

                <div class="argen-qty-quote-row">
                    <div class="argen-qty-wrapper">
                        <button class="argen-qty-btn argen-qty-minus" type="button" aria-label="Disminuir">−</button>
                        <input  class="argen-qty-input"
                                type="number"
                                name="quantity"
                                value="1"
                                min="1"
                                aria-label="Cantidad">
                        <button class="argen-qty-btn argen-qty-plus" type="button" aria-label="Aumentar">+</button>
                    </div>

                    <div class="argen-add-quote-wrap">
                        <button class="argen-add-quote-btn"
                                type="button"
                                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                data-nonce="<?php echo wp_create_nonce( 'argen_quote_loop_' . $product_id ); ?>">
                            <?php _e( 'Agregar', 'argen-quote-loop' ); ?>
                        </button>
                    </div>
                </div>
                <div class="argen-quote-feedback" aria-live="polite"></div>
            </div>

        </div>
        <?php
    }


    // ─────────────────────────────────────────────────────────────
    // FORMULARIO para productos simples (sin variaciones)
    // ─────────────────────────────────────────────────────────────
    private function render_simple_quote_form( $product, $image_html ) {
        $product_id = $product->get_id();
        ?>
        <div class="argen-quote-loop-form" data-product-id="<?php echo esc_attr( $product_id ); ?>">

            <?php echo $image_html; ?>

            <div class="argen-form-inner">

                <div class="argen-list-name"><a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></div>

                <div class="argen-qty-quote-row">
                    <div class="argen-qty-wrapper">
                        <button class="argen-qty-btn argen-qty-minus" type="button" aria-label="Disminuir">−</button>
                        <input  class="argen-qty-input"
                                type="number"
                                name="quantity"
                                value="1"
                                min="1"
                                aria-label="Cantidad">
                        <button class="argen-qty-btn argen-qty-plus" type="button" aria-label="Aumentar">+</button>
                    </div>

                    <div class="argen-add-quote-wrap">
                        <button class="argen-add-quote-btn"
                                type="button"
                                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                data-nonce="<?php echo wp_create_nonce( 'argen_quote_loop_' . $product_id ); ?>">
                            <?php _e( 'Agregar', 'argen-quote-loop' ); ?>
                        </button>
                    </div>
                </div>
                <div class="argen-quote-feedback" aria-live="polite"></div>
            </div>

        </div>
        <?php
    }


    // ─────────────────────────────────────────────────────────────
    // OCULTAR botón nativo en loop
    // ─────────────────────────────────────────────────────────────
    public function maybe_hide_native_button( $html, $product ) {
        return '';
    }


    // ─────────────────────────────────────────────────────────────
    // ENCOLAR assets (CSS + JS)
    // ─────────────────────────────────────────────────────────────
    public function enqueue_assets() {
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() && ! is_product() ) {
            return;
        }

        wp_enqueue_style(
            'argen-quote-loop',
            plugin_dir_url( __FILE__ ) . 'assets/quote-loop.css',
            array(),
            '1.3.0'
        );

        wp_enqueue_script(
            'argen-quote-loop',
            plugin_dir_url( __FILE__ ) . 'assets/quote-loop.js',
            array( 'jquery' ),
            '1.3.0',
            true
        );

        wp_localize_script( 'argen-quote-loop', 'argenQuote', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'action'  => 'argen_add_to_quote_loop',
            'i18n'    => array(
                'added'        => __( '¡Agregado al presupuesto!', 'argen-quote-loop' ),
                'error'        => __( 'Error al agregar. Intentá de nuevo.', 'argen-quote-loop' ),
                'selectOption' => __( 'Por favor seleccioná una opción.', 'argen-quote-loop' ),
                'adding'       => __( 'Agregando...', 'argen-quote-loop' ),
            ),
        ));
    }


    // ─────────────────────────────────────────────────────────────
    // AJAX — YITH Request a Quote
    // ─────────────────────────────────────────────────────────────
    public function ajax_add_to_quote() {

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        if ( ! $product_id || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'argen_quote_loop_' . $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Solicitud inválida.' ) );
        }

        $quantity = max( 1, isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1 );
        $product  = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado.' ) );
        }

        $variation_id = 0;
        $variations   = array();

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $_POST as $key => $value ) {
                if ( strpos( $key, 'attribute_' ) === 0 ) {
                    $variations[ sanitize_key( $key ) ] = sanitize_text_field( $value );
                }
            }
            $data_store   = WC_Data_Store::load( 'product' );
            $variation_id = $data_store->find_matching_product_variation( $product, $variations );
            if ( ! $variation_id ) {
                wp_send_json_error( array(
                    'message' => 'Variación no encontrada.',
                    'debug'   => $variations,
                ) );
            }
        }

        if ( $variation_id ) {
            $product_raq = array_merge(
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'quantity'     => $quantity,
                ),
                $variations
            );
        } else {
            $product_raq = array(
                'product_id' => $product_id,
                'quantity'   => $quantity,
            );
        }

        $quote = null;
        $added = false;

        if ( class_exists( 'YITH_Request_Quote' ) ) {
            if ( method_exists( 'YITH_Request_Quote', 'get_instance' ) ) {
                $quote = YITH_Request_Quote::get_instance();
            }
            if ( ! $quote || ! is_object( $quote ) ) {
                global $yith_request_quote;
                if ( isset( $yith_request_quote ) && is_object( $yith_request_quote ) ) {
                    $quote = $yith_request_quote;
                }
            }
            if ( ! $quote || ! is_object( $quote ) ) {
                $quote = new YITH_Request_Quote();
                if ( method_exists( $quote, 'init_raq_content' ) ) {
                    $quote->init_raq_content();
                } elseif ( method_exists( $quote, 'get_raq_content' ) ) {
                    $quote->get_raq_content();
                }
            }
            if ( isset( $quote->raq_content ) && ! is_array( $quote->raq_content ) ) {
                $quote->raq_content = array();
            }
            if ( $quote && method_exists( $quote, 'add_item' ) ) {
                try {
                    $result = $quote->add_item( $product_raq );
                    $added  = ( $result === 'true' || $result === 'exists' );
                } catch ( Throwable $e ) {
                    error_log( 'ArgenQuote add_item Throwable: ' . $e->getMessage() );
                    $added = false;
                }
            }
        }

        if ( $added ) {
            $quote_count = 0;
            if ( $quote && isset( $quote->raq_content ) && is_array( $quote->raq_content ) ) {
                foreach ( $quote->raq_content as $item ) {
                    $quote_count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
                }
            }
            wp_send_json_success( array(
                'message'     => '¡Agregado al presupuesto!',
                'product'     => $product->get_name(),
                'quote_count' => $quote_count,
            ) );
        } else {
            wp_send_json_error( array(
                'message'    => 'No se pudo agregar al presupuesto.',
                'debug'      => array(
                    'quote_class'  => $quote ? get_class( $quote ) : 'null',
                    'raq_content'  => $quote ? ( isset( $quote->raq_content ) ? $quote->raq_content : 'no existe' ) : 'sin objeto',
                    'product_raq'  => $product_raq,
                    'variation_id' => $variation_id,
                ),
            ) );
        }
    }
}
