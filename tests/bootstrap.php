<?php
/**
 * PHPUnit bootstrap for Peptide News Plugin tests.
 *
 * Loads WordPress stubs so unit tests can reference WP functions/classes
 * without requiring a full WordPress installation.
 *
 * @package PeptideNews\Tests
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── WordPress function stubs ──────────────────────────────────────────
// Minimal stubs so the plugin files can be required without a WP runtime.
// Integration tests would use the real WP test suite instead.

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'PEPTIDE_NEWS_VERSION' ) ) {
    define( 'PEPTIDE_NEWS_VERSION', '1.2.1-test' );
}
if ( ! defined( 'PEPTIDE_NEWS_PLUGIN_DIR' ) ) {
    define( 'PEPTIDE_NEWS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'PEPTIDE_NEWS_PLUGIN_URL' ) ) {
    define( 'PEPTIDE_NEWS_PLUGIN_URL', 'https://example.com/wp-content/plugins/peptide-news-plugin/' );
}
if ( ! defined( 'PEPTIDE_NEWS_PLUGIN_BASENAME' ) ) {
    define( 'PEPTIDE_NEWS_PLUGIN_BASENAME', 'peptide-news-plugin/peptide-news-plugin.php' );
}

// Stub commonly used WP functions that the plugin calls at include time.
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return trailingslashit( dirname( $file ) );
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) {
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, '/\\' ) . '/';
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $str ) {
        return strip_tags( (string) $str, '<p><a><b><i><strong><em><ul><ol><li><br><blockquote>' );
    }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $val ) {
        return abs( (int) $val );
    }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_string( $args ) ) {
            parse_str( $args, $args );
        }
        return array_merge( $defaults, $args );
    }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        global $_test_options;
        return isset( $_test_options[ $option ] ) ? $_test_options[ $option ] : $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        global $_test_options;
        $_test_options[ $option ] = $value;
        return true;
    }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        if ( 'mysql' === $type ) {
            return gmdate( 'Y-m-d H:i:s' );
        }
        return time();
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return ( $thing instanceof WP_Error );
    }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $_test_transients;
        return isset( $_test_transients[ $transient ] ) ? $_test_transients[ $transient ] : false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $_test_transients;
        $_test_transients[ $transient ] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        global $_test_transients;
        unset( $_test_transients[ $transient ] );
        return true;
    }
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}
if ( ! function_exists( 'add_settings_error' ) ) {
    function add_settings_error( $setting, $code, $message, $type = 'error' ) {
        global $_test_settings_errors;
        $_test_settings_errors[] = compact( 'setting', 'code', 'message', 'type' );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return esc_html( $text );
    }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        global $_test_current_user_can;
        return ! empty( $_test_current_user_can );
    }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        return false;
    }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 0;
    }
}

// Stub WP_Error for permission checks.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors  = array();
        public $error_data = array();

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( $code ) {
                $this->errors[ $code ][] = $message;
                if ( $data ) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        public function get_error_code() {
            $codes = array_keys( $this->errors );
            return $codes ? $codes[0] : '';
        }

        public function get_error_message( $code = '' ) {
            if ( ! $code ) {
                $code = $this->get_error_code();
            }
            return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
        }
    }
}

// ── Load plugin classes for testing ───────────────────────────────────
// Content filter can be loaded standalone (no WordPress runtime needed).
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-filter-rules.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-filter.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-google-url-resolver.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-pubmed-extractor.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-extractor.php';

// LLM subsystem: client and prompt-builder must load before the orchestrator.
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm-client.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm-prompt-builder.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm-ajax.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-cli.php';

// Stub AJAX-related functions used by cost tracker and other modules.
if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null ) {
        global $_test_json_response;
        $_test_json_response = array( 'success' => true, 'data' => $data );
    }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null ) {
        global $_test_json_response;
        $_test_json_response = array( 'success' => false, 'data' => $data );
    }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
        return true;
    }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.com' . $path;
    }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        return new WP_Error( 'http_request_not_available', 'HTTP requests not available in tests' );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return 200;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return '';
    }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    function wp_remote_retrieve_header( $response, $header ) {
        return '';
    }
}
if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, $echo = true ) {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ( $echo ) {
            echo $result;
        }
        return $result;
    }
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
        if ( $echo ) {
            echo $result;
        }
        return $result;
    }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
        return true;
    }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
        return true;
    }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $object_name, $l10n ) {
        return true;
    }
}
if ( ! function_exists( 'add_menu_page' ) ) {
    function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
        return $menu_slug;
    }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
    function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
        return $menu_slug;
    }
}
if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( $option_group, $option_name, $args = array() ) {
        return true;
    }
}
if ( ! function_exists( 'add_settings_section' ) ) {
    function add_settings_section( $id, $title, $callback, $page, $args = array() ) {
        return true;
    }
}
if ( ! function_exists( 'add_settings_field' ) ) {
    function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
        return true;
    }
}

// Cost subsystem: reporter must load before tracker (tracker proxies to reporter).
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-cost-reporter.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-cost-tracker.php';

// REST API stubs.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
        return true;
    }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        return $response;
    }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

// Stub WP_REST_Request for REST API tests.
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $params = array();
        private $method = 'GET';

        public function __construct( $method = 'GET', $route = '' ) {
            $this->method = $method;
        }

        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }

        public function get_param( $key ) {
            return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
        }

        public function get_params() {
            return $this->params;
        }
    }
}
