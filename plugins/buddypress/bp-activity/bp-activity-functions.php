<?php

/**
 * BuddyPress Activity Functions
 *
 * Functions for the Activity Streams component
 *
 * @package BuddyPress
 * @subpackage Activity Core
 */

/**
 * Searches through the content of an activity item to locate usernames, designated by an @ sign
 *
 * @package BuddyPress Activity
 * @since 1.3
 *
 * @param str $content The content of the activity, usually found in $activity->content
 * @return array $usernames Array of the found usernames that match existing users
 */
function bp_activity_find_mentions( $content ) {
	$pattern = '/[@]+([A-Za-z0-9-_\.@]+)/';
	preg_match_all( $pattern, $content, $usernames );

	// Make sure there's only one instance of each username
	if ( !$usernames = array_unique( $usernames[1] ) )
		return false;

	return $usernames;
}

/**
 * Resets a user's unread mentions list and count
 *
 * @package BuddyPress Activity
 * @since 1.3
 *
 * @param int $user_id The id of the user whose unread mentions are being reset
 */
function bp_activity_clear_new_mentions( $user_id ) {
	delete_user_meta( $user_id, bp_get_user_meta_key( 'bp_new_mention_count' ) );
	delete_user_meta( $user_id, bp_get_user_meta_key( 'bp_new_mentions' ) );
}

/**
 * Adjusts new mention count for mentioned users when activity items are deleted or created
 *
 * @package BuddyPress Activity
 * @since 1.3
 *
 * @param int $activity_id The unique id for the activity item
 */
function bp_activity_adjust_mention_count( $activity_id, $action = 'add' ) {
	$activity = new BP_Activity_Activity( $activity_id );

	if ( $usernames = bp_activity_find_mentions( strip_tags( $activity->content ) ) ) {
		foreach( (array)$usernames as $username ) {
			if ( defined( 'BP_ENABLE_USERNAME_COMPATIBILITY_MODE' ) )
				$user_id = username_exists( $username );
			else
				$user_id = bp_core_get_userid_from_nicename( $username );

			if ( empty( $user_id ) )
				continue;

			// Adjust the mention list and count for the member
			$new_mention_count = (int)get_user_meta( $user_id, bp_get_user_meta_key( 'bp_new_mention_count' ), true );
			if ( !$new_mentions = get_user_meta( $user_id, bp_get_user_meta_key( 'bp_new_mentions' ), true ) )
				$new_mentions = array();
				
			switch ( $action ) {
				case 'delete' :
					$key = array_search( $activity_id, $new_mentions );
					if ( $key !== false ) {
						unset( $new_mentions[$key] );
					}
					break;
				
				case 'add' :
				default :
					if ( !in_array( $activity_id, $new_mentions ) ) {
						$new_mentions[] = (int)$activity_id;
					}
					break;
			}
			
			// Get an updated mention count			
			$new_mention_count = count( $new_mentions );
			
			// Resave the user_meta
			update_user_meta( $user_id, bp_get_user_meta_key( 'bp_new_mention_count' ), $new_mention_count );
			update_user_meta( $user_id, bp_get_user_meta_key( 'bp_new_mentions' ), $new_mentions );
		}
	}
}

/**
 * Formats notifications related to activity
 *
 * @package BuddyPress Activity
 * @param $action The type of activity item. Just 'new_at_mention' for now
 * @param $item_id The activity id
 * @param $secondary_item_id In the case of at-mentions, this is the mentioner's id
 * @param $total_items The total number of notifications to format
 */
function bp_activity_format_notifications( $action, $item_id, $secondary_item_id, $total_items ) {
	global $bp;

	switch ( $action ) {
		case 'new_at_mention':
			$activity_id      = $item_id;
			$poster_user_id   = $secondary_item_id;
			$at_mention_link  = $bp->loggedin_user->domain . $bp->activity->slug . '/mentions/';
			$at_mention_title = sprintf( __( '@%s Mentions', 'buddypress' ), $bp->loggedin_user->userdata->user_nicename );

			if ( (int)$total_items > 1 ) {
				return apply_filters( 'bp_activity_multiple_at_mentions_notification', '<a href="' . $at_mention_link . '" title="' . $at_mention_title . '">' . sprintf( __( 'You have %1$d new activity mentions', 'buddypress' ), (int)$total_items ) . '</a>', $at_mention_link, $total_items, $activity_id, $poster_user_id );
			} else {
				$user_fullname = bp_core_get_user_displayname( $poster_user_id );

				return apply_filters( 'bp_activity_single_at_mentions_notification', '<a href="' . $at_mention_link . '" title="' . $at_mention_title . '">' . sprintf( __( '%1$s mentioned you in an activity update', 'buddypress' ), $user_fullname ) . '</a>', $at_mention_link, $total_items, $activity_id, $poster_user_id );
			}
		break;
	}

	do_action( 'activity_format_notifications', $action, $item_id, $secondary_item_id, $total_items );

	return false;
}

/** Actions *******************************************************************/

/**
 * Sets the current action for a given activity stream location
 *
 * @global obj $bp
 * @param str $component_id
 * @param str $key
 * @param str $value
 * @return bool False on error, True on success
 */
function bp_activity_set_action( $component_id, $key, $value ) {
	global $bp;

	// Return false if any of the above values are not set
	if ( empty( $component_id ) || empty( $key ) || empty( $value ) )
		return false;

	// Set activity action
	$bp->activity->actions->{$component_id}->{$key} = apply_filters( 'bp_activity_set_action', array(
		'key'   => $key,
		'value' => $value
	), $component_id, $key, $value );

	return true;
}

/**
 * Retreives the current action from a component and key
 *
 * @global obj $bp
 * @param str $component_id
 * @param str $key
 * @return mixed False on error, action on success
 */
function bp_activity_get_action( $component_id, $key ) {
	global $bp;

	// Return false if any of the above values are not set
	if ( empty( $component_id ) || empty( $key ) )
		return false;

	return apply_filters( 'bp_activity_get_action', $bp->activity->actions->{$component_id}->{$key}, $component_id, $key );
}

/** Favorites *****************************************************************/

/**
 * Get a users favorite activity stream items
 *
 * @global obj $bp
 * @param int $user_id
 * @return array Array of users favorite activity stream ID's
 */
function bp_activity_get_user_favorites( $user_id = 0 ) {
	global $bp;

	// Fallback to logged in user if no user_id is passed
	if ( empty( $user_id ) )
		$user_id = $bp->displayed_user->id;

	// Get favorites for user
	$favs = get_user_meta( $user_id, bp_get_user_meta_key( 'bp_favorite_activities' ), true );

	return apply_filters( 'bp_activity_get_user_favorites', $favs );
}

/**
 * Add an activity stream item as a favorite for a user
 *
 * @global obj $bp
 * @param int $activity_id
 * @param int $user_id
 * @return bool
 */
function bp_activity_add_user_favorite( $activity_id, $user_id = 0 ) {
	global $bp;

	// Favorite activity stream items are for logged in users only
	if ( !is_user_logged_in() )
		return false;

	// Fallback to logged in user if no user_id is passed
	if ( empty( $user_id ) )
		$user_id = $bp->loggedin_user->id;

	// Update the user's personal favorites
	$my_favs   = get_user_meta( $bp->loggedin_user->id, bp_get_user_meta_key( 'bp_favorite_activities' ), true );
	$my_favs[] = $activity_id;

	// Update the total number of users who have favorited this activity
	$fav_count = bp_activity_get_meta( $activity_id, 'favorite_count' );
	$fav_count = !empty( $fav_count ) ? (int)$fav_count + 1 : 1;

	// Update user meta
	update_user_meta( $bp->loggedin_user->id, bp_get_user_meta_key( 'bp_favorite_activities' ), $my_favs );

	// Update activity meta counts
	if ( true === bp_activity_update_meta( $activity_id, 'favorite_count', $fav_count ) ) {

		// Execute additional code
		do_action( 'bp_activity_add_user_favorite', $activity_id, $user_id );

		// Success
		return true;

	// Saving meta was unsuccessful for an unknown reason
	} else {
		// Execute additional code
		do_action( 'bp_activity_add_user_favorite_fail', $activity_id, $user_id );

		return false;
	}
}

function bp_activity_remove_user_favorite( $activity_id, $user_id = 0 ) {
	global $bp;

	// Favorite activity stream items are for logged in users only
	if ( !is_user_logged_in() )
		return false;

	// Fallback to logged in user if no user_id is passed
	if ( empty( $user_id ) )
		$user_id = $bp->loggedin_user->id;

	// Remove the fav from the user's favs
	$my_favs = get_user_meta( $user_id, bp_get_user_meta_key( 'bp_favorite_activities' ), true );
	$my_favs = array_flip( (array) $my_favs );
	unset( $my_favs[$activity_id] );
	$my_favs = array_unique( array_flip( $my_favs ) );

	// Update the total number of users who have favorited this activity
	if ( $fav_count = bp_activity_get_meta( $activity_id, 'favorite_count' ) ) {

		// Deduct from total favorites
		if ( bp_activity_update_meta( $activity_id, 'favorite_count', (int)$fav_count - 1 ) ) {

			// Update users favorites
			if ( update_user_meta( $user_id, bp_get_user_meta_key( 'bp_favorite_activities' ), $my_favs ) ) {

				// Execute additional code
				do_action( 'bp_activity_remove_user_favorite', $activity_id, $user_id );

				// Success
				return true;

			// Error updating
			} else {
				return false;
			}

		// Error updating favorite count
		} else {
			return false;
		}

	// Error getting favorite count
	} else {
		return false;
	}
}

/**
 * Check if activity exists by scanning content
 *
 * @param str $content
 * @return bool
 */
function bp_activity_check_exists_by_content( $content ) {
	return apply_filters( 'bp_activity_check_exists_by_content', BP_Activity_Activity::check_exists_by_content( $content ) );
}

/**
 * Retreive the last time activity was updated
 *
 * @return str
 */
function bp_activity_get_last_updated() {
	return apply_filters( 'bp_activity_get_last_updated', BP_Activity_Activity::get_last_updated() );
}

/**
 * Retreive the number of favorite activity stream items a user has
 *
 * @global obj $bp
 * @param int $user_id
 * @return int
 */
function bp_activity_total_favorites_for_user( $user_id = 0 ) {
	global $bp;

	// Fallback on displayed user, and then logged in user
	if ( empty( $user_id ) )
		$user_id = ( $bp->displayed_user->id ) ? $bp->displayed_user->id : $bp->loggedin_user->id;

	return BP_Activity_Activity::total_favorite_count( $user_id );
}

/** Meta **********************************************************************/

/**
 * Delete a meta entry from the DB for an activity stream item
 *
 * @global DB $wpdb
 * @global obj $bp
 * @param int $activity_id
 * @param str $meta_key
 * @param str $meta_value
 * @return bool
 */
function bp_activity_delete_meta( $activity_id, $meta_key = '', $meta_value = '' ) {
	global $wpdb, $bp;

	// Return false if any of the above values are not set
	if ( !is_numeric( $activity_id ) )
		return false;

	// Sanitize key
	$meta_key = preg_replace( '|[^a-z0-9_]|i', '', $meta_key );

	if ( is_array( $meta_value ) || is_object( $meta_value ) )
		$meta_value = serialize( $meta_value );

	// Trim off whitespace
	$meta_value = trim( $meta_value );

	// Delete all for activity_id
	if ( empty( $meta_key ) )
		$retval = $wpdb->query( $wpdb->prepare( "DELETE FROM {$bp->activity->table_name_meta} WHERE activity_id = %d", $activity_id ) );

	// Delete only when all match
	else if ( $meta_value )
		$retval = $wpdb->query( $wpdb->prepare( "DELETE FROM {$bp->activity->table_name_meta} WHERE activity_id = %d AND meta_key = %s AND meta_value = %s", $activity_id, $meta_key, $meta_value ) );

	// Delete only when activity_id and meta_key match
	else
		$retval = $wpdb->query( $wpdb->prepare( "DELETE FROM {$bp->activity->table_name_meta} WHERE activity_id = %d AND meta_key = %s", $activity_id, $meta_key ) );

	// Delete cache entry
	wp_cache_delete( 'bp_activity_meta_' . $meta_key . '_' . $activity_id, 'bp' );

	// Success
	if ( !is_wp_error( $retval ) )
		return true;

	// Fail
	else
		return false;
}

/**
 * Get activity meta
 *
 * @global DB $wpdb
 * @global obj $bp
 * @param int $activity_id
 * @param str $meta_key
 * @return bool
 */
function bp_activity_get_meta( $activity_id = 0, $meta_key = '' ) {
	global $wpdb, $bp;

	// Make sure activity_id is valid
	if ( empty( $activity_id ) || !is_numeric( $activity_id ) )
		return false;

	// We have a key to look for
	if ( !empty( $meta_key ) ) {

		// Sanitize key
		$meta_key = preg_replace( '|[^a-z0-9_]|i', '', $meta_key );

		// Check cache
		if ( !$metas = wp_cache_get( 'bp_activity_meta_' . $meta_key . '_' . $activity_id, 'bp' ) ) {

			// No cache so hit the DB
			$metas = $wpdb->get_col( $wpdb->prepare("SELECT meta_value FROM {$bp->activity->table_name_meta} WHERE activity_id = %d AND meta_key = %s", $activity_id, $meta_key ) );

			// Set cache
			wp_cache_set( 'bp_activity_meta_' . $meta_key . '_' . $activity_id, $metas, 'bp' );
		}

	// No key so get all for activity_id
	} else {
		$metas = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$bp->activity->table_name_meta} WHERE activity_id = %d", $activity_id ) );
	}

	// No result so return false
	if ( empty( $metas ) )
		return false;

	// Maybe, just maybe... unserialize
	$metas = array_map( 'maybe_unserialize', (array)$metas );

	// Return first item in array if only 1, else return all metas found
	$retval = ( 1 == count( $metas ) ? $metas[0] : $metas );

	// Filter result before returning
	return apply_filters( 'bp_activity_get_meta', $retval, $activity_id, $meta_key );
}

/**
 * Update activity meta
 *
 * @global DB $wpdb
 * @global obj $bp
 * @param int $activity_id
 * @param str $meta_key
 * @param str $meta_value
 * @return bool
 */
function bp_activity_update_meta( $activity_id, $meta_key, $meta_value ) {
	global $wpdb, $bp;

	// Make sure activity_id is valid
	if ( !is_numeric( $activity_id ) )
		return false;

	// Sanitize key
	$meta_key = preg_replace( '|[^a-z0-9_]|i', '', $meta_key );

	// Sanitize value
	if ( is_string( $meta_value ) )
		$meta_value = stripslashes( $wpdb->escape( $meta_value ) );

	// Maybe, just maybe... serialize
	$meta_value = maybe_serialize( $meta_value );

	// If value is empty, delete the meta key
	if ( empty( $meta_value ) )
		return bp_activity_delete_meta( $activity_id, $meta_key );

	// See if meta key exists for activity_id
	$cur = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bp->activity->table_name_meta} WHERE activity_id = %d AND meta_key = %s", $activity_id, $meta_key ) );

	// Meta key does not exist so INSERT
	if ( empty( $cur ) )
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$bp->activity->table_name_meta} ( activity_id, meta_key, meta_value ) VALUES ( %d, %s, %s )", $activity_id, $meta_key, $meta_value ) );

	// Meta key exists, so UPDATE
	else if ( $cur->meta_value != $meta_value )
		$wpdb->query( $wpdb->prepare( "UPDATE {$bp->activity->table_name_meta} SET meta_value = %s WHERE activity_id = %d AND meta_key = %s", $meta_value, $activity_id, $meta_key ) );

	// Weirdness, so return false
	else
		return false;

	// Set cache
	wp_cache_set( 'bp_activity_meta_' . $meta_key . '_' . $activity_id, $meta_value, 'bp' );

	// Victory is ours!
	return true;
}

/** Clean up ******************************************************************/

/**
 * Completely remove
 * @param int $user_id
 */
function bp_activity_remove_all_user_data( $user_id = 0 ) {

	// Do not delete user data unless a logged in user says so
	if ( empty( $user_id ) || !is_user_logged_in() )
		return false;

	// Clear the user's activity from the sitewide stream and clear their activity tables
	bp_activity_delete( array( 'user_id' => $user_id ) );

	// Remove any usermeta
	delete_user_meta( $user_id, bp_get_user_meta_key( 'bp_latest_update' ) );
	delete_user_meta( $user_id, bp_get_user_meta_key( 'bp_favorite_activities' ) );

	// Execute additional code
	do_action( 'bp_activity_remove_data', $user_id ); // Deprecated! Do not use!

	// Use this going forward
	do_action( 'bp_activity_remove_all_user_data', $user_id );
}
add_action( 'wpmu_delete_user',  'bp_activity_remove_all_user_data' );
add_action( 'delete_user',       'bp_activity_remove_all_user_data' );
add_action( 'bp_make_spam_user', 'bp_activity_remove_all_user_data' );

/**
 * Register the activity stream actions for updates
 *
 * @global obj $bp
 */
function updates_register_activity_actions() {
	global $bp;

	bp_activity_set_action( $bp->activity->id, 'activity_update', __( 'Posted an update', 'buddypress' ) );

	do_action( 'updates_register_activity_actions' );
}
add_action( 'bp_register_activity_actions', 'updates_register_activity_actions' );

/*******************************************************************************
 * Business functions are where all the magic happens in BuddyPress. They will
 * handle the actual saving or manipulation of information. Usually they will
 * hand off to a database class for data access, then return
 * true or false on success or failure.
 */

function bp_activity_get( $args = '' ) {
	$defaults = array(
		'max'              => false,  // Maximum number of results to return
		'page'             => 1,      // page 1 without a per_page will result in no pagination.
		'per_page'         => false,  // results per page
		'sort'             => 'DESC', // sort ASC or DESC
		'display_comments' => false,  // false for no comments. 'stream' for within stream display, 'threaded' for below each activity item

		'search_terms'     => false,  // Pass search terms as a string
		'show_hidden'      => false,  // Show activity items that are hidden site-wide?
		'exclude'          => false,  // Comma-separated list of activity IDs to exclude
		'in'               => false,  // Comma-separated list or array of activity IDs to which you want to limit the query

		/**
		 * Pass filters as an array -- all filter items can be multiple values comma separated:
		 * array(
		 * 	'user_id'      => false, // user_id to filter on
		 *	'object'       => false, // object to filter on e.g. groups, profile, status, friends
		 *	'action'       => false, // action to filter on e.g. activity_update, profile_updated
		 *	'primary_id'   => false, // object ID to filter on e.g. a group_id or forum_id or blog_id etc.
		 *	'secondary_id' => false, // secondary object ID to filter on e.g. a post_id
		 * );
		 */
		'filter' => array()
	);
	$r = wp_parse_args( $args, $defaults );
	extract( $r, EXTR_SKIP );

	// Attempt to return a cached copy of the first page of sitewide activity.
	if ( 1 == (int)$page && empty( $max ) && empty( $search_terms ) && empty( $filter ) && 'DESC' == $sort && empty( $exclude ) ) {
		if ( !$activity = wp_cache_get( 'bp_activity_sitewide_front', 'bp' ) ) {
			$activity = BP_Activity_Activity::get( $max, $page, $per_page, $sort, $search_terms, $filter, $display_comments, $show_hidden );
			wp_cache_set( 'bp_activity_sitewide_front', $activity, 'bp' );
		}
	} else
		$activity = BP_Activity_Activity::get( $max, $page, $per_page, $sort, $search_terms, $filter, $display_comments, $show_hidden, $exclude, $in );

	return apply_filters_ref_array( 'bp_activity_get', array( &$activity, &$r ) );
}

function bp_activity_get_specific( $args = '' ) {
	$defaults = array(
		'activity_ids'     => false,  // A single activity_id or array of IDs.
		'page'             => 1,      // page 1 without a per_page will result in no pagination.
		'per_page'         => false,  // results per page
		'max'              => false,  // Maximum number of results to return
		'sort'             => 'DESC', // sort ASC or DESC
		'display_comments' => false,  // true or false to display threaded comments for these specific activity items
		'show_hidden'      => false
	);
	$r = wp_parse_args( $args, $defaults );
	extract( $r, EXTR_SKIP );

	return apply_filters( 'bp_activity_get_specific', BP_Activity_Activity::get( $max, $page, $per_page, $sort, false, false, $display_comments, $show_hidden, false, $activity_ids ) );
}

function bp_activity_add( $args = '' ) {
	global $bp;

	$defaults = array(
		'id'                => false, // Pass an existing activity ID to update an existing entry.

		'action'            => '',    // The activity action - e.g. "Jon Doe posted an update"
		'content'           => '',    // Optional: The content of the activity item e.g. "BuddyPress is awesome guys!"

		'component'         => false, // The name/ID of the component e.g. groups, profile, mycomponent
		'type'              => false, // The activity type e.g. activity_update, profile_updated
		'primary_link'      => '',    // Optional: The primary URL for this item in RSS feeds (defaults to activity permalink)

		'user_id'           => $bp->loggedin_user->id, // Optional: The user to record the activity for, can be false if this activity is not for a user.
		'item_id'           => false, // Optional: The ID of the specific item being recorded, e.g. a blog_id
		'secondary_item_id' => false, // Optional: A second ID used to further filter e.g. a comment_id
		'recorded_time'     => bp_core_current_time(), // The GMT time that this activity was recorded
		'hide_sitewide'     => false  // Should this be hidden on the sitewide activity stream?
	);
	$params = wp_parse_args( $args, $defaults );
	extract( $params, EXTR_SKIP );

	// Make sure we are backwards compatible
	if ( empty( $component ) && !empty( $component_name ) )
		$component = $component_name;

	if ( empty( $type ) && !empty( $component_action ) )
		$type = $component_action;

	// Setup activity to be added
	$activity                    = new BP_Activity_Activity( $id );
	$activity->user_id           = $user_id;
	$activity->component         = $component;
	$activity->type              = $type;
	$activity->action            = $action;
	$activity->content           = $content;
	$activity->primary_link      = $primary_link;
	$activity->item_id           = $item_id;
	$activity->secondary_item_id = $secondary_item_id;
	$activity->date_recorded     = $recorded_time;
	$activity->hide_sitewide     = $hide_sitewide;

	if ( !$activity->save() )
		return false;

	// If this is an activity comment, rebuild the tree
	if ( 'activity_comment' == $activity->type )
		BP_Activity_Activity::rebuild_activity_comment_tree( $activity->item_id );

	wp_cache_delete( 'bp_activity_sitewide_front', 'bp' );
	do_action( 'bp_activity_add', $params );

	return $activity->id;
}

function bp_activity_post_update( $args = '' ) {
	global $bp;

	$defaults = array(
		'content' => false,
		'user_id' => $bp->loggedin_user->id
	);
	$r = wp_parse_args( $args, $defaults );
	extract( $r, EXTR_SKIP );

	if ( empty( $content ) || !strlen( trim( $content ) ) )
		return false;

	// Record this on the user's profile
	$from_user_link = bp_core_get_userlink( $user_id );
	$activity_action = sprintf( __( '%s posted an update:', 'buddypress' ), $from_user_link );
	$activity_content = $content;

	$primary_link = bp_core_get_userlink( $user_id, false, true );

	// Now write the values
	$activity_id = bp_activity_add( array(
		'user_id'      => $user_id,
		'action'       => apply_filters( 'bp_activity_new_update_action', $activity_action ),
		'content'      => apply_filters( 'bp_activity_new_update_content', $activity_content ),
		'primary_link' => apply_filters( 'bp_activity_new_update_primary_link', $primary_link ),
		'component'    => $bp->activity->id,
		'type'         => 'activity_update'
	) );

	// Add this update to the "latest update" usermeta so it can be fetched anywhere.
	update_user_meta( $bp->loggedin_user->id, bp_get_user_meta_key( 'bp_latest_update' ), array( 'id' => $activity_id, 'content' => wp_filter_kses( $content ) ) );

 	// Require the notifications code so email notifications can be set on the 'bp_activity_posted_update' action.
	require_once( BP_PLUGIN_DIR . '/bp-activity/bp-activity-notifications.php' );

	do_action( 'bp_activity_posted_update', $content, $user_id, $activity_id );

	return $activity_id;
}

function bp_activity_new_comment( $args = '' ) {
	global $bp;

	$defaults = array(
		'id'          => false,
		'content'     => false,
		'user_id'     => $bp->loggedin_user->id,
		'activity_id' => false, // ID of the root activity item
		'parent_id'   => false  // ID of a parent comment (optional)
	);

	$params = wp_parse_args( $args, $defaults );
	extract( $params, EXTR_SKIP );

	if ( empty($content) || empty($user_id) || empty($activity_id) )
		return false;

	if ( empty($parent_id) )
		$parent_id = $activity_id;

	// Check to see if the parent activity is hidden, and if so, hide this comment publically.
	$activity = new BP_Activity_Activity( $activity_id );
	$is_hidden = ( (int)$activity->hide_sitewide ) ? 1 : 0;

	// Insert the activity comment
	$comment_id = bp_activity_add( array(
		'id' => $id,
		'action' => apply_filters( 'bp_activity_comment_action', sprintf( __( '%s posted a new activity comment:', 'buddypress' ), bp_core_get_userlink( $user_id ) ) ),
		'content' => apply_filters( 'bp_activity_comment_content', $content ),
		'component' => $bp->activity->id,
		'type' => 'activity_comment',
		'user_id' => $user_id,
		'item_id' => $activity_id,
		'secondary_item_id' => $parent_id,
		'hide_sitewide' => $is_hidden
	) );

	// Send an email notification if settings allow
	require_once( BP_PLUGIN_DIR . '/bp-activity/bp-activity-notifications.php' );
	bp_activity_new_comment_notification( $comment_id, $user_id, $params );

	// Clear the comment cache for this activity
	wp_cache_delete( 'bp_activity_comments_' . $parent_id );

	do_action( 'bp_activity_comment_posted', $comment_id, $params );

	return $comment_id;
}

/**
 * bp_activity_get_activity_id()
 *
 * Fetch the activity_id for an existing activity entry in the DB.
 *
 * @package BuddyPress Activity
 */
function bp_activity_get_activity_id( $args = '' ) {
	$defaults = array(
		'user_id'           => false,
		'component'         => false,
		'type'              => false,
		'item_id'           => false,
		'secondary_item_id' => false,
		'action'            => false,
		'content'           => false,
		'date_recorded'     => false,
	);

	$r = wp_parse_args( $args, $defaults );
	extract( $r, EXTR_SKIP );

 	return apply_filters( 'bp_activity_get_activity_id', BP_Activity_Activity::get_id( $user_id, $component, $type, $item_id, $secondary_item_id, $action, $content, $date_recorded ) );
}

/***
 * Deleting Activity
 *
 * If you're looking to hook into one action that provides the ID(s) of
 * the activity/activities deleted, then use:
 *
 * add_action( 'bp_activity_deleted_activities', 'my_function' );
 *
 * The action passes one parameter that is a single activity ID or an
 * array of activity IDs depending on the number deleted.
 *
 * If you are deleting an activity comment please use bp_activity_delete_comment();
*/

function bp_activity_delete( $args = '' ) {
	global $bp;

	// Pass one or more the of following variables to delete by those variables
	$defaults = array(
		'id'                => false,
		'action'            => false,
		'content'           => false,
		'component'         => false,
		'type'              => false,
		'primary_link'      => false,
		'user_id'           => false,
		'item_id'           => false,
		'secondary_item_id' => false,
		'date_recorded'     => false,
		'hide_sitewide'     => false
	);

	$args = wp_parse_args( $args, $defaults );

	// Adjust the new mention count of any mentioned member
	bp_activity_adjust_mention_count( $args['id'], 'delete' );

	if ( !$activity_ids_deleted = BP_Activity_Activity::delete( $args ) )
		return false;

	// Check if the user's latest update has been deleted
	if ( empty( $args['user_id'] ) )
		$user_id = $bp->loggedin_user->id;
	else
		$user_id = $args['user_id'];

	do_action( 'bp_before_activity_delete', $args );

	$latest_update = get_user_meta( $user_id, bp_get_user_meta_key( 'bp_latest_update' ), true );
	if ( !empty( $latest_update ) ) {
		if ( in_array( (int)$latest_update['id'], (array)$activity_ids_deleted ) )
			delete_user_meta( $user_id, bp_get_user_meta_key( 'bp_latest_update' ) );
	}

	do_action( 'bp_activity_delete', $args );
	do_action( 'bp_activity_deleted_activities', $activity_ids_deleted );

	wp_cache_delete( 'bp_activity_sitewide_front', 'bp' );

	return true;
}
	// The following functions have been deprecated in place of bp_activity_delete()
	function bp_activity_delete_by_item_id( $args = '' ) {
		global $bp;

		$defaults = array( 'item_id' => false, 'component' => false, 'type' => false, 'user_id' => false, 'secondary_item_id' => false );
		$r = wp_parse_args( $args, $defaults );
		extract( $r, EXTR_SKIP );

		return bp_activity_delete( array( 'item_id' => $item_id, 'component' => $component, 'type' => $type, 'user_id' => $user_id, 'secondary_item_id' => $secondary_item_id ) );
	}

	function bp_activity_delete_by_activity_id( $activity_id ) {
		return bp_activity_delete( array( 'id' => $activity_id ) );
	}

	function bp_activity_delete_by_content( $user_id, $content, $component, $type ) {
		return bp_activity_delete( array( 'user_id' => $user_id, 'content' => $content, 'component' => $component, 'type' => $type ) );
	}

	function bp_activity_delete_for_user_by_component( $user_id, $component ) {
		return bp_activity_delete( array( 'user_id' => $user_id, 'component' => $component ) );
	}
	// End deprecation

function bp_activity_delete_comment( $activity_id, $comment_id ) {
	/***
	 * You may want to hook into this filter if you want to override this function and
	 * handle the deletion of child comments differently. Make sure you return false.
	 */
	if ( !apply_filters( 'bp_activity_delete_comment_pre', true, $activity_id, $comment_id ) )
		return false;

	// Delete any children of this comment.
	bp_activity_delete_children( $activity_id, $comment_id );

	// Delete the actual comment
	if ( !bp_activity_delete( array( 'id' => $comment_id, 'type' => 'activity_comment' ) ) )
		return false;

	// Recalculate the comment tree
	BP_Activity_Activity::rebuild_activity_comment_tree( $activity_id );

	do_action( 'bp_activity_delete_comment', $activity_id, $comment_id );

	return true;
}
	function bp_activity_delete_children( $activity_id, $comment_id) {
		// Recursively delete all children of this comment.
		if ( $children = BP_Activity_Activity::get_child_comments( $comment_id ) ) {
			foreach( (array)$children as $child )
				bp_activity_delete_children( $activity_id, $child->id );
		}
		bp_activity_delete( array( 'secondary_item_id' => $comment_id, 'type' => 'activity_comment', 'item_id' => $activity_id ) );
	}

function bp_activity_get_permalink( $activity_id, $activity_obj = false ) {
	global $bp;

	if ( !$activity_obj )
		$activity_obj = new BP_Activity_Activity( $activity_id );

	if ( 'new_blog_post' == $activity_obj->type || 'new_blog_comment' == $activity_obj->type || 'new_forum_topic' == $activity_obj->type || 'new_forum_post' == $activity_obj->type )
		$link = $activity_obj->primary_link;
	else {
		if ( 'activity_comment' == $activity_obj->type )
			$link = bp_get_root_domain() . '/' . $bp->activity->root_slug . '/p/' . $activity_obj->item_id . '/';
		else
			$link = bp_get_root_domain() . '/' . $bp->activity->root_slug . '/p/' . $activity_obj->id . '/';
	}

	return apply_filters( 'bp_activity_get_permalink', $link );
}

function bp_activity_hide_user_activity( $user_id ) {
	return BP_Activity_Activity::hide_all_for_user( $user_id );
}

/**
 * bp_activity_thumbnail_content_images()
 *
 * Take content, remove all images and replace them with one thumbnail image.
 *
 * @package BuddyPress Activity
 * @param $content str - The content to work with
 * @param $link str - Optional. The URL that the image should link to
 * @return $content str - The content with images stripped and replaced with a single thumb.
 */
function bp_activity_thumbnail_content_images( $content, $link = false ) {
	global $post;

	preg_match_all( '/<img[^>]*>/Ui', $content, $matches );
	$content = preg_replace('/<img[^>]*>/Ui', '', $content );

	if ( !empty( $matches ) && !empty( $matches[0] ) ) {
		// Get the SRC value
		preg_match( '/<img.*?(src\=[\'|"]{0,1}.*?[\'|"]{0,1})[\s|>]{1}/i', $matches[0][0], $src );

		// Get the width and height
		preg_match( '/<img.*?(height\=[\'|"]{0,1}.*?[\'|"]{0,1})[\s|>]{1}/i', $matches[0][0], $height );
		preg_match( '/<img.*?(width\=[\'|"]{0,1}.*?[\'|"]{0,1})[\s|>]{1}/i', $matches[0][0], $width );

		if ( !empty( $src ) ) {
			$src = substr( substr( str_replace( 'src=', '', $src[1] ), 0, -1 ), 1 );
			$height = substr( substr( str_replace( 'height=', '', $height[1] ), 0, -1 ), 1 );
			$width = substr( substr( str_replace( 'width=', '', $width[1] ), 0, -1 ), 1 );

			if ( empty( $width ) || empty( $height ) ) {
				$width = 100;
				$height = 100;
			}

			$ratio = (int)$width / (int)$height;
			$new_height = (int)$height >= 100 ? 100 : $height;
			$new_width = $new_height * $ratio;

			$image = '<img src="' . esc_attr( $src ) . '" width="' . $new_width . '" height="' . $new_height . '" alt="' . __( 'Thumbnail', 'buddypress' ) . '" class="align-left thumbnail" />';

			if ( !empty( $link ) ) {
				$image = '<a href="' . $link . '">' . $image . '</a>';
			}

			$content = $image . $content;
		}
	}

	return apply_filters( 'bp_activity_thumbnail_content_images', $content, $matches );
}

?>
