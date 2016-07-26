<?php

namespace Article2Email;
require (__DIR__ . '/vendor/autoload.php');

define ( 'Article2Email\revisit_time', 1800 );
define ( 'Article2Email\bookmarks', __DIR__ . DIRECTORY_SEPARATOR . 'bookmarks.tsv' );
define ( 'Article2Email\fromfile', __DIR__ . DIRECTORY_SEPARATOR . 'from.tsv' );
define ( 'Article2Email\cachedir', __DIR__ . DIRECTORY_SEPARATOR . 'cache' );
define ( 'Article2Email\defaultfrom', 'articlesemail@' . gethostname() );
define ( 'Article2Email\debuglevel', 5 );


/**
 *
 * @param string $message
 * @param int $level
 *
 * @output log to syslog | die on high level
 * @return false on not taking action, true on log sent
 */
function debug( $message, $level = LOG_NOTICE ) {

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

	// ERR, CRIT, ALERT and EMERG
	if ( 3 >= $level_ ) {
		die( "Error: {$message}" );
	}

	if ( defined( 'Article2Email\debuglevel') ) {
		if ( $level_ > debuglevel )
			return false;
	}

	$trace = debug_backtrace();
	$caller = $trace[1];
	$parent = $caller['function'];

	if (isset($caller['class']))
		$parent = $caller['class'] . '::' . $parent;

	return error_log( "{$parent}: {$message}" );
}

/**
 *
 */
function main() {
	if ( !is_dir( cachedir ) )
		mkdir ( cachedir );

	$bookmarks = get_bookmarks();
	foreach ( $bookmarks as $i => $bookmark ) {
		debug( "Processing {$bookmark['url']} for {$bookmark['email']}", 5 );
		if ( $bookmark['type'] == 'rss' ) {
			debug( "{$bookmark['url']} is rss", 7 );
			$t = process_rss( $bookmark );
		}
		elseif ( $bookmark['type'] == 'mf2' ) {
			debug( "{$bookmark['url']} is mf2", 7 );
			$t = process_mf2( $bookmark );
		}
		$bookmarks[ $i ]['updated'] = $t;
	}

	update_bookmarks( $bookmarks );
}

function fetch_mf2 ( $bookmark ) {

	$hash = md5( $bookmark['url'] );
	$cachefile = cachedir . DIRECTORY_SEPARATOR . $hash;

	if ( file_exists( $cachefile ) ) {
		$mtime = filemtime( $cachefile );
		if ( $mtime > (time() - revisit_time) ) {
			debug ("Using cachefile", 7 );
			$mf = unserialize( file_get_contents( $cachefile ) );
			return $mf;
		}
	}

	$data = get_url ( $bookmark['url'] );
	$test = json_decode ( $data, true );

	// content is json
	if ( false !== $test && null !== $test ) {
		debug ( "content is JSON", 7);
		$mf = $test;
	}
	else {
		debug ( "content is HTML", 7);
		$mf = \Mf2\parse( $data );
	}

	file_put_contents( $cachefile, serialize( $mf ) );
	touch( $cachefile, time() );
	return $mf;
}

/***
 * Microformats2 parser version
 *
 * @param object $bookmark
 * @param object $owner
 *
 */
function process_mf2 ( $bookmark ) {

	$last_updated_ = $last_updated = $bookmark['updated'];
	$mf = fetch_mf2( $bookmark );

	$mfitems = $items = array ();
	if ( empty( $mf ) || ! is_array( $mf) ) {
		debug ( "parsing {$bookmark['url']} as MF2 failed", 4 );
		return $last_updated_;
	}

	$config = get_from();

	foreach ($mf['items'] as $i ) {

		if ( in_array( 'h-feed', $i['type']) && !empty($i['children'])) {
			$mfitems = array_merge( $mfitems, $i['children'] );
		}
		elseif ( in_array( 'h-entry', $i['type']) ) {
			$mfitems[] = $i;
		}
	}

	foreach ($mfitems as $entry ) {
		// double-check h-entries
		if ( in_array( 'h-entry', $entry['type']) ) {

			$properties = $entry['properties'];
			$title = $bookmark['url'];

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

	if ( isset( $config[ $bookmark['email'] ] ) )
		$frommail = $config[ $bookmark['email'] ];
	else
		$frommail = defaultfrom;

	$fromname = $bookmark['url'];

	foreach ( $items as $time => $item ) {
		if ( $time > $last_updated ) {
			if ( send (
				$bookmark['email'],
				$item['url'],
				$item['title'],
				$fromname,
				$frommail,
				$item['url'],
				$item['content'],
				$time
			)) {
				if ( $time > $last_updated_ )
				$last_updated_ = $time;
			}
		}
	}

	return $last_updated_;

}


/**
 *
 */
function process_rss( $bookmark ) {

	$url = $bookmark['url'];
	$last_updated_ = $last_updated = $bookmark['updated'];

	$feed = new \SimplePie();
	$feed->set_feed_url( $url );
	$feed->set_cache_duration ( revisit_time - 10 );
	$feed->set_cache_location( cachedir );
	$feed->force_feed(true);

	// optimization
	$feed->enable_order_by_date(true);
	$feed->remove_div(true);
	$feed->strip_comments(true);
	$feed->strip_htmltags(false);
	$feed->strip_attributes(true);
	$feed->set_image_handler(false);

	$feed->set_output_encoding('UTF-8');

	$feed->init();
	$feed->handle_content_type();

	if ( $feed->error() ) {
		//if ( preg_match( '/invalid XML/i', $feed->error() ))
			// try mf2;

		return date( 'U', time() );
	}

	// set max items to 12
	// especially useful with first runs
	$maxitems = $feed->get_item_quantity( 12 );
	$feed_items = $feed->get_items( 0, $maxitems );

	//if ( 0 == $feed_items )
	//	debug( "{$url} is empty, try mf2!" );

	$f_author = $feed->get_author();

	if ( $maxitems <= 0 )
		return date( 'U', time() );

	$config = get_from();

	foreach ( $feed_items as $item ) {
		$i_date = $item->get_date( 'U' );
		$i_url = $item->get_link();
		$i_title = $item->get_title();

		if ( $i_date <=  $last_updated ) {
			debug ( "skipping {$i_url}; already processed", 7 );
			continue;
		}

		$i_content = $item->get_content();
		if ( empty( $i_content ) ) {
			debug ( "skipping {$i_url}; empty content", 6 );
			continue;
		}

		$i_author = $item->get_author();

		if ($i_author)
			$fromname =  $feed->get_title() . ': ' . $i_author->get_name();
		elseif ( $f_author )
			$fromname = $feed->get_title() . ': ' . $f_author->get_name();

		if ( isset( $config[ $bookmark['email'] ] ) )
			$frommail = $config[ $bookmark['email'] ];
		else
			$frommail = defaultfrom;

		send(
			$bookmark['email'],
			$i_url,
			$i_title,
			$fromname,
			$frommail,
			$url,
			$i_content,
			$i_date
		);

		if ( $i_date > $last_updated_ )
			$last_updated_ = $i_date;
	}

	return $last_updated_;

}

/**
 *
 */
function get_bookmarks( $bookmarksfile = bookmarks  ) {
	$bookmarks = [];
	if (($handle = fopen( $bookmarksfile, "r" )) !== FALSE) {
		while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
			$e = [
				'email'   => $data[0],
				'type'    => $data[1],
				'url'     => htmlspecialchars_decode( $data[2] ),
				'updated' => strtotime( $data[3] ),
			];
			array_push( $bookmarks, $e );
		}
		fclose($handle);
	}

	return $bookmarks;
}

/**
 *
 */
function update_bookmarks( $bookmarks, $bookmarksfile = bookmarks ) {

	foreach ( $bookmarks as $i => $e ) {
		$e['updated'] = date('c', $e['updated'] );
		$bookmarks[ $i ] = join( " ", $e );
	}
	$bookmarks = join ( "\n", $bookmarks );
	file_put_contents ( $bookmarksfile, $bookmarks );
	debug("Bookmarks file updated", 6);
}

/**
 *
 */
function get_from( $fromfile = fromfile  ) {
	$config = [];
	if (($handle = fopen( $fromfile, "r" )) !== FALSE) {
		while (($data = fgetcsv($handle, 1000, " ")) !== FALSE) {
			if ( isset( $data[1] ) && !empty( $data[1] ) )
				$config [ $data[0] ] = $data[1];
		}
		fclose($handle);
	}

	return $config;
}

/**
 *
 */
function send ( $to, $link, $title, $fromname, $frommail, $sourceurl, $content, $time ) {

	// build a more readable body
	$body = '<html>
		<head></head>
		<h1>
			<a href="'. $link .'">'. $title .'</a>
		</h1>
		<body>'
			. $content
			. '<p>URL:
				<a href="'.$link.'">'.$link.'</a>
				</p>
		</body>
	</html>';

	$mail = new \PHPMailer;
	$mail->CharSet = "UTF-8";
	$mail->setFrom( $frommail, $fromname );
	$mail->isHTML(true);
	$mail->addAddress( $to );

	$mail->addCustomHeader('X-RSS-ID', $link );
	$mail->addCustomHeader('X-RSS-URL', $link );
	$mail->addCustomHeader('X-RSS-Feed', $sourceurl );
	$mail->addCustomHeader('User-Agent', __NAMESPACE__ );
	$mail->addCustomHeader('Date', date( 'r', $time ) );

	$mail->Subject = $title;
	$mail->Body    = $body;

	debug ( "Sending {$link} to {$to}", 5 );

	if(!$mail->send()) {
		debug( 'Message could not be sent: ' . $mail->ErrorInfo, 4 );
		return false;
	}

	return true;

}

/**
 *
 */
function get_url( $url ){
	/*
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $url );
	$data = curl_exec($ch);
	if ( empty( $data ) )
		die( curl_error($ch) . curl_errno($ch));
	curl_close($ch);
	* return $data;
	*/
	return file_get_contents( $url );

}

// ---
main();