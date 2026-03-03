<?php
/**
 * Profile Generation Admin Page
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'wporgcd_add_profiles_menu', 15);

add_action('admin_init', 'wporgcd_handle_profile_reset');

function wporgcd_handle_profile_reset() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'contributor-profiles') {
        return;
    }
    
    if ( isset( $_POST['wporgcd_reset_state'] ) && check_admin_referer( 'wporgcd_profiles_nonce' ) ) {
        wporgcd_reset_profile_generation();
        wp_safe_redirect( admin_url( 'admin.php?page=contributor-profiles' ) );
        exit;
    }

}

function wporgcd_add_profiles_menu() {
    add_submenu_page(
        'contributor-dashboard',
        'Generate Profiles',
        'Profiles',
        'manage_options',
        'contributor-profiles',
        'wporgcd_render_profiles_page'
    );
}

function wporgcd_render_profiles_page() {
    $message = '';
    
    if (isset($_POST['wporgcd_start_profiles']) && check_admin_referer('wporgcd_profiles_nonce')) {
        // Delete all existing profiles first to ensure a clean regeneration
        wporgcd_delete_all_profiles();
        
        $result = wporgcd_start_profile_generation();
        if ($result['success']) {
            $message = '<div class="notice notice-success"><p>Profile generation started! Processing ' . number_format($result['profiles_needing_update']) . ' profiles.</p></div>';
        }
    }
    
    if (isset($_POST['wporgcd_stop_profiles']) && check_admin_referer('wporgcd_profiles_nonce')) {
        wporgcd_stop_profile_generation();
        $message = '<div class="notice notice-warning"><p>Profile generation stopped.</p></div>';
    }

    $generation_status = wporgcd_get_profile_generation_status();
    
    ?>
    <div class="wrap">
        <h1>Generate Profiles</h1>
        
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message contains safe HTML from this function
        echo $message;
        ?>
        
        <div style="margin-top: 20px;">
            <div style="background: #fff; border: 1px solid #ddd; padding: 20px; max-width: 600px;">
                
                <?php if ($generation_status['is_running']): ?>
                    <h2 style="margin-top: 0;">⏳ Generation in Progress</h2>
                    
                    <div style="background: #ddd; border-radius: 4px; height: 24px; overflow: hidden; margin: 15px 0;">
                        <div style="background: #0073aa; height: 100%; width: <?php echo esc_attr( $generation_status['progress'] ); ?>%;"></div>
                    </div>
                    
                    <p>
                        <strong><?php echo esc_html( $generation_status['progress'] ); ?>%</strong> complete
                        (<?php echo number_format($generation_status['processed']); ?> / <?php echo number_format($generation_status['total_to_process']); ?>)
                    </p>
                    
                    <p style="margin-top: 20px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=contributor-profiles')); ?>" class="button">Refresh</a>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('wporgcd_profiles_nonce'); ?>
                            <button type="submit" name="wporgcd_stop_profiles" class="button" style="color: #a00;" onclick="return confirm('Stop?')">Stop</button>
                        </form>
                    </p>
                    
                <?php elseif ($generation_status['status'] === 'completed'): ?>
                    <h2 style="margin-top: 0; color: #46b450;">Complete</h2>
                    
                    <p>Processed <strong><?php echo number_format($generation_status['processed']); ?></strong> profiles.</p>
                    
                    <form method="post">
                        <?php wp_nonce_field('wporgcd_profiles_nonce'); ?>
                        <button type="submit" name="wporgcd_reset_state" class="button button-primary">Generate Again</button>
                    </form>
                    
                <?php else: ?>
                    <h2 style="margin-top: 0;">Generate Profiles</h2>
                    
                    <p class="description">Deletes all existing profiles and regenerates them from events. Status (active/warning/inactive) is calculated based on last activity date.</p>
                    
                    <form method="post" style="margin-top: 20px;" onsubmit="return confirm('This will delete all existing profiles and regenerate them. Continue?');">
                        <?php wp_nonce_field('wporgcd_profiles_nonce'); ?>
                        <button type="submit" name="wporgcd_start_profiles" class="button button-primary">Start Generation</button>
                    </form>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
    <?php
}
