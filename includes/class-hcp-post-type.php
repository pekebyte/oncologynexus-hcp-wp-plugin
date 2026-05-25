<?php

if (!defined('ABSPATH')) {
    exit;
}

class OncologyNexus_HCP_Post_Type {
    public function __construct() {
        add_action('init', array($this, 'register_doctor_post_type'));
        add_filter('use_block_editor_for_post_type', array($this, 'disable_block_editor'), 10, 2);
    }

    public function disable_block_editor($use_block_editor, $post_type) {
        if ('doctor' === $post_type) {
            return false;
        }
        return $use_block_editor;
    }

    public function register_doctor_post_type() {
        $labels = array(
            'name'                  => _x('Doctors', 'Post Type General Name', 'oncologynexus'),
            'singular_name'         => _x('Doctor', 'Post Type Singular Name', 'oncologynexus'),
            'menu_name'             => __('HCP / Doctors', 'oncologynexus'),
            'name_admin_bar'        => __('Doctor', 'oncologynexus'),
            'archives'              => __('Doctor Archives', 'oncologynexus'),
            'attributes'            => __('Doctor Attributes', 'oncologynexus'),
            'parent_item_colon'     => __('Parent Doctor:', 'oncologynexus'),
            'all_items'             => __('All Doctors', 'oncologynexus'),
            'add_new_item'          => __('Add New Doctor', 'oncologynexus'),
            'add_new'               => __('Add New', 'oncologynexus'),
            'new_item'              => __('New Doctor', 'oncologynexus'),
            'edit_item'             => __('Edit Doctor', 'oncologynexus'),
            'update_item'           => __('Update Doctor', 'oncologynexus'),
            'view_item'             => __('View Doctor', 'oncologynexus'),
            'view_items'            => __('View Doctors', 'oncologynexus'),
            'search_items'          => __('Search Doctor', 'oncologynexus'),
            'not_found'             => __('Not found', 'oncologynexus'),
            'not_found_in_trash'    => __('Not found in Trash', 'oncologynexus'),
            'featured_image'        => __('Featured Image', 'oncologynexus'),
            'set_featured_image'    => __('Set featured image', 'oncologynexus'),
            'remove_featured_image' => __('Remove featured image', 'oncologynexus'),
            'use_featured_image'    => __('Use as featured image', 'oncologynexus'),
            'insert_into_item'      => __('Insert into doctor', 'oncologynexus'),
            'uploaded_to_this_item' => __('Uploaded to this doctor', 'oncologynexus'),
            'items_list'            => __('Doctors list', 'oncologynexus'),
            'items_list_navigation' => __('Doctors list navigation', 'oncologynexus'),
            'filter_items_list'     => __('Filter doctors list', 'oncologynexus'),
        );
        $args = array(
            'label'                 => __('Doctor', 'oncologynexus'),
            'description'           => __('Healthcare Professional profiles', 'oncologynexus'),
            'labels'                => $labels,
            'supports'              => array('title', 'thumbnail', 'revisions', 'excerpt'),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 25,
            'menu_icon'             => 'dashicons-businessperson',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'show_in_rest'          => true,
            'rest_base'             => 'doctors',
            'rewrite'               => array('slug' => 'doctors'),
        );
        register_post_type('doctor', $args);
    }
}
