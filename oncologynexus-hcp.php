<?php
/**
 * Plugin Name: OncologyNexus HCP
 * Description: Manages Healthcare Professional (Doctor) profiles and their associations to content.
 * Author: Pedro Molina
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-hcp-post-type.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hcp-acf-fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hcp-rest-api.php';

class OncologyNexus_HCP {
    public function __construct() {
        new OncologyNexus_HCP_Post_Type();
        new OncologyNexus_HCP_ACF_Fields();
        new OncologyNexus_HCP_REST_API();
    }
}

new OncologyNexus_HCP();
