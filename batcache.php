<?php
/*
Plugin name: Batcache Manager
Plugin URI: http://wordpress.org/extend/plugins/batcache/
Description: This optional plugin improves Batcache.
Author: Andy Skelton
Author URI: http://andyskelton.com/
*/

// Do not load if our advanced-cache.php isn't loaded
if ( ! function_exists('batcache') )
	return;

add_action('init', 'batcache_init');

function batcache_init() {
	global $batcache_remote, $batcache_group;

	if ( !$batcache_remote )
		if ( function_exists('wp_cache_add_no_remote_groups') )
			wp_cache_add_no_remote_groups(array($batcache_group));
	if ( function_exists('wp_cache_add_global_groups') )
		wp_cache_add_global_groups(array($batcache_group));
}

// Regen home and permalink on posts and pages
add_action('clean_post_cache', 'batcache_post');

// Regen permalink on comments (TODO)
//add_action('comment_post',          'batcache_comment');
//add_action('wp_set_comment_status', 'batcache_comment');
//add_action('edit_comment',          'batcache_comment');

function batcache_post($post_id) {
	global $batcache_group;

	$permalink = get_permalink($post_id);
	if ( empty($permalink) )
		return false;

	$url_key = md5($permalink);

	wp_cache_add("{$url_key}_version", 0, $batcache_group);
	$version = wp_cache_incr("{$url_key}_version", 1, $batcache_group);
}