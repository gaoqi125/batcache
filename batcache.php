<?php
/*
Plugin name: Batcache Manager
Plugin URI: http://wordpress.org/extend/plugins/batcache/
Description: This optional plugin improves Batcache.
Author: Andy Skelton
Author URI: http://andyskelton.com/
*/

// Do not load if our advanced-cache.php isn't loaded
if ( ! is_object($batcache) || ! method_exists( $wp_object_cache, 'incr' ) )
	return;

$batcache->configure_groups();

// Regen home and permalink on posts and pages
add_action('clean_post_cache', 'batcache_post');

// Regen permalink on comments (TODO)
//add_action('comment_post',          'batcache_comment');
//add_action('wp_set_comment_status', 'batcache_comment');
//add_action('edit_comment',          'batcache_comment');

function batcache_post($post_id) {
	global $batcache;

	$permalink = get_permalink($post_id);
	if ( empty($permalink) )
		return false;

	$url_key = md5($permalink);

	wp_cache_add("{$url_key}_version", 0, $batcache->group);
	$version = wp_cache_incr("{$url_key}_version", 1, $batcache->group);
//var_dump($post_id, $permalink, $batcache);
//	var_dump( "{$url_key}_version", wp_cache_get("{$url_key}_version", $batcache->group));die;
}