<?php

/**
 * Plugin Name: Kiloships Shipping
 * Plugin URI: https://kiloships.com
 * Description: Integrate Kiloships shipping label generation into WooCommerce.
 * Version: 1.1.0
 * Author: DuongTuanVn
 * Author URI: https://tuan.digital
 * Text Domain: kiloships-shipping
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
define('KILOSHIPS_VERSION', '1.0.0');
define('KILOSHIPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KILOSHIPS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Kiloships Class.
 */
class Kiloships_Shipping
{

    /**
     * Instance of this class.
     *
     * @var object
     */
    protected static $instance = null;

    /**
     * Return an instance of this class.
     *
     * @return object A single instance of this class.
     */
    public static function get_instance()
    {
        // If the single instance hasn't been set, set it now.
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin.
     */
    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes()
    {
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-api.php';
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin.php';
        require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-order.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks()
    {
        new Kiloships_Admin();
        new Kiloships_Order();
    }
}

// Initialize the plugin.
add_action('plugins_loaded', array('Kiloships_Shipping', 'get_instance'));

// Plugin activation hook to create database table
register_activation_hook(__FILE__, 'kiloships_activate_plugin');

function kiloships_activate_plugin()
{
    require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';
    Kiloships_Admin_Reports::create_table();
}
