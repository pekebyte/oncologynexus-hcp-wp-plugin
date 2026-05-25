<?php

if (!defined('ABSPATH')) {
    exit;
}

class OncologyNexus_HCP_ACF_Fields {
    public function __construct() {
        add_action('acf/init', array($this, 'register_acf_fields'));
        add_filter('get_user_option_meta-box-order_doctor', array($this, 'force_metabox_order'));
    }

    public function register_acf_fields() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        // Group 1: Doctor Profile
        acf_add_local_field_group(array(
            'key' => 'group_hcp_doctor_profile',
            'title' => 'Doctor Profile',
            'fields' => array(
                array(
                    'key' => 'field_hcp_title',
                    'label' => 'Title',
                    'name' => 'doctor_title',
                    'type' => 'text',
                    'instructions' => 'e.g. MD, PhD, FACS',
                    'required' => 0,
                ),
                array(
                    'key' => 'field_hcp_specialty',
                    'label' => 'Specialty',
                    'name' => 'doctor_specialty',
                    'type' => 'text',
                    'instructions' => 'e.g. Oncology',
                    'required' => 0,
                ),
                array(
                    'key' => 'field_hcp_biography',
                    'label' => 'Biography',
                    'name' => 'doctor_biography',
                    'type' => 'wysiwyg',
                    'tabs' => 'all',
                    'toolbar' => 'basic',
                    'media_upload' => 0,
                    'delay' => 0,
                ),
                array(
                    'key' => 'field_hcp_profile_image',
                    'label' => 'Profile Image',
                    'name' => 'doctor_profile_image',
                    'type' => 'image',
                    'return_format' => 'array',
                    'preview_size' => 'medium',
                    'library' => 'all',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'doctor',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
            'show_in_rest' => true,
        ));

        // Group 2: Associated Doctors
        acf_add_local_field_group(array(
            'key' => 'group_hcp_associated_doctors',
            'title' => 'Associated Doctors',
            'fields' => array(
                array(
                    'key' => 'field_hcp_associated_doctors',
                    'label' => 'Associated Doctors',
                    'name' => 'associated_doctors',
                    'type' => 'relationship',
                    'instructions' => 'Select doctors associated with this content.',
                    'post_type' => array(
                        0 => 'doctor',
                    ),
                    'taxonomy' => '',
                    'filters' => array(
                        0 => 'search',
                    ),
                    'elements' => '',
                    'min' => '',
                    'max' => '',
                    'return_format' => 'object',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'post',
                    ),
                ),
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'page',
                    ),
                ),
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'video',
                    ),
                ),
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'podcast',
                    ),
                ),
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'conference-post',
                    ),
                ),
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'news',
                    ),
                ),
            ),
            'menu_order' => 10,
            'position' => 'side',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
            'show_in_rest' => true,
        ));
        // Group 3: Associated Content
        acf_add_local_field_group(array(
            'key' => 'group_hcp_associated_content',
            'title' => 'Associated Content',
            'fields' => array(
                array(
                    'key' => 'field_hcp_associated_content',
                    'label' => 'Associated Content',
                    'name' => 'associated_content',
                    'type' => 'relationship',
                    'instructions' => 'Select posts, podcasts, videos, conference posts, or any other content associated with this doctor.',
                    'post_type' => array(
                        0 => 'post',
                        1 => 'page',
                        2 => 'video',
                        3 => 'podcast',
                        4 => 'conference-post',
                        5 => 'news',
                    ),
                    'taxonomy' => '',
                    'filters' => array(
                        0 => 'search',
                        1 => 'post_type',
                    ),
                    'elements' => '',
                    'min' => '',
                    'max' => '',
                    'return_format' => 'object',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'doctor',
                    ),
                ),
            ),
            'menu_order' => 100,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
            'show_in_rest' => true,
        ));
    }

    public function force_metabox_order($order) {
        $log_file = dirname(__FILE__) . '/debug.log';
        
        // Log that the filter was called
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - filter called. Original order: " . print_r($order, true) . "\n", FILE_APPEND);

        if (!is_array($order)) {
            $order = array();
        }

        // Initialize default containers if not present
        if (!isset($order['normal'])) $order['normal'] = '';
        if (!isset($order['advanced'])) $order['advanced'] = '';
        if (!isset($order['side'])) $order['side'] = '';

        // Clean out our metaboxes from any section they might already be in
        foreach ($order as $key => $val) {
            if (is_string($val)) {
                $items = explode(',', $val);
                $items = array_diff($items, array('acf-group_hcp_doctor_profile', 'acf-group_hcp_associated_content'));
                $order[$key] = implode(',', $items);
            }
        }

        // Force Doctor Profile to the very top of normal section
        $normal_items = explode(',', $order['normal']);
        $normal_items = array_filter($normal_items);
        array_unshift($normal_items, 'acf-group_hcp_doctor_profile');

        // Force Associated Content to the very bottom of normal section
        $normal_items[] = 'acf-group_hcp_associated_content';

        $order['normal'] = implode(',', $normal_items);

        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Returning order: " . print_r($order, true) . "\n", FILE_APPEND);

        return $order;
    }
}
