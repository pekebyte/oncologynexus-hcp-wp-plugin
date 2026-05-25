<?php

if (!defined('ABSPATH')) {
    exit;
}

class OncologyNexus_HCP_REST_API {
    /** Post types that support the Associated Doctors ACF field. */
    private static $content_post_types = array('post', 'page', 'video', 'podcast', 'conference-post', 'news');

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('rest_api_init', array($this, 'register_associated_doctors_rest_field'));
    }

    public function register_rest_routes() {
        register_rest_route('oncologynexus/v1', '/doctors', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_doctors'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('oncologynexus/v1', '/doctors/(?P<slug>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_doctor'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('oncologynexus/v1', '/content-doctors', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_doctors'),
            'permission_callback' => '__return_true',
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                ),
            ),
        ));

        register_rest_route('oncologynexus/v1', '/top-creators', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_top_creators'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Expose tagged associated_doctors on wp/v2 content responses (ACF relationship does not serialize reliably).
     */
    public function register_associated_doctors_rest_field() {
        foreach (self::$content_post_types as $post_type) {
            register_rest_field(
                $post_type,
                'associated_doctors',
                array(
                    'get_callback' => function ($post_arr) {
                        return $this->get_associated_doctors_for_post((int) $post_arr['id']);
                    },
                    'schema' => array(
                        'description' => 'Doctors tagged on this content via OncologyNexus HCP.',
                        'type' => 'array',
                        'context' => array('view', 'edit'),
                    ),
                )
            );
        }
    }

    /**
     * Read associated_doctors ACF relationship and return formatted doctor profiles.
     *
     * Falls back to raw postmeta when get_field() returns empty. This handles the
     * common "field-key mismatch" scenario: data was saved with the ACF-admin-generated
     * key (e.g. field_63abc…) but the plugin registers the field with its own key
     * (field_hcp_associated_doctors). The _associated_doctors postmeta still points to
     * the old key, so get_field() silently returns empty — but the IDs are still there
     * in the associated_doctors postmeta row and can be read directly.
     *
     * @param int $post_id
     * @return array
     */
    public function get_associated_doctors_for_post($post_id) {
        if (!$post_id) {
            return array();
        }

        // Primary path: ACF get_field() (respects return_format => 'object')
        $raw = null;
        if (function_exists('get_field')) {
            $raw = get_field('associated_doctors', $post_id);
        }

        // Fallback: read raw postmeta when get_field() returns empty.
        // ACF stores relationship values as a serialised array of post IDs; WordPress
        // auto-unserialises on retrieval, so we get an array of ID strings/integers.
        if (empty($raw)) {
            $raw_meta = get_post_meta($post_id, 'associated_doctors', true);
            if (!empty($raw_meta)) {
                if (is_array($raw_meta)) {
                    $raw = array_filter(array_map(function ($id) {
                        return is_numeric($id) ? get_post((int) $id) : null;
                    }, $raw_meta));
                } elseif (is_numeric($raw_meta)) {
                    $post_obj = get_post((int) $raw_meta);
                    $raw = $post_obj ? array($post_obj) : array();
                }
            }
        }

        if (empty($raw)) {
            $raw = array();
        }

        if (!is_array($raw)) {
            $raw = array($raw);
        }

        // --- Bidirectional Lookup ---
        // Find doctors that selected this post in their 'associated_content' field.
        $reverse_query = new WP_Query(array(
            'post_type'      => 'doctor',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'associated_content',
                    // ACF serializes arrays; the ID usually appears as a string like "1234"
                    'value'   => sprintf('"%s"', $post_id),
                    'compare' => 'LIKE',
                ),
            ),
            'fields'         => 'ids',
        ));

        if (!empty($reverse_query->posts)) {
            foreach ($reverse_query->posts as $doc_id) {
                $doc_post = get_post($doc_id);
                if ($doc_post) {
                    $raw[] = $doc_post;
                }
            }
        }

        $doctors = array();
        $seen_ids = array();

        foreach ($raw as $item) {
            if (is_numeric($item)) {
                $item = get_post((int) $item);
            }
            if ($item instanceof WP_Post && $item->post_type === 'doctor' && $item->post_status === 'publish') {
                if (!in_array($item->ID, $seen_ids, true)) {
                    $doctors[] = $this->format_doctor($item);
                    $seen_ids[] = $item->ID;
                }
            }
        }

        return $doctors;
    }

    public function get_content_doctors($request) {
        $post_id = (int) $request->get_param('post_id');
        $post    = get_post($post_id);

        // Accept 'publish' and 'private' — the REST API can expose private posts to
        // authenticated callers, and staging sites often keep pages as 'private'.
        $viewable_statuses = array('publish', 'private');
        if (!$post || !in_array($post->post_status, $viewable_statuses, true)) {
            return new WP_Error('invalid_post', 'Post not found or not viewable', array('status' => 404));
        }

        return rest_ensure_response($this->get_associated_doctors_for_post($post_id));
    }

    private function format_doctor($post) {
        $doctor_title = get_field('doctor_title', $post->ID);
        $doctor_specialty = get_field('doctor_specialty', $post->ID);
        $doctor_biography = get_field('doctor_biography', $post->ID);
        $doctor_profile_image = get_field('doctor_profile_image', $post->ID);
        $page_blocks = get_field('page_blocks', $post->ID) ?: array();
        
        $featured_image_url = null;
        if (has_post_thumbnail($post->ID)) {
            $featured_image_url = get_the_post_thumbnail_url($post->ID, 'full');
        } elseif (!empty($doctor_profile_image) && is_array($doctor_profile_image)) {
            $featured_image_url = $doctor_profile_image['url'];
        }

        // Fetch associated_content robustly (handles ACF return_format object/ID and raw postmeta fallback)
        $associated_content_raw = get_field('associated_content', $post->ID);
        if (!is_array($associated_content_raw)) {
            $associated_content_raw = array();
        }
        $associated_content = array();
        foreach ($associated_content_raw as $item) {
            // If ACF returns an array/object with ID, use it; if numeric, treat as post ID
            $post_obj = null;
            if (is_object($item) && isset($item->ID)) {
                $post_obj = $item;
            } elseif (is_array($item) && isset($item['ID'])) {
                $post_obj = get_post($item['ID']);
            } elseif (is_numeric($item)) {
                $post_obj = get_post($item);
            }
            if ($post_obj instanceof WP_Post) {
                $associated_content[] = array(
                    'id' => $post_obj->ID,
                    'title' => $post_obj->post_title,
                    'type' => $post_obj->post_type,
                    'slug' => $post_obj->post_name,
                    'excerpt' => get_the_excerpt($post_obj),
                    'featured_image_url' => get_the_post_thumbnail_url($post_obj->ID, 'full'),
                    'link' => get_permalink($post_obj->ID),
                );
            }
        }
        // Fallback: If still empty, try raw postmeta (handles ACF misconfig or migration)
        if (empty($associated_content)) {
            $raw_meta = get_post_meta($post->ID, 'associated_content', true);
            if (!empty($raw_meta) && is_array($raw_meta)) {
                foreach ($raw_meta as $id) {
                    $post_obj = get_post($id);
                    if ($post_obj instanceof WP_Post) {
                        $associated_content[] = array(
                            'id' => $post_obj->ID,
                            'title' => $post_obj->post_title,
                            'type' => $post_obj->post_type,
                            'slug' => $post_obj->post_name,
                            'excerpt' => get_the_excerpt($post_obj),
                            'featured_image_url' => get_the_post_thumbnail_url($post_obj->ID, 'full'),
                            'link' => get_permalink($post_obj->ID),
                        );
                    }
                }
            }
        }

        return array(
            'id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'type' => 'doctor',
            'title' => $doctor_title,
            'specialty' => $doctor_specialty,
            'biography' => $doctor_biography,
            'profile_image' => $doctor_profile_image,
            'featured_image_url' => $featured_image_url,
            'associated_content' => $associated_content,
            'excerpt' => get_the_excerpt($post),
            'link' => get_permalink($post->ID),
            'acf' => array(
                'doctor_title' => $doctor_title,
                'doctor_specialty' => $doctor_specialty,
                'doctor_biography' => $doctor_biography,
                'doctor_profile_image' => $doctor_profile_image,
                'page_blocks' => $page_blocks,
            ),
        );
    }

    public function get_doctors($request) {
        $per_page = $request->get_param('per_page') ?: 100;
        $page = $request->get_param('page') ?: 1;
        $search = $request->get_param('search') ?: '';

        $args = array(
            'post_type' => 'doctor',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            's' => $search,
            'orderby' => 'title',
            'order' => 'ASC',
        );

        $query = new WP_Query($args);
        $doctors = array();

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $doctors[] = $this->format_doctor($post);
            }
        }

        return rest_ensure_response($doctors);
    }

    public function get_doctor($request) {
        $slug = $request->get_param('slug');

        $args = array(
            'name' => $slug,
            'post_type' => 'doctor',
            'post_status' => 'publish',
            'numberposts' => 1
        );

        $posts = get_posts($args);

        if (empty($posts)) {
            return new WP_Error('no_doctor', 'Doctor not found', array('status' => 404));
        }

        return rest_ensure_response($this->format_doctor($posts[0]));
    }

    public function get_top_creators($request) {
        $transient_key = 'onx_top_creators_cache';
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return rest_ensure_response($cached);
        }

        // 1. Get all published doctors
        $doctors_query = new WP_Query(array(
            'post_type' => 'doctor',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ));

        $doc_posts = array();
        $doctor_profiles = array();

        foreach ($doctors_query->posts as $doc) {
            $doc_posts[$doc->ID] = array();
            $profile = $this->format_doctor($doc);
            $doctor_profiles[$doc->ID] = $profile;
            
            // Add explicitly associated content
            if (!empty($profile['associated_content'])) {
                foreach ($profile['associated_content'] as $ac) {
                    $doc_posts[$doc->ID][] = $ac['id'];
                }
            }
        }

        // 2. Query all supported content types to find tagged doctors
        global $wpdb;
        $post_types = "'" . implode("','", self::$content_post_types) . "'";
        $results = $wpdb->get_results("
            SELECT post_id, meta_value 
            FROM $wpdb->postmeta 
            JOIN $wpdb->posts ON $wpdb->posts.ID = $wpdb->postmeta.post_id
            WHERE meta_key = 'associated_doctors' 
            AND post_status = 'publish' 
            AND post_type IN ($post_types)
        ");

        foreach ($results as $row) {
            $meta = maybe_unserialize($row->meta_value);
            if (!empty($meta)) {
                $doc_ids = is_array($meta) ? $meta : array($meta);
                foreach ($doc_ids as $d_id) {
                    if (isset($doc_posts[$d_id])) {
                        $doc_posts[$d_id][] = (int)$row->post_id;
                    }
                }
            }
        }

        // 3. Tally and sort
        $top_creators = array();
        foreach ($doc_posts as $d_id => $p_ids) {
            $unique_posts = array_unique($p_ids);
            $count = count($unique_posts);
            if ($count > 0) {
                $profile = $doctor_profiles[$d_id];
                $profile['post_count'] = $count;
                // remove full associated_content to keep payload small
                unset($profile['associated_content']); 
                unset($profile['acf']);
                $top_creators[] = $profile;
            }
        }

        usort($top_creators, function($a, $b) {
            return $b['post_count'] - $a['post_count'];
        });

        // Cache for 12 hours
        set_transient($transient_key, $top_creators, 12 * HOUR_IN_SECONDS);
        
        return rest_ensure_response($top_creators);
    }
}
