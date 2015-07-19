<?php

/*
Plugin Name: blogroll2email
Plugin URI: https://github.com/petermolnar/blogroll2email
Description: Pulls RSS, Atom and microformats entries from blogroll links and sends them as email
Version: 0.2
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
*/

/*  Copyright 2015 Peter Molnar ( hello@petermolnar.eu )

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 3, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class blogroll2email {
	// 30 mins is reasonable
	const revisit_time = 1800;
	const schedule = 'blogroll2email';

	/**
	 * thing to run as early as possible
	 */
	public function __construct () {
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
		self::debug('activating');

	}

	/**
	 * deactivation hook function; clears schedules
	 */
	public function plugin_deactivate () {
		self::debug('deactivating');
		wp_unschedule_event( time(), static::schedule );
		wp_clear_scheduled_hook( static::schedule );
	}

	/**
	 * main worker function: reads all bookmarks sorted by owner;
	 * gets them, processes them, sends the new entries
	 *
	 */
	public function worker () {
		self::debug('worker started');
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

			if ( empty($bookmark->link_rss))
				$this->parse_mf ( $bookmark, $owner );
			else
				$this->parse_rss( $bookmark, $owner );
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

		self::debug('sending ' . $title . ' to ' . $to );

		// for debung & specific reasons, there is a dry run mode
		if ( !$dry )
			$return = wp_mail( $to, $title, $body, $headers );


		// disable HTML mail
		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type') );

		return $return;
	}

	/**
	 *
	 * @param object $bookmark
	 * @param object $owner
	 */
	protected function parse_rss ( $bookmark, $owner ) {

		// no bookmark, no fun
		if ( empty ($bookmark) || !is_object ($bookmark))
			return false;

		// no owner means no email, so no reason to parse
		if ( empty ($owner) || !is_object ($owner))
			return false;

		// instead of the way too simple fetch_feed, we'll use SimplePie itself
		if ( !class_exists('SimplePie') )
			require_once( ABSPATH . WPINC . '/class-simplepie.php' );

		$url = $bookmark->link_rss;
		$last_updated = strtotime( $bookmark->link_updated );

		error_log('Fetching: ' . $url );
		$feed = new SimplePie();
		$feed->set_feed_url( $url );
		$feed->set_cache_duration ( static::revisit_time - 10 );
		$feed->set_cache_location( WP_CONTENT_DIR . DIRECTORY_SEPARATOR. 'cache' );
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
		if ( $feed->error() )
			return new WP_Error( 'simplepie-error', $feed->error() );

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

		if ( $maxitems > 0 ) {
			$last_updated_ = 0;
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
	protected function parse_mf ( $bookmark, $owner ) {

		if ( empty ($bookmark) || !is_object ($bookmark))
			return false;

		if ( empty ($owner) || !is_object ($owner))
			return false;

		if ( !class_exists('Mf2') )
			require_once ( dirname(__FILE__) . '/lib/php-mf2/Mf2/Parser.php' );

		$last_updated = strtotime( $bookmark->link_updated );

		$url = $bookmark->link_url;

		//$hash = md5( $url );
		// check cache file and skip this step if exists ?

		$mf = Mf2\fetch($url,  true, $curlInfo);
		error_log('MF2 fetching: ' . $url );

		// check for rss, because it's older and more mature
		// if there is one, register it for the link and skip this run
		// in the next run, we'll parse the RSS
		if ( isset($mf['alternates']) && !empty($mf['alternates'])) {
			foreach ( $mf['alternates'] as $alternate ) {
				if ( 	isset($alternate['type']) &&
						!empty($alternate['type']) &&
						$alternate['type'] == 'application/rss+xml'
						&& isset($altenate['url']) &&
						!empty($alternate['ulr']) &&
						filter_var($alternate['ulr'], FILTER_VALIDATE_URL)
					) {
						global $wpdb;
						$wpdb->update( $wpdb->prefix . 'links', array ( 'link_rss' => $alternate['url'] ), array('link_id'=> $bookmark->link_id ) );
						return false;
					}
			}
		}

		$items = array ();
		foreach ($mf['items'] as $topitem ) {
			// look for h-feeds
			if ( in_array( 'h-feed', $topitem['type'])) {
				if ( !empty($topitem['children'])) {
					foreach ( $topitem['children'] as $entry ) {
						// look for h-entries
						if ( in_array( 'h-entry', $entry['type']) ) {

							$properties = $entry['properties'];

							$title = $properties['name'][0];
							$url = $properties['url'][0];

							if ( isset($properties['updated']) && !empty($properties['updated']) )
								$date = $properties['updated'][0];
							else
								$date = $properties['published'][0];

							if ( isset($properties['content'][0]['html']) && !empty($properties['content'][0]['html']) )
								$content = $properties['content'][0]['html'];
							elseif ( isset($properties['summary'][0]['html']) && !empty($properties['summary'][0]['html']))
								$content = $properties['summary'][0]['html'] . '<h2><a href="'.$url.'">Read the full article &raquo;&raquo;</a></h2>';
							else
								$content = '<h1><a href="'.$url.'">'.$title.'</a></h1>';

							$time = strtotime( $date );

							$item = array (
								'url' => $url,
								'content' => $content,
								'title' => $title,
							);

							$items[ $time ] = $item;
						}
					}
				}
			}
		}

		if ( empty( $items ) )
			return false;

		$last_updated_ = 0;
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

	/**
	 *
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 */
	static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . ": " . $message );
	}


}

$blogroll2email = new blogroll2email();
