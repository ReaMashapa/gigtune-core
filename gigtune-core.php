<?php
/**
 * Plugin Name: GigTune Core
 * Description: Core functionality for the GigTune marketplace.
 * Version: 1.5
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

    <form method="post" enctype="multipart/form-data">
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

            // Phase 1: availability + travel radius defaults
            update_post_meta($profile_id, 'gigtune_artist_travel_radius_km', 25);
            update_post_meta($profile_id, 'gigtune_artist_base_area', '');
            update_post_meta($profile_id, 'gigtune_artist_available_now', 0);
            update_post_meta($profile_id, 'gigtune_artist_visibility_mode', 'approx');
            update_post_meta($profile_id, 'gigtune_artist_availability_days', []);
            update_post_meta($profile_id, 'gigtune_artist_availability_start_time', '');
            update_post_meta($profile_id, 'gigtune_artist_availability_end_time', '');
            update_post_meta($profile_id, 'gigtune_demo_videos', []);
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

    <?php echo gigtune_render_demo_gallery($profile_id, 'artist_dashboard'); ?>

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
    echo gigtune_render_availability_block($profile_id, 'Availability', true);

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

    $errors = [];

    $existing_videos = gigtune_get_demo_videos($profile_id);
    $remove_ids = [];
    if (isset($_POST['gigtune_remove_demo']) && is_array($_POST['gigtune_remove_demo'])) {
        $remove_ids = array_map('absint', $_POST['gigtune_remove_demo']);
    }
    if (!empty($remove_ids)) {
        foreach ($remove_ids as $rid) {
            if ($rid > 0) {
                wp_delete_attachment($rid, true);
            }
        }
        $existing_videos = array_values(array_diff($existing_videos, $remove_ids));
    }

    $updated_videos = gigtune_handle_demo_uploads($profile_id, $existing_videos, $errors);
    $updated_videos = array_values(array_unique(array_map('absint', $updated_videos)));

    $limits = gigtune_get_demo_video_limits();
    $blocking_error = false;
    if (count($updated_videos) > $limits['max_count']) {
        $errors[] = 'You can only have up to ' . $limits['max_count'] . ' demo videos.';
        $blocking_error = true;
    }
    if (count($updated_videos) < $limits['min_count']) {
        $errors[] = 'At least ' . $limits['min_count'] . ' demo video is required.';
        $blocking_error = true;
    }

    if (!$blocking_error) {
        update_post_meta($profile_id, 'gigtune_demo_videos', $updated_videos);
    }

    $available_now = isset($_POST['gigtune_artist_available_now']) ? 1 : 0;
    $base_area = sanitize_text_field($_POST['gigtune_artist_base_area'] ?? '');
    $travel_radius = absint($_POST['gigtune_artist_travel_radius_km'] ?? 0);

    $visibility_mode = sanitize_text_field($_POST['gigtune_artist_visibility_mode'] ?? 'approx');
    if (!in_array($visibility_mode, ['approx', 'hidden'], true)) {
        $visibility_mode = 'approx';
    }

    $allowed_days = ['mon','tue','wed','thu','fri','sat','sun'];
    $days = [];
    if (isset($_POST['gigtune_artist_availability_days']) && is_array($_POST['gigtune_artist_availability_days'])) {
        foreach ($_POST['gigtune_artist_availability_days'] as $d) {
            $d = sanitize_text_field($d);
            if (in_array($d, $allowed_days, true)) {
                $days[] = $d;
            }
        }
    }

    $start_time = sanitize_text_field($_POST['gigtune_artist_availability_start_time'] ?? '');
    $end_time = sanitize_text_field($_POST['gigtune_artist_availability_end_time'] ?? '');
    if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) {
        $start_time = '';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) {
        $end_time = '';
    }

    update_post_meta($profile_id, 'gigtune_artist_available_now', $available_now);
    update_post_meta($profile_id, 'gigtune_artist_base_area', $base_area);
    update_post_meta($profile_id, 'gigtune_artist_travel_radius_km', $travel_radius);
    update_post_meta($profile_id, 'gigtune_artist_visibility_mode', $visibility_mode);
    update_post_meta($profile_id, 'gigtune_artist_availability_days', array_values(array_unique($days)));
    update_post_meta($profile_id, 'gigtune_artist_availability_start_time', $start_time);
    update_post_meta($profile_id, 'gigtune_artist_availability_end_time', $end_time);

    if (!empty($errors)) {
        $GLOBALS['gigtune_profile_errors'] = $errors;
        return;
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
    $available_now = (int) get_post_meta($profile_id, 'gigtune_artist_available_now', true);
    $base_area = (string) get_post_meta($profile_id, 'gigtune_artist_base_area', true);
    $travel_radius = (int) get_post_meta($profile_id, 'gigtune_artist_travel_radius_km', true);
    $visibility_mode = (string) get_post_meta($profile_id, 'gigtune_artist_visibility_mode', true);
    $availability_days = get_post_meta($profile_id, 'gigtune_artist_availability_days', true);
    $availability_start = (string) get_post_meta($profile_id, 'gigtune_artist_availability_start_time', true);
    $availability_end = (string) get_post_meta($profile_id, 'gigtune_artist_availability_end_time', true);

    if (!is_array($availability_days)) {
        $availability_days = [];
    }

    ob_start(); ?>

    <h2>Edit Artist Profile</h2>

    <form method="post" enctype="multipart/form-data">
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

        <h3>Demo Videos</h3>

        <?php
        $limits = gigtune_get_demo_video_limits();
        $demo_videos = gigtune_get_demo_videos($profile_id);
        $errors = isset($GLOBALS['gigtune_profile_errors']) && is_array($GLOBALS['gigtune_profile_errors']) ? $GLOBALS['gigtune_profile_errors'] : [];
        ?>

        <?php if (!empty($errors)): ?>
            <div style="color:red;margin-bottom:10px;">
                <?php foreach ($errors as $e): ?>
                    <div><?php echo esc_html($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p style="color:#555;">
            Upload 1–5 videos. Each must be 30–60 seconds and no larger than 200MB.
            Allowed: mp4, mov, webm, m4v. Orientation is required (portrait/landscape).
        </p>
        <p style="color:#777;">Server max upload size: <?php echo esc_html(size_format(wp_max_upload_size())); ?></p>

        <?php if (!empty($demo_videos)): ?>
            <fieldset>
                <legend>Existing Demos (check to remove)</legend>
                <?php foreach ($demo_videos as $vid_id): ?>
                    <?php
                    $src = wp_get_attachment_url($vid_id);
                    if (!$src) continue;
                    ?>
                    <label>
                        <input type="checkbox" name="gigtune_remove_demo[]" value="<?php echo esc_attr($vid_id); ?>">
                        Remove
                    </label>
                    <div style="margin:6px 0 12px 0;">
                        <video controls playsinline controlslist="nodownload noplaybackrate" oncontextmenu="return false;" style="max-width:100%;">
                            <source src="<?php echo esc_url($src); ?>">
                        </video>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

        <p>
            <label>Add Demo Videos</label><br>
            <input type="file" name="gigtune_demo_videos[]" accept="video/*" multiple>
        </p>

        <h3>Availability and Travel</h3>

        <p>
            <label>
                <input type="checkbox" name="gigtune_artist_available_now" value="1" <?php checked($available_now === 1); ?>>
                Available now
            </label>
        </p>

        <p>
            <label>Base Area (City/Region)</label><br>
            <input type="text" name="gigtune_artist_base_area" value="<?php echo esc_attr($base_area); ?>">
        </p>

        <p>
            <label>Travel Radius (km)</label><br>
            <input type="number" name="gigtune_artist_travel_radius_km" min="0" step="1" value="<?php echo esc_attr($travel_radius); ?>">
        </p>

        <p>
            <label>Location Visibility</label><br>
            <select name="gigtune_artist_visibility_mode">
                <option value="approx" <?php selected($visibility_mode, 'approx'); ?>>Approximate area</option>
                <option value="hidden" <?php selected($visibility_mode, 'hidden'); ?>>Hidden</option>
            </select>
        </p>

        <fieldset>
            <legend>Weekly Availability</legend>
            <?php
            $day_labels = [
                'mon' => 'Mon',
                'tue' => 'Tue',
                'wed' => 'Wed',
                'thu' => 'Thu',
                'fri' => 'Fri',
                'sat' => 'Sat',
                'sun' => 'Sun',
            ];
            foreach ($day_labels as $key => $label): ?>
                <label>
                    <input type="checkbox"
                           name="gigtune_artist_availability_days[]"
                           value="<?php echo esc_attr($key); ?>"
                           <?php checked(in_array($key, $availability_days, true)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <p>
            <label>Available From</label><br>
            <input type="time" name="gigtune_artist_availability_start_time" value="<?php echo esc_attr($availability_start); ?>">
        </p>

        <p>
            <label>Available To</label><br>
            <input type="time" name="gigtune_artist_availability_end_time" value="<?php echo esc_attr($availability_end); ?>">
        </p>

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

    <?php echo gigtune_render_demo_gallery($profile_id, 'public_profile'); ?>

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

    <?php echo gigtune_render_availability_block($profile_id, 'Availability', false); ?>

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
 * MEDIA: DEMO VIDEOS (1 MIN, 5 MAX)
 * --------------------------------------------------
 */
function gigtune_get_demo_video_limits() {
    return [
        'min_count' => 1,
        'max_count' => 5,
        'max_size_bytes' => 200 * 1024 * 1024,
        'min_duration' => 30,
        'max_duration' => 60,
        'allowed_ext' => ['mp4','mov','webm','m4v']
    ];
}

function gigtune_get_demo_videos($profile_id) {
    $videos = get_post_meta($profile_id, 'gigtune_demo_videos', true);
    return is_array($videos) ? array_values(array_unique(array_map('absint', $videos))) : [];
}

function gigtune_render_demo_gallery($profile_id, $context = 'public_profile') {
    $videos = gigtune_get_demo_videos($profile_id);
    if (empty($videos)) {
        return $context === 'artist_dashboard' ? '<h3>Demo Videos</h3><p>No demo videos uploaded yet.</p>' : '';
    }

    $slider_id = 'gigtune-demo-slider-' . $profile_id . '-' . $context;
    $out = '<h3>Demo Videos</h3>';
    $out .= '<div id="' . esc_attr($slider_id) . '" class="gigtune-demo-slider" data-index="0">';
    $out .= '<div class="gigtune-demo-track">';

    foreach ($videos as $idx => $attachment_id) {
        if ($attachment_id <= 0) continue;
        $src = wp_get_attachment_url($attachment_id);
        if (!$src) continue;

        $orientation = get_post_meta($attachment_id, 'gigtune_video_orientation', true);
        $orientation_label = $orientation !== '' ? ucfirst($orientation) : 'Unknown';

        $out .= '<div class="gigtune-demo-slide" data-idx="' . esc_attr($idx) . '" style="' . ($idx === 0 ? '' : 'display:none;') . '">';
        $out .= '<video controls playsinline controlslist="nodownload noplaybackrate" oncontextmenu="return false;" style="max-width:100%;">';
        $out .= '<source src="' . esc_url($src) . '">';
        $out .= '</video>';
        $out .= '<div style="margin-top:6px;font-size:13px;color:#555;">Orientation: ' . esc_html($orientation_label) . '</div>';
        $out .= '<div style="margin-top:6px;">Share: <a href="' . esc_url($src) . '" target="_blank" rel="noopener noreferrer">Open demo link</a></div>';
        $out .= '</div>';
    }

    $out .= '</div>';

    if (count($videos) > 1) {
        $out .= '<div style="margin-top:8px;">';
        $out .= '<button type="button" class="gigtune-demo-prev">Prev</button>';
        $out .= '<button type="button" class="gigtune-demo-next" style="margin-left:8px;">Next</button>';
        $out .= '</div>';
    }

    $out .= '</div>';

    if (count($videos) > 1) {
        $out .= '<script>
            (function(){
                var root = document.getElementById("' . esc_js($slider_id) . '");
                if (!root) return;
                var slides = root.querySelectorAll(".gigtune-demo-slide");
                var idx = 0;
                function show(i){
                    slides.forEach(function(s){ s.style.display = "none"; });
                    if (slides[i]) slides[i].style.display = "block";
                    idx = i;
                }
                var prev = root.querySelector(".gigtune-demo-prev");
                var next = root.querySelector(".gigtune-demo-next");
                if (prev) prev.addEventListener("click", function(){
                    var i = idx - 1;
                    if (i < 0) i = slides.length - 1;
                    show(i);
                });
                if (next) next.addEventListener("click", function(){
                    var i = idx + 1;
                    if (i >= slides.length) i = 0;
                    show(i);
                });
            })();
        </script>';
    }

    return $out;
}

function gigtune_handle_demo_uploads($profile_id, $existing, &$errors) {
    $limits = gigtune_get_demo_video_limits();

    if (empty($_FILES['gigtune_demo_videos'])) {
        return $existing;
    }

    $files = $_FILES['gigtune_demo_videos'];
    if (isset($files['name']) && !is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']]
        ];
    }
    $file_count = is_array($files['name']) ? count($files['name']) : 0;

    if ($file_count <= 0) {
        $errors[] = 'No demo videos were received by the server. Check upload limits.';
        return $existing;
    }

    if (count($existing) + $file_count > $limits['max_count']) {
        $errors[] = 'You can only upload ' . $limits['max_count'] . ' demo videos total.';
        return $existing;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $updated = $existing;

    for ($i = 0; $i < $file_count; $i++) {
        $name = $files['name'][$i] ?? '';
        $size = (int) ($files['size'][$i] ?? 0);
        $error = (int) ($files['error'][$i] ?? 0);

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed for ' . ($name !== '' ? $name : 'a demo video') . '.';
            continue;
        }

        if ($size <= 0 || $size > $limits['max_size_bytes']) {
            $errors[] = $name . ' exceeds the 200MB limit.';
            continue;
        }

        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];

        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $ext = strtolower($filetype['ext'] ?? '');
        if ($ext === '' || !in_array($ext, $limits['allowed_ext'], true)) {
            $errors[] = $name . ' is not an allowed video type.';
            continue;
        }

        $_FILES['gigtune_demo_single'] = $file;
        $attach_id = media_handle_upload('gigtune_demo_single', 0);
        unset($_FILES['gigtune_demo_single']);
        if (is_wp_error($attach_id)) {
            $errors[] = 'Could not save ' . $name . '.';
            continue;
        }

        $file_path = get_attached_file($attach_id);
        $meta = wp_read_video_metadata($file_path);
        $duration = isset($meta['length']) ? (float) $meta['length'] : 0;
        $width = isset($meta['width']) ? (int) $meta['width'] : 0;
        $height = isset($meta['height']) ? (int) $meta['height'] : 0;

        if ($duration < $limits['min_duration'] || $duration > $limits['max_duration']) {
            wp_delete_attachment($attach_id, true);
            $errors[] = $name . ' must be 30–60 seconds.';
            continue;
        }

        if ($width <= 0 || $height <= 0) {
            wp_delete_attachment($attach_id, true);
            $errors[] = $name . ' orientation could not be detected.';
            continue;
        }

        $orientation = $width >= $height ? 'landscape' : 'portrait';
        update_post_meta($attach_id, 'gigtune_video_orientation', $orientation);
        update_post_meta($attach_id, 'gigtune_video_duration', $duration);

        $updated[] = $attach_id;
    }

    return $updated;
}

/**
 * --------------------------------------------------
 * PHASE 1: AVAILABILITY + TRAVEL RADIUS (BLOCK)
 * --------------------------------------------------
 */
function gigtune_render_availability_block($profile_id, $heading = 'Availability', $show_visibility = false) {
    if (!$profile_id) return '';

    $travel_radius = (int) get_post_meta($profile_id, 'gigtune_artist_travel_radius_km', true);
    $base_area = (string) get_post_meta($profile_id, 'gigtune_artist_base_area', true);
    $available_now = (int) get_post_meta($profile_id, 'gigtune_artist_available_now', true);
    $visibility_mode = (string) get_post_meta($profile_id, 'gigtune_artist_visibility_mode', true);
    $days = get_post_meta($profile_id, 'gigtune_artist_availability_days', true);
    $start_time = (string) get_post_meta($profile_id, 'gigtune_artist_availability_start_time', true);
    $end_time = (string) get_post_meta($profile_id, 'gigtune_artist_availability_end_time', true);

    if (!is_array($days)) {
        $days = [];
    }

    $day_labels = [
        'mon' => 'Mon',
        'tue' => 'Tue',
        'wed' => 'Wed',
        'thu' => 'Thu',
        'fri' => 'Fri',
        'sat' => 'Sat',
        'sun' => 'Sun',
    ];

    $day_names = [];
    foreach ($days as $d) {
        if (isset($day_labels[$d])) {
            $day_names[] = $day_labels[$d];
        }
    }

    $availability_label = empty($day_names) ? 'Not set' : implode(', ', $day_names);
    if ($start_time !== '' && $end_time !== '') {
        $availability_label .= ' (' . esc_html($start_time) . ' - ' . esc_html($end_time) . ')';
    }

    $availability_status = $available_now ? 'Available now' : 'Not available now';
    $visibility_label = $visibility_mode === 'hidden' ? 'Hidden' : 'Approximate area';

    ob_start(); ?>

    <h3><?php echo esc_html($heading); ?></h3>
    <ul>
        <li><strong>Status:</strong> <?php echo esc_html($availability_status); ?></li>
        <li><strong>Availability:</strong> <?php echo esc_html($availability_label); ?></li>
        <?php if ($visibility_mode !== 'hidden' && $base_area !== ''): ?>
            <li><strong>Base area:</strong> <?php echo esc_html($base_area); ?></li>
        <?php endif; ?>
        <?php if ($travel_radius > 0): ?>
            <li><strong>Travel radius:</strong> <?php echo esc_html($travel_radius); ?> km</li>
        <?php endif; ?>
        <?php if ($show_visibility): ?>
            <li><strong>Location visibility:</strong> <?php echo esc_html($visibility_label); ?></li>
        <?php endif; ?>
    </ul>

    <?php
    return ob_get_clean();
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

function gigtune_fit_score_artist($profile_id, $search_tokens, $selected_filters, $filter_taxonomies, $term_name_map, $availability_filters = []) {

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

    $available_now = (int) get_post_meta($profile_id, 'gigtune_artist_available_now', true);
    if ($available_now === 1) {
        $score += 5;
    }

    if (is_array($availability_filters) && !empty($availability_filters)) {
        if (!empty($availability_filters['available_now']) && $available_now === 1) {
            $score += 15;
        }

        if (!empty($availability_filters['availability_day'])) {
            $days = get_post_meta($profile_id, 'gigtune_artist_availability_days', true);
            if (is_array($days) && in_array($availability_filters['availability_day'], $days, true)) {
                $score += 10;
            }
        }

        if (!empty($availability_filters['min_travel_radius'])) {
            $radius = (int) get_post_meta($profile_id, 'gigtune_artist_travel_radius_km', true);
            if ($radius >= (int) $availability_filters['min_travel_radius']) {
                $score += 5;
            }
        }
    }

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
    $available_now_filter = isset($_GET['available_now']) ? 1 : 0;
    $availability_day_filter = isset($_GET['availability_day']) ? sanitize_text_field($_GET['availability_day']) : '';
    $base_area_filter = isset($_GET['base_area']) ? sanitize_text_field($_GET['base_area']) : '';
    $min_travel_radius = isset($_GET['min_travel_radius']) ? absint($_GET['min_travel_radius']) : 0;

    if (!in_array($availability_day_filter, ['mon','tue','wed','thu','fri','sat','sun'], true)) {
        $availability_day_filter = '';
    }

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

    if (!empty($candidate_ids)) {
        $filtered = [];
        foreach ($candidate_ids as $pid) {
            $pid = absint($pid);
            if ($pid <= 0) continue;

            if ($available_now_filter === 1) {
                $available_now = (int) get_post_meta($pid, 'gigtune_artist_available_now', true);
                if ($available_now !== 1) continue;
            }

            if ($availability_day_filter !== '') {
                $days = get_post_meta($pid, 'gigtune_artist_availability_days', true);
                if (!is_array($days) || !in_array($availability_day_filter, $days, true)) continue;
            }

            if ($min_travel_radius > 0) {
                $radius = (int) get_post_meta($pid, 'gigtune_artist_travel_radius_km', true);
                if ($radius < $min_travel_radius) continue;
            }

            if ($base_area_filter !== '') {
                $base_area = (string) get_post_meta($pid, 'gigtune_artist_base_area', true);
                if ($base_area === '' || stripos($base_area, $base_area_filter) === false) continue;
            }

            $filtered[] = $pid;
        }
        $candidate_ids = $filtered;
    }

    $availability_filters = [
        'available_now' => $available_now_filter,
        'availability_day' => $availability_day_filter,
        'min_travel_radius' => $min_travel_radius
    ];

    ob_start(); ?>

    <h2>Artists</h2>

    <form method="get" style="margin-bottom: 20px;">
        <p>
            <label>Search (Profile name or Bio)</label><br>
            <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="e.g. 'wedding singer' or 'deep house'">
        </p>

        <fieldset style="margin-bottom: 12px;">
            <legend>Availability & Travel</legend>
            <p>
                <label>
                    <input type="checkbox" name="available_now" value="1" <?php checked($available_now_filter === 1); ?>>
                    Available now
                </label>
            </p>
            <p>
                <label>Availability day</label><br>
                <select name="availability_day">
                    <option value="">Any day</option>
                    <option value="mon" <?php selected($availability_day_filter, 'mon'); ?>>Mon</option>
                    <option value="tue" <?php selected($availability_day_filter, 'tue'); ?>>Tue</option>
                    <option value="wed" <?php selected($availability_day_filter, 'wed'); ?>>Wed</option>
                    <option value="thu" <?php selected($availability_day_filter, 'thu'); ?>>Thu</option>
                    <option value="fri" <?php selected($availability_day_filter, 'fri'); ?>>Fri</option>
                    <option value="sat" <?php selected($availability_day_filter, 'sat'); ?>>Sat</option>
                    <option value="sun" <?php selected($availability_day_filter, 'sun'); ?>>Sun</option>
                </select>
            </p>
            <p>
                <label>Base area contains</label><br>
                <input type="text" name="base_area" value="<?php echo esc_attr($base_area_filter); ?>" placeholder="e.g. Durban, Johannesburg">
            </p>
            <p>
                <label>Minimum travel radius (km)</label><br>
                <input type="number" name="min_travel_radius" min="0" step="1" value="<?php echo esc_attr($min_travel_radius); ?>">
            </p>
        </fieldset>

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

        $score = gigtune_fit_score_artist($pid, $tokens, $selected_filters, $filter_taxonomies, $term_name_map, $availability_filters);
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
            $available_now = (int) get_post_meta($post_id, 'gigtune_artist_available_now', true);
            $base_area = (string) get_post_meta($post_id, 'gigtune_artist_base_area', true);
            $travel_radius = (int) get_post_meta($post_id, 'gigtune_artist_travel_radius_km', true);
            $visibility_mode = (string) get_post_meta($post_id, 'gigtune_artist_visibility_mode', true);

            $meta_bits = [];
            if ($available_now === 1) $meta_bits[] = 'Available now';
            if ($visibility_mode !== 'hidden' && $base_area !== '') $meta_bits[] = 'Based in ' . $base_area;
            if ($travel_radius > 0) $meta_bits[] = 'Travels ' . $travel_radius . ' km';
            ?>
            <li style="margin-bottom: 14px;">
                <strong><?php echo esc_html($post_obj->post_title); ?></strong><br>
                <?php if (!empty($meta_bits)): ?>
                    <span style="color:#555;"><?php echo esc_html(implode(' | ', $meta_bits)); ?></span><br>
                <?php endif; ?>
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
 * PHASE 6A – BOOKINGS CPT + CLIENT DASHBOARD + BOOK ARTIST (SKELETON)
 * ==================================================
 */

/**
 * --------------------------------------------------
 * BOOKING SETTINGS + STATUS LABELS
 * --------------------------------------------------
 */
function gigtune_get_booking_request_expiry_hours() {
    $hours = 2;
    return max(1, (int) apply_filters('gigtune_booking_request_expiry_hours', $hours));
}

function gigtune_get_booking_dispute_window_hours() {
    $hours = 24;
    return max(1, (int) apply_filters('gigtune_booking_dispute_window_hours', $hours));
}

function gigtune_get_booking_status_labels() {
    return [
        'requested' => 'Requested',
        'accepted'  => 'Accepted',
        'escrowed'  => 'Escrowed',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled',
        'expired'   => 'Expired',
        'awaiting_client_confirmation' => 'Awaiting Client Confirmation',
        'completed' => 'Completed',
        'paid'      => 'Paid',
        'reviewed'  => 'Reviewed',
        'disputed'  => 'Disputed',
        'no_show'   => 'No-show'
    ];
}

function gigtune_get_booking_status_label($status) {
    $labels = gigtune_get_booking_status_labels();
    return $labels[$status] ?? $status;
}

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
    $request_expires_at = get_post_meta($post->ID, 'gigtune_booking_request_expires_at', true);
    $escrow_status = get_post_meta($post->ID, 'gigtune_escrow_status', true);
    $escrow_amount = get_post_meta($post->ID, 'gigtune_escrow_amount', true);
    $escrow_captured_at = get_post_meta($post->ID, 'gigtune_escrow_captured_at', true);
    $escrow_release_at = get_post_meta($post->ID, 'gigtune_escrow_release_at', true);
    $dispute_raised = get_post_meta($post->ID, 'gigtune_dispute_raised', true);
    $dispute_notes = get_post_meta($post->ID, 'gigtune_dispute_notes', true);
    $payout_released_at = get_post_meta($post->ID, 'gigtune_payout_released_at', true);

    if ($status === '') $status = 'requested';

    $statuses = gigtune_get_booking_status_labels();

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

    echo '<p><label><strong>Request Expires At</strong></label><br>';
    echo '<input type="text" name="gigtune_booking_request_expires_at" value="' . esc_attr($request_expires_at) . '" placeholder="UNIX timestamp" style="width: 260px;" />';
    echo '</p>';

    echo '<hr>';
    echo '<p><label><strong>Escrow Status</strong></label><br>';
    echo '<input type="text" name="gigtune_escrow_status" value="' . esc_attr($escrow_status) . '" placeholder="pending_capture / captured / released / held" style="width: 260px;" />';
    echo '</p>';

    echo '<p><label><strong>Escrow Amount</strong></label><br>';
    echo '<input type="number" name="gigtune_escrow_amount" step="0.01" min="0" value="' . esc_attr($escrow_amount) . '" style="width: 160px;" />';
    echo '</p>';

    echo '<p><label><strong>Escrow Captured At</strong></label><br>';
    echo '<input type="text" name="gigtune_escrow_captured_at" value="' . esc_attr($escrow_captured_at) . '" placeholder="UNIX timestamp" style="width: 260px;" />';
    echo '</p>';

    echo '<p><label><strong>Escrow Release At</strong></label><br>';
    echo '<input type="text" name="gigtune_escrow_release_at" value="' . esc_attr($escrow_release_at) . '" placeholder="UNIX timestamp" style="width: 260px;" />';
    echo '</p>';

    echo '<p><label><strong>Dispute Raised</strong></label><br>';
    echo '<select name="gigtune_dispute_raised">';
    echo '<option value="0"' . selected($dispute_raised, '0', false) . '>No</option>';
    echo '<option value="1"' . selected($dispute_raised, '1', false) . '>Yes</option>';
    echo '</select>';
    echo '</p>';

    echo '<p><label><strong>Dispute Notes</strong></label><br>';
    echo '<textarea name="gigtune_dispute_notes" rows="3" style="width:100%;">' . esc_textarea($dispute_notes) . '</textarea>';
    echo '</p>';

    echo '<p><label><strong>Payout Released At</strong></label><br>';
    echo '<input type="text" name="gigtune_payout_released_at" value="' . esc_attr($payout_released_at) . '" placeholder="UNIX timestamp" style="width: 260px;" />';
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

    $allowed = array_keys(gigtune_get_booking_status_labels());
    if (!in_array($status, $allowed, true)) $status = 'requested';

    $requested_at = isset($_POST['gigtune_booking_requested_at']) ? sanitize_text_field($_POST['gigtune_booking_requested_at']) : '';
    $responded_at = isset($_POST['gigtune_booking_responded_at']) ? sanitize_text_field($_POST['gigtune_booking_responded_at']) : '';
    $request_expires_at = isset($_POST['gigtune_booking_request_expires_at']) ? sanitize_text_field($_POST['gigtune_booking_request_expires_at']) : '';

    $escrow_status = isset($_POST['gigtune_escrow_status']) ? sanitize_text_field($_POST['gigtune_escrow_status']) : '';
    $escrow_amount = isset($_POST['gigtune_escrow_amount']) ? floatval($_POST['gigtune_escrow_amount']) : 0;
    $escrow_captured_at = isset($_POST['gigtune_escrow_captured_at']) ? sanitize_text_field($_POST['gigtune_escrow_captured_at']) : '';
    $escrow_release_at = isset($_POST['gigtune_escrow_release_at']) ? sanitize_text_field($_POST['gigtune_escrow_release_at']) : '';
    $dispute_raised = isset($_POST['gigtune_dispute_raised']) ? sanitize_text_field($_POST['gigtune_dispute_raised']) : '0';
    $dispute_notes = isset($_POST['gigtune_dispute_notes']) ? sanitize_textarea_field($_POST['gigtune_dispute_notes']) : '';
    $payout_released_at = isset($_POST['gigtune_payout_released_at']) ? sanitize_text_field($_POST['gigtune_payout_released_at']) : '';

    update_post_meta($post_id, 'gigtune_booking_client_user_id', $client_id);
    update_post_meta($post_id, 'gigtune_booking_artist_profile_id', $artist_id);
    update_post_meta($post_id, 'gigtune_booking_status', $status);

    if ($requested_at !== '') update_post_meta($post_id, 'gigtune_booking_requested_at', $requested_at);
    if ($responded_at !== '') update_post_meta($post_id, 'gigtune_booking_responded_at', $responded_at);
    if ($request_expires_at !== '') update_post_meta($post_id, 'gigtune_booking_request_expires_at', $request_expires_at);

    if ($escrow_status !== '') update_post_meta($post_id, 'gigtune_escrow_status', $escrow_status);
    update_post_meta($post_id, 'gigtune_escrow_amount', $escrow_amount);
    if ($escrow_captured_at !== '') update_post_meta($post_id, 'gigtune_escrow_captured_at', $escrow_captured_at);
    if ($escrow_release_at !== '') update_post_meta($post_id, 'gigtune_escrow_release_at', $escrow_release_at);
    update_post_meta($post_id, 'gigtune_dispute_raised', $dispute_raised === '1' ? '1' : '0');
    if ($dispute_notes !== '') update_post_meta($post_id, 'gigtune_dispute_notes', $dispute_notes);
    if ($payout_released_at !== '') update_post_meta($post_id, 'gigtune_payout_released_at', $payout_released_at);
}
add_action('save_post', 'gigtune_booking_details_metabox_save');

/**
 * --------------------------------------------------
 * ADMIN: DISPUTES PANEL
 * --------------------------------------------------
 */
function gigtune_register_disputes_admin_page() {
    add_submenu_page(
        'edit.php?post_type=gigtune_booking',
        'Disputes',
        'Disputes',
        'manage_options',
        'gigtune-disputes',
        'gigtune_render_disputes_admin_page'
    );
}
add_action('admin_menu', 'gigtune_register_disputes_admin_page');

function gigtune_render_disputes_admin_page() {

    if (!current_user_can('manage_options')) {
        echo '<div class="wrap"><h1>Disputes</h1><p>Access denied.</p></div>';
        return;
    }

    $disputes = get_posts([
        'post_type' => 'gigtune_booking',
        'post_status' => 'any',
        'numberposts' => 50,
        'meta_query' => [
            [
                'key' => 'gigtune_dispute_raised',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    echo '<div class="wrap">';
    echo '<h1>Disputes</h1>';

    if (empty($disputes)) {
        echo '<p>No disputes found.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Booking</th>';
    echo '<th>Artist</th>';
    echo '<th>Client</th>';
    echo '<th>Status</th>';
    echo '<th>Escrow</th>';
    echo '<th>Raised</th>';
    echo '<th>Notes</th>';
    echo '</tr></thead><tbody>';

    foreach ($disputes as $b) {
        $artist_id = (int) get_post_meta($b->ID, 'gigtune_booking_artist_profile_id', true);
        $client_id = (int) get_post_meta($b->ID, 'gigtune_booking_client_user_id', true);
        $status = (string) get_post_meta($b->ID, 'gigtune_booking_status', true);
        $escrow_status = (string) get_post_meta($b->ID, 'gigtune_escrow_status', true);
        $raised_at = (int) get_post_meta($b->ID, 'gigtune_dispute_raised_at', true);
        $notes = (string) get_post_meta($b->ID, 'gigtune_dispute_notes', true);

        $artist_title = $artist_id > 0 ? get_the_title($artist_id) : '';
        $client_label = $client_id > 0 ? 'Client #' . $client_id : '';

        $raised_label = $raised_at > 0 ? date_i18n('Y-m-d H:i', $raised_at) : '';

        $edit_link = admin_url('post.php?post=' . $b->ID . '&action=edit');

        echo '<tr>';
        echo '<td><a href="' . esc_url($edit_link) . '">' . esc_html($b->post_title) . '</a></td>';
        echo '<td>' . esc_html($artist_title !== '' ? $artist_title : ($artist_id > 0 ? 'Artist #' . $artist_id : '')) . '</td>';
        echo '<td>' . esc_html($client_label) . '</td>';
        echo '<td>' . esc_html(gigtune_get_booking_status_label($status)) . '</td>';
        echo '<td>' . esc_html($escrow_status !== '' ? $escrow_status : 'n/a') . '</td>';
        echo '<td>' . esc_html($raised_label !== '' ? $raised_label : 'n/a') . '</td>';
        echo '<td>' . esc_html($notes !== '' ? $notes : 'n/a') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}


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
                $dispute_raised = (string) get_post_meta($b->ID, 'gigtune_dispute_raised', true);
                $completed_at = (int) get_post_meta($b->ID, 'gigtune_booking_client_confirmed_at', true);

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
                    <td style="border-bottom:1px solid #eee;padding:8px;">
                    <?php

                    echo esc_html(gigtune_get_booking_status_label($status !== '' ? $status : 'requested'));

                    $dispute_window_open = false;
                    if ($status === 'completed' && $completed_at > 0) {
                        $window_seconds = gigtune_get_booking_dispute_window_hours() * 3600;
                        $dispute_window_open = (time() - $completed_at) <= $window_seconds;
                    }

                    if ($status === 'awaiting_client_confirmation') {

                        echo '<form method="post" style="margin-top:6px;">';
                        wp_nonce_field('gigtune_client_confirm_completion', 'gigtune_client_confirm_nonce');
                        echo '<input type="hidden" name="gigtune_confirm_booking_id" value="' . esc_attr($b->ID) . '">';
                        echo '<button type="submit" name="gigtune_client_confirm_completion">
                                Confirm Completion
                            </button>';
                        echo '</form>';

                    } elseif (($status === 'completed' || $status === 'paid') && $dispute_raised !== '1') {

                        echo gigtune_render_rating_form(
                            $b->ID,
                            (int) get_post_meta($b->ID, 'gigtune_booking_artist_profile_id', true)
                        );

                        if ($status === 'completed' && $dispute_window_open) {
                            echo '<form method="post" style="margin-top:8px;">';
                            wp_nonce_field('gigtune_client_raise_dispute', 'gigtune_client_dispute_nonce');
                            echo '<input type="hidden" name="gigtune_dispute_booking_id" value="' . esc_attr($b->ID) . '">';
                            echo '<textarea name="gigtune_dispute_notes" rows="3" placeholder="Describe the issue..." style="width:100%;"></textarea>';
                            echo '<button type="submit" name="gigtune_client_raise_dispute" style="margin-top:6px;">Raise Dispute</button>';
                            echo '</form>';
                        }

                    } elseif ($dispute_raised === '1') {
                        echo '<div style="margin-top:6px;"><em>Dispute submitted.</em></div>';
                    } elseif ($status === 'reviewed') {
                        echo '<div style="margin-top:6px;"><em>Rating submitted.</em></div>';
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
            $requested_ts = current_time('timestamp');
            update_post_meta($booking_id, 'gigtune_booking_requested_at', current_time('mysql'));
            update_post_meta($booking_id, 'gigtune_booking_request_expires_at', $requested_ts + (gigtune_get_booking_request_expiry_hours() * 3600));

            update_post_meta($booking_id, 'gigtune_escrow_status', 'pending_capture');
            update_post_meta($booking_id, 'gigtune_escrow_amount', 0);
            update_post_meta($booking_id, 'gigtune_dispute_raised', 0);

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
                $expires_at = (int) get_post_meta($booking_id, 'gigtune_booking_request_expires_at', true);
                if ($expires_at > 0 && time() > $expires_at) {
                    update_post_meta($booking_id, 'gigtune_booking_status', 'expired');
                    return '<p>This request expired before you responded.</p>';
                }

                if ($action === 'accept') {
                    update_post_meta($booking_id, 'gigtune_booking_status', 'escrowed');
                    update_post_meta($booking_id, 'gigtune_booking_responded_at', current_time('mysql'));
                    update_post_meta($booking_id, 'gigtune_escrow_status', 'captured');
                    update_post_meta($booking_id, 'gigtune_escrow_captured_at', current_time('timestamp'));

                    if (function_exists('gigtune_phase5_apply_reliability_event')) {
                        gigtune_phase5_apply_reliability_event($artist_profile_id, 'accepted');
                    }

                } elseif ($action === 'decline') {
                    update_post_meta($booking_id, 'gigtune_booking_status', 'declined');
                    update_post_meta($booking_id, 'gigtune_booking_responded_at', current_time('mysql'));
                    update_post_meta($booking_id, 'gigtune_escrow_status', 'released');

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
                $expires_at = (int) get_post_meta($r->ID, 'gigtune_booking_request_expires_at', true);
                $expires_label = $expires_at > 0 ? date_i18n('Y-m-d H:i', $expires_at) : '';
                ?>
                <li style="margin-bottom: 14px;">
                    <strong><?php echo esc_html($r->post_title); ?></strong><br>
                    <span><strong>Client ID:</strong> <?php echo esc_html($client_id); ?></span><br>
                    <span><strong>Requested:</strong> <?php echo esc_html($requested_at); ?></span><br>
                    <?php if ($expires_label !== ''): ?><span><strong>Expires:</strong> <?php echo esc_html($expires_label); ?></span><br><?php endif; ?>
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
                'value'   => ['accepted','escrowed','awaiting_client_confirmation','completed','paid','reviewed'],
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
                            <?php echo esc_html(gigtune_get_booking_status_label($status)); ?>

                            <?php if ($status === 'accepted' || $status === 'escrowed'): ?>
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

    // Must be completed or paid
    $status = get_post_meta($booking_id, 'gigtune_booking_status', true);
    if (!in_array($status, ['completed','paid'], true)) return;

    if (get_post_meta($booking_id, 'gigtune_dispute_raised', true) === '1') return;

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
    update_post_meta($booking_id, 'gigtune_reviewed_at', current_time('timestamp'));
    if ($status === 'paid') {
        update_post_meta($booking_id, 'gigtune_booking_status', 'reviewed');
    }
}
add_action('init', 'gigtune_handle_rating_submission');

/**
 * --------------------------------------------------
 * HANDLE CLIENT DISPUTE
 * --------------------------------------------------
 */
function gigtune_handle_client_raise_dispute() {

    if (!isset($_POST['gigtune_client_raise_dispute'])) return;

    if (
        !isset($_POST['gigtune_client_dispute_nonce']) ||
        !wp_verify_nonce($_POST['gigtune_client_dispute_nonce'], 'gigtune_client_raise_dispute')
    ) return;

    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('gigtune_client', $user->roles, true) && !current_user_can('manage_options')) {
        return;
    }

    $booking_id = absint($_POST['gigtune_dispute_booking_id']);
    if ($booking_id <= 0) return;

    $booking = get_post($booking_id);
    if (!$booking || $booking->post_type !== 'gigtune_booking') return;

    $client_id = (int) get_post_meta($booking_id, 'gigtune_booking_client_user_id', true);
    if ($client_id !== (int) $user->ID && !current_user_can('manage_options')) return;

    $status = (string) get_post_meta($booking_id, 'gigtune_booking_status', true);
    if ($status !== 'completed') return;

    $completed_at = (int) get_post_meta($booking_id, 'gigtune_booking_client_confirmed_at', true);
    if ($completed_at <= 0) return;

    $window_seconds = gigtune_get_booking_dispute_window_hours() * 3600;
    if ((time() - $completed_at) > $window_seconds) return;

    $notes = sanitize_textarea_field($_POST['gigtune_dispute_notes'] ?? '');

    update_post_meta($booking_id, 'gigtune_dispute_raised', 1);
    update_post_meta($booking_id, 'gigtune_dispute_notes', $notes);
    update_post_meta($booking_id, 'gigtune_dispute_raised_at', current_time('timestamp'));
    update_post_meta($booking_id, 'gigtune_escrow_status', 'held');
    update_post_meta($booking_id, 'gigtune_booking_status', 'disputed');
}
add_action('init', 'gigtune_handle_client_raise_dispute');

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
    update_post_meta($booking_id, 'gigtune_escrow_release_at', time() + (gigtune_get_booking_dispute_window_hours() * 3600));
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
            update_post_meta($b->ID, 'gigtune_escrow_release_at', time() + (gigtune_get_booking_dispute_window_hours() * 3600));
        }
    }
}
add_action('init', 'gigtune_auto_complete_after_timeout');

/**
 * --------------------------------------------------
 * AUTO-EXPIRE BOOKING REQUESTS
 * --------------------------------------------------
 */
function gigtune_auto_expire_booking_requests() {

    $now = time();

    $requests = get_posts([
        'post_type' => 'gigtune_booking',
        'post_status' => 'any',
        'numberposts' => 50,
        'meta_query' => [
            [
                'key' => 'gigtune_booking_status',
                'value' => 'requested',
                'compare' => '='
            ],
            [
                'key' => 'gigtune_booking_request_expires_at',
                'value' => $now,
                'compare' => '<=',
                'type' => 'NUMERIC'
            ]
        ]
    ]);

    foreach ($requests as $r) {
        update_post_meta($r->ID, 'gigtune_booking_status', 'expired');
        update_post_meta($r->ID, 'gigtune_booking_responded_at', current_time('mysql'));
        update_post_meta($r->ID, 'gigtune_escrow_status', 'released');
    }
}
add_action('init', 'gigtune_auto_expire_booking_requests');

/**
 * --------------------------------------------------
 * AUTO-RELEASE ESCROW AFTER DISPUTE WINDOW
 * --------------------------------------------------
 */
function gigtune_auto_release_escrow_after_dispute_window() {

    $now = time();

    $bookings = get_posts([
        'post_type' => 'gigtune_booking',
        'post_status' => 'any',
        'numberposts' => 50,
        'meta_query' => [
            [
                'key' => 'gigtune_booking_status',
                'value' => 'completed',
                'compare' => '='
            ],
            [
                'key' => 'gigtune_escrow_status',
                'value' => 'captured',
                'compare' => '='
            ],
            [
                'key' => 'gigtune_dispute_raised',
                'value' => '1',
                'compare' => '!='
            ],
            [
                'key' => 'gigtune_escrow_release_at',
                'value' => $now,
                'compare' => '<=',
                'type' => 'NUMERIC'
            ]
        ]
    ]);

    foreach ($bookings as $b) {
        $rating_submitted = (string) get_post_meta($b->ID, 'gigtune_rating_submitted', true);
        update_post_meta($b->ID, 'gigtune_escrow_status', 'released');
        update_post_meta($b->ID, 'gigtune_payout_released_at', $now);
        update_post_meta($b->ID, 'gigtune_booking_status', $rating_submitted === '1' ? 'reviewed' : 'paid');
    }
}
add_action('init', 'gigtune_auto_release_escrow_after_dispute_window');


