<?php

/*
Plugin Name: blogroll2email
Plugin URI: https://github.com/petermolnar/blogroll2email
Description: Pulls RSS, Atom and microformats entries from blogroll links and sends them as email
Version: 0.2.3
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.3
*/

//  Copyright 2015 Peter Molnar ( hello@petermolnar.eu )

if ( !class_exists('Mf2\Parser') ) {
	require (__DIR__ . '/vendor/autoload.php');
}


if (!class_exists('blogroll2email')):

class blogroll2email {
	// 30 mins is reasonable
	const revisit_time = 1800;
	const schedule = 'blogroll2email';

	private $cachedir;

	/**
	 * thing to run as early as possible
	 */
	public function __construct () {
		$this->cachedir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR. 'cache' .  DIRECTORY_SEPARATOR. __CLASS__;
		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );
		//
		add_action( 'init', array( &$this, 'init'));
		// extend current cron schedules with our entry
		add_filter( 'cron_schedules', array(&$this, 'add_cron_schedule' ));
		// re-enable the links manager
		add_filter( 'pre_option_link_manager_enabled', '__return_true' );
		// add our own action for the scheduler to call it
		add_action( static::schedule, array( &$this, 'worker' ) );

		if (!is_dir($this->cachedir))
			mkdir ($this->cachedir);
	}

	/**
	 * things to run within WordPress init
	 */
	public function init () {
		if (!wp_get_schedule( static::schedule ))
			wp_schedule_event ( time(), static::schedule, static::schedule );
		return false;
	}

	/**
	 * add our own schedule
	 *
	 * @param array $schedules - current schedules list
	 *
	 * @return array $schedules - extended schedules list
	 */
	public function add_cron_schedule ( $schedules ) {

		$schedules[ static::schedule ] = array(
			'interval' => static::revisit_time,
			'display' => sprintf(__( 'every %d seconds' ), static::revisit_time )
		);

		return $schedules;
	}


	/**
	 * activation hook function
	 */
	public function plugin_activate() {
		static::debug('activating');

	}

	/**
	 * deactivation hook function; clears schedules
	 */
	public function plugin_deactivate () {
		static::debug('deactivating');
		wp_unschedule_event( time(), static::schedule );
		wp_clear_scheduled_hook( static::schedule );
	}

	/**
	 * main worker function: reads all bookmarks sorted by owner;
	 * gets them, processes them, sends the new entries
	 *
	 */
	public function worker () {
		static::debug('worker started');
		$args = array(
			'orderby' => 'owner',
			'order' => 'ASC',
			'limit' => -1,
		);

		$bookmarks = get_bookmarks( $args );

		$currowner = $owner = false;

		foreach ( $bookmarks as $bookmark ) {


			/* print_r ($bookmark);
				stdClass Object
				(
					[link_id] => 1
					[link_url] => http://devopsreactions.tumblr.com/
					[link_name] => http://devopsreactions.tumblr.com/
					[link_image] =>
					[link_target] =>
					[link_description] =>
					[link_visible] => Y
					[link_owner] => 1
					[link_rating] => 0
					[link_updated] => 0000-00-00 00:00:00
					[link_rel] =>
					[link_notes] =>
					[link_rss] => http://devopsreactions.tumblr.com/rss
				)
			*/

			if ( $currowner != $bookmark->link_owner) {
				$currowner = $bookmark->link_owner;
				$owner = get_userdata($currowner);
				$owner = $owner->data;
				/* print_r ( $owner );
				stdClass Object
				(
					[ID] => 1
					[user_login] => cadeyrn
					[user_pass] => $P$B.QI9GiDNyfXC7S75jJ4pcrQjx3awy/
					[user_nicename] => cadeyrn
					[user_email] => hello@petermolnar.eu
					[user_url] => https://petermolnar.eu/
					[user_registered] => 2014-04-28 21:36:35
					[user_activation_key] =>
					[user_status] => 0
					[display_name] => Peter Molnar
				)
				*/
			}

			$export_yaml[ $owner->user_nicename ][] = array (
				'name' => $bookmark->link_name,
				'url' => $bookmark->link_url,
				'rss' => $bookmark->link_rss,
				'description' => $bookmark->link_description,
				'lastfetched' => $bookmark->link_updated,
			);

			if ( !empty($bookmark->link_rss)) {
				$this->do_rss( $bookmark, $owner );
			}
			else {
				static::debug('Switcing into HTML mode');
				$url = htmlspecialchars_decode($bookmark->link_url);

				static::debug("  fetching {$url}");
				$q = wp_remote_get($url);

				if (is_wp_error($q)) {
					static::debug('  something went wrong: ' . $q->get_error_message());
					continue;
				}

				if (!is_array($q))
					continue;

				if (!isset($q['headers']) || !is_array($q['headers']))
					continue;

				if (!isset($q['body']) || empty($q['body']))
					continue;

				$ctype = isset($q['headers']['content-type']) ? $q['headers']['content-type'] : 'text/html';

				if ($ctype == "application/json") {
					static::debug("  content is json");
					$content = json_decode($q['body'], true);
				}
				else {
					static::debug("  content is html");
					$content = Mf2\parse($q['body'], $url);
				}

				static::debug("  sending it to mf parser");
				$this->parse_mf ( $bookmark, $owner, $content );
			}

		}

		if (function_exists('yaml_emit')) {
			$flatroot = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'flat';
			if ( !is_dir($flatroot)) {
				if (!mkdir( $flatroot )) {
					static::debug_log('Failed to create ' . $flatroot . ', exiting YAML creation');
				}
			}
			foreach ( $export_yaml as $owner => $bookmarks ) {
				$export = yaml_emit($bookmarks, YAML_UTF8_ENCODING );
				$export_file = $flatroot . DIRECTORY_SEPARATOR . 'bookmarks_' . $owner . '.yml';
				file_put_contents ($export_file, $export);
			}
		}
	}

	/**
	 * set HTML mail filter
	 *
	 * @return string HTML mime type
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 *
	 * @param string $to
	 * @param string $link
	 * @param string $title
	 * @param string $fromname
	 * @param string $sourceurl
	 * @param string $content
	 *
	 *
	 */
	protected function send ( $to, $link, $title, $fromname, $sourceurl, $content, $time, $dry = false ) {
		// enable HTML mail
		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type') );

		// build a more readable body
		$body = '<html><head></head><h1><a href="'. $link .'">'. $title .'</a></h1><body>'. $content . '<p>URL: <a href="'.$link.'">'.$link.'</a></p></body></html>';

		// this is to set the sender mail from our own domain
		$sitedomain = parse_url( get_bloginfo('url'), PHP_URL_HOST);

		// additional header, for potential sieve backwards compatibility
		// with http://www.aaronsw.com/weblog/001148
		$headers = array (
			'X-RSS-ID: ' . $link,
			'X-RSS-URL: ' . $link,
			'X-RSS-Feed: ' . $sourceurl,
			'User-Agent: blogroll2email',
			'From: "' . $fromname .'" <'. static::schedule . '@'. $sitedomain .'>',
			'Date: ' . date( 'r', $time ),
		);

		static::debug('sending ' . $title . ' to ' . $to );

		// for debung & specific reasons, there is a dry run mode
		if ( !$dry )
			$return = wp_mail( $to, $title, $body, $headers );


		// disable HTML mail
		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type') );

		return $return;
	}

	/**
	 *
	 * @param string $to
	 * @param string $link
	 * @param string $message
	 *
	 *
	 */
	protected function failed ( $to, $link, $message ) {
		$title = "blogroll2email: getting {$link} failed";
		$body = "Error message: \n {$message}";
		// this is to set the sender mail from our own domain
		$sitedomain = parse_url( get_bloginfo('url'), PHP_URL_HOST);

		// additional header, for potential sieve backwards compatibility
		// with http://www.aaronsw.com/weblog/001148
		$from = static::schedule . '@'. $sitedomain;
		$headers = array (
			'User-Agent: blogroll2email',
			'From: " '. $from .' <' . $from .'>',
			'Date: ' . date( 'r' ),
		);

		//static::debug('sending error to ' . $to );

		// for debung & specific reasons, there is a dry run mode
		//if ( !$dry )
		//	$return = wp_mail( $to, $title, $body, $headers );

		//return $return;
	}

	/**
	 *
	 * @param object $bookmark
	 * @param object $owner
	 */
	protected function do_rss ( $bookmark, $owner ) {

		// no bookmark, no fun
		if ( empty ($bookmark) || !is_object ($bookmark))
			return false;

		// no owner means no email, so no reason to parse
		if ( empty ($owner) || !is_object ($owner))
			return false;

		// instead of the way too simple fetch_feed, we'll use SimplePie itself
		if ( !class_exists('SimplePie') )
			require_once( ABSPATH . WPINC . '/class-simplepie.php' );

		$url = htmlspecialchars_decode($bookmark->link_rss);
		$last_updated = strtotime( $bookmark->link_updated );

		error_log('Fetching: ' . $url );
		$feed = new SimplePie();
		$feed->set_feed_url( $url );
		$feed->set_cache_duration ( static::revisit_time - 10 );
		$feed->set_cache_location( $this->cachedir );
		$feed->force_feed(true);

		// optimization
		$feed->enable_order_by_date(true);
		$feed->remove_div(true);
		$feed->strip_comments(true);
		$feed->strip_htmltags(false);
		$feed->strip_attributes(true);
		$feed->set_image_handler(false);

		$feed->init();
		$feed->handle_content_type();
		if ( $feed->error() ) {
			$err = new WP_Error( 'simplepie-error', $feed->error() );

			//static::debug('Error: ' . $err->get_error_message());
			 $this->failed (
				$owner->user_email, // to
				$url, // target
				$err->get_error_message() // error message
			);
			return $err;
		}

		// set max items to 12
		// especially useful with first runs
		$maxitems = $feed->get_item_quantity( 12 );
		$feed_items = $feed->get_items( 0, $maxitems );
		$feed_title = $feed->get_title();

		// set the link name from the RSS title
		if ( !empty($feed_title) && $bookmark->link_name != $feed_title ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'links', array ( 'link_name' => $feed_title ), array('link_id'=> $bookmark->link_id ) );
		}

		// if there's a feed author, get it, we may need it if there's no entry
		// author
		$feed_author = $feed->get_author();
		$last_updated_ = 0;

		if ( $maxitems > 0 ) {
			foreach ( $feed_items as $item ) {
				// U stands for Unix Time
				$date = $item->get_date( 'U' );

				if ( $date > $last_updated ) {
					$from = $feed_title;
					$author = $item->get_author();
					if ($author)
						$from = $from . ': ' . $author->get_name();
					elseif ( $feed_author )
						$from = $from . ': ' . $feed_author->get_name();

					$content = $item->get_content();

					$matches = array();
					preg_match_all('/farm[0-9]\.staticflickr\.com\/[0-9]+\/([0-9]+_[0-9a-zA-Z]+_m\.jpg)/s', $content, $matches);

					if ( !empty ( $matches[0] ) ) {
						foreach ( $matches[0] as $to_replace ) {
							$clean = str_replace('_m.jpg', '_c.jpg', $to_replace);
							$content = str_replace ( $to_replace, $clean, $content );
						}
						$content = preg_replace("/(width|height)=\"(.*?)\" ?/is", '', $content);
					}

					$content = apply_filters('blogroll2email_message', $content);

					if ( $this->send (
						$owner->user_email,
						$item->get_link(),
						$item->get_title(),
						$from,
						$url,
						$item->get_content(),
						$date
					)) {
					if ( $date > $last_updated_ )
						$last_updated_ = $date;
					}
				}
			}
		}

		// poke the link's last update field, so we know what was the last sent
		// entry's date
		$this->update_link_date ( $bookmark, $last_updated_ );
	}


	/***
	 * Microformats2 parser version
	 *
	 * @param object $bookmark
	 * @param object $owner
	 *
	 */
	protected function parse_mf ( $bookmark, $owner, $mf ) {

		if ( empty ($bookmark) || !is_object ($bookmark))
			return false;

		if ( empty ($owner) || !is_object ($owner))
			return false;

		$last_updated = strtotime( $bookmark->link_updated );

		$mfitems = $items = array ();

		static::debug("    looping topitems");
		foreach ($mf['items'] as $topitem ) {

			if ( in_array( 'h-feed', $topitem['type']) && !empty($topitem['children'])) {
				$mfitems[] = $topitem['children'];
			}
			elseif ( in_array( 'h-entry', $topitem['type']) ) {
				$mfitems[] = $topitem;
			}
		}

		static::debug("    looping mfitems");
		foreach ($mfitems as $entry ) {
			// double-check h-entries
			if ( in_array( 'h-entry', $entry['type']) ) {

				$properties = $entry['properties'];
				$title = $bookmark->link_name;

				if (isset($properties['name'][0]) && !empty($properties['name'][0]))
					$title = $properties['name'][0];

				elseif(isset($properties['author'][0]['properties']['name'][0]) && !empty($properties['author'][0]['properties']['name'][0]) )
					$title = $properties['author'][0]['properties']['name'][0];

				if (isset($properties['uid'][0])  && !empty($properties['uid'][0]))
					$title .= ' (' . $properties['uid'][0] .  ')';

				$url = $properties['url'][0];

				if ( isset($properties['updated']) && !empty($properties['updated']) )
					$date = $properties['updated'][0];
				else
					$date = $properties['published'][0];

				$time = strtotime( $date );

				if ( isset($properties['content'][0]['html']) && !empty($properties['content'][0]['html']) )
					$content = $properties['content'][0]['html'];
				elseif ( isset($properties['summary'][0]['html']) && !empty($properties['summary'][0]['html']))
					$content = $properties['summary'][0]['html'] . '<h2><a href="'.$url.'">Read the full article &raquo;&raquo;</a></h2>';
				else
					$content = '<h1><a href="'.$url.'">'.$title.'</a></h1>';

				if ( isset($properties['photo'][0]) && !empty($properties['photo'][0] ) && !stristr($content, $properties['photo'][0]) )
					$content .= '<p><img src="'.$properties['photo'][0].'" /></p>';

				if ( isset($properties['video'][0]) && !empty($properties['video'][0] ) && !stristr($content, $properties['video'][0]) )
					$content .= '<p><video><source src="'.$properties['video'][0].'" /></video></p>';

				$item = array (
					'url' => $url,
					'content' => $content,
					'title' => $title,
				);

				$items[ $time ] = $item;
			}
		}

		if ( empty( $items ) )
			return false;

		$last_updated_ = 0;

		static::debug("    looping items");
		foreach ( $items as $time => $item ) {
			if ( $time > $last_updated ) {
				if ($this->send (
					$owner->user_email,
					$item['url'],
					$item['title'],
					$bookmark->link_name,
					$item['url'],
					$item['content'],
					$time
				)) {
					if ( $time > $last_updated_ )
					$last_updated_ = $time;
				}
			}
		}

		$this->update_link_date ( $bookmark, $last_updated_ );
	}

	/**
	 * in case there was an update, set the last update time for the bookmark
	 *
	 * @param object $bookmark
	 * @param epoch $last_updated
	 */
	protected function update_link_date ( $bookmark, $last_updated ) {
		$current_updated = strtotime( $bookmark->link_updated );
		if ( $last_updated > $current_updated ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'links', array ( 'link_updated' => date( 'Y-m-d H:i:s', $last_updated )), array('link_id'=> $bookmark->link_id ) );
		}
	}

	protected function generate_sieve_description ( $category ) {

	}

	/**
	 *
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 *
	 * @output log to syslog | wp_die on high level
	 * @return false on not taking action, true on log sent
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
		if ( empty( $message ) )
			return false;

		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);

		$levels = array (
			LOG_EMERG => 0, // system is unusable
			LOG_ALERT => 1, // Alert 	action must be taken immediately
			LOG_CRIT => 2, // Critical 	critical conditions
			LOG_ERR => 3, // Error 	error conditions
			LOG_WARNING => 4, // Warning 	warning conditions
			LOG_NOTICE => 5, // Notice 	normal but significant condition
			LOG_INFO => 6, // Informational 	informational messages
			LOG_DEBUG => 7, // Debug 	debug-level messages
		);

		// number for number based comparison
		// should work with the defines only, this is just a make-it-sure step
		$level_ = $levels [ $level ];

		// in case WordPress debug log has a minimum level
		if ( defined ( 'WP_DEBUG_LEVEL' ) ) {
			$wp_level = $levels [ WP_DEBUG_LEVEL ];
			if ( $level_ < $wp_level ) {
				return false;
			}
		}

		// ERR, CRIT, ALERT and EMERG
		if ( 3 >= $level_ ) {
			wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
			exit;
		}

		$trace = debug_backtrace();
		$caller = $trace[1];
		$parent = $caller['function'];

		if (isset($caller['class']))
			$parent = $caller['class'] . '::' . $parent;

		return error_log( "{$parent}: {$message}" );
	}


}

$blogroll2email = new blogroll2email();

endif;
