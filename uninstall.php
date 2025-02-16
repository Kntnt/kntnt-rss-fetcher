<?php
/**
 * Uninstall Kntnt RSS Fetcher (Data Only)
 *
 * Uninstalling Kntnt RSS Fetcher plugin deletes only the data related to the plugin:
 *
 * It does NOT delete the ACF Field Group definitions, the Custom Post Type definition,
 * the Custom Taxonomy definition, or the Options Page definition itself. These structures
 * will remain in your WordPress installation.
 *
 * @package Kntnt\RSSFetcher
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete options
$options = wp_load_alloptions();
foreach ( $options as $option => $value ) {
	if ( str_starts_with( $option, 'options_kntnt_rss_' ) || str_starts_with( $option, '_options_kntnt_rss_' ) ) {
		delete_option( $option );
	}
}

// Delete posts
$posts = get_posts( [
	                    'post_type' => 'kntnt-rss-item',
	                    'numberposts' => - 1,
	                    'post_status' => 'any',
                    ] );
foreach ( $posts as $post ) {
	wp_delete_post( $post->ID, true );
}

// Delete terms
$terms = get_terms([
	                   'taxonomy' => 'kntnt-rss-tag',
	                   'hide_empty' => false
                   ]);
if (!is_wp_error($terms)) {
	foreach ($terms as $term) {
		wp_delete_term($term->term_id, 'kntnt-rss-tag');
	}
}

// Log completion of uninstall process (optional, for debugging or audit).
error_log( 'Kntnt RSS Fetcher Uninstall (Data Only): Plugin data deleted, definitions kept.' );