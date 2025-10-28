<?php
/**
 * @package Kntnt\RSSFetcher
 * Plugin Name:       Kntnt RSS Fetcher
 * Description:       Fetches and displays content from multiple RSS feeds, with flexible configuration.
 * Version:           0.1.3
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

defined( 'WPINC' ) || exit;

use SimplePie\Item;

enum Level: int {

	/**
	 * Define KNTNT_RSS_FETCHER_DEBUG as Kntnt\RSSFetcher\ERROR to allow
	 * error messages but nothing more to be logged if WP_DEBUG is true.
	 */
	case ERROR = 0;

	/**
	 * Define KNTNT_RSS_FETCHER_DEBUG as Kntnt\RSSFetcher\WARNING to allow
	 * warning messages and error messages but nothing more to be logged
	 * if WP_DEBUG is true.
	 */
	case WARNING = 1;

	/**
	 * Define KNTNT_RSS_FETCHER_DEBUG to Kntnt\RSSFetcher\ERROR to allow
	 * info messages, warning messages and error messages but nothing more
	 * to be logged if WP_DEBUG is true.
	 */
	case INFO = 2;

	/**
	 * Set Define KNTNT_RSS_FETCHER_DEBUG to Kntnt\RSSFetcher\DEBUG to allow
	 * all messages to be logged if WP_DEBUG is true.
	 */
	case DEBUG = 3;

}

final class Plugin {

	/**
	 * The constructor adds hooks. The whole plugin is driven by these hooks.
	 */
	public function __construct() {

		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
		register_uninstall_hook( __FILE__, 'uninstall' );

		add_action( 'acf/save_post', [ $this, 'save_options_page' ] );
		add_filter( 'cron_schedules', [ $this, 'cron_schedule' ] );
		add_action( 'kntnt_rss_fetch', [ $this, 'fetch_rss' ] );
		add_action( 'pre_get_posts', [ $this, 'hide_rss_images' ] );
		add_action( 'trashed_post', [ $this, 'skip_trash' ] );
		add_action( 'before_delete_post', [ $this, 'delete_image_with_post' ] );

		self::log( Level::INFO, 'Hooks registered' );

	}

	/**
	 * Register custom cron interval on plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void {

		// If the plugin is activated after it was previously deactivated in a
		// way that doesn't trigger the deactivation hook, e.g. by moving it
		//out of the plugin directory, delete old schedules.
		$this->deschedule_cron();

		$this->schedule_cron();

		self::log( Level::INFO, "Activated" );

	}

	/**
	 * Deactivate hook to clear cron schedule.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->deschedule_cron();
		self::log( Level::INFO, "Deactivated" );
	}

	/**
	 * Delete options, posts, terms and images on uninstallation.
	 *
	 * @return void
	 */
	public function uninstall(): void {

		// Delete options
		$options = wp_load_alloptions();
		foreach ( $options as $option => $value ) {
			if ( str_starts_with( $option, 'options_kntnt_rss_' ) || str_starts_with( $option, '_options_kntnt_rss_' ) ) {
				delete_option( $option );
			}
		}

		// Delete posts and images by delete_image_with_post()
		$posts = get_posts( [
			                    'post_type' => 'kntnt-rss-item',
			                    'numberposts' => - 1,
			                    'post_status' => 'any',
		                    ] );
		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}

		// Delete terms
		$terms = get_terms( [
			                    'taxonomy' => 'kntnt-rss-tag',
			                    'hide_empty' => false,
		                    ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				wp_delete_term( $term->term_id, 'kntnt-rss-tag' );
			}
		}

		// Log completion of uninstall process (optional
		self::log( Level::INFO, 'Uninstalled kntnt_rss_fetch' );

	}

	/**
	 * Adds a custom cron schedule for RSS polling.
	 *
	 * Adds the schedule 'kntnt_rss_interval' to WordPress cron schedules.
	 * The interval is determined by the shortest poll interval among all feeds.
	 *
	 * @param array $schedules Array of existing cron schedules.
	 *
	 * @return array Modified array of cron schedules with kntnt_rss_interval added.
	 */
	public function cron_schedule( array $schedules ): array {
		$interval = $this->get_min_interval();
		$schedules['kntnt_rss_interval'] = [
			'interval' => $interval,
			'display' => 'Kntnt RSS Fetcher Poll Interval',
		];
		self::log( Level::DEBUG, 'Added poll interval schedule kntnt_rss_interval: %s minutes', $interval );
		return $schedules;
	}

	/**
	 * Fetch RSS feed items and create/update posts.
	 *
	 * @return void
	 */
	public function fetch_rss(): void {

		// Ensure cron is scheduled (robustness check)
		if ( ! wp_next_scheduled( 'kntnt_rss_fetch' ) ) {
			self::log( Level::WARNING, 'Cron job was not scheduled. Rescheduling now.' );
			$this->schedule_cron();
		}

		// Get all feeds
		if ( empty( $feed_configs = $this->feed_configs() ) ) {
			self::log( Level::WARNING, 'No feeds configured.' );
			return;
		}

		// Process each feed
		foreach ( $feed_configs as $feed_config ) {

			$url = $feed_config['url'];
			$author_id = $feed_config['author_id'];
			$max_items = $feed_config['max_items'];
			$tags = $feed_config['tags'];
			$rss_id_table = $this->rss_id_table( $feed_config['url'] );

			self::log( Level::INFO, 'Fetching feed from URL: %s', $url );
			$feed = fetch_feed( $url );
			if ( is_wp_error( $feed ) ) {
				$error_message = $feed->get_error_message();
				self::log( Level::WARNING, 'Failed to fetch feed from %s: %s', $url, $error_message );
				continue;
			}

			$feed_items = $feed->get_items( 0, $max_items );
			if ( empty( $feed_items ) ) {
				self::log( Level::WARNING, 'No items found in feed URL: %s', $url );
				continue;
			}

			// Create post of each item
			foreach ( $feed_items as $item ) {

				$item_id = $item->get_id();
				if ( isset( $rss_id_table[ $item_id ] ) ) {
					self::log( Level::DEBUG, 'Skipping RSS item "%s" as it already exists as post %s', $item_id, $rss_id_table[ $item_id ] );
					continue;
				}

				self::log( Level::INFO, 'Processing RSS item %s.', $item_id );
				$item_data = $this->item_data( $item );
				if ( $post_id = $this->create_post( $item_data, $author_id ) ) {
					$this->add_metadata_to_post( $post_id, $item_id, $item_data['item_link'], $url );
					$this->add_tags_to_post( $post_id, $tags );
					if ( isset( $item_data['thumbnail_url'] ) ) {
						$this->add_thumbnail_to_post( $post_id, $item_data['thumbnail_url'] );
					}
					else {
						self::log( Level::DEBUG, 'No image found' );
					}
					$rss_id_table[ $item_id ] = $post_id;
				}

			}

			// Keep not more posts of the feed than $max_items
			if ( count( $rss_id_table ) > $max_items ) {
				$this->prune_items( $rss_id_table, $max_items );
			}

		}

	}

	/**
	 * Action to run when the options page is saved.
	 *
	 * @return void
	 */
	public function save_options_page(): void {

		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'kntnt-rss-settings' ) {
			return;
		}

		$this->set_default_author();
		$this->reschedule_cron();

	}

	/**
	 * Filters the WordPress attachment query to hide attachments with
	 * the '_kntnt_rss_item_post_id' meta key in the admin area.
	 *
	 * This function is hooked to the 'pre_get_posts' action and modifies the
	 * query object to exclude attachments that have the meta key
	 * '_kntnt_rss_item_post_id'. This effectively hides these specific
	 * attachments from being displayed in the Media Library, block editor,
	 * and other admin areas where attachments are queried.
	 *
	 * @param \WP_Query $query The WP_Query instance (passed by reference).
	 *                         It is modified in place to filter attachments.
	 *
	 * @return void
	 */
	public function hide_rss_images( $query ): void {
		if ( is_admin() && $query->get( 'post_type' ) === 'attachment' ) {
			$meta_query = [
				[
					'key' => '_kntnt_rss_item_post_id',
					'compare' => 'NOT EXISTS',
				],
			];
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Skip trash for RSS items.
	 *
	 * @param int $post_id Post id
	 *
	 * @return void
	 */
	public function skip_trash( int $post_id ): void {
		if ( 'kntnt-rss-item' === get_post_type( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Raderar utvald bild och dess varianter när en kntnt-rss-item post raderas.
	 *
	 * @param int $post_id ID för posten som raderas.
	 *
	 * @return void
	 */
	public function delete_image_with_post( int $post_id ): void {

		if ( get_post_type( $post_id ) !== 'kntnt-rss-item' ) {
			return;
		}

		if ( $thumbnail_id = get_post_thumbnail_id( $post_id ) ) {
			$deleted = wp_delete_attachment( $thumbnail_id, true );
			if ( $deleted ) {
				self::log( Level::DEBUG, 'Succeed to deleted featured image (attachment ID: %s) for post ID: %s', $thumbnail_id, $post_id );
			}
			else {
				self::log( Level::DEBUG, 'Failed to deleted featured image (attachment ID: %s) for post ID: %s', $thumbnail_id, $post_id );
			}
		}
		else {
			self::log( Level::DEBUG, 'No featured image to be deleted for record ID: %s', $post_id );
		}

	}

	/**
	 * If `$message` isn't a string, its value is printed. If `$message` is
	 * a string, it is written with each occurrence of '%s' replaced with
	 * the value of the corresponding additional argument converted to string.
	 * Any percent sign that should be written must be escaped with another
	 * percent sign, that is `%%`.
	 *
	 * @param Level $level   Log level
	 * @param mixed $message [Optional] String with %s where to print remaining arguments, or a single scalar, array, or object.
	 * @param       ...$args [Optional] Scalars, arrays, and objects to replace %s in message with
	 *
	 * @return void
	 */
	private static function log( Level $level, mixed $message = '', ...$args ): void {

		// Skip if debugging is disabled
		if ( ! defined( 'WP_DEBUG' ) || ! constant( 'WP_DEBUG' ) ) {
			return;
		}

		// Skip if either:
		// - it's not an ERROR message and KNTNT_RSS_FETCHER_DEBUG is not defined
		// - message level is higher than KNTNT_RSS_FETCHER_DEBUG
		if ( $level !== Level::ERROR && ( ! defined( 'KNTNT_RSS_FETCHER_DEBUG' ) || $level > constant( 'KNTNT_RSS_FETCHER_DEBUG' ) ) ) {
			return;
		}

		// Handle the case no message is given, but just
		if ( ! is_string( $message ) ) {
			if ( is_scalar( $message ) ) {
				$args = [ $message ];
			}
			$message = '%s';
		}

		// Stringify the arguments
		foreach ( $args as &$arg ) {
			if ( is_array( $arg ) || is_object( $arg ) ) {
				$arg = print_r( $arg, true );
			}
		}

		// Get the caller path
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
		$caller = $caller[1]['class'] . '->' . $caller[1]['function'] . '()';

		// Output the log message
		$message = sprintf( $message, ...$args );
		error_log( "$caller: $message" );

	}

	/**
	 *  Add plugin's cron schedule.
	 *
	 * @return void
	 */
	private function schedule_cron(): void {

		$scheduled = wp_schedule_event( time(), 'kntnt_rss_interval', 'kntnt_rss_fetch' );

		if ( $scheduled ) {
			self::log( Level::INFO, 'Scheduled kntnt_rss_fetch as a recurring event.' );
			return;
		}

		if ( is_wp_error( $scheduled ) ) {
			self::log( Level::ERROR, sprintf( 'Failed to schedule kntnt_rss_fetch: %s', $scheduled->get_error_message() ) );
		}
		else {
			self::log( Level::ERROR, 'Failed to schedule kntnt_rss_fetch event' );
		}

	}

	/**
	 * Reschedules the RSS feed fetching cron job.
	 *
	 * Removes existing cron schedule, executes an immediate feed fetch,
	 * and schedules the next cron run. This ensures feeds are current
	 * after any configuration changes.
	 *
	 * @return void
	 */
	private function reschedule_cron(): void {

		$this->deschedule_cron();
		$this->fetch_rss();
		$this->schedule_cron();

		self::log( Level::INFO, 'Fetched all RSS feeds and rescheduled future fetches.' );

	}

	/**
	 *  Clear this plugin's cron schedule.
	 *
	 * @return void
	 */
	private function deschedule_cron(): void {
		wp_clear_scheduled_hook( 'kntnt_rss_fetch' );
		self::log( Level::INFO, 'Cleared kntnt_rss_fetch cron schedule.' );
	}

	/**
	 * Gets the shortest poll interval among all configured feeds.
	 *
	 * Reads poll_interval from ACF repeater field 'kntnt_rss_feeds' and returns
	 * the minimum interval in seconds. If no interval is configured, defaults to
	 * HOUR_IN_SECONDS (3600 seconds).
	 *
	 * @return int Minimum poll interval in seconds.
	 */
	private function get_min_interval(): int {
		$min_interval = null;
		while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			the_row();
			$interval = (int) get_sub_field( 'poll_interval' ) * 60; // In seconds
			self::log( Level::DEBUG, 'Poll interval of %s: %s minutes', get_sub_field( 'url' ), $interval );
			if ( $interval ) {
				if ( ! $min_interval || $interval < $min_interval ) {
					$min_interval = $interval;
				}
			}
		}
		if ( ! $min_interval ) {
			self::log( Level::WARNING, 'No poll interval found. Defaults to hourly.' );
			$min_interval = HOUR_IN_SECONDS;
		}
		self::log( Level::INFO, 'Poll interval is %s minutes.', $min_interval / 60 );
		return $min_interval;
	}

	/**
	 * Retrieves all RSS feed configurations.
	 *
	 * Each feed configuration contains following elements:
	 * - url:           The URL of the feed.
	 * - author_id:     User ID to be assigned as author of imported posts. Defaults to 0 (no author).
	 * - max_items:     Maximum number of items to import and retain. Defaults to 10.
	 * - poll_interval: Minimum minutes between feed content polls. Defaults to 60.
	 * - tags:          Array of term IDs to assign to imported posts. Defaults to empty array.
	 *
	 * @return array<int, array{
	 *     url: string,
	 *     author_id: int,
	 *     max_items: int,
	 *     poll_interval: int,
	 *     tags: int[]
	 * }>
	 */
	private function feed_configs(): array {
		self::log( Level::INFO, 'Build a list of all feeds to process.' );
		$feeds = [];
		while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			the_row();
			if ( $url = get_sub_field( 'url' ) ) {
				$feeds[] = [
					'url' => $url,
					'author_id' => $this->get_sub_field( 'author', 0 ),
					'max_items' => $this->get_sub_field( 'max_items', 10 ),
					'poll_interval' => $this->get_sub_field( 'poll_interval', 60 ),
					'tags' => $this->get_sub_field( 'tag', [] ),
				];
			}
			else {
				self::log( Level::ERROR, 'No value found for field "url"' );
			}
		}
		return $feeds;
	}

	/**
	 * Retrieves a value from an ACF sub-field with fallback to default.
	 *
	 * Gets a value from a sub-field in an ACF repeater field. If no value is found
	 * or the value is empty, returns the provided default value. Supports both
	 * integer and array return types.
	 *
	 * @param string    $selector Field selector/name in the ACF repeater.
	 * @param int|array $default  Default value to return if field is empty.
	 *
	 * @return int|array Value from the ACF field or the default value.
	 */
	private function get_sub_field( string $selector, int|array $default ): int|array {
		$value = get_sub_field( $selector );
		if ( ! $value ) {
			$value = $default;
			self::log( Level::WARNING, 'No value found for field "%s", using default value: %s', $selector, $default );
		}
		return $value;
	}

	/**
	 * Extracts and processes data from a feed item for post creation.
	 *
	 * The returned array contains following elements:
	 * - post_title:    Post title from feed, generated from post_excerpt if missing.
	 * - post_date:     Date in Y-m-d H:i:s format, defaults to current time.
	 * - post_excerpt:  Post excerpt from feed description or content.
	 * - post_content:  Post content from feed content or description.
	 * - item_link:     URL to original feed item.
	 * - thumbnail_url: URL to item's featured image, null if invalid or missing.
	 *
	 * @param Item $item Feed item to process.
	 *
	 * @return array{
	 *     post_title: ?string,
	 *     post_date: string,
	 *     post_excerpt: ?string,
	 *     post_content: ?string,
	 *     item_link: ?string,
	 *     thumbnail_url: ?string
	 * } Processed feed item data ready for post creation.
	 */
	private function item_data( Item $item ): array {

		$post_date = $item->get_date( 'Y-m-d H:i:s' );
		if ( ! $post_date ) {
			$post_date = current_time( 'Y-m-d H:i:s' );
			self::log( Level::WARNING, 'No publishing date found, using curren time.' );
		}

		$post_excerpt = $item->get_description( true );
		$post_content = $item->get_content( true );
		if ( ! $post_excerpt && $post_content ) {
			$post_excerpt = $post_content;
			self::log( Level::INFO, 'No description found, using content.' );
		}
		elseif ( $post_excerpt && ! $post_content ) {
			$post_content = $post_excerpt;
			self::log( Level::INFO, 'No content found, using description.' );
		}
		elseif ( ! $post_excerpt && ! $post_content ) {
			self::log( Level::WARNING, 'No description or content found.' );
		}
		$post_excerpt = wp_strip_all_tags( $post_excerpt, true ); // Remove any HTML

		$post_title = $item->get_title();
		if ( ! $post_title ) {
			if ( $post_excerpt ) {
				$post_title = $this->generate_title_from_description( $post_excerpt );
				self::log( Level::WARNING, 'No title found, generated title from description.' );
			}
			else {
				self::log( Level::WARNING, 'No title found.' );
			}
		}

		$item_link = $item->get_link();
		if ( ! $item_link ) {
			self::log( Level::WARNING, 'No link found.' );
		}

		$thumbnail_url = $this->get_feed_item_image( $item );
		if ( empty( $thumbnail_url ) ) {
			self::log( Level::INFO, 'No thumbnail found for item: %s', $item->get_title() );
		}
		elseif ( ! filter_var( $thumbnail_url, FILTER_VALIDATE_URL ) ) {
			$thumbnail_url = null;
			self::log( Level::WARNING, 'Invalid thumbnail URL: %s', $thumbnail_url );
		}
		else {
			self::log( Level::DEBUG, 'Thumbnail URL found: %s', $thumbnail_url );
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

	/**
	 * Truncates a long text to create a title, ensuring it doesn't exceed 50 characters,
	 * breaking only at word boundaries. If truncated, adds an ellipsis to the end.
	 *
	 * @param string $title Text to be truncated into a title.
	 *
	 * @return string Truncated text with ellipsis if shortened, original text if under limit.
	 */
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

	/**
	 * Extracts an image URL from a feed item, checking multiple potential sources.
	 *
	 * Checks sources in this order:
	 * 1. Item's thumbnail
	 * 2. Image enclosures
	 * 3. First image found in content
	 *
	 * @param Item $item Feed item to extract image from.
	 *
	 * @return string|null URL of the first found image, or null if no image found.
	 */
	private function get_feed_item_image( Item $item ): ?string {

		// Try thumbnail first - already sanitized by SimplePie
		$thumbnail = $item->get_thumbnail();
		if ( ! empty( $thumbnail['url'] ) ) {
			return $thumbnail['url'];
		}

		// Check enclosures - already sanitized by SimplePie
		$enclosures = $item->get_enclosures();
		foreach ( $enclosures as $enclosure ) {
			if ( str_starts_with( $enclosure->get_type(), 'image' ) ) {
				return $enclosure->get_link();
			}
		}

		// Parse content as last resort - needs sanitization
		$content = $item->get_content();
		if ( preg_match( '/<img.+?src=[\'"]([^\'"]+)[\'"].*?>/i', $content, $matches ) ) {
			return esc_url( $matches[1] );
		}

		return null;

	}

	/**
	 * Creates a WordPress post from feed item data.
	 *
	 * The feed item data $item_data should contain following elements:
	 * - post_title:   The title of the post.
	 * - post_date:    Publication date in Y-m-d H:i:s format.
	 * - post_excerpt: Optional excerpt.
	 * - post_content: The main content of the post.
	 *
	 * @param array{
	 *     post_title: string,
	 *     post_date: string,
	 *     post_excerpt: string,
	 *     post_content: string
	 * }          $item_data Feed item data to create post from.
	 * @param int $author_id User ID to set as post author.
	 *
	 * @return int Post ID if creation successful, 0 on failure.
	 */
	private function create_post( array $item_data, int $author_id ): int {

		$post_data = [
			'post_type' => 'kntnt-rss-item',
			'post_title' => $item_data['post_title'],
			'post_date' => $item_data['post_date'],
			'post_excerpt' => $item_data['post_excerpt'],
			'post_content' => $item_data['post_content'],
			'post_status' => 'publish',
			'post_author' => $author_id,
		];

		$post_id = wp_insert_post( $post_data );
		if ( is_wp_error( $post_id ) ) {
			self::log( Level::ERROR, "Inserting post failed: %s", $post_id->get_error_message() );
			return 0;
		}
		else {
			self::log( Level::INFO, "Created post id %s", $post_id );
		}

		return $post_id;

	}

	/**
	 * Adds RSS-related metadata to a post.
	 *
	 * Adds three meta fields:
	 * - kntnt_rss_item_feed: The feed URL
	 * - kntnt_rss_item_id:   The item's ID in the feed
	 * - kntnt_rss_item_link: The item's original URL (if available)
	 *
	 * @param int    $post_id   The post ID to add metadata to.
	 * @param string $item_id   The feed item's unique identifier.
	 * @param string $item_link The feed item's original URL.
	 * @param string $url       The feed URL.
	 *
	 * @return void
	 */
	private function add_metadata_to_post( int $post_id, string $item_id, string $item_link, string $url ): void {

		if ( update_post_meta( $post_id, 'kntnt_rss_item_feed', $url ) ) {
			self::log( Level::DEBUG, 'Update post %s with RSS feed URL: %s', $post_id, $url );
		}
		else {
			self::log( Level::ERROR, 'Failed to update post %s with RSS feed URL: %s', $post_id, $url );
		}

		if ( update_post_meta( $post_id, 'kntnt_rss_item_id', $item_id ) ) {
			self::log( Level::DEBUG, 'Update post %s with RSS item ID: %s', $post_id, $item_id );
		}
		else {
			self::log( Level::ERROR, 'Failed to update post %s with RSS item ID: %s', $post_id, $item_id );
		}

		if ( $item_link ) {
			if ( update_post_meta( $post_id, 'kntnt_rss_item_link', $item_link ) ) {
				self::log( Level::DEBUG, 'Update post %s with RSS item link: %s', $post_id, $item_link );
			}
			else {
				self::log( Level::ERROR, 'Failed to update post %s with RSS item link: %s', $post_id, $item_link );
			}
		}

	}

	/**
	 * Assigns tags to a post in the 'kntnt-rss-tag' taxonomy.
	 *
	 * @param int   $post_id The post ID to assign tags to.
	 * @param int[] $tags    Array of term IDs in the kntnt-rss-tag taxonomy.
	 *
	 * @return void
	 */
	private function add_tags_to_post( int $post_id, array $tags ): void {
		if ( ! empty( $tags ) ) {
			$tids = wp_set_object_terms( $post_id, $tags, 'kntnt-rss-tag' );
			if ( is_wp_error( $tids ) ) {
				self::log( Level::ERROR, 'Failed to update post %s with tags: %s', $post_id, $tags );
			}
		}
	}

	/**
	 * Downloads and sets a remote image as the post's featured image.
	 *
	 * Downloads the image from the provided URL, adds it to the media library,
	 * and sets it as the post's featured image (thumbnail).
	 *
	 * @param int    $post_id       The post ID to set the thumbnail for.
	 * @param string $thumbnail_url URL of the image to use as thumbnail.
	 *
	 * @return void
	 */
	private function add_thumbnail_to_post( int $post_id, string $thumbnail_url ): void {
		self::log( Level::DEBUG, 'Uploading thumbnail from %s to post ID: %s', $thumbnail_url, $post_id );
		$thumbnail_id = $this->upload_image( $thumbnail_url, $post_id );
		if ( $thumbnail_id && ! is_wp_error( $thumbnail_id ) ) {
			if ( set_post_thumbnail( $post_id, $thumbnail_id ) ) {
				self::log( Level::DEBUG, 'Thumbnail set successfully, thumbnail ID: %s for post ID: %s', $thumbnail_id, $post_id );
			}
			else {
				self::log( Level::ERROR, 'Could not update post %s with thumbnail ID: %s', $post_id, $thumbnail_id );
			}
		}
	}

	/**
	 * Downloads an image from a URL and adds it to the WordPress media library.
	 *
	 * First downloads the image to a temporary file, then adds it to the media library
	 * using WordPress' sideloading functionality. The temporary file is removed after
	 * the upload regardless of success or failure.
	 *
	 * @param string $image_url URL of the image to download.
	 * @param int    $post_id   Post ID to associate the uploaded image with.
	 *
	 * @return int|false Attachment ID if successful, false on failure.
	 */
	private function upload_image( string $image_url, int $post_id ): string|bool {

		self::log( Level::DEBUG, 'Downloading image from URL: %s', $image_url );
		$tmp_file = download_url( $image_url );
		if ( is_wp_error( $tmp_file ) ) {
			self::log( Level::ERROR, 'Downloading image failed: %s', $tmp_file->get_error_message() );
			return false;
		}
		self::log( Level::DEBUG, 'Image downloaded to temp file: %s', $tmp_file );

		// Get filename from URL path
		$file_name = basename( parse_url( $image_url, PHP_URL_PATH ) );

		self::log( Level::DEBUG, 'Sideloading media: %s', $file_name );
		$file_array = [];
		$file_array['tmp_name'] = $tmp_file;
		$file_array['name'] = $file_name;
		$attachment_id = media_handle_sideload( $file_array, $post_id, null );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			self::log( Level::ERROR, 'Media sideloading failed: %s', $attachment_id->get_error_message() );
			return false;
		}
		update_post_meta( $attachment_id, '_kntnt_rss_item_post_id', $post_id ); // Necessary to hide image in UI
		self::log( Level::INFO, 'Media sideloaded successfully, attachment ID: %s', $attachment_id );

		return $attachment_id;

	}

	/**
	 * Retrieves a hash table mapping feed item IDs to post IDs for a specific feed URL.
	 *
	 * @param string $feed_url URL of the RSS feed.
	 *
	 * @return array<string, int> Hash table where feed item IDs are keys and WordPress post IDs are values.
	 */
	private function rss_id_table( string $feed_url ): array {

		$args = [
			'post_type' => 'kntnt-rss-item',
			'posts_per_page' => - 1,
			'orderby'          => 'date',
			'order'            => 'ASC',
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

		self::log( Level::INFO, 'Found %s existing posts for the feed %s.', count( $rss_post_ids ), $feed_url );

		return $table;

	}

	/**
	 * Removes oldest imported posts when exceeding maximum item limit.
	 *
	 * Sorts posts by creation order and deletes the oldest posts until the
	 * number of remaining posts equals the maximum limit. Posts are permanently
	 * deleted (not moved to trash).
	 *
	 * @param array<string, int> $rss_id_table Hash table mapping feed item IDs to post IDs.
	 * @param int                $max_items    Maximum number of posts to keep.
	 *
	 * @return void
	 */
	private function prune_items( array $rss_id_table, int $max_items ): void {
		$prune_ids = array_slice( $rss_id_table, 0, count( $rss_id_table ) - $max_items );
		foreach ( $prune_ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				self::log( Level::DEBUG, 'Deleted post ID:', $id );
			}
			else {
				self::log( Level::ERROR, 'Failed to delete post ID:', $id );
			}
		}
	}

	/**
	 * Sets current user as default author for feeds missing an author.
	 *
	 * Iterates through the ACF repeater field 'kntnt_rss_feeds' and sets
	 * the current user's ID as author for any feed where no author is set.
	 *
	 * @return void
	 */
	private function set_default_author(): void {
		while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			the_row();
			if ( empty( get_sub_field( 'author' ) ) ) {
				update_sub_field( 'author', get_current_user_id() );
			}
		}
	}

}

new Plugin;