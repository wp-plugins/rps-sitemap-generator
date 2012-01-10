<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Clear out _rps_sitemap_options from all blogs on multisite
// (or just the single blog if not on multisite).
if ( is_multisite() ) {
    global $wpdb;
    $blogs = $wpdb->get_results( "SELECT blog_id FROM {$wpdb->blogs}", ARRAY_A );
    if ( $blogs ) {
        foreach( $blogs as $blog ) {
            switch_to_blog( $blog['blog_id'] );
            delete_option('_rps_sitemap_options');
        }
        restore_current_blog();
    }
} else {
    delete_option('_rps_sitemap_options');
}

?>