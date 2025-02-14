<?php
/**
 * Plugin Name:       Kntnt RSS Fetcher
 * Description:       Fetches and displays content from multiple RSS feeds, with flexible configuration.
 * Version:           0.1.1
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

	private static function log( $message = '', ...$args ) {
		if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) && defined( 'KNTNT_RSS_FETCHER_DEBUG' ) && constant( 'KNTNT_RSS_FETCHER_DEBUG' ) ) {
			if ( ! is_string( $message ) ) {
				$args    = [ $message ];
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

	public function __construct() {

		self::log( 'Entry' );

		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

		add_action( 'acf/save_post', [ $this, 'save_options_page' ] );
		add_filter( 'cron_schedules', [ $this, 'cron_schedule' ] );
		add_action( 'kntnt_rss_fetch', [ $this, 'fetch_rss' ] );

		self::log( 'Exit' );
	}

	/**
	 * Register custom cron interval on plugin activation.
	 */
	public function activate() {
		self::log( 'Entry' );

		if ( ! wp_next_scheduled( 'kntnt_rss_fetch' ) ) {
			$interval = $this->get_min_interval(); // Get shortest interval for initial schedule
			$schedule = 'hourly'; // Default schedule if interval is not set or invalid
			if ( $interval ) {
				$schedule = 'kntnt_rss_interval';
				add_filter( 'cron_schedules', [ $this, 'cron_schedule' ] ); // Ensure custom schedule is registered
			}
			wp_schedule_event( time(), $schedule, 'kntnt_rss_fetch' );
			self::log( 'Scheduled kntnt_rss_fetch event with schedule: %s', $schedule );
		} else {
			self::log( 'kntnt_rss_fetch event already scheduled.' );
		}

		self::log( 'Exit' );
	}

	/**
	 * Deactivate hook to clear cron schedule.
	 */
	public function deactivate() {
		self::log( 'Entry' );
		wp_clear_scheduled_hook( 'kntnt_rss_fetch' );
		self::log( 'Cleared kntnt_rss_fetch cron schedule.' );
		self::log( 'Exit' );
	}

	/**
	 * Action to run when the options page is saved.
	 */
	public function save_options_page( $post_id ) {
		self::log( 'Entry, post_id: %s', $post_id );

		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'kntnt-rss-settings' ) {
			self::log( 'Not RSS settings page, exiting.' );

			return;
		}

		wp_clear_scheduled_hook( 'kntnt_rss_fetch' );
		self::log( 'Cleared existing kntnt_rss_fetch cron schedule.' );
		$this->fetch_rss(); // Run fetch immediately on save

		$interval = $this->get_min_interval(); // Recalculate interval after save
		if ( $interval ) {
			wp_schedule_event( time(), 'kntnt_rss_interval', 'kntnt_rss_fetch' );
			self::log( 'Rescheduled kntnt_rss_fetch event with interval: %s minutes.', $interval );
		} else {
			self::log( 'Interval not set, cron event not rescheduled.' );
		}

		self::log( 'Exit' );
	}

	/**
	 * Add custom cron schedule based on ACF option.
	 */
	public function cron_schedule( $schedules ) {
		self::log( 'Entry, current schedules: %s', $schedules );

		$interval = $this->get_min_interval();
		if ( $interval ) {
			$schedules['kntnt_rss_interval'] = [
				'interval' => $interval * 60,
				'display'  => esc_html__( 'Custom RSS Interval', 'kntnt-rss-fetcher' ),
			];
			self::log( 'Added custom schedule kntnt_rss_interval: %s minutes', $interval );
		} else {
			self::log( 'Interval not set, custom schedule not added.' );
		}

		self::log( 'Exit' );

		return $schedules;
	}

	/**
	 * Get the minimum poll interval from all feeds.
	 */
	private function get_min_interval() {
		$min_interval = false;
		if ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) : the_row();
				$interval = get_sub_field( 'poll_interval' );
				if ( $interval ) {
					if ( ! $min_interval || $interval < $min_interval ) {
						$min_interval = $interval;
					}
				}
			endwhile;
		}

		return $min_interval;
	}

	/**
	 * Fetch RSS feed items and create/update posts.
	 */
	public function fetch_rss() {
		self::log( 'Entry' );

		$feeds = [];
		if ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
			while ( have_rows( 'kntnt_rss_feeds', 'option' ) ) {
				the_row();
				$feed_config = [
					'url'           => get_sub_field( 'url' ),
					'author_id'     => get_sub_field( 'author' ),
					'max_items'     => get_sub_field( 'max_items' ),
					'poll_interval' => get_sub_field( 'poll_interval' ),
					'tags'          => get_sub_field( 'tag' ),
				];
				$feeds[]     = $feed_config;
			}
		}

		if ( empty( $feeds ) ) {
			self::log( 'No feeds configured.' );

			return;
		}

		foreach ( $feeds as $feed_index => $feed_config ) {
			$feed_url  = $feed_config['url'];
			$author_id = $feed_config['author_id'];
			$max_items = $feed_config['max_items'];
			$feed_tags = $feed_config['tags'];
			$table     = $this->rss_id_table( $feed_config['url'] ); // Build hash table for each feed processing

			if ( ! $author_id ) {
				self::log( 'Error: Author not set for feed URL: %s. Skipping feed.', $feed_url );
				continue; // Skip to the next feed if author is not set
			}

			if ( ! $max_items ) {
				$max_items = 10; // Default max items if not set
				self::log( 'Max items not set for feed URL: %s, using default: %s', $feed_url, $max_items );
			} else {
				self::log( 'Max items from options for feed URL: %s: %s', $feed_url, $max_items );
			}

			self::log( 'Fetching feed from URL: %s (feed index: %s)', $feed_url, $feed_index );
			$feed = fetch_feed( $feed_url );

			if ( is_wp_error( $feed ) ) {
				$error_message = $feed->get_error_message();
				$http_code     = wp_remote_retrieve_response_code( $feed ); // Försök hämta HTTP-statuskod
				$log_message   = 'WP_Error fetching feed: ' . $error_message . ' for URL: ' . $feed_url;
				if ( $http_code ) {
					$log_message    .= ' (HTTP Status Code: ' . $http_code . ')';
					$notice_message = sprintf( esc_html__( 'Kntnt RSS Fetcher: Error fetching RSS feed from URL: %1$s.  HTTP Status Code: %2$s. Error message: %3$s', 'kntnt-rss-fetcher' ), esc_url( $feed_url ), esc_html( $http_code ), esc_html( $error_message ) );
				} else {
					$notice_message = sprintf( esc_html__( 'Kntnt RSS Fetcher: Error fetching RSS feed from URL: %1$s. Error message: %2$s', 'kntnt-rss-fetcher' ), esc_url( $feed_url ), esc_html( $error_message ) );
				}
				self::log( '%s', $log_message );
				continue; // Skip to the next feed if there's an error
			}

			$feed_items = $feed->get_items( 0, $max_items ); // Limit items fetched from feed

			if ( empty( $feed_items ) ) {
				self::log( 'No items found in feed URL: %s', $feed_url );
				continue; // Skip to the next feed if no items
			}

			self::log( 'Processing %s items from feed URL: %s', count( $feed_items ), $feed_url );

			foreach ( $feed_items as $item ) {
				$item_id = '';
				self::log( 'Processing item: %s', $item->get_title() );

				// Try to get item ID from guid, id, or hash
				if ( $item->get_id() ) {
					$item_id = $item->get_id();
					self::log( 'Item ID from get_id(): %s', $item_id );
				} elseif ( $item->get_permalink() ) {
					$item_id = hash( 'crc32b', $item->get_permalink() . '|' . $item->get_title() . '|' . $item->get_date( 'U' ) );
					self::log( 'Item ID from hash (permalink): %s', $item_id );
				} else {
					self::log( 'Could not generate item ID for item: %s', $item->get_title() );
					continue; // Skip item if no ID can be generated
				}

				if ( isset( $table[ $item_id ] ) ) {
					self::log( 'Item with ID %s already exists, skipping.', $item_id );
					continue; // Skip if item already exists
				}

				$post_title = wp_strip_all_tags( $item->get_title() );
				if ( empty( $post_title ) ) {
					$description = wp_strip_all_tags( $item->get_description() );
					if ( ! empty( $description ) ) {
						$words      = explode( ' ', $description );
						$post_title = $words[0];
						$suffix     = '';
						$word_count = 1;
						while ( strlen( $post_title . $suffix . '...' ) < 50 && $word_count < count( $words ) ) {
							$word_count ++;
							$suffix = ' ' . implode( ' ', array_slice( $words, 1, $word_count - 1 ) );
						}
						$post_title = $words[0] . $suffix . '...';
					}
					if ( empty( $post_title ) ) {
						self::log( 'Could not generate post title for item ID: %s', $item_id );
						continue; // Skip item if no title can be generated
					}
				}
				self::log( 'Generated post title: %s', $post_title );


				$post_date_raw = $item->get_date( 'Y-m-d H:i:s' );
				$post_date     = ! empty( $post_date_raw ) ? $post_date_raw : current_time( 'mysql' );

				$post_excerpt = wp_strip_all_tags( $item->get_description() );

				$content_encoded = $item->get_content();
				$post_content    = ! empty( $content_encoded ) ? $content_encoded : $item->get_description();
				if ( empty( $post_content ) ) {
					$post_content = '';
				}

				$post_link      = '';
				$link_from_item = $item->get_link();
				$guid_from_item = $item->get_item_tags( '', 'guid' );

				if ( $link_from_item ) {
					$post_link = $link_from_item;
				} elseif ( ! empty( $guid_from_item ) && isset( $guid_from_item[0]['attribs']['']['isPermaLink'] ) && $guid_from_item[0]['attribs']['']['isPermaLink'] === 'true' ) {
					$post_link = $guid_from_item[0]['data'];
				}


				$thumbnail_url = '';
				$thumbnails    = $item->get_item_tags( 'http://search.yahoo.com/mrss/', 'thumbnail' );
				if ( ! empty( $thumbnails ) ) {
					$thumbnail_url = $thumbnails[0]['attribs']['']['url'];
				} else {
					$enclosures = $item->get_enclosures();
					if ( ! empty( $enclosures ) ) {
						foreach ( $enclosures as $enclosure ) {
							$enclosure_type = $enclosure->get_type(); // Get enclosure type
							if ( is_string( $enclosure_type ) && strpos( $enclosure_type, 'image/' ) === 0 ) { // Check if type is string and then use strpos()
								$thumbnail_url = $enclosure->get_link();
								break; // Use the first image enclosure
							}
						}
					}
				}

				if ( empty( $thumbnail_url ) ) {
					self::log( 'No thumbnail found for item: %s', $item->get_title() );
				} else {
					self::log( 'Thumbnail URL found: %s', $thumbnail_url );
				}


				$post_data = [
					'post_type'    => 'kntnt-rss-item', // Updated post type
					'post_title'   => $post_title,
					'post_date'    => $post_date,
					'post_excerpt' => $post_excerpt,
					'post_content' => $post_content,
					'post_status'  => 'publish', // Or 'draft' if you prefer
					'post_author'  => $author_id,
				];

				$post_id = wp_insert_post( $post_data );

				if ( is_wp_error( $post_id ) ) {
					self::log( 'WP_Error inserting post: %s for item ID: %s', $post_id->get_error_message(), $item_id );
					continue; // Skip to the next item if post creation fails
				}

				update_post_meta( $post_id, 'kntnt_rss_item_feed', $feed_url );
				update_post_meta( $post_id, 'kntnt_rss_item_id', $item_id );
				update_post_meta( $post_id, 'kntnt_rss_item_link', esc_url_raw( $post_link ) ); // Updated meta field name

				// Set taxonomy terms (using feed_tags from repeater config)
				if ( ! empty( $feed_tags ) && is_array( $feed_tags ) ) {
					wp_set_object_terms( $post_id, $feed_tags, 'kntnt-rss-tag' ); // Updated taxonomy name
					self::log( 'Taxonomy terms set: %s for post ID: %s', implode( ',', $feed_tags ), $post_id );
				}

				if ( $thumbnail_url ) {
					self::log( 'Uploading thumbnail from URL: %s for post ID: %s', $thumbnail_url, $post_id );
					$thumbnail_id = $this->upload_image( $thumbnail_url, $post_id );
					if ( $thumbnail_id && ! is_wp_error( $thumbnail_id ) ) {
						set_post_thumbnail( $post_id, $thumbnail_id );
						self::log( 'Thumbnail set successfully, thumbnail ID: %s for post ID: %s', $thumbnail_id, $post_id );
					} elseif ( is_wp_error( $thumbnail_id ) ) {
						self::log( 'WP_Error uploading thumbnail: %s for post ID: %s', $thumbnail_id->get_error_message(), $post_id );
					}
				}

				$table[ $item_id ] = $post_id; // Add new post ID to the table
			}
		}

		// Prune old posts if exceeding max items (based on the total number of items in the table, not just newly added)
		if ( count( $table ) > $max_items ) { // Use the LAST feed's max_items for pruning - consider if this should be the SUM or MAX of all feeds max_items in future versions.
			self::log( 'Pruning posts. Current item count: %s, max items: %s', count( $table ), $max_items );
			asort( $table, SORT_NUMERIC ); // Sort by post ID (creation order)
			$prune_ids = array_slice( $table, 0, count( $table ) - $max_items );
			self::log( 'IDs to prune: %s', $prune_ids );
			foreach ( $prune_ids as $id ) {
				wp_delete_post( $id, true ); // true for force delete
				self::log( 'Post ID %s pruned.', $id );
			}
			self::log( 'Pruning complete. %s posts pruned.', count( $prune_ids ) );
		} else {
			self::log( 'No pruning needed. Current item count: %s, max items: %s', count( $table ), $max_items );
		}


		self::log( 'Exit' );
	}

	/**
	 * Build a hash table of existing RSS items for faster lookup.
	 */
	private function rss_id_table( $feed_url ) {
		self::log( 'Entry' );

		$args         = [
			'post_type'      => 'kntnt-rss-item',
			'posts_per_page' => - 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key' => 'kntnt_rss_item_id', // To ensure we have a value
				],
				[
					'key'     => 'kntnt_rss_item_feed',
					'value'   => $feed_url,
					'compare' => '=',
				],
			],
		];
		$rss_post_ids = get_posts( $args );
		self::log( 'Fetched %s post IDs for kntnt-rss-item post type.', count( $rss_post_ids ) );

		$hash = [];
		foreach ( $rss_post_ids as $post_id ) {
			$rss_id = get_post_meta( $post_id, 'kntnt_rss_item_id', true );
			if ( ! empty( $rss_id ) ) {
				$hash[ $rss_id ] = $post_id;
			}
		}
		self::log( 'Hash table built with %s entries.', count( $hash ) );

		self::log( 'Exit' );

		return $hash;
	}

	/**
	 * Handle image upload from URL and attach to post.
	 */
	private function upload_image( $image_url, $post_id ) {
		self::log( 'Entry, image_url: %s, post_id: %s', $image_url, $post_id );

		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		self::log( 'Downloading image from URL: %s', $image_url );
		$tmp_file = download_url( $image_url );
		if ( is_wp_error( $tmp_file ) ) {
			self::log( 'WP_Error downloading image: %s', $tmp_file->get_error_message() );

			return $tmp_file; // Return WP_Error if download fails
		}
		self::log( 'Image downloaded to temp file: %s', $tmp_file );

		$file_array             = [];
		$file_array['tmp_name'] = $tmp_file;
		$file_array['name']     = basename( parse_url( $image_url, PHP_URL_PATH ) ); // Get filename from URL path
		self::log( 'Sideloading media: %s', $file_array );

		$attachment_id = media_handle_sideload( $file_array, $post_id, null );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file ); // Clean up temp file
			self::log( 'WP_Error sideloading media: %s, temp file deleted.', $attachment_id->get_error_message() );

			return $attachment_id; // Return WP_Error if sideload fails
		}
		self::log( 'Media sideloaded successfully, attachment ID: %s', $attachment_id );

		self::log( 'Exit' );

		return $attachment_id;
	}

}