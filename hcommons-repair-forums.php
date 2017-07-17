<?php

/**
 * INTENDED WORKFLOW FOR THIS SCRIPT:
 *
 * 0. install plugin files on both source & destination, no need to activate since everything runs on eval-file
 * 1. on source, cd to plugin dir & eval-file this script with first arg "export"
 * 2. copy all exported csvs to destination into same path as export, this plugin dir
 * 3. on destination, update SOURCE_DOMAIN & DESTINATION_DOMAIN consts below
 * 4. on destination, eval-file this script with first arg "import"
 * 5. rm *csv from both source & destination (or at least move out of plugin dir)
 */

// SOURCE_DOMAIN will be replaced with DESTINATION_DOMAIN when importing.
const SOURCE_DOMAIN = 'mla.rumi.mlacommons.org';
const DESTINATION_DOMAIN = 'mla.chaucer.mlacommons.org';

// we only need the BBP_Forum_Export class from the plugin
require_once( './includes/export.php' );


if ( isset( $args[0] ) && 'export' == $args[0] ) {
	hc_repair_forums_export();
} else if ( isset( $args[0] ) && 'import' == $args[0] ) {
	hc_repair_forums_import();
} else {
	WP_CLI::error( 'Please specify either "export" or "import" as the first argument to this script.' );
	die;
}


/**
 * output group_forums.csv containing all forums which exist & belong to a group
 */
function hc_repair_forums_export() {
	$gs = groups_get_groups( [
		'per_page' => 9999, // TODO scalability might matter at some point...
	] );

	$export = new BBP_Forum_Export;

	$forums_csv = fopen( 'group_forums.csv', 'w' );

	foreach ( $gs['groups'] as $g ) {
		$forum_id = groups_get_groupmeta( $g->id, 'forum_id' )[0];

		$forum = bbp_get_forum( $forum_id );

		if ( $forum ) {

			echo "exporting forum $forum_id for group {$g->id} '{$g->name}'... ";

			// export forum itself to main csv
			fputcsv( $forums_csv, [
				// not part of what's ultimately passed to bbp_insert_forum, but used to map replies once new forum has an id
				'group_id'       => $g->id,
				'forum_id'       => $forum_id,

				'post_parent'    => $forum->post_parent,
				'post_status'    => $forum->post_status,
				'post_type'      => $forum->post_type,
				'post_author'    => $forum->post_author,
				'post_password'  => $forum->post_password,
				'post_content'   => $forum->post_content,
				'post_title'     => $forum->post_title,
				'menu_order'     => $forum->menu_order,
				'comment_status' => $forum->comment_status,
			] );

			// now export posts within that forum to their own csv
			echo "exporting posts... ";

			ob_start();

			$export->set_forum( absint( $forum_id ) );
			$export->csv_cols_out();
			$export->csv_rows_out();

			$posts_csv = fopen( "forum_{$forum_id}_posts.csv", 'w' );
			fwrite( $posts_csv, ob_get_clean() );
			fclose( $posts_csv );

			echo "finished\n";
		} else {
			echo "no forum to export for group {$g->id} '{$g->name}'\n";
		}
	}
}

/**
 * read group_forums.csv along with any existing forum_*_posts.csv and import everything that doesn't already exist
 */
function hc_repair_forums_import() {
	// basically a copy of BBP_Forum_Import::csv_to_array, adjusted to feed hc_repair_posts_import()
	$import_posts = function( $old_forum_id, $new_forum_id ) {
		if ( ( $posts_csv = fopen( "forum_{$old_forum_id}_posts.csv", 'r' ) ) !== FALSE ) {
			echo "importing posts... ";

			$header = NULL;
			$data = array();

			while ( ( $row = fgetcsv( $posts_csv, 0, ';' ) ) !== FALSE ) {
				if( ! $header )
					$header = $row;
				else
					$data[] = array_combine( $header, $row );
			}

			fclose( $posts_csv );

			hc_repair_posts_import( $new_forum_id, $data );
		} else {
			echo "no posts to import. ";
		}
	};

	if ( ( $forums_csv = fopen( 'group_forums.csv', 'r' ) ) !== FALSE ) {
		while ( ( $data = fgetcsv( $forums_csv ) ) !== FALSE ) {
			$group_id = $data[0];
			$old_forum_id = $data[1];

			if ( ! $group_id ) {
				echo "skipping - no group id for forum $old_forum_id\n";
				continue;
			}

			$g = groups_get_group( $group_id );

			if ( empty( $g->id ) ) {
				echo "skipping - no group found with id $group_id\n";
				continue;
			}

			$meta_forum_ids = groups_get_groupmeta( $g->id, 'forum_id' );

			if ( isset( $meta_forum_ids[0] ) && $old_forum_id != $meta_forum_ids[0] ) {
				echo "skipping - source forum $old_forum_id does not match existing forum ${meta_forum_ids[0]} for group '{$g->name}'\n";
				continue;
			}

			$forum = bbp_get_forum( $old_forum_id );

			// forum does not exist, create it and import its topics & replies.
			if ( ! $forum ) {
				echo "importing forum $old_forum_id for group '{$g->name}'... ";

				$forum_data = [
					'post_parent' => $data[2],
					'post_status' => $data[3],
					'post_type' => $data[4],
					'post_author' => $data[5],
					'post_password' => $data[6],
					'post_content' => str_replace( SOURCE_DOMAIN, DESTINATION_DOMAIN, $data[7] ),
					'post_title' => $data[8],
					'menu_order' => $data[9],
					'comment_status' => $data[10],
				];

				$new_forum_id = bbp_insert_forum( $forum_data );

				echo "new forum $new_forum_id created... ";

				$import_posts( $old_forum_id, $new_forum_id );

				echo "finished\n";
			} else {
				echo "skipping existing forum for group '{$g->name}'\n";
			}

		}

	}
}

/**
 * mostly copied from BBP_Forum_Import::import()
 * adjusted to allow passing forum id & data as parameters
 * and to replace domains according to consts at the top of this file
 * and not to do any admin redirects
 */
function hc_repair_posts_import( $forum_id, $csv_array ) {
	foreach( $csv_array as $key => $line ) {

		$post_args = $line;
		$meta_args = array();

		// Setup author date
		if( $post_args['anonymous'] == '1' ) {
			$meta_args['anonymous_email'] = $line['post_author'];
		} else {

			$user = get_user_by( 'email', $post_args['post_author'] );

			if ( ! $user ) {
				$user = get_user_by( 'login', $post_args['user_login'] );
			}

			if( ! $user ) {
				// The user doesn't exist, so create them
				$user = wp_insert_user( array(
					'user_email' => $post_args['post_author'],
					'user_login' => $post_args['user_login']
				) );
			}
			$post_args['post_author'] = $user->ID;
		}

		// Decode content
		$post_args['post_content'] = html_entity_decode( $post_args['post_content'] );

		// replace domains
		$post_args['post_content'] = str_replace( SOURCE_DOMAIN, DESTINATION_DOMAIN, $post_args['post_content'] );

		$topic_type = bbp_get_topic_post_type();
		$reply_type = bbp_get_reply_post_type();

		// Remove the post args we don't want sent to wp_insert_post
		unset( $post_args['anonymous']  );
		unset( $post_args['user_login'] );

		switch( $line['post_type'] ) {

		case $topic_type :

			// Set the forum parent for topics
			$post_args['post_parent'] = $forum_id;
			$meta_args['voice_count'] = $line['voices'];
			$meta_args['reply_count'] = $post_args['reply_count'];

			$topic_id = bbp_insert_topic( $post_args, $meta_args );

			// Subscribe the original poster to the topic
			bbp_add_user_subscription( $post_args['post_author'], $topic_id );

			// Add the topic to the user's favorites
			if( bbp_is_user_favorite( $post_args['post_author'], $topic_id ) )
				bbp_add_user_favorite( $post_args['post_author'], $topic_id );

			// Set topic as resolved if GetShopped Support Forum is active
			if( $post_args['resolved'] == '1' )
				add_post_meta( $topic_id, '_bbps_topic_status', '2' );

			break;

		case $reply_type :

			// Set the forum parent for replies. The topic ID is created above when the replie's topic is first created
			$post_args['post_parent'] = $topic_id;

			$reply_id = bbp_insert_reply( $post_args, $meta_args );

			// Subscribe reply author, if not already
			if( ! bbp_is_user_subscribed( $post_args['post_author'], $topic_id ) )
				bbp_add_user_subscription( $post_args['post_author'], $topic_id );

			// Mark as favorite
			if( bbp_is_user_favorite( $post_args['post_author'], $topic_id ) )
				bbp_add_user_favorite( $post_args['post_author'], $topic_id );

			// Check if the next row is a topic, meaning we have reached the last reply and need to update the last active time
			if( $csv_array[ $key + 1 ]['post_type'] == bbp_get_topic_post_type() )
				bbp_update_forum_last_active_time( $forum_id, $post_args['post_date'] );

			break;

		}

	}

	// Recount forum topic / reply counts
	bbp_admin_repair_forum_topic_count();
	bbp_admin_repair_forum_reply_count();
}
