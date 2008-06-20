<?php

// nananananananananananananananana BATCACHE!!!

// These variables are for the default configuration. Domain-specific configs follow.

$batcache_max_age =  300; // Expire batcache items aged this many seconds (zero to disable batcache)
$batcache_remote  =    0; // Zero disables sending buffers to remote datacenters (req/sec is never sent)

$batcache_times   =    2; // Only batcache a page after it is accessed this many times... (two or more)
$batcache_seconds =  120; // ...in this many seconds (zero to ignore this and use batcache immediately)

$batcache_group = 'batcache'; // Name of memcached group
$batcache_ok_filenames = array(); // Script filenames that may be batcached when query string is present (e.g. from mod-rewrite)

$batcache_unique = array(); // If you conditionally serve different content, put the variable values here.

/* For example, if your documents have a mobile variant (a different document served by the same URL) you must tell batcache about the variance. Otherwise you might accidentally cache the mobile version and serve it to desktop users, or vice versa.
$batcache_unique['mobile'] = is_mobile_user_agent();
*/

// Put any conditional configurations here.

/* Example: never batcache for this host
if ( $_SERVER['HTTP_HOST'] == 'do-not-batcache-me.com' )
	$batcache_max_age = 0;
*/

/* Example: batcache everything on this host regardless of traffic level
if ( $_SERVER['HTTP_HOST'] == 'always-batcache-me.com' )
	$batcache_seconds = 0;
*/

/* Example: If you sometimes serve variants dynamically (e.g. referrer search term highlighting) you probably don't want to batcache those variants. Remember this code is run very early in wp-settings.php so plugins are not yet loaded. You will get a fatal error if you try to call an undefined function. Either include your plugin now or define a test function in this file.
if ( include_once( 'plugins/searchterm-highlighter.php') && referrer_has_search_terms() )
	$batcache_max_age = 0;
*/

function batcache() {
	global $batcache_key, $batcache_group, $batcache_max_age, $batcache_times, $batcache_seconds, $batcache_ok_filenames, $batcache_remote, $batcache_unique;

	// Never batcache when run from CLI
	if ( !empty( $_SERVER[ 'argv' ] ) )
		return;

	// Never batcache when POST data is present
	if ( !empty( $HTTP_RAW_POST_DATA ) )
		return;

	// Exclusion tests

	// Disabled
	if ( $batcache_max_age < 1 )
		return;

	// HTTP POST
	if ( !empty($_POST) )
		return;

	// HTTP GET, with a whitelist of script filenames that should be batcached with GET query vars
	if ( !empty($_GET) && ( !is_array($batcache_ok_filenames) || !in_array(basename($_SERVER['SCRIPT_NAME']), $batcache_ok_filenames) ) )
		return;

	// Cookied users and previous commenters
	if ( !empty($_COOKIE) )
		foreach ( $_COOKIE as $k => $v )
			if ( substr($k, 0, 9) == 'wordpress' || substr($k, 0, 14) == 'comment_author' )
				return;

	if ( ! include_once( dirname(__FILE__) . '/object-cache.php' ) )
		return;

	wp_cache_init();

	// Make sure we can increment
	if ( ! method_exists( $GLOBALS['wp_object_cache'], 'incr' ) )
		return;

	// If your blog shows logged-in pages after you log out, uncomment this. (Typical CDN issue.)
	// header('Vary: Cookie');

	// Things that define a unique page. You must 
	$keys = array(
		'host' => $_SERVER['HTTP_HOST'],
		'path' => $_SERVER['REQUEST_URI'],
		'query' => $_SERVER['QUERY_STRING'],
		'extra' => $batcache_unique
	);

	// Configure the memcached client
	if ( !$batcache_remote )
		if ( function_exists('wp_cache_add_no_remote_groups') )
			wp_cache_add_no_remote_groups(array($batcache_group));
	if ( function_exists('wp_cache_add_global_groups') )
		wp_cache_add_global_groups(array($batcache_group));

	// Generate the traffic threshold measurement key
	$batcache_req_key = md5(serialize($keys)) . '_req';

	// Generate the batcache key
	$batcache_key = md5(serialize($keys));

	// Get the batcache
	$batcache = wp_cache_get($batcache_key, $batcache_group);

	// Are we only caching frequently-requested pages?
	if ( $batcache_seconds < 1 || $batcache_times < 2 ) {
		$do_batcache = true;
	} else {
		// No batcache item found, or ready to sample traffic again at the end of the batcache life?
		if ( !is_array($batcache) || time() >= $batcache['time'] + $batcache_max_age - $batcache_seconds ) {
			// incr returns the new value, or null if the key doesn't exist
			$requests = wp_cache_incr($batcache_req_key, 1, $batcache_group);

			// First time this interval?
			if ( $requests == null ) {
				wp_cache_set($batcache_req_key, 1, $batcache_group, $batcache_seconds);
				$do_batcache = false;
			}

			// Minimum condition met?
			elseif ( $requests >= $batcache_times ) {
				$do_batcache = true;
			}

			// Minimum condition not met.
			else {
				$do_batcache = false;
			}
		}
	}

	// Defined here because timer_stop() calls number_format_i18n(), which might not be defined when we need it (sunrise, SHORTINIT)
	function batcache_timer_stop($display = 0, $precision = 3) {
		global $timestart, $timeend;
		$mtime = microtime();
		$mtime = explode(' ',$mtime);
		$mtime = $mtime[1] + $mtime[0];
		$timeend = $mtime;
		$timetotal = $timeend-$timestart;
		$r = number_format($timetotal, $precision);
		if ( $display )
			echo $r;
		return $r;
	}

	// Did we find a batcached page that hasn't expired?
	if ( isset($batcache['time']) && time() < $batcache['time'] + $batcache_max_age ) {
		// 304 Not Modified
		if ( isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ) {
			$since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
			if ( $batcache['time'] == $since ) {
				header('Last-Modified: ' . date('r', $batcache['time']), 1, 304);
				exit;
			}
		}

		// Use the batcache save time for Last-Modified so we can 304
		header('Last-Modified: ' . date('r', $batcache['time']), 1);

		// Add some debug info just before </head>
		$tag = "<!--\n\tgenerated " . (time() - $batcache['time']) . " seconds ago\n\tgenerated in " . $batcache['timer'] . " seconds\n\tserved from batcache in " . batcache_timer_stop(false, 3) . " seconds\n\texpires in " . ($batcache_max_age - time() + $batcache['time']) . " seconds\n-->\n";
		$tag_position = strpos($batcache['output'], '</head>');
		$output = substr($batcache['output'], 0, $tag_position) . $tag . substr($batcache['output'], $tag_position);

		if ( !empty($batcache['headers']) ) foreach ( $batcache['headers'] as $k => $v )
			header("$k: $v");

		// Have you ever heard a death rattle before?
		die($output);
	}

	// Didn't meet the minimum condition?
	if ( !$do_batcache )
		return;

	function batcache_ob($output) {
		global $batcache_key, $batcache_group, $batcache_max_age;

		// Configure the memcached client again because it can get clobbered
		if ( !$batcache_remote )
			if ( function_exists('wp_cache_add_no_remote_groups') )
				wp_cache_add_no_remote_groups(array($batcache_group));
		if ( function_exists('wp_cache_add_global_groups') )
			wp_cache_add_global_groups(array($batcache_group));

		// Do not batcache blank pages (usually they are HTTP redirects)
		$output = trim($output);
		if ( empty($output) )
			return;

		// Construct and save the batcache
		$batcache = array(
			'output' => $output,
			'time' => time(),
			'timer' => batcache_timer_stop(false, 3),
			'headers' => apache_response_headers()
		);
		header('Last-Modified: ' . date('r', $batcache['time']), 1, 200);
		wp_cache_set($batcache_key, $batcache, $batcache_group, $batcache_max_age + $batcache_seconds + 30);

		// Add some debug info just before </head>
		$tag = "<!--\n\tgenerated in " . $batcache['timer'] . " seconds\n\t" . strlen(serialize($batcache)) . " bytes batcached for " . $batcache_max_age . " seconds\n-->\n";
		$tag_position = strpos($output, '</head>');
		$output = substr($output, 0, $tag_position) . $tag . substr($output, $tag_position);

		// Pass output to next ob handler
		return $output;
	}
	ob_start('batcache_ob');
}
batcache();

?>
