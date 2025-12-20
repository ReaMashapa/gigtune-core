<?php
/**
 * Plugin Name: GigTune Core
 * Description: Core functionality for the GigTune marketplace.
 * Version: 1.0 test
 * Author: Capital-Iz
 */

/**
 * --------------------------------------------------
 * FORCE SHORTCODES TO RENDER IN CONTENT
 * --------------------------------------------------
 */
add_filter('the_content', 'do_shortcode');

/**
 * --------------------------------------------------
 * USER ROLES
 * --------------------------------------------------
 */
function gigtune_add_user_roles() {
    add_role('gigtune_artist', 'Artist', ['read' => true]);
    add_role('gigtune_client', 'Client', ['read' => true]);
}
register_activation_hook(__FILE__, 'gigtune_add_user_roles');

/**
 * --------------------------------------------------
 * REGISTRATION SHORTCODE
 * --------------------------------------------------
 */
function gigtune_register_form_shortcode() {

    if (is_user_logged_in()) {
        return '<p>You are already logged in.</p>';
    }

    ob_start(); ?>

    <form method="post">
        <?php wp_nonce_field('gigtune_register_action', 'gigtune_register_nonce'); ?>

        <p>
            <label>Email</label><br>
            <input type="email" name="gigtune_email" required>
        </p>

        <p>
            <label>Password</label><br>
            <input type="password" name="gigtune_password" required>
        </p>

        <p>
            <label>Register as</label><br>
            <select name="gigtune_role" required>
                <option value="">Select one</option>
                <option value="gigtune_artist">Artist</option>
                <option value="gigtune_client">Client</option>
            </select>
        </p>

        <p>
            <input type="submit" name="gigtune_register_submit" value="Register">
        </p>
    </form>

    <?php
    return ob_get_clean();
}
add_shortcode('gigtune_register', 'gigtune_register_form_shortcode');

/**
 * --------------------------------------------------
 * HANDLE REGISTRATION + AUTO PROFILE
 * --------------------------------------------------
 */
function gigtune_handle_registration() {

    if (!isset($_POST['gigtune_register_submit'])) return;

    if (
        !isset($_POST['gigtune_register_nonce']) ||
        !wp_verify_nonce($_POST['gigtune_register_nonce'], 'gigtune_register_action')
    ) return;

    $email = sanitize_email($_POST['gigtune_email']);
    $password = $_POST['gigtune_password'];
    $role = sanitize_text_field($_POST['gigtune_role']);

    if (!in_array($role, ['gigtune_artist','gigtune_client'], true)) return;
    if (email_exists($email)) return;

    $user_id = wp_create_user($email, $password, $email);
    if (is_wp_error($user_id)) return;

    $user = new WP_User($user_id);
    $user->set_role($role);

    if ($role === 'gigtune_artist') {
        $profile_id = wp_insert_post([
            'post_type' => 'artist_profile',
            'post_title' => $email,
            'post_status' => 'publish'
        ]);

        if (!is_wp_error($profile_id)) {
            update_user_meta($user_id, 'gigtune_artist_profile_id', $profile_id);
            update_post_meta($profile_id, 'gigtune_user_id', $user_id);

            // Phase 5: initialize basic reliability metrics
            update_post_meta($profile_id, 'gigtune_reliability_response_time_hours', 24);
            update_post_meta($profile_id, 'gigtune_reliability_acceptance_rate', 100);
            update_post_meta($profile_id, 'gigtune_reliability_cancellation_rate', 0);
            update_post_meta($profile_id, 'gigtune_reliability_no_show_count', 0);

            // Phase 5: initialize reputation system fields (separate from reliability metrics)
            update_post_meta($profile_id, 'gigtune_performance_rating_avg', 0);
            update_post_meta($profile_id, 'gigtune_performance_rating_count', 0);

            update_post_meta($profile_id, 'gigtune_reliability_rating_avg', 0);
            update_post_meta($profile_id, 'gigtune_reliability_rating_count', 0);
        }
    }

    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id);

    wp_redirect(
        $role === 'gigtune_artist'
        ? site_url('/artist-dashboard')
        : site_url('/client-dashboard')
    );
    exit;
}
add_action('init', 'gigtune_handle_registration');

/**
 * --------------------------------------------------
 * ARTIST PROFILE POST TYPE
 * --------------------------------------------------
 */
function gigtune_register_artist_profile_cpt() {
    register_post_type('artist_profile', [
        'labels' => [
            'name' => 'Artist Profiles',
            'singular_name' => 'Artist Profile'
        ],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title','editor'],
        'menu_icon' => 'dashicons-microphone'
    ]);
}
add_action('init', 'gigtune_register_artist_profile_cpt');

/**
 * --------------------------------------------------
 * TAXONOMIES
 * --------------------------------------------------
 */
function gigtune_register_artist_taxonomies() {

    register_taxonomy('performer_type', 'artist_profile', ['label'=>'Performer Type','show_ui'=>true]);
    register_taxonomy('instrument_category', 'artist_profile', ['label'=>'Instrument Category','show_ui'=>true]);
    register_taxonomy('keyboard_parts', 'artist_profile', ['label'=>'Keyboard Parts','show_ui'=>true]);
    register_taxonomy('vocal_type', 'artist_profile', ['label'=>'Vocal Type','show_ui'=>true]);
    register_taxonomy('vocal_role', 'artist_profile', ['label'=>'Vocal Role','show_ui'=>true]);

}
add_action('init', 'gigtune_register_artist_taxonomies');

/**
 * --------------------------------------------------
 * ARTIST DASHBOARD SHORTCODE
 * --------------------------------------------------
 */
function gigtune_artist_dashboard_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('gigtune_artist', $user->roles, true)) {
        return '<p>Access denied.</p>';
    }

    $profile_id = get_user_meta($user->ID, 'gigtune_artist_profile_id', true);
    if (!$profile_id) {
        return '<p>No artist profile found.</p>';
    }

    $profile = get_post($profile_id);

    ob_start(); ?>

    <h2>Artist Dashboard</h2>

    <p><strong>Profile Name:</strong> <?php echo esc_html($profile->post_title); ?></p>

    <h3>Bio</h3>
    <div><?php echo wpautop($profile->post_content); ?></div>

    <h3>Capabilities</h3>
    <ul>
        <?php
        $taxonomies = [
            'performer_type',
            'instrument_category',
            'keyboard_parts',
            'vocal_type',
            'vocal_role'
        ];

        foreach ($taxonomies as $tax) {
            $terms = wp_get_post_terms($profile_id, $tax, ['fields'=>'names']);
            if (!empty($terms)) {
                echo '<li><strong>' . esc_html(ucwords(str_replace('_',' ', $tax))) . ':</strong> ';
                echo esc_html(implode(', ', $terms));
                echo '</li>';
            }
        }
        ?>
    </ul>

    <p>
        <a href="<?php echo esc_url(site_url('/artist-profile-edit')); ?>">Edit Profile</a> |
        <a href="<?php echo esc_url(site_url('/artist-profile/?artist_id=' . $profile_id)); ?>">View Public Profile</a>
    </p>

    <?php
    // Phase 5: reputation fields (separate ratings)
    $perf_avg = (float) get_post_meta($profile_id, 'gigtune_performance_rating_avg', true);
    $perf_count = (int) get_post_meta($profile_id, 'gigtune_performance_rating_count', true);

    $rel_avg = (float) get_post_meta($profile_id, 'gigtune_reliability_rating_avg', true);
    $rel_count = (int) get_post_meta($profile_id, 'gigtune_reliability_rating_count', true);
    ?>

    <h3>Ratings</h3>
    <ul>
        <li><strong>Performance (Talent):</strong> <?php echo esc_html(number_format($perf_avg, 2)); ?> / 5 (<?php echo esc_html($perf_count); ?> reviews)</li>
        <li><strong>Reliability (Professionalism):</strong> <?php echo esc_html(number_format($rel_avg, 2)); ?> / 5 (<?php echo esc_html($rel_count); ?> reviews)</li>
    </ul>

    <h3>Reliability</h3>
    <ul>
        <?php
        $resp = get_post_meta($profile_id, 'gigtune_reliability_response_time_hours', true);
        $acc = get_post_meta($profile_id, 'gigtune_reliability_acceptance_rate', true);
        $cancel = get_post_meta($profile_id, 'gigtune_reliability_cancellation_rate', true);
        $noshow = get_post_meta($profile_id, 'gigtune_reliability_no_show_count', true);
        ?>
        <li><strong>Avg response (hrs):</strong> <?php echo esc_html($resp); ?></li>
        <li><strong>Acceptance rate:</strong> <?php echo esc_html($acc); ?>%</li>
        <li><strong>Cancellation rate:</strong> <?php echo esc_html($cancel); ?>%</li>
        <li><strong>No-shows:</strong> <?php echo esc_html($noshow); ?></li>
    </ul>

    <?php
    // Phase 6A: Artist booking requests (skeleton)
    echo gigtune_phase6_artist_booking_requests_block($profile_id);
    
    // Phase 6.1: Accepted & upcoming bookings
    echo gigtune_phase61_artist_accepted_bookings_block($profile_id);


    return ob_get_clean();
}
add_shortcode('gigtune_artist_dashboard', 'gigtune_artist_dashboard_shortcode');

/**
 * --------------------------------------------------
 * HANDLE ARTIST PROFILE UPDATE (FRONTEND)
 * --------------------------------------------------
 */
function gigtune_handle_artist_profile_update() {

    if (!isset($_POST['gigtune_profile_submit'])) return;

    if (
        !isset($_POST['gigtune_profile_nonce']) ||
        !wp_verify_nonce($_POST['gigtune_profile_nonce'], 'gigtune_profile_action')
    ) return;

    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('gigtune_artist', $user->roles, true)) return;

    $profile_id = get_user_meta($user->ID, 'gigtune_artist_profile_id', true);
    if (!$profile_id) return;

    wp_update_post([
        'ID' => $profile_id,
        'post_title' => sanitize_text_field($_POST['profile_name']),
        'post_content' => wp_kses_post($_POST['profile_bio'])
    ]);

    $taxonomies = [
        'performer_type',
        'instrument_category',
        'keyboard_parts',
        'vocal_type',
        'vocal_role'
    ];

    foreach ($taxonomies as $tax) {
        if (isset($_POST[$tax]) && is_array($_POST[$tax])) {
            wp_set_post_terms($profile_id, array_map('sanitize_text_field', $_POST[$tax]), $tax);
        } else {
            wp_set_post_terms($profile_id, [], $tax);
        }
    }

    wp_redirect(site_url('/artist-dashboard'));
    exit;
}
add_action('init', 'gigtune_handle_artist_profile_update');

/**
 * --------------------------------------------------
 * ARTIST PROFILE EDIT SHORTCODE
 * --------------------------------------------------
 */
function gigtune_artist_profile_edit_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('gigtune_artist', $user->roles, true)) {
        return '<p>Access denied.</p>';
    }

    $profile_id = get_user_meta($user->ID, 'gigtune_artist_profile_id', true);
    if (!$profile_id) {
        return '<p>No artist profile found.</p>';
    }

    $profile = get_post($profile_id);

    ob_start(); ?>

    <h2>Edit Artist Profile</h2>

    <form method="post">
        <?php wp_nonce_field('gigtune_profile_action', 'gigtune_profile_nonce'); ?>

        <p>
            <label>Profile Name</label><br>
            <input type="text" name="profile_name" value="<?php echo esc_attr($profile->post_title); ?>" required>
        </p>

        <p>
            <label>Bio</label><br>
            <textarea name="profile_bio" rows="6"><?php echo esc_textarea($profile->post_content); ?></textarea>
        </p>

        <?php
        $taxonomies = [
            'performer_type' => 'Performer Type',
            'instrument_category' => 'Instrument Category',
            'keyboard_parts' => 'Keyboard Parts',
            'vocal_type' => 'Vocal Type',
            'vocal_role' => 'Vocal Role'
        ];

        foreach ($taxonomies as $tax => $label) {
            $terms = get_terms(['taxonomy'=>$tax,'hide_empty'=>false]);
            $selected = wp_get_post_terms($profile_id, $tax, ['fields'=>'slugs']);
            ?>

            <fieldset>
                <legend><?php echo esc_html($label); ?></legend>

                <?php foreach ($terms as $term): ?>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr($tax); ?>[]"
                               value="<?php echo esc_attr($term->slug); ?>"
                               <?php checked(in_array($term->slug, $selected, true)); ?>>
                        <?php echo esc_html($term->name); ?>
                    </label><br>
                <?php endforeach; ?>
            </fieldset>

        <?php } ?>

        <p>
            <input type="submit" name="gigtune_profile_submit" value="Save Profile">
        </p>
    </form>

    <?php
    return ob_get_clean();
}
add_shortcode('gigtune_artist_profile_edit', 'gigtune_artist_profile_edit_shortcode');

/**
 * --------------------------------------------------
 * PUBLIC ARTIST PROFILE (PHASE 4.2A)
 * --------------------------------------------------
 */
function gigtune_public_artist_profile_shortcode() {

    if (!isset($_GET['artist_id'])) {
        return '<p>Artist not found.</p>';
    }

    $profile_id = absint($_GET['artist_id']);
    $profile = get_post($profile_id);

    if (!$profile || $profile->post_type !== 'artist_profile') {
        return '<p>Artist not found.</p>';
    }

    ob_start(); ?>

    <h2><?php echo esc_html($profile->post_title); ?></h2>

    <div><?php echo wpautop($profile->post_content); ?></div>

    <h3>Capabilities</h3>
    <ul>
        <?php
        $taxonomies = [
            'performer_type',
            'instrument_category',
            'keyboard_parts',
            'vocal_type',
            'vocal_role'
        ];

        foreach ($taxonomies as $tax) {
            $terms = wp_get_post_terms($profile_id, $tax, ['fields'=>'names']);
            if (!empty($terms)) {
                echo '<li><strong>' . esc_html(ucwords(str_replace('_',' ', $tax))) . ':</strong> ';
                echo esc_html(implode(', ', $terms));
                echo '</li>';
            }
        }
        ?>
    </ul>

    <?php
    $content = ob_get_clean();
    $content = gigtune_public_profile_ratings_append($content, $profile_id);
    return gigtune_public_profile_reliability_append($content, $profile_id);
}
add_shortcode('gigtune_public_artist_profile', 'gigtune_public_artist_profile_shortcode');

/**
 * --------------------------------------------------
 * PHASE 5: PUBLIC PROFILE - APPEND RATINGS
 * --------------------------------------------------
 */
function gigtune_public_profile_ratings_append($output, $profile_id) {
    if (!$profile_id) return $output;

    $perf_avg = (float) get_post_meta($profile_id, 'gigtune_performance_rating_avg', true);
    $perf_count = (int) get_post_meta($profile_id, 'gigtune_performance_rating_count', true);

    $rel_avg = (float) get_post_meta($profile_id, 'gigtune_reliability_rating_avg', true);
    $rel_count = (int) get_post_meta($profile_id, 'gigtune_reliability_rating_count', true);

    if ($perf_avg === 0.0 && $perf_count === 0 && $rel_avg === 0.0 && $rel_count === 0) {
        return $output;
    }

    $append  = '<h3>Ratings</h3><ul>';
    $append .= '<li><strong>Performance (Talent):</strong> ' . esc_html(number_format($perf_avg, 2)) . ' / 5 (' . esc_html($perf_count) . ' reviews)</li>';
    $append .= '<li><strong>Reliability (Professionalism):</strong> ' . esc_html(number_format($rel_avg, 2)) . ' / 5 (' . esc_html($rel_count) . ' reviews)</li>';
    $append .= '</ul>';

    return $output . $append;
}

/**
 * --------------------------------------------------
 * PHASE 6.1 — APPLY RELIABILITY EVENTS
 * Updates artist reliability metrics based on booking outcomes
 * --------------------------------------------------
 */
function gigtune_phase5_apply_reliability_event($artist_profile_id, $event) {

    $artist_profile_id = absint($artist_profile_id);
    if ($artist_profile_id <= 0) return;

    $response_hours = (float) get_post_meta($artist_profile_id, 'gigtune_reliability_response_time_hours', true);
    $acceptance = (float) get_post_meta($artist_profile_id, 'gigtune_reliability_acceptance_rate', true);
    $cancellation = (float) get_post_meta($artist_profile_id, 'gigtune_reliability_cancellation_rate', true);
    $no_shows = (int) get_post_meta($artist_profile_id, 'gigtune_reliability_no_show_count', true);

    if ($response_hours <= 0) $response_hours = 24;
    if ($acceptance <= 0) $acceptance = 100;
    if ($cancellation < 0) $cancellation = 0;
    if ($no_shows < 0) $no_shows = 0;

    switch ($event) {

        case 'accepted':
            $acceptance = min(100, $acceptance + 2);
            break;

        case 'declined':
            $acceptance = max(0, $acceptance - 3);
            break;

        case 'cancelled':
            $cancellation = min(100, $cancellation + 5);
            break;

        case 'no_show':
            $no_shows++;
            break;
    }

    update_post_meta($artist_profile_id, 'gigtune_reliability_acceptance_rate', round($acceptance));
    update_post_meta($artist_profile_id, 'gigtune_reliability_cancellation_rate', round($cancellation));
    update_post_meta($artist_profile_id, 'gigtune_reliability_no_show_count', $no_shows);
}

/**
 * --------------------------------------------------
 * PUBLIC PROFILE - APPEND RELIABILITY METRICS (EXISTING)
 * --------------------------------------------------
 */
function gigtune_public_profile_reliability_append($output, $profile_id) {
    if (!$profile_id) return $output;
    $resp = get_post_meta($profile_id, 'gigtune_reliability_response_time_hours', true);
    if ($resp === '') return $output;

    $acc = get_post_meta($profile_id, 'gigtune_reliability_acceptance_rate', true);
    $cancel = get_post_meta($profile_id, 'gigtune_reliability_cancellation_rate', true);
    $noshow = get_post_meta($profile_id, 'gigtune_reliability_no_show_count', true);

    $append = '<h3>Reliability</h3><ul>';
    $append .= '<li><strong>Avg response (hrs):</strong> ' . esc_html($resp) . '</li>';
    $append .= '<li><strong>Acceptance rate:</strong> ' . esc_html($acc) . '%</li>';
    $append .= '<li><strong>Cancellation rate:</strong> ' . esc_html($cancel) . '%</li>';
    $append .= '<li><strong>No-shows:</strong> ' . esc_html($noshow) . '</li>';
    $append .= '</ul>';

    return $output . $append;
}

/**
 * --------------------------------------------------
 * INTERNAL HELPER (FILTER SANITATION)
 * --------------------------------------------------
 */
function gigtune_get_filter_values($key) {
    if (!isset($_GET[$key]) || !is_array($_GET[$key])) {
        return [];
    }
    $out = [];
    foreach ($_GET[$key] as $v) {
        $v = sanitize_text_field($v);
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return array_values(array_unique($out));
}

/**
 * --------------------------------------------------
 * PHASE 4.5: FIT v1 (RELEVANCE SCORING FOR SORTING)
 * --------------------------------------------------
 */

function gigtune_tokenize_query($q) {
    $q = strtolower(wp_strip_all_tags((string)$q));
    $q = preg_replace('/[^a-z0-9\s\-]+/i', ' ', $q);
    $q = preg_replace('/\s+/', ' ', trim($q));
    if ($q === '') return [];

    $parts = explode(' ', $q);
    $tokens = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strlen($p) < 2) continue;
        $tokens[] = $p;
    }
    return array_values(array_unique($tokens));
}

function gigtune_count_matches_in_text($tokens, $text) {
    $text = strtolower((string)$text);
    $count = 0;
    foreach ($tokens as $t) {
        if ($t !== '' && strpos($text, $t) !== false) {
            $count++;
        }
    }
    return $count;
}

function gigtune_get_term_name_map($taxonomies) {
    $map = [];
    foreach ($taxonomies as $tax) {
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        $map[$tax] = [];
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $map[$tax][$term->slug] = strtolower($term->name);
            }
        }
    }
    return $map;
}

function gigtune_fit_score_artist($profile_id, $search_tokens, $selected_filters, $filter_taxonomies, $term_name_map) {

    $profile = get_post($profile_id);
    if (!$profile || $profile->post_type !== 'artist_profile') {
        return 0;
    }

    $score = 0;

    $title = (string)$profile->post_title;
    $bio_raw = (string)$profile->post_content;
    $bio_plain = trim(wp_strip_all_tags($bio_raw));

    if ($title !== '') $score += 5;

    $bio_len = strlen($bio_plain);
    if ($bio_len >= 30) $score += 5;
    if ($bio_len >= 120) $score += 5;

    $has_caps = 0;
    foreach ($filter_taxonomies as $tax) {
        $assigned = wp_get_post_terms($profile_id, $tax, ['fields' => 'ids']);
        if (!empty($assigned) && !is_wp_error($assigned)) {
            $has_caps++;
        }
    }
    $score += min(10, $has_caps * 2);

    foreach ($filter_taxonomies as $tax) {
        if (!isset($selected_filters[$tax]) || empty($selected_filters[$tax])) {
            continue;
        }

        $selected_slugs = $selected_filters[$tax];

        $artist_slugs = wp_get_post_terms($profile_id, $tax, ['fields' => 'slugs']);
        if (empty($artist_slugs) || is_wp_error($artist_slugs)) {
            continue;
        }

        $overlap = array_intersect($selected_slugs, $artist_slugs);
        $overlap_count = is_array($overlap) ? count($overlap) : 0;

        if ($overlap_count > 0) {
            $score += 25;
            $score += min(25, $overlap_count * 10);
        }
    }

    if (!empty($search_tokens)) {

        $title_lower = strtolower($title);
        $bio_lower = strtolower($bio_plain);

        $title_hits = gigtune_count_matches_in_text($search_tokens, $title_lower);
        $bio_hits = gigtune_count_matches_in_text($search_tokens, $bio_lower);

        $score += min(60, $title_hits * 20);
        $score += min(40, $bio_hits * 10);

        foreach ($filter_taxonomies as $tax) {
            $artist_slugs = wp_get_post_terms($profile_id, $tax, ['fields' => 'slugs']);
            if (empty($artist_slugs) || is_wp_error($artist_slugs)) continue;

            foreach ($artist_slugs as $slug) {
                if (!isset($term_name_map[$tax][$slug])) continue;
                $tname = $term_name_map[$tax][$slug];

                foreach ($search_tokens as $tok) {
                    if ($tok !== '' && strpos($tname, $tok) !== false) {
                        $score += 8;
                        break;
                    }
                }
            }
        }
    }

    $response_hours = (float) get_post_meta($profile_id, 'gigtune_reliability_response_time_hours', true);
    $acceptance = (float) get_post_meta($profile_id, 'gigtune_reliability_acceptance_rate', true);
    $cancellation = (float) get_post_meta($profile_id, 'gigtune_reliability_cancellation_rate', true);
    $no_shows = (int) get_post_meta($profile_id, 'gigtune_reliability_no_show_count', true);

    $reliability_score = 0;
    if ($response_hours > 0) {
        $r = min(72, $response_hours);
        $reliability_score += (1 - ($r / 72)) * 15;
    }
    $reliability_score += min(100, max(0, $acceptance)) / 100 * 15;
    $reliability_score += (1 - min(100, max(0, $cancellation)) / 100) * 10;
    $reliability_score += max(-10, -min(10, $no_shows)) * 1;

    $score += (int) round($reliability_score);

    if ($score < 0) $score = 0;
    if ($score > 999) $score = 999;

    return (int)$score;
}

/**
 * --------------------------------------------------
 * ARTIST DIRECTORY (PHASE 4.3 + 4.4 + 4.5 FIT SORTING)
 * Shortcode: [gigtune_artist_directory]
 * --------------------------------------------------
 */
function gigtune_artist_directory_shortcode() {

    $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

    $filter_taxonomies = [
        'performer_type',
        'instrument_category',
        'keyboard_parts',
        'vocal_type',
        'vocal_role'
    ];

    $selected_filters = [];
    foreach ($filter_taxonomies as $tax) {
        $selected_filters[$tax] = gigtune_get_filter_values($tax);
    }

    $tax_query = ['relation' => 'AND'];
    foreach ($filter_taxonomies as $tax) {
        $values = $selected_filters[$tax];
        if (!empty($values)) {
            $tax_query[] = [
                'taxonomy' => $tax,
                'field'    => 'slug',
                'terms'    => $values,
                'operator' => 'IN'
            ];
        }
    }

    $candidate_args = [
        'post_type'      => 'artist_profile',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids'
    ];

    if ($search !== '') {
        $candidate_args['s'] = $search;
    }

    if (count($tax_query) > 1) {
        $candidate_args['tax_query'] = $tax_query;
    }

    $candidate_ids = get_posts($candidate_args);

    ob_start(); ?>

    <h2>Artists</h2>

    <form method="get" style="margin-bottom: 20px;">
        <p>
            <label>Search (Profile name or Bio)</label><br>
            <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="e.g. 'wedding singer' or 'deep house'">
        </p>

        <?php foreach ($filter_taxonomies as $tax): ?>
            <fieldset style="margin-bottom: 12px;">
                <legend><?php echo esc_html(ucwords(str_replace('_',' ', $tax))); ?></legend>
                <?php
                $terms = get_terms(['taxonomy'=>$tax,'hide_empty'=>false]);
                if (!empty($terms) && !is_wp_error($terms)) :
                    $selected_values = $selected_filters[$tax];
                    foreach ($terms as $term):
                ?>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr($tax); ?>[]"
                                   value="<?php echo esc_attr($term->slug); ?>"
                                   <?php checked(in_array($term->slug, $selected_values, true)); ?>>
                            <?php echo esc_html($term->name); ?>
                        </label><br>
                <?php
                    endforeach;
                else:
                    echo '<em>No terms configured.</em>';
                endif;
                ?>
            </fieldset>
        <?php endforeach; ?>

        <p>
            <input type="submit" value="Apply Filters">
            <a href="<?php echo esc_url(site_url('/artist')); ?>" style="margin-left: 10px;">Reset</a>
        </p>
    </form>

    <?php
    if (empty($candidate_ids)) {
        echo '<p>No artists found.</p>';
        return ob_get_clean();
    }

    $tokens = gigtune_tokenize_query($search);
    $term_name_map = gigtune_get_term_name_map($filter_taxonomies);

    $scored = [];
    foreach ($candidate_ids as $pid) {
        $pid = absint($pid);
        if ($pid <= 0) continue;

        $score = gigtune_fit_score_artist($pid, $tokens, $selected_filters, $filter_taxonomies, $term_name_map);
        $date_ts = (int)get_post_time('U', true, $pid);

        $scored[] = [
            'id' => $pid,
            'score' => $score,
            'date' => $date_ts
        ];
    }

    usort($scored, function($a, $b) {
        if ($a['score'] !== $b['score']) {
            return ($a['score'] > $b['score']) ? -1 : 1;
        }
        if ($a['date'] !== $b['date']) {
            return ($a['date'] > $b['date']) ? -1 : 1;
        }
        return ($a['id'] > $b['id']) ? -1 : 1;
    });

    $per_page = 10;
    $total_items = count($scored);
    $total_pages = (int)ceil($total_items / $per_page);
    if ($total_pages < 1) $total_pages = 1;
    if ($paged > $total_pages) $paged = $total_pages;

    $offset = ($paged - 1) * $per_page;
    $page_items = array_slice($scored, $offset, $per_page);

    ?>

    <ul>
        <?php foreach ($page_items as $row): ?>
            <?php
            $post_id = (int)$row['id'];
            $post_obj = get_post($post_id);
            if (!$post_obj) continue;
            ?>
            <li style="margin-bottom: 14px;">
                <strong><?php echo esc_html($post_obj->post_title); ?></strong><br>
                <a href="<?php echo esc_url(site_url('/artist-profile/?artist_id=' . $post_id)); ?>">
                    View Profile
                </a>
                <?php if (is_user_logged_in()): ?>
                    <span style="margin-left: 10px;">|</span>
                    <a style="margin-left: 10px;" href="<?php echo esc_url(site_url('/book-artist/?artist_id=' . $post_id)); ?>">
                        Book This Artist
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php
    if ($total_pages > 1) {

        $base_args = $_GET;
        unset($base_args['paged']);

        echo '<div style="margin-top: 18px;">';

        if ($paged > 1) {
            $prev_args = $base_args;
            $prev_args['paged'] = $paged - 1;
            echo '<a href="' . esc_url(add_query_arg($prev_args, site_url('/artist'))) . '">← Previous</a> ';
        }

        echo '<span style="margin: 0 10px;">Page ' . esc_html($paged) . ' of ' . esc_html($total_pages) . '</span>';

        if ($paged < $total_pages) {
            $next_args = $base_args;
            $next_args['paged'] = $paged + 1;
            echo '<a href="' . esc_url(add_query_arg($next_args, site_url('/artist'))) . '">Next →</a>';
        }

        echo '</div>';
    }

    return ob_get_clean();
}
add_shortcode('gigtune_artist_directory', 'gigtune_artist_directory_shortcode');


/**
 * ==================================================
 * PHASE 6A — BOOKINGS CPT + CLIENT DASHBOARD + BOOK ARTIST (SKELETON)
 * ==================================================
 */

/**
 * --------------------------------------------------
 * BOOKINGS POST TYPE
 * --------------------------------------------------
 */
function gigtune_register_booking_cpt() {

    register_post_type('gigtune_booking', [
        'labels' => [
            'name' => 'Bookings',
            'singular_name' => 'Booking'
        ],
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-calendar-alt',
        'supports' => ['title'],
    ]);
}
add_action('init', 'gigtune_register_booking_cpt');


/**
 * --------------------------------------------------
 * BOOKINGS ADMIN META BOX (THIS CREATES THE FIELDS YOU EXPECT)
 * --------------------------------------------------
 */
function gigtune_booking_add_meta_boxes() {
    add_meta_box(
        'gigtune_booking_details',
        'Booking Details',
        'gigtune_booking_details_metabox_render',
        'gigtune_booking',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'gigtune_booking_add_meta_boxes');

function gigtune_booking_details_metabox_render($post) {

    wp_nonce_field('gigtune_booking_details_save', 'gigtune_booking_details_nonce');

    $client_id = get_post_meta($post->ID, 'gigtune_booking_client_user_id', true);
    $artist_id = get_post_meta($post->ID, 'gigtune_booking_artist_profile_id', true);
    $status = get_post_meta($post->ID, 'gigtune_booking_status', true);

    $requested_at = get_post_meta($post->ID, 'gigtune_booking_requested_at', true);
    $responded_at = get_post_meta($post->ID, 'gigtune_booking_responded_at', true);

    if ($status === '') $status = 'requested';

    $statuses = [
        'requested' => 'Requested',
        'accepted'  => 'Accepted',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled',
        'awaiting_client_confirmation' => 'Awaiting Client Confirmation',
        'completed' => 'Completed',
        'no_show'   => 'No-show'
    ];

    echo '<p><label><strong>Artist Profile ID</strong></label><br>';
    echo '<input type="number" name="gigtune_booking_artist_profile_id" value="' . esc_attr($artist_id) . '" style="width: 220px;" />';
    echo '</p>';

    echo '<p><label><strong>Client User ID</strong></label><br>';
    echo '<input type="number" name="gigtune_booking_client_user_id" value="' . esc_attr($client_id) . '" style="width: 220px;" />';
    echo '</p>';

    echo '<p><label><strong>Status</strong></label><br>';
    echo '<select name="gigtune_booking_status">';
    foreach ($statuses as $k => $label) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($status, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</p>';

    echo '<p><label><strong>Requested At</strong></label><br>';
    echo '<input type="text" name="gigtune_booking_requested_at" value="' . esc_attr($requested_at) . '" placeholder="YYYY-mm-dd HH:MM:SS" style="width: 260px;" />';
    echo '</p>';

    echo '<p><label><strong>Responded At</strong></label><br>';
    echo '<input type="text" name="gigtune_booking_responded_at" value="' . esc_attr($responded_at) . '" placeholder="YYYY-mm-dd HH:MM:SS" style="width: 260px;" />';
    echo '</p>';

    echo '<p style="color:#666;margin-top:10px;">Note: These fields are stored as post meta on this booking.</p>';
}

function gigtune_booking_details_metabox_save($post_id) {

    if (!isset($_POST['gigtune_booking_details_nonce']) || !wp_verify_nonce($_POST['gigtune_booking_details_nonce'], 'gigtune_booking_details_save')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (!current_user_can('edit_post', $post_id)) return;

    if (get_post_type($post_id) !== 'gigtune_booking') return;

    $client_id = isset($_POST['gigtune_booking_client_user_id']) ? absint($_POST['gigtune_booking_client_user_id']) : 0;
    $artist_id = isset($_POST['gigtune_booking_artist_profile_id']) ? absint($_POST['gigtune_booking_artist_profile_id']) : 0;
    $status = isset($_POST['gigtune_booking_status']) ? sanitize_text_field($_POST['gigtune_booking_status']) : 'requested';

    $allowed = ['requested','accepted','declined','cancelled','completed','no_show'];
    if (!in_array($status, $allowed, true)) $status = 'requested';

    $requested_at = isset($_POST['gigtune_booking_requested_at']) ? sanitize_text_field($_POST['gigtune_booking_requested_at']) : '';
    $responded_at = isset($_POST['gigtune_booking_responded_at']) ? sanitize_text_field($_POST['gigtune_booking_responded_at']) : '';

    update_post_meta($post_id, 'gigtune_booking_client_user_id', $client_id);
    update_post_meta($post_id, 'gigtune_booking_artist_profile_id', $artist_id);
    update_post_meta($post_id, 'gigtune_booking_status', $status);

    if ($requested_at !== '') update_post_meta($post_id, 'gigtune_booking_requested_at', $requested_at);
    if ($responded_at !== '') update_post_meta($post_id, 'gigtune_booking_responded_at', $responded_at);
}
add_action('save_post', 'gigtune_booking_details_metabox_save');


/**
 * --------------------------------------------------
 * CLIENT DASHBOARD SHORTCODE (THIS WAS MISSING — THAT’S WHY IT RENDERED AS TEXT)
 * Shortcode: [gigtune_client_dashboard]
 * --------------------------------------------------
 */
function gigtune_client_dashboard_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('gigtune_client', $user->roles, true) && !current_user_can('manage_options')) {
        return '<p>Access denied.</p>';
    }

    $client_user_id = (int)$user->ID;

    $bookings = get_posts([
        'post_type' => 'gigtune_booking',
        'post_status' => 'any',
        'numberposts' => 50,
        'meta_key' => 'gigtune_booking_client_user_id',
        'meta_value' => $client_user_id,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    ob_start(); ?>

    <h2>Client Dashboard</h2>

    <p><a href="<?php echo esc_url(site_url('/browse-artists')); ?>">Browse Artists</a></p>

    <h3>Your Bookings</h3>

    <?php if (empty($bookings)): ?>
        <p>No bookings yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;">Booking</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;">Artist</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;">Status</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px;">Requested</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $b): ?>
                <?php
                $artist_id = (int) get_post_meta($b->ID, 'gigtune_booking_artist_profile_id', true);
                $status = (string) get_post_meta($b->ID, 'gigtune_booking_status', true);
                $requested_at = (string) get_post_meta($b->ID, 'gigtune_booking_requested_at', true);

                $artist_title = '';
                if ($artist_id > 0) {
                    $artist_post = get_post($artist_id);
                    if ($artist_post && $artist_post->post_type === 'artist_profile') {
                        $artist_title = $artist_post->post_title;
                    }
                }
                ?>
                <tr>
                    <td style="border-bottom:1px solid #eee;padding:8px;"><?php echo esc_html($b->post_title); ?></td>
                    <td style="border-bottom:1px solid #eee;padding:8px;">
                        <?php if ($artist_id > 0): ?>
                            <a href="<?php echo esc_url(site_url('/artist-profile/?artist_id=' . $artist_id)); ?>">
                                <?php echo esc_html($artist_title !== '' ? $artist_title : ('Artist #' . $artist_id)); ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="border-bottom:1px solid #eee;padding:8px;"><?php echo esc_html($status !== '' ? $status : 'requested'); ?></td>
                    <td style="border-bottom:1px solid #eee;padding:8px;">
                    <?php

                    if ($status === 'awaiting_client_confirmation') {

                        echo '<form method="post">';
                        wp_nonce_field('gigtune_client_confirm_completion', 'gigtune_client_confirm_nonce');
                        echo '<input type="hidden" name="gigtune_confirm_booking_id" value="' . esc_attr($b->ID) . '">';
                        echo '<button type="submit" name="gigtune_client_confirm_completion">
                                Confirm Completion
                            </button>';
                        echo '</form>';

                    } elseif ($status === 'completed') {

                        echo gigtune_render_rating_form(
                            $b->ID,
                            (int) get_post_meta($b->ID, 'gigtune_booking_artist_profile_id', true)
                        );
                    }

                    ?>
                    </td>
                    <td style="border-bottom:1px solid #eee;padding:8px;"><?php echo esc_html($requested_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}
add_shortcode('gigtune_client_dashboard', 'gigtune_client_dashboard_shortcode');


/**
 * --------------------------------------------------
 * BOOK AN ARTIST (FRONTEND) — Creates a booking record
 * Shortcode: [gigtune_book_artist]
 * URL expects: /book-artist/?artist_id=123
 * --------------------------------------------------
 */
function gigtune_book_artist_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to book an artist.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('gigtune_client', $user->roles, true) && !current_user_can('manage_options')) {
        return '<p>Access denied.</p>';
    }

    if (!isset($_GET['artist_id'])) {
        return '<p>Missing artist_id.</p>';
    }

    $artist_id = absint($_GET['artist_id']);
    $artist = get_post($artist_id);

    if (!$artist || $artist->post_type !== 'artist_profile') {
        return '<p>Invalid artist.</p>';
    }

    $msg = '';

    if (isset($_POST['gigtune_book_artist_submit'])) {

        if (!isset($_POST['gigtune_book_artist_nonce']) || !wp_verify_nonce($_POST['gigtune_book_artist_nonce'], 'gigtune_book_artist_action')) {
            return '<p>Security check failed.</p>';
        }

        $event_date = isset($_POST['gigtune_event_date']) ? sanitize_text_field($_POST['gigtune_event_date']) : '';
        $notes = isset($_POST['gigtune_notes']) ? sanitize_textarea_field($_POST['gigtune_notes']) : '';

        $title = 'Booking Request — ' . $artist->post_title . ' — ' . date('Y-m-d H:i');

        $booking_id = wp_insert_post([
            'post_type' => 'gigtune_booking',
            'post_status' => 'publish',
            'post_title' => $title
        ]);

        if (!is_wp_error($booking_id) && $booking_id > 0) {

            update_post_meta($booking_id, 'gigtune_booking_artist_profile_id', $artist_id);
            update_post_meta($booking_id, 'gigtune_booking_client_user_id', (int)$user->ID);
            update_post_meta($booking_id, 'gigtune_booking_status', 'requested');
            update_post_meta($booking_id, 'gigtune_booking_requested_at', current_time('mysql'));

            if ($event_date !== '') update_post_meta($booking_id, 'gigtune_booking_event_date', $event_date);
            if ($notes !== '') update_post_meta($booking_id, 'gigtune_booking_notes', $notes);

            $msg = '<p><strong>Booking request sent.</strong> You can view it in your Client Dashboard.</p>';
        } else {
            $msg = '<p>Could not create booking. Try again.</p>';
        }
    }

    ob_start(); ?>

    <h2>Book Artist — <?php echo esc_html($artist->post_title); ?></h2>

    <?php echo $msg; ?>

    <form method="post">
        <?php wp_nonce_field('gigtune_book_artist_action', 'gigtune_book_artist_nonce'); ?>

        <p>
            <label>Event date (optional)</label><br>
            <input type="text" name="gigtune_event_date" placeholder="e.g. 2026-01-05 18:00">
        </p>

        <p>
            <label>Notes (optional)</label><br>
            <textarea name="gigtune_notes" rows="5" placeholder="Tell the artist about the gig..."></textarea>
        </p>

        <p>
            <button type="submit" name="gigtune_book_artist_submit" value="1">Send Booking Request</button>
        </p>
    </form>

    <p><a href="<?php echo esc_url(site_url('/artist-profile/?artist_id=' . $artist_id)); ?>">Back to Artist Profile</a></p>

    <?php
    return ob_get_clean();
}
add_shortcode('gigtune_book_artist', 'gigtune_book_artist_shortcode');


/**
 * --------------------------------------------------
 * PHASE 6A: Artist booking request block (shown inside artist dashboard)
 * - Lists "requested" bookings for this artist
 * - Allows Accept / Decline
 * - Hooks to Phase 5 reliability events (accepted/declined)
 * --------------------------------------------------
 */
function gigtune_phase6_artist_booking_requests_block($artist_profile_id) {

    $artist_profile_id = absint($artist_profile_id);
    if ($artist_profile_id <= 0) return '';

    // Handle accept/decline actions
    if (isset($_POST['gigtune_artist_booking_action']) && isset($_POST['gigtune_booking_id'])) {

        if (!isset($_POST['gigtune_artist_booking_nonce']) || !wp_verify_nonce($_POST['gigtune_artist_booking_nonce'], 'gigtune_artist_booking_action')) {
            return '<p>Security check failed.</p>';
        }

        $booking_id = absint($_POST['gigtune_booking_id']);
        $action = sanitize_text_field($_POST['gigtune_artist_booking_action']);

        $booking = get_post($booking_id);
        if ($booking && $booking->post_type === 'gigtune_booking') {

            $b_artist_id = (int) get_post_meta($booking_id, 'gigtune_booking_artist_profile_id', true);

            if ($b_artist_id === $artist_profile_id) {

                if ($action === 'accept') {
                    update_post_meta($booking_id, 'gigtune_booking_status', 'accepted');
                    update_post_meta($booking_id, 'gigtune_booking_responded_at', current_time('mysql'));

                    if (function_exists('gigtune_phase5_apply_reliability_event')) {
                        gigtune_phase5_apply_reliability_event($artist_profile_id, 'accepted');
                    }

                } elseif ($action === 'decline') {
                    update_post_meta($booking_id, 'gigtune_booking_status', 'declined');
                    update_post_meta($booking_id, 'gigtune_booking_responded_at', current_time('mysql'));

                    if (function_exists('gigtune_phase5_apply_reliability_event')) {
                        gigtune_phase5_apply_reliability_event($artist_profile_id, 'declined');
                    }
                }
            }
        }
    }

    $requests = get_posts([
        'post_type' => 'gigtune_booking',
        'post_status' => 'any',
        'numberposts' => 20,
        'meta_query' => [
            [
                'key' => 'gigtune_booking_artist_profile_id',
                'value' => $artist_profile_id,
                'compare' => '='
            ],
            [
                'key' => 'gigtune_booking_status',
                'value' => 'requested',
                'compare' => '='
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    ob_start(); ?>

    <h3>Booking Requests</h3>

    <?php if (empty($requests)): ?>
        <p>No new requests.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($requests as $r): ?>
                <?php
                $client_id = (int) get_post_meta($r->ID, 'gigtune_booking_client_user_id', true);
                $requested_at = (string) get_post_meta($r->ID, 'gigtune_booking_requested_at', true);
                $event_date = (string) get_post_meta($r->ID, 'gigtune_booking_event_date', true);
                $notes = (string) get_post_meta($r->ID, 'gigtune_booking_notes', true);
                ?>
                <li style="margin-bottom: 14px;">
                    <strong><?php echo esc_html($r->post_title); ?></strong><br>
                    <span><strong>Client ID:</strong> <?php echo esc_html($client_id); ?></span><br>
                    <span><strong>Requested:</strong> <?php echo esc_html($requested_at); ?></span><br>
                    <?php if ($event_date !== ''): ?><span><strong>Event date:</strong> <?php echo esc_html($event_date); ?></span><br><?php endif; ?>
                    <?php if ($notes !== ''): ?><div style="margin-top:6px;"><strong>Notes:</strong><br><?php echo esc_html($notes); ?></div><?php endif; ?>

                    <form method="post" style="margin-top: 8px;">
                        <?php wp_nonce_field('gigtune_artist_booking_action', 'gigtune_artist_booking_nonce'); ?>
                        <input type="hidden" name="gigtune_booking_id" value="<?php echo esc_attr($r->ID); ?>">
                        <button type="submit" name="gigtune_artist_booking_action" value="accept">Accept</button>
                        <button type="submit" name="gigtune_artist_booking_action" value="decline" style="margin-left:8px;">Decline</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

/**
 * --------------------------------------------------
 * PHASE 6.1: Artist Accepted & Upcoming Bookings
 * - Shows bookings that are accepted or completed
 * - Visible only to the artist who owns the profile
 * --------------------------------------------------
 */
function gigtune_phase61_artist_accepted_bookings_block($artist_profile_id) {

    $artist_profile_id = absint($artist_profile_id);
    if ($artist_profile_id <= 0) return '';

    $bookings = get_posts([
        'post_type'   => 'gigtune_booking',
        'post_status' => 'any',
        'numberposts' => 20,
        'meta_query'  => [
            [
                'key'   => 'gigtune_booking_artist_profile_id',
                'value' => $artist_profile_id,
            ],
            [
                'key'     => 'gigtune_booking_status',
                'value'   => ['accepted','completed'],
                'compare' => 'IN'
            ]
        ],
        'orderby' => 'date',
        'order'   => 'DESC'
    ]);

    ob_start(); ?>

    <h3>Confirmed & Upcoming Bookings</h3>

    <?php if (empty($bookings)): ?>
        <p>No confirmed bookings yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Booking</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Client</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Event Date</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <?php
                    $client_id  = (int) get_post_meta($b->ID, 'gigtune_booking_client_user_id', true);
                    $event_date = (string) get_post_meta($b->ID, 'gigtune_booking_event_date', true);
                    $status     = (string) get_post_meta($b->ID, 'gigtune_booking_status', true);

                    $client_label = $client_id > 0 ? 'Client #' . $client_id : '—';
                    ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #eee;">
                            <?php echo esc_html($b->post_title); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;">
                            <?php echo esc_html($client_label); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;">
                            <?php echo esc_html($event_date !== '' ? $event_date : '—'); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;">
                            <?php echo esc_html(ucfirst(str_replace('_',' ', $status))); ?>

                            <?php if ($status === 'accepted'): ?>
                                <form method="post" style="margin-top:6px;">
                                    <?php wp_nonce_field('gigtune_artist_complete_booking', 'gigtune_artist_complete_nonce'); ?>
                                    <input type="hidden" name="gigtune_complete_booking_id" value="<?php echo esc_attr($b->ID); ?>">
                                    <button type="submit" name="gigtune_artist_mark_completed">
                                        Mark Completed
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

/**
 * ==================================================
 * ACCOUNT PORTAL (LOGIN / REGISTER / REDIRECT)
 * Shortcode: [gigtune_account_portal]
 * ==================================================
 */

function gigtune_account_portal_shortcode() {

    // If logged in → redirect to correct dashboard
    // BUT: never redirect during REST or admin save requests
    if (is_user_logged_in() && !defined('REST_REQUEST') && !is_admin()) {

        $user = wp_get_current_user();

        if (in_array('gigtune_artist', $user->roles, true)) {
            wp_safe_redirect(site_url('/artist-dashboard'));
            exit;
        }

        if (in_array('gigtune_client', $user->roles, true)) {
            wp_safe_redirect(site_url('/client-dashboard'));
            exit;
        }

        wp_safe_redirect(site_url('/'));
        exit;
    }
    
    $error = '';

    // Handle login
    if (isset($_POST['gigtune_login_submit'])) {

        if (
            !isset($_POST['gigtune_login_nonce']) ||
            !wp_verify_nonce($_POST['gigtune_login_nonce'], 'gigtune_login_action')
        ) {
            $error = 'Security check failed.';
        } else {

            $creds = [
                'user_login'    => sanitize_text_field($_POST['gigtune_login_email']),
                'user_password' => $_POST['gigtune_login_password'],
                'remember'      => true
            ];

            $user = wp_signon($creds, false);

            if (is_wp_error($user)) {
                $error = 'Invalid login details.';
            } else {
                wp_redirect(site_url('/'));
                exit;
            }
        }
    }

    ob_start(); ?>

    <h2>Account</h2>

    <?php if ($error !== ''): ?>
        <p style="color:red;"><?php echo esc_html($error); ?></p>
    <?php endif; ?>

    <div style="display:flex;gap:40px;flex-wrap:wrap;">

        <!-- LOGIN -->
        <div style="flex:1;min-width:260px;">
            <h3>Sign In</h3>

            <form method="post">
                <?php wp_nonce_field('gigtune_login_action', 'gigtune_login_nonce'); ?>

                <p>
                    <label>Email</label><br>
                    <input type="text" name="gigtune_login_email" required>
                </p>

                <p>
                    <label>Password</label><br>
                    <input type="password" name="gigtune_login_password" required>
                </p>

                <p>
                    <button type="submit" name="gigtune_login_submit">Sign In</button>
                </p>
            </form>
        </div>

        <!-- REGISTER -->
        <div style="flex:1;min-width:260px;">
            <h3>Create Account</h3>

            <?php echo do_shortcode('[gigtune_register]'); ?>
        </div>

    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('gigtune_account_portal', 'gigtune_account_portal_shortcode');

/**
 * --------------------------------------------------
 * ROLE AWARE NAVIGATION (ARTIST / CLIENT / GUEST)
 * Shortcode: [gigtune_role_nav]
 * --------------------------------------------------
 */
function gigtune_role_nav_shortcode() {

    if (!is_user_logged_in()) {
        return '
            <nav class="gigtune-nav">
                <a href="' . esc_url(site_url('/account')) . '">Sign In / Register</a>
            </nav>
        ';
    }

    $user = wp_get_current_user();

    // Artist navigation
    if (in_array('gigtune_artist', $user->roles, true)) {

        $logout_url = wp_logout_url(site_url('/'));

        return '
            <nav class="gigtune-nav">
                <a href="' . esc_url(site_url('/artist-dashboard')) . '">Artist Dashboard</a> |
                <a href="' . esc_url(site_url('/artist-profile-edit')) . '">Edit Profile</a> |
                <a href="' . esc_url(site_url('/artist')) . '">Browse Artists</a> |
                <a href="' . esc_url($logout_url) . '">Logout</a>
            </nav>
        ';
    }

    // Client navigation
    if (in_array('gigtune_client', $user->roles, true)) {

        $logout_url = wp_logout_url(site_url('/'));

        return '
            <nav class="gigtune-nav">
                <a href="' . esc_url(site_url('/client-dashboard')) . '">Client Dashboard</a> |
                <a href="' . esc_url(site_url('/artist')) . '">Browse Artists</a> |
                <a href="' . esc_url($logout_url) . '">Logout</a>
            </nav>
        ';
    }

    return '';
}
add_shortcode('gigtune_role_nav', 'gigtune_role_nav_shortcode');

/**
 * ==================================================
 * PHASE 6.3 — CLIENT RATES ARTIST (POST-COMPLETION)
 * ==================================================
 */

/**
 * --------------------------------------------------
 * RENDER RATING FORM (CLIENT DASHBOARD HELPER)
 * --------------------------------------------------
 */
function gigtune_render_rating_form($booking_id, $artist_profile_id) {

    // Prevent double rating
    if (get_post_meta($booking_id, 'gigtune_rating_submitted', true)) {
        return '<em>Rating submitted.</em>';
    }

    ob_start(); ?>

    <form method="post" style="margin-top:10px;">
        <?php wp_nonce_field('gigtune_submit_rating_action', 'gigtune_submit_rating_nonce'); ?>

        <input type="hidden" name="gigtune_booking_id" value="<?php echo esc_attr($booking_id); ?>">
        <input type="hidden" name="gigtune_artist_profile_id" value="<?php echo esc_attr($artist_profile_id); ?>">

        <p>
            <label>Performance (Talent)</label><br>
            <select name="gigtune_performance_rating" required>
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
        </p>

        <p>
            <label>Reliability (Professionalism)</label><br>
            <select name="gigtune_reliability_rating" required>
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
        </p>

        <p>
            <button type="submit" name="gigtune_submit_rating">
                Submit Rating
            </button>
        </p>
    </form>

    <?php
    return ob_get_clean();
}

/**
 * --------------------------------------------------
 * HANDLE RATING SUBMISSION
 * --------------------------------------------------
 */
function gigtune_handle_rating_submission() {

    if (!isset($_POST['gigtune_submit_rating'])) return;

    if (
        !isset($_POST['gigtune_submit_rating_nonce']) ||
        !wp_verify_nonce($_POST['gigtune_submit_rating_nonce'], 'gigtune_submit_rating_action')
    ) return;

    if (!is_user_logged_in()) return;

    $booking_id = absint($_POST['gigtune_booking_id']);
    $artist_profile_id = absint($_POST['gigtune_artist_profile_id']);

    if ($booking_id <= 0 || $artist_profile_id <= 0) return;

    // Booking validation
    $booking = get_post($booking_id);
    if (!$booking || $booking->post_type !== 'gigtune_booking') return;

    // Must be completed
    $status = get_post_meta($booking_id, 'gigtune_booking_status', true);
    if ($status !== 'completed') return;

    // Prevent re-rating
    if (get_post_meta($booking_id, 'gigtune_rating_submitted', true)) return;

    $perf = (int) $_POST['gigtune_performance_rating'];
    $rel  = (int) $_POST['gigtune_reliability_rating'];

    if ($perf < 1 || $perf > 5 || $rel < 1 || $rel > 5) return;

    /**
     * UPDATE PERFORMANCE RATING
     */
    $p_avg = (float) get_post_meta($artist_profile_id, 'gigtune_performance_rating_avg', true);
    $p_cnt = (int) get_post_meta($artist_profile_id, 'gigtune_performance_rating_count', true);

    $new_p_cnt = $p_cnt + 1;
    $new_p_avg = (($p_avg * $p_cnt) + $perf) / $new_p_cnt;

    update_post_meta($artist_profile_id, 'gigtune_performance_rating_avg', round($new_p_avg, 2));
    update_post_meta($artist_profile_id, 'gigtune_performance_rating_count', $new_p_cnt);

    /**
     * UPDATE RELIABILITY RATING
     */
    $r_avg = (float) get_post_meta($artist_profile_id, 'gigtune_reliability_rating_avg', true);
    $r_cnt = (int) get_post_meta($artist_profile_id, 'gigtune_reliability_rating_count', true);

    $new_r_cnt = $r_cnt + 1;
    $new_r_avg = (($r_avg * $r_cnt) + $rel) / $new_r_cnt;

    update_post_meta($artist_profile_id, 'gigtune_reliability_rating_avg', round($new_r_avg, 2));
    update_post_meta($artist_profile_id, 'gigtune_reliability_rating_count', $new_r_cnt);

    /**
     * LOCK BOOKING
     */
    update_post_meta($booking_id, 'gigtune_rating_submitted', 1);
}
add_action('init', 'gigtune_handle_rating_submission');

function gigtune_handle_artist_mark_completed() {

    if (!isset($_POST['gigtune_artist_mark_completed'])) return;

    if (
        !isset($_POST['gigtune_artist_complete_nonce']) ||
        !wp_verify_nonce($_POST['gigtune_artist_complete_nonce'], 'gigtune_artist_complete_booking')
    ) return;

    if (!is_user_logged_in()) return;

    $booking_id = absint($_POST['gigtune_complete_booking_id']);
    if ($booking_id <= 0) return;

    update_post_meta($booking_id, 'gigtune_booking_status', 'awaiting_client_confirmation');
    update_post_meta($booking_id, 'gigtune_booking_artist_completed_at', current_time('timestamp'));
}
add_action('init', 'gigtune_handle_artist_mark_completed');

function gigtune_handle_client_confirm_completion() {

    if (!isset($_POST['gigtune_client_confirm_completion'])) return;

    if (
        !isset($_POST['gigtune_client_confirm_nonce']) ||
        !wp_verify_nonce($_POST['gigtune_client_confirm_nonce'], 'gigtune_client_confirm_completion')
    ) return;

    $booking_id = absint($_POST['gigtune_confirm_booking_id']);
    if ($booking_id <= 0) return;

    update_post_meta($booking_id, 'gigtune_booking_status', 'completed');
    update_post_meta($booking_id, 'gigtune_booking_client_confirmed_at', current_time('timestamp'));
}
add_action('init', 'gigtune_handle_client_confirm_completion');

function gigtune_auto_complete_after_timeout() {

    $bookings = get_posts([
        'post_type' => 'gigtune_booking',
        'numberposts' => 20,
        'meta_query' => [
            [
                'key' => 'gigtune_booking_status',
                'value' => 'awaiting_client_confirmation'
            ]
        ]
    ]);

    foreach ($bookings as $b) {

        $completed_at = (int) get_post_meta($b->ID, 'gigtune_booking_artist_completed_at', true);
        if ($completed_at <= 0) continue;

        if ((time() - $completed_at) >= (48 * 3600)) {
            update_post_meta($b->ID, 'gigtune_booking_status', 'completed');
            update_post_meta($b->ID, 'gigtune_booking_client_confirmed_at', time());
        }
    }
}
add_action('init', 'gigtune_auto_complete_after_timeout');


