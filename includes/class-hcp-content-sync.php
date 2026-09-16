<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the content-side `associated_doctors` field and the doctor-side
 * `associated_content` field in sync automatically.
 *
 * The content post (podcast/article/video/...) is the source of truth. Whenever
 * a doctor is selected as a contributor on a content post, this content is
 * automatically added to that doctor's `associated_content`. When the doctor is
 * removed from the content, the content is removed from the doctor's profile too.
 */
class OncologyNexus_HCP_Content_Sync {

    private static $content_post_types = array('post', 'page', 'video', 'podcast', 'conference-post', 'news');

    public function __construct() {
        add_action('save_post', array($this, 'sync_doctor_relations'), 99);
        add_action('before_delete_post', array($this, 'cleanup_doctor_relations'));
        add_action('admin_init', array($this, 'maybe_backfill_existing_content'));
    }

    /**
     * Sync a content post's selected doctors against each doctor's associated_content.
     *
     * @param int $post_id Content post ID.
     */
    public function sync_doctor_relations($post_id) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, self::$content_post_types, true)) {
            return;
        }

        $selected_doctor_ids = $this->read_ids($post_id, 'associated_doctors');

        // Ensure every selected doctor references this content on their profile.
        foreach ($selected_doctor_ids as $doctor_id) {
            $this->add_content_to_doctor($doctor_id, $post_id);
        }

        // Remove this content from any doctor that is no longer selected.
        foreach ($this->find_doctors_with_content($post_id) as $doctor_id) {
            if (!in_array($doctor_id, $selected_doctor_ids, true)) {
                $this->remove_content_from_doctor($doctor_id, $post_id);
            }
        }
    }

    /**
     * Remove a content post from every doctor's profile when it is permanently deleted.
     *
     * @param int $post_id Content post ID.
     */
    public function cleanup_doctor_relations($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, self::$content_post_types, true)) {
            return;
        }

        foreach ($this->find_doctors_with_content($post_id) as $doctor_id) {
            $this->remove_content_from_doctor($doctor_id, $post_id);
        }
    }

    /**
     * One-time backfill so existing content already tagged with doctors
     * immediately appears on doctor profiles without needing a re-save.
     */
    public function maybe_backfill_existing_content() {
        if (get_option('onx_hcp_content_sync_backfilled')) {
            return;
        }

        foreach (self::$content_post_types as $post_type) {
            $paged = 1;
            do {
                $query = new WP_Query(array(
                    'post_type'      => $post_type,
                    'post_status'    => array('publish', 'private', 'draft', 'pending'),
                    'posts_per_page' => 100,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ));

                foreach ($query->posts as $content_id) {
                    $this->sync_doctor_relations((int) $content_id);
                }

                $found = $query->post_count;
                $paged++;
            } while ($found === 100);
            wp_reset_postdata();
        }

        update_option('onx_hcp_content_sync_backfilled', current_time('timestamp'), false);
    }

    /**
     * Add a content post to a doctor's associated_content list.
     */
    private function add_content_to_doctor($doctor_id, $content_id) {
        $doctor_id  = (int) $doctor_id;
        $content_id = (int) $content_id;

        if (!$doctor_id || !$content_id || 'doctor' !== get_post_type($doctor_id)) {
            return;
        }

        $content_ids = $this->read_ids($doctor_id, 'associated_content');
        if (!in_array($content_id, $content_ids, true)) {
            $content_ids[] = $content_id;
            $this->write_ids($doctor_id, 'associated_content', $content_ids);
        }
    }

    /**
     * Remove a content post from a doctor's associated_content list.
     */
    private function remove_content_from_doctor($doctor_id, $content_id) {
        $doctor_id  = (int) $doctor_id;
        $content_id = (int) $content_id;

        if (!$doctor_id || !$content_id) {
            return;
        }

        $content_ids = $this->read_ids($doctor_id, 'associated_content');
        $position    = array_search($content_id, $content_ids, true);
        if (false !== $position) {
            unset($content_ids[$position]);
            $this->write_ids($doctor_id, 'associated_content', array_values($content_ids));
        }
    }

    /**
     * Find all published doctors whose associated_content contains the given post.
     *
     * @param int $content_id
     * @return int[]
     */
    private function find_doctors_with_content($content_id) {
        $query = new WP_Query(array(
            'post_type'      => 'doctor',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'associated_content',
                    // ACF serializes the relationship as an array of IDs, e.g. a:2:{i:0;s:4:"1234";...}
                    'value'   => sprintf('"%d"', (int) $content_id),
                    'compare' => 'LIKE',
                ),
            ),
        ));

        return !empty($query->posts) ? array_map('intval', $query->posts) : array();
    }

    /**
     * Read an ACF relationship field as a flat list of post IDs.
     *
     * Tries get_field() first (which returns objects due to return_format => 'object'),
     * then falls back to the raw postmeta in case of the known field-key mismatch.
     *
     * @param int    $post_id
     * @param string $field_name
     * @return int[]
     */
    private function read_ids($post_id, $field_name) {
        $value = null;
        if (function_exists('get_field')) {
            $value = get_field($field_name, $post_id);
        }

        $ids = $this->normalize_ids($value);

        if (empty($ids)) {
            $ids = $this->normalize_ids(get_post_meta($post_id, $field_name, true));
        }

        return $ids;
    }

    /**
     * Persist a flat list of post IDs to an ACF relationship field.
     *
     * @param int    $post_id
     * @param string $field_name
     * @param int[]  $ids
     */
    private function write_ids($post_id, $field_name, $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (function_exists('update_field')) {
            return update_field($field_name, $ids, $post_id);
        }

        return update_post_meta($post_id, $field_name, $ids);
    }

    /**
     * Coerce mixed ACF relationship values (objects, arrays, IDs) into int IDs.
     *
     * @param mixed $value
     * @return int[]
     */
    private function normalize_ids($value) {
        $ids = array();

        if (empty($value)) {
            return $ids;
        }

        if (!is_array($value)) {
            $value = array($value);
        }

        foreach ($value as $item) {
            if (is_object($item) && isset($item->ID)) {
                $ids[] = (int) $item->ID;
            } elseif (is_array($item) && isset($item['ID'])) {
                $ids[] = (int) $item['ID'];
            } elseif (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}