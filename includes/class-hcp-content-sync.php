<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the content-side `associated_doctors` field and the doctor-side
 * `associated_content` field in sync automatically, in both directions.
 *
 * Adding/removing a doctor as a contributor on a content post
 * (podcast/article/video/...) is mirrored to that doctor's `associated_content`.
 * Editing a doctor's `associated_content` is mirrored back to the content posts'
 * `associated_doctors`, so the two fields can never drift apart.
 */
class OncologyNexus_HCP_Content_Sync {

    private static $content_post_types = array('post', 'page', 'video', 'podcast', 'conference-post', 'news');

    public function __construct() {
        add_action('save_post', array($this, 'sync_doctor_relations'), 99);
        add_action('before_delete_post', array($this, 'cleanup_doctor_relations'));
        add_action('admin_init', array($this, 'maybe_backfill_existing_content'));
    }

    /**
     * Dispatch the bidirectional sync based on the post type being saved.
     *
     * @param int $post_id
     */
    public function sync_doctor_relations($post_id) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        if ('doctor' === $post->post_type) {
            $this->sync_doctor_to_content($post_id);
        } elseif (in_array($post->post_type, self::$content_post_types, true)) {
            $this->sync_content_to_doctors($post_id);
        }
    }

    /**
     * Mirror a content post's selected doctors onto each doctor's profile.
     *
     * @param int $content_id
     */
    private function sync_content_to_doctors($content_id) {
        $content_id = (int) $content_id;
        $selected_doctor_ids = $this->read_ids($content_id, 'associated_doctors');

        // Ensure every selected doctor references this content on their profile.
        foreach ($selected_doctor_ids as $doctor_id) {
            $this->add_content_to_doctor($doctor_id, $content_id);
        }

        // Remove this content from any doctor that is no longer selected.
        foreach ($this->find_doctors_with_content($content_id) as $doctor_id) {
            if (!in_array($doctor_id, $selected_doctor_ids, true)) {
                $this->remove_content_from_doctor($doctor_id, $content_id);
            }
        }
    }

    /**
     * Mirror a doctor's associated_content back onto the content posts.
     *
     * @param int $doctor_id
     */
    private function sync_doctor_to_content($doctor_id) {
        $doctor_id = (int) $doctor_id;
        $selected_content_ids = $this->read_ids($doctor_id, 'associated_content');

        // Ensure the doctor is tagged as a contributor on every associated content.
        foreach ($selected_content_ids as $content_id) {
            $this->add_doctor_to_content($content_id, $doctor_id);
        }

        // Remove this doctor from any content that no longer appears on the profile.
        foreach ($this->find_content_with_doctor($doctor_id) as $content_id) {
            if (!in_array($content_id, $selected_content_ids, true)) {
                $this->remove_doctor_from_content($content_id, $doctor_id);
            }
        }
    }

    /**
     * Remove orphaned references when a post is permanently deleted:
     * - content deleted => remove it from every doctor's profile.
     * - doctor deleted  => remove the doctor from every content's contributors.
     *
     * @param int $post_id
     */
    public function cleanup_doctor_relations($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        if ('doctor' === $post->post_type) {
            foreach ($this->find_content_with_doctor($post_id) as $content_id) {
                $this->remove_doctor_from_content($content_id, $post_id);
            }
            return;
        }

        if (in_array($post->post_type, self::$content_post_types, true)) {
            foreach ($this->find_doctors_with_content($post_id) as $doctor_id) {
                $this->remove_content_from_doctor($doctor_id, $post_id);
            }
        }
    }

    /**
     * One-time backfill so existing data is converged in both directions:
     * content already tagged with doctors appears on the doctor profiles, and
     * profile-curated content appears on the content posts, without a re-save.
     */
    public function maybe_backfill_existing_content() {
        if (get_option('onx_hcp_content_sync_backfilled_v2')) {
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
                    $this->sync_content_to_doctors((int) $content_id);
                }

                $found = $query->post_count;
                $paged++;
            } while ($found === 100);
            wp_reset_postdata();
        }

        // Convergence pass over doctors for pre-existing profile curations.
        $paged = 1;
        do {
            $query = new WP_Query(array(
                'post_type'      => 'doctor',
                'post_status'    => array('publish', 'private', 'draft', 'pending'),
                'posts_per_page' => 100,
                'paged'          => $paged,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ));

            foreach ($query->posts as $doctor_id) {
                $this->sync_doctor_to_content((int) $doctor_id);
            }

            $found = $query->post_count;
            $paged++;
        } while ($found === 100);
        wp_reset_postdata();

        update_option('onx_hcp_content_sync_backfilled_v2', current_time('timestamp'), false);
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
     * Add a doctor to a content post's associated_doctors list.
     */
    private function add_doctor_to_content($content_id, $doctor_id) {
        $content_id = (int) $content_id;
        $doctor_id  = (int) $doctor_id;

        if (!$content_id || !$doctor_id || !get_post($content_id)) {
            return;
        }

        $doctor_ids = $this->read_ids($content_id, 'associated_doctors');
        if (!in_array($doctor_id, $doctor_ids, true)) {
            $doctor_ids[] = $doctor_id;
            $this->write_ids($content_id, 'associated_doctors', $doctor_ids);
        }
    }

    /**
     * Remove a doctor from a content post's associated_doctors list.
     */
    private function remove_doctor_from_content($content_id, $doctor_id) {
        $content_id = (int) $content_id;
        $doctor_id  = (int) $doctor_id;

        if (!$content_id || !$doctor_id) {
            return;
        }

        $doctor_ids = $this->read_ids($content_id, 'associated_doctors');
        $position   = array_search($doctor_id, $doctor_ids, true);
        if (false !== $position) {
            unset($doctor_ids[$position]);
            $this->write_ids($content_id, 'associated_doctors', array_values($doctor_ids));
        }
    }

    /**
     * Find published doctors whose associated_content references the given post.
     *
     * @param int $content_id
     * @return int[]
     */
    private function find_doctors_with_content($content_id) {
        $query = new WP_Query(array(
            'post_type'      => 'doctor',
            'post_status'    => array('publish', 'private', 'draft', 'pending'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'associated_content',
                    // ACF serializes the relationship as an array of IDs, e.g. a:1:{i:0;s:5:"12345";}
                    'value'   => sprintf('"%d"', (int) $content_id),
                    'compare' => 'LIKE',
                ),
            ),
        ));

        return !empty($query->posts) ? array_map('intval', $query->posts) : array();
    }

    /**
     * Find content posts whose associated_doctors references the given doctor.
     *
     * @param int $doctor_id
     * @return int[]
     */
    private function find_content_with_doctor($doctor_id) {
        $query = new WP_Query(array(
            'post_type'      => self::$content_post_types,
            'post_status'    => array('publish', 'private', 'draft', 'pending'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'associated_doctors',
                    'value'   => sprintf('"%d"', (int) $doctor_id),
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