<?php
/**
 * Plugin Name:       Kntnt RSS Fetcher
 * Description:       Fetches and displays content from multiple RSS feeds, with flexible configuration.
 * Version:           0.1.2
 * Tags:              rss, feed, aggregator, content
 * Plugin URI:        https://github.com/Kntnt/kntnt-rss-fetcher
 * Tested up to: 6.7
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Requires Plugins:  advanced-custom-fields-pro
 * Author:            TBarregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-3.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Kntnt\RSSFetcher;

defined( 'ABSPATH' ) && new Plugin;

final class Plugin {

	public function __construct() {

		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

		add_action( 'acf/save_post', [ $this, 'save_options_page' ] );
		add_filter( 'cron_schedules', [ $this, 'cron_schedule' ] );
		add_action( 'kntnt_rss_fetch', [ $this, 'fetch_rss' ] );

		self::log( '[INFO] Hooks registered' );

	}

	private static function log( $message = '', ...$args ): void {
		if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) && defined( 'KNTNT_RSS_FETCHER_DEBUG' ) && constant( 'KNTNT_RSS_FETCHER_DEBUG' ) ) {
			if ( ! is_string( $message ) ) {
				if ( is_scalar( $message ) ) {
					$args = [ $message ];
				}
				$message = '%s';
			}
			$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
			$caller = $caller[1]['class'] . '->' . $caller[1]['function'] . '()';
			foreach ( $args as &$arg ) {
				if ( is_array( $arg ) || is_object( $arg ) ) {
					$arg = print_r( $arg, true );
				}
			}
			$message = sprintf( $message, ...$args );
			error_log( "$caller: $message" );
		}
	}

	/**
	 * Register custom cron interval on plugin activation.
	 */
	public function activate(): void {

		// If the plugin is activated after it was previously deactivated in a
		// way that doesn't trigger the deactivation hook, e.g. by moving it
		//out of the plugin directory, delete old schedules.
		$this->deschedule_cron();

		$this->schedule_cron();

		self::log( "[INFO] Activated" );

	}

	/**
	 * Clear this plugin's cron schedule.
	 */
	private function deschedule_cron(): void {
		wp_clear_scheduled_hook( 'kntnt_rss_fetch' );
		self::log( '[INFO] Cleared kntnt_rss_fetch cron schedule.' );
	}

	/**
	 * Add plugin's cron schedule.
	 */
	private function schedule_cron(): void {

		$scheduled = wp_schedule_event( time(), 'kntnt_rss_interval', 'kntnt_rss_fetch' );

		if ( $scheduled ) {
			self::log( '[INFO] Scheduled kntnt_rss_fetch as a recurring event.' );
			return;
		}

		if ( is_wp_error( $scheduled ) ) {
			self::log( sprintf( '[ERROR] Failed to schedule kntnt_rss_fetch: %s', $scheduled->get_error_message() ) );
		}
		else {
			self::log( '[ERROR] Failed to schedule kntnt_rss_fetch event' );
		}

	}

	/**
	 * Deactivate hook to clear cron schedule.
	 */
	public function deactivate(): void {
		$this->deschedule_cron();
		self::log( "[INFO] Deactivated" );
	}

	/**
	 * Action to run when the options page is saved.
	 */
	public function save_options_page( $post_id ): void {

		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'kntnt-rss-settings' ) {
			return;
		}

		$this->deschedule_cron();
		$this->fetch_rss();
		$this->schedule_cron();

		self::log( '[INFO] Fetched all RSS feeds and rescheduled future fetches.' );

	}

	/**
	 * Fetch RSS feed items and create/update posts.
	 */
	public function fetch_rss() {

		// Get all feeds
		if ( empty( $feed_configs = $this->feed_configs() ) ) {
			self::log( '[WARNING] No feeds configured.' );
			return;
		}

		// Process each feed
		foreach ( $feed_configs as $feed_config ) {

			$url = $feed_config['url'];
			$author_id = $feed_config['author_id'];
			$max_items = $feed_config['max_items'];
			$tags = $feed_config['tags'];
			$rss_id_table = $this->rss_id_table( $feed_config['url'] );

			self::log( 'Fetching feed from URL: %s', $url );
			$feed = fetch_feed( $url );
			if ( is_wp_error( $feed ) ) {
				$error_message = $feed->get_error_message();
				self::log( '[WARNING] Failed to fetch feed from URL: %s', $url );
				continue;
			}

			$feed_items = $feed->get_items( 0, $max_items );
			if ( empty( $feed_items ) ) {
				self::log( '[WARNING] No items found in feed URL: %s', $url );
				continue;
			}

			// Create post of each item
			self::log( '[INFO] Processing %s items from %s', count( $feed_items ), $url );
			foreach ( $feed_items as $item ) {

				$item_id = $item->get_id();
				if ( isset( $rss_id_table[ $item_id ] ) ) {
					self::log( '[DEBUG] Skipping RSS item "%s" as it already exists as post %s', $item_id, $rss_id_table[ $item_id ] );
					return null;
				}

				$item_data = $this->item_data( $item, $item_id );

				$post_id = $this->create_post( $item_data['post_title'], $item_data['post_date'], $item_data['post_excerpt'], $item_data['post_content'], $author_id, $item_data['thumbnail_url'], $tags, $item_id, $item_data['item_link'], $url );

				$rss_id_table[ $item_id ] = $post_id;

			}

			// Keep not more posts of the feed than $max_items
			if ( count( $rss_id_table ) > $max_items ) {
				$this->prune_items( $rss_id_table, $max_items );
			}

		}

	}

	private function feed_configs(): array {
		self::log( '[INFO] Build a list of all feeds to process.' );
		$feeds = [];
		while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			the_row();
			if ( $url = get_sub_field( 'url' ) ) {
				$feeds[] = [
					'url' => $url,
					'author_id' => $this->get_sub_field( 'author', 'get_current_user_id' ),
					'max_items' => $this->get_sub_field( 'max_items', fn() => 10 ),
					'poll_interval' => $this->get_sub_field( 'poll_interval', fn() => 60 ),
					'tags' => $this->get_sub_field( 'tag', fn() => [] ),
				];
			}
			else {
				self::log( '[ERROR] No value found for field "url"' );
			}
		}
		return $feeds;
	}

	private function get_sub_field( $selector, callable $default ) {
		$value = get_sub_field( $selector );
		if ( ! $value ) {
			$value = $default();
			self::log( '[WARNING] No value found for field "%s", using default value: %s', $selector, $default );
		}
		return $value;
	}

	/**
	 * Build a hash table of existing RSS items for faster lookup.
	 */
	private function rss_id_table( $feed_url ): array {

		$args = [
			'post_type' => 'kntnt-rss-item',
			'posts_per_page' => - 1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => 'kntnt_rss_item_id', // Ensures get_post_meta() below returs a value for 'kntnt_rss_item_id'
				],
				[
					'key' => 'kntnt_rss_item_feed',
					'value' => $feed_url,
					'compare' => '=',
				],
			],
		];
		$rss_post_ids = get_posts( $args );

		$table = [];
		foreach ( $rss_post_ids as $post_id ) {
			$rss_id = get_post_meta( $post_id, 'kntnt_rss_item_id', true );
			$table[ $rss_id ] = $post_id;
		}

		self::log( 'Found %s existing posts for the feed %s.', count( $rss_post_ids ), $feed_url );

		return $table;

	}

	private function item_data( \SimplePie\Item $item, $item_id ): ?array {

		$post_date = $item->get_date( 'Y-m-d H:i:s' );
		if ( ! $post_date ) {
			$post_date = current_time( 'Y-m-d H:i:s' );
			self::log( '[WARNING] No publishing date found, using curren time for item ID: %s', $item_id );
		}

		$post_excerpt = $item->get_description( true );
		$post_content = $item->get_content( true );
		if ( ! $post_excerpt && $post_content ) {
			$post_excerpt = $post_content;
			self::log( '[INFO] No description found, using content for item ID: %s', $item_id );
		}
		elseif ( $post_excerpt && ! $post_content ) {
			$content_encoded = $post_excerpt;
			self::log( '[INFO] No content found, using description for item ID: %s', $item_id );
		}
		elseif ( ! $post_excerpt && ! $post_content ) {
			self::log( '[WARNING] No description or content found for item ID: %s', $item_id );
		}

		$post_title = $item->get_title();
		if ( ! $post_excerpt ) {
			self::log( '[WARNING] No title found for item ID: %s', $item_id );
		}
		else {
			$this->generate_title_from_description( $post_excerpt );
			self::log( '[WARNING] No title found, generated title from description for item ID: %s', $item_id );
		}

		$item_link = $item->get_link();
		if ( ! $item_link ) {
			self::log( '[WARNING] No link found for item ID: %s', $item_id );
		}

		$thumbnail_url = $this->get_feed_item_image( $item );
		if ( empty( $thumbnail_url ) ) {
			self::log( '[INFO] No thumbnail found for item: %s', $item->get_title() );
		}
		else {
			self::log( '[DEBUG] Thumbnail URL found: %s', $thumbnail_url );
		}

		return [
			'post_title' => $post_title,
			'post_date' => $post_date,
			'post_excerpt' => $post_excerpt,
			'post_content' => $post_content,
			'item_link' => $item_link,
			'thumbnail_url' => $thumbnail_url,
		];

	}

	private function generate_title_from_description( string $title ): string {
		if ( strlen( $title ) > 50 ) {
			$words = explode( ' ', $title );
			while ( strlen( $title = implode( ' ', $words ) ) > 50 ) {
				array_pop( $words );
			}
			$title = "{$title}…";
		}
		return $title;
	}

	private function get_feed_item_image( $item ) {

		// Try thumbnail first - already sanitized by SimplePie
		$thumbnail = $item->get_thumbnail();
		if ( ! empty( $thumbnail['url'] ) ) {
			return $thumbnail['url'];
		}

		// Check enclosures - already sanitized by SimplePie
		$enclosures = $item->get_enclosures();
		foreach ( $enclosures as $enclosure ) {
			if ( strpos( $enclosure->get_type(), 'image' ) === 0 ) {
				return $enclosure->get_link();
			}
		}

		// Parse content as last resort - needs sanitization
		$content = $item->get_content();
		if ( preg_match( '/<img.+?src=[\'"]([^\'"]+)[\'"].*?>/i', $content, $matches ) ) {
			return esc_url( $matches[1] );
		}

		return false;

	}

	private function create_post( $post_title, $post_date, $post_excerpt, $post_content, $author_id, $thumbnail_url, $tags, $item_id, $item_link, $url ) {

		$post_id = wp_insert_post( [
			                           'post_title' => $post_title,
			                           'post_date' => $post_date,
			                           'post_excerpt' => $post_excerpt,
			                           'post_content' => $post_content,
			                           'post_status' => 'published',
			                           'post_author' => $author_id,
		                           ] );
		if ( is_wp_error( $post_id ) ) {
			self::log( "[ERROR] Inserting post failed for item ID: %s\nError message: %s\n", $item_id, $post_id->get_error_message() );
			return null;
		}
		else {
			self::log( "[INFO] Created post id %s from item ID %s", $post_id, $item_id );
		}

		if ( update_post_meta( $post_id, 'kntnt_rss_item_feed', $url ) ) {
			self::log( '[DEBUG] Update post %s with RSS feed URL: %s', $post_id, $url );
		}
		else {
			self::log( '[ERROR] Failed to update post %s with RSS feed URL: %s', $post_id, $url );
		}

		if ( update_post_meta( $post_id, 'kntnt_rss_item_id', $item_id ) ) {
			self::log( '[DEBUG] Update post %s with RSS item ID: %s', $post_id, $item_id );
		}
		else {
			self::log( '[ERROR] Failed to update post %s with RSS item ID: %s', $post_id, $item_id );
		}

		if ( $item_link ) {
			if ( update_post_meta( $post_id, 'kntnt_rss_item_link', $item_link ) ) {
				self::log( '[DEBUG] Update post %s with RSS item link: %s', $post_id, $item_link );
			}
			else {
				self::log( '[ERROR] Failed to update post %s with RSS item link: %s', $post_id, $item_link );
			}
		}

		if ( ! empty( $tags ) && is_array( $tags ) ) {
			$tids = wp_set_object_terms( $post_id, $tags, 'kntnt-rss-tag' );
			if ( is_wp_error( $tids ) ) {
				self::log( '[ERROR] Failed to update post %s with tags: %s', $post_id, $tags );
			}
		}

		if ( $thumbnail_url ) {
			self::log( 'Uploading thumbnail from %s to post ID: %s', $thumbnail_url, $post_id );
			$thumbnail_id = $this->upload_image( $thumbnail_url, $post_id );
			if ( $thumbnail_id && ! is_wp_error( $thumbnail_id ) ) {
				if ( set_post_thumbnail( $post_id, $thumbnail_id ) ) {
					self::log( '[DEBUG] Thumbnail set successfully, thumbnail ID: %s for post ID: %s', $thumbnail_id, $post_id );
				}
				else {
					self::log( '[ERROR] Could not upate post %s with thumbnail ID: %s', $post_id, $thumbnail_id );
				}
			}
		}

	}

	/**
	 * Handle image upload from URL and attach to post.
	 */
	private function upload_image( $image_url, $post_id ): string|bool {

		self::log( 'Downloading image from URL: %s', $image_url );
		$tmp_file = download_url( $image_url );
		if ( is_wp_error( $tmp_file ) ) {
			self::log( '[ERROR] Downloading image failed: %s', $tmp_file->get_error_message() );
			return false;
		}
		self::log( '[DEBUG] Image downloaded to temp file: %s', $tmp_file );

		// Get filename from URL path
		$file_name = basename( parse_url( $image_url, PHP_URL_PATH ) );

		self::log( '[DEBUG] Sideloading media: %s', $file_name );
		$file_array = [];
		$file_array['tmp_name'] = $tmp_file;
		$file_array['name'] = $file_name;
		$attachment_id = media_handle_sideload( $file_array, $post_id, null );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			self::log( '[ERROR] Media sideloading failed: %s', $attachment_id->get_error_message() );
			return false;
		}
		self::log( '[INFO] Media sideloaded successfully, attachment ID: %s', $attachment_id );

		return $attachment_id;

	}

	private function prune_items( array $rss_id_table, int $max_items ) {
		asort( $rss_id_table, SORT_NUMERIC ); // Sort by post id (creation order)
		$prune_ids = array_slice( $rss_id_table, 0, count( $rss_id_table ) - $max_items );
		foreach ( $prune_ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				self::log( '[DEBUG] Deleted post ID:', $id );
			}
			else {
				self::log( '[ERROR] Failed to delete post ID:', $id );
			}
		}
	}

	/**
	 * Add custom cron schedule based on ACF option.
	 */
	public function cron_schedule( $schedules ): array {
		$interval = $this->get_min_interval();
		$schedules['kntnt_rss_interval'] = [
			'interval' => $interval,
			'display' => 'Kntnt RSS Fetcher Poll Interval',
		];
		self::log( 'Added pol interval schedule kntnt_rss_interval: %s minutes', $interval );
		return $schedules;
	}

	/**
	 * Get the minimum poll interval from all feeds.
	 */
	private function get_min_interval(): int {
		$min_interval = null;
		while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			the_row();
			$interval = (int) get_sub_field( 'poll_interval' ) * 60; // In seconds
			self::log( '[DEBUG] Poll interval of %s: %s minutes', get_sub_field( 'url' ), $interval );
			if ( $interval ) {
				if ( ! $min_interval || $interval < $min_interval ) {
					$min_interval = $interval;
				}
			}
		}
		if ( ! $min_interval ) {
			self::log( '[WARNING] No poll interval found. Defaults to hourly.' );
			$min_interval = HOUR_IN_SECONDS;
		}
		self::log( '[INFO] Poll interval is %s minutes.', $min_interval / 60 );
		return $min_interval;
	}

}