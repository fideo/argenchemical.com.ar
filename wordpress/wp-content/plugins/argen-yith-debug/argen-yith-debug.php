<?php
/**
 * Plugin Name: ArgenChemical — YITH Debug (TEMPORAL)
 * Description: Diagnóstico de YITH Request a Quote. DESACTIVAR después de usar.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Agregar una página de admin simple en el menú de Herramientas
add_action( 'admin_menu', function() {
    add_management_page(
        'YITH Quote Debug',
        'YITH Quote Debug',
        'manage_options',
        'argen-yith-debug',
        'argen_yith_debug_page'
    );
});

function argen_yith_debug_page() {
    // Inicializar session si no existe
    if ( function_exists('WC') && WC()->session && ! WC()->session->has_session() ) {
        WC()->session->set_customer_session_cookie( true );
    }

    echo '<div class="wrap"><h1>YITH Request a Quote — Diagnóstico</h1>';
    echo '<style>
        .debug-box { background:#f1f1f1; border:1px solid #ccc; padding:15px; margin:15px 0; border-radius:4px; font-family:monospace; font-size:13px; white-space:pre-wrap; word-break:break-all; }
        .ok  { color: #1a7340; font-weight:bold; }
        .err { color: #c0392b; font-weight:bold; }
        h2   { margin-top:25px; border-bottom:2px solid #0073aa; padding-bottom:5px; }
    </style>';

    // ── 1. Clases y funciones disponibles ──────────────────
    echo '<h2>1. Detección de YITH</h2><div class="debug-box">';
    $checks = [
        'function_exists("YITH_YWRAQ")'            => function_exists('YITH_YWRAQ'),
        'class_exists("YITH_YWRAQ_Main")'          => class_exists('YITH_YWRAQ_Main'),
        'class_exists("YITH_YWRAQ_Quote")'         => class_exists('YITH_YWRAQ_Quote'),
        'class_exists("YITH_Request_Quote")'       => class_exists('YITH_Request_Quote'),
        'class_exists("YWRAQ_Request_Quote")'      => class_exists('YWRAQ_Request_Quote'),
        'class_exists("YITH_YWRAQ_Quote_Request")' => class_exists('YITH_YWRAQ_Quote_Request'),
    ];
    foreach ( $checks as $label => $result ) {
        $icon = $result ? '<span class="ok">✔ TRUE</span>' : '<span class="err">✘ false</span>';
        echo $label . ' → ' . $icon . "\n";
    }
    echo '</div>';

    // ── 2. Si YITH_YWRAQ() existe, inspeccionar su estructura ──
    if ( function_exists('YITH_YWRAQ') ) {
        echo '<h2>2. Estructura de YITH_YWRAQ()</h2><div class="debug-box">';
        $ywraq = YITH_YWRAQ();
        echo "Clase del objeto: " . get_class($ywraq) . "\n\n";
        echo "Propiedades públicas:\n";
        $props = get_object_vars($ywraq);
        foreach ($props as $k => $v) {
            $type = is_object($v) ? '(object: ' . get_class($v) . ')' : gettype($v);
            echo "  → \${$k}: {$type}\n";
        }
        echo "\nMétodos públicos:\n";
        $methods = get_class_methods($ywraq);
        sort($methods);
        foreach ($methods as $m) {
            echo "  → {$m}()\n";
        }
        echo '</div>';

        // Si tiene quote_request, inspeccionar ese objeto también
        if ( isset($ywraq->quote_request) && is_object($ywraq->quote_request) ) {
            echo '<h2>2b. Métodos de $ywraq->quote_request</h2><div class="debug-box">';
            $qr_methods = get_class_methods($ywraq->quote_request);
            sort($qr_methods);
            echo "Clase: " . get_class($ywraq->quote_request) . "\n\n";
            foreach ($qr_methods as $m) {
                echo "  → {$m}()\n";
            }
            echo '</div>';
        }
    }

    // ── 3. Session de WooCommerce ──────────────────────────
    echo '<h2>3. Session de WooCommerce</h2><div class="debug-box">';
    if ( function_exists('WC') && WC()->session ) {
        echo "Session activa: <span class='ok'>SÍ</span>\n";
        echo "has_session(): " . ( WC()->session->has_session() ? '<span class="ok">true</span>' : '<span class="err">false</span>' ) . "\n\n";

        // Buscar TODAS las keys de session que contengan "quote" o "ywraq"
        echo "Keys de session relacionadas con quote/ywraq:\n";
        $session_data = WC()->session->get_session_data();
        $found = false;
        if ( is_array($session_data) ) {
            foreach ( $session_data as $k => $v ) {
                if ( stripos($k, 'quote') !== false || stripos($k, 'ywraq') !== false ) {
                    $found = true;
                    echo "  → [{$k}]: " . print_r( maybe_unserialize($v), true ) . "\n";
                }
            }
        }
        // También probar directamente las keys conocidas
        $known_keys = [
            'ywraq_request_quote_list',
            'ywraq_quote_list',
            'yith_ywraq_quote_list',
            'request_quote_list',
        ];
        echo "\nKeys conocidas probadas directamente:\n";
        foreach ($known_keys as $key) {
            $val = WC()->session->get($key);
            echo "  → WC()->session->get('{$key}'): ";
            if ($val !== null) {
                echo "<span class='ok'>ENCONTRADA</span> = " . print_r($val, true);
            } else {
                echo "<span class='err'>null (no existe)</span>";
            }
            echo "\n";
        }

        if (!$found) {
            echo "\n<span class='err'>No se encontraron keys de quote en la session.</span>\n";
            echo "Todas las keys disponibles:\n";
            if (is_array($session_data)) {
                foreach (array_keys($session_data) as $k) {
                    echo "  → {$k}\n";
                }
            }
        }
    } else {
        echo "<span class='err'>WC Session NO disponible en este contexto.</span>\n";
    }
    echo '</div>';

    // ── 4. Cookies relevantes ──────────────────────────────
    echo '<h2>4. Cookies del navegador (relacionadas con YITH/WC)</h2><div class="debug-box">';
    foreach ($_COOKIE as $k => $v) {
        if ( stripos($k, 'woo') !== false || stripos($k, 'wc_') !== false ||
             stripos($k, 'quote') !== false || stripos($k, 'ywraq') !== false ||
             stripos($k, 'session') !== false ) {
            echo "  → [{$k}]: {$v}\n";
        }
    }
    echo '</div>';

    // ── 5. Constantes y versión de YITH ───────────────────
    echo '<h2>5. Constantes de YITH YWRAQ</h2><div class="debug-box">';
    $consts = ['YWRAQ_VERSION', 'YITH_YWRAQ_VERSION', 'YWRAQ_DIR', 'YWRAQ_URL'];
    foreach ($consts as $c) {
        echo "  {$c}: " . (defined($c) ? constant($c) : '<span class="err">no definida</span>') . "\n";
    }
    echo '</div>';

    echo '<p><strong>⚠️ Recordá desactivar y eliminar este plugin cuando termines el diagnóstico.</strong></p>';
    echo '</div>';
}
