<?php
/**
 * Plugin Name:       Client Media Folders
 * Description:       Adds personal media folders to the WordPress media modal everywhere it opens (front end, wp-admin, Media Library, block editor, page builders) for every user who can upload files. Create, rename and delete folders and move files between them. No shortcode needed.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       client-media-folders
 *
 * Developer hooks
 * ---------------
 * Filters:
 *   cmf_allowed_roles       (array)  Restrict the feature to these roles. Default: empty = every user who can upload_files.
 *   cmf_enqueue_assets      (bool)   Return false to stop the JS/CSS loading on a given request.
 *   cmf_folders_payload     (array)  Folder list sent to the browser: [ {id, name}, ... ].
 *   cmf_max_folder_name     (int)    Max folder-name length. Default 60.
 *   cmf_can_move_attachment (bool)   ( $can, $attachment_id, $user_id ) Default: only the file's author.
 * Actions:
 *   cmf_folder_created      ( $folder_id, $name, $user_id )
 *   cmf_folder_renamed      ( $folder_id, $name, $user_id )
 *   cmf_folder_deleted      ( $folder_id, $user_id )
 *   cmf_files_moved         ( $attachment_ids, $target_folder_id|'none', $user_id )
 */

defined( 'ABSPATH' ) || exit;

final class CMF_Client_Media_Folders {

	const VERSION      = '1.1.0';
	const USER_META    = 'cmf_folders';  // array( folder_id => name ), stored per user.
	const ATTACH_META  = '_cmf_folder';  // folder_id stored on each attachment.
	const NONCE_ACTION = 'cmf_media_folders';

	private static $assets_enqueued = false;

	/* ------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------- */

	public static function init() {
		// Fires whenever wp_enqueue_media() runs: front end, wp-admin, block editor, page builders.
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_assets' ) );

		// Media modal: query filter, JS model data, upload into folder.
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_query_args' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'filter_attachment_js' ), 10, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'assign_folder_on_upload' ) );

		// Folder CRUD + move (logged-in users only).
		foreach ( array( 'create', 'rename', 'delete', 'move' ) as $action ) {
			add_action( 'wp_ajax_cmf_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}

		// Media Library list view (upload.php?mode=list): folder filter + bulk move.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_filter_query' ) );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	public static function activate() {
		$role = get_role( 'client' );
		if ( ! $role ) {
			add_role(
				'client',
				__( 'Client', 'client-media-folders' ),
				array(
					'read'         => true,
					'upload_files' => true,
				)
			);
		} else {
			$role->add_cap( 'upload_files' );
		}
	}

	/* ------------------------------------------------------------------
	 * Permissions + storage helpers
	 * ---------------------------------------------------------------- */

	/** Every logged-in user who can upload files (admin, client, editor, author...). */
	public static function user_allowed() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! user_can( $user, 'upload_files' ) ) {
			return false;
		}
		$roles = (array) apply_filters( 'cmf_allowed_roles', array() );
		if ( $roles ) {
			return (bool) array_intersect( $roles, (array) $user->roles );
		}
		return true;
	}

	private static function get_folders( $user_id ) {
		$folders = get_user_meta( $user_id, self::USER_META, true );
		if ( ! is_array( $folders ) ) {
			$folders = array();
		}
		natcasesort( $folders );
		return $folders;
	}

	private static function save_folders( $user_id, array $folders ) {
		update_user_meta( $user_id, self::USER_META, $folders );
	}

	private static function payload( $user_id ) {
		$list = array();
		foreach ( self::get_folders( $user_id ) as $id => $name ) {
			$list[] = array(
				'id'   => (string) $id,
				'name' => (string) $name,
			);
		}
		return apply_filters( 'cmf_folders_payload', $list, $user_id );
	}

	private static function clean_name( $raw, array $folders, $ignore_id = '' ) {
		$name = sanitize_text_field( wp_unslash( (string) $raw ) );
		$name = mb_substr( $name, 0, (int) apply_filters( 'cmf_max_folder_name', 60 ) );

		if ( '' === $name ) {
			return new WP_Error( 'cmf_empty', __( 'Please enter a folder name.', 'client-media-folders' ) );
		}
		foreach ( $folders as $fid => $existing ) {
			if ( (string) $fid !== (string) $ignore_id && 0 === strcasecmp( $existing, $name ) ) {
				return new WP_Error( 'cmf_duplicate', __( 'A folder with that name already exists.', 'client-media-folders' ) );
			}
		}
		return $name;
	}

	/** Meta query for a folder id / 'none' / 'all'; null means "no folder filter". */
	private static function folder_meta_query( $requested, $user_id ) {
		if ( 'none' === $requested ) {
			return array(
				'key'     => self::ATTACH_META,
				'compare' => 'NOT EXISTS',
			);
		}
		$folders = self::get_folders( $user_id );
		if ( '' !== $requested && 'all' !== $requested && isset( $folders[ $requested ] ) ) {
			return array(
				'key'   => self::ATTACH_META,
				'value' => $requested,
			);
		}
		return null;
	}

	/** Moves attachments into a folder ('none' = unfiled). Returns the ids actually moved. */
	private static function move_attachments( array $ids, $target, $user_id ) {
		$moved = array();
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $attachment_id ) {
			$can = 'attachment' === get_post_type( $attachment_id )
				&& (int) get_post_field( 'post_author', $attachment_id ) === (int) $user_id;
			if ( ! apply_filters( 'cmf_can_move_attachment', $can, $attachment_id, $user_id ) ) {
				continue;
			}
			if ( 'none' === $target ) {
				delete_post_meta( $attachment_id, self::ATTACH_META );
			} else {
				update_post_meta( $attachment_id, self::ATTACH_META, $target );
			}
			$moved[] = $attachment_id;
		}
		do_action( 'cmf_files_moved', $moved, $target, $user_id );
		return $moved;
	}

	/* ------------------------------------------------------------------
	 * Media modal hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Filter the library query by folder. Core strips unknown keys from
	 * $_REQUEST['query'] before this filter runs, so we read the raw value.
	 */
	public static function filter_query_args( $args ) {
		if ( ! self::user_allowed() ) {
			return $args;
		}

		// Users who cannot see others' content only ever see their own files.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$requested = 'all';
		if ( isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && isset( $_REQUEST['query']['cmf_folder'] ) ) {
			$requested = sanitize_text_field( wp_unslash( $_REQUEST['query']['cmf_folder'] ) );
		}

		$meta = self::folder_meta_query( $requested, get_current_user_id() );
		if ( $meta ) {
			$args['meta_query'][] = $meta;
		}
		return $args;
	}

	/** Expose the folder id to the media modal's JS attachment model. */
	public static function filter_attachment_js( $response, $attachment ) {
		$response['cmfFolder'] = (string) get_post_meta( $attachment->ID, self::ATTACH_META, true );
		return $response;
	}

	/** Files uploaded while a folder is selected land in that folder. */
	public static function assign_folder_on_upload( $attachment_id ) {
		if ( ! self::user_allowed() || empty( $_REQUEST['cmf_folder'] ) ) {
			return;
		}
		$folder = sanitize_text_field( wp_unslash( $_REQUEST['cmf_folder'] ) );
		if ( in_array( $folder, array( 'all', 'none' ), true ) ) {
			return;
		}
		$folders = self::get_folders( get_current_user_id() );
		if ( isset( $folders[ $folder ] ) ) {
			update_post_meta( $attachment_id, self::ATTACH_META, $folder );
		}
	}

	/* ------------------------------------------------------------------
	 * AJAX: create / rename / delete / move
	 * ---------------------------------------------------------------- */

	private static function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! self::user_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'client-media-folders' ) ), 403 );
		}
	}

	public static function ajax_create() {
		self::guard();
		$user_id = get_current_user_id();
		$folders = self::get_folders( $user_id );

		$name = self::clean_name( isset( $_POST['name'] ) ? $_POST['name'] : '', $folders );
		if ( is_wp_error( $name ) ) {
			wp_send_json_error( array( 'message' => $name->get_error_message() ) );
		}

		$id             = 'f' . strtolower( wp_generate_password( 8, false, false ) );
		$folders[ $id ] = $name;
		self::save_folders( $user_id, $folders );

		do_action( 'cmf_folder_created', $id, $name, $user_id );
		wp_send_json_success( array( 'id' => $id, 'folders' => self::payload( $user_id ) ) );
	}

	public static function ajax_rename() {
		self::guard();
		$user_id = get_current_user_id();
		$folders = self::get_folders( $user_id );
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		if ( ! isset( $folders[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Folder not found.', 'client-media-folders' ) ), 404 );
		}
		$name = self::clean_name( isset( $_POST['name'] ) ? $_POST['name'] : '', $folders, $id );
		if ( is_wp_error( $name ) ) {
			wp_send_json_error( array( 'message' => $name->get_error_message() ) );
		}

		$folders[ $id ] = $name;
		self::save_folders( $user_id, $folders );

		do_action( 'cmf_folder_renamed', $id, $name, $user_id );
		wp_send_json_success( array( 'folders' => self::payload( $user_id ) ) );
	}

	/** Deleting a folder never deletes files; they simply become "Unfiled". */
	public static function ajax_delete() {
		self::guard();
		$user_id = get_current_user_id();
		$folders = self::get_folders( $user_id );
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		if ( ! isset( $folders[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Folder not found.', 'client-media-folders' ) ), 404 );
		}

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => self::ATTACH_META,
				'meta_value'     => $id,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $attachment_ids as $attachment_id ) {
			delete_post_meta( $attachment_id, self::ATTACH_META );
		}

		unset( $folders[ $id ] );
		self::save_folders( $user_id, $folders );

		do_action( 'cmf_folder_deleted', $id, $user_id );
		wp_send_json_success( array( 'folders' => self::payload( $user_id ) ) );
	}

	public static function ajax_move() {
		self::guard();
		$user_id = get_current_user_id();
		$folders = self::get_folders( $user_id );
		$target  = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : 'none';

		if ( 'none' !== $target && ! isset( $folders[ $target ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Folder not found.', 'client-media-folders' ) ), 404 );
		}

		$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		wp_send_json_success( array( 'moved' => self::move_attachments( $ids, $target, $user_id ) ) );
	}

	/* ------------------------------------------------------------------
	 * Media Library list view (upload.php?mode=list)
	 * ---------------------------------------------------------------- */

	public static function list_filter_dropdown( $post_type = '' ) {
		static $done = false;
		if ( $done || 'attachment' !== $post_type || ! self::user_allowed() ) {
			return;
		}
		$done    = true;
		$current = isset( $_GET['cmf_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['cmf_folder'] ) ) : '';

		echo '<label for="cmf-folder-filter" class="screen-reader-text">' . esc_html__( 'Filter by folder', 'client-media-folders' ) . '</label>';
		echo '<select name="cmf_folder" id="cmf-folder-filter">';
		echo '<option value="">' . esc_html__( 'All folders', 'client-media-folders' ) . '</option>';
		echo '<option value="none"' . selected( $current, 'none', false ) . '>' . esc_html__( 'Unfiled', 'client-media-folders' ) . '</option>';
		foreach ( self::get_folders( get_current_user_id() ) as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
	}

	public static function list_filter_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() || ! self::user_allowed() ) {
			return;
		}
		$requested = isset( $_GET['cmf_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['cmf_folder'] ) ) : '';
		$meta      = self::folder_meta_query( $requested, get_current_user_id() );
		if ( $meta ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = $meta;
			$query->set( 'meta_query', $meta_query );
		}
	}

	public static function bulk_actions( $actions ) {
		if ( ! self::user_allowed() ) {
			return $actions;
		}
		$actions['cmf_move_none'] = __( 'Move to: Unfiled', 'client-media-folders' );
		foreach ( self::get_folders( get_current_user_id() ) as $id => $name ) {
			/* translators: %s: folder name */
			$actions[ 'cmf_move_' . $id ] = sprintf( __( 'Move to folder: %s', 'client-media-folders' ), $name );
		}
		return $actions;
	}

	public static function handle_bulk( $redirect_to, $action, $post_ids ) {
		if ( 0 !== strpos( (string) $action, 'cmf_move_' ) || ! self::user_allowed() ) {
			return $redirect_to;
		}
		$target  = sanitize_text_field( substr( $action, strlen( 'cmf_move_' ) ) );
		$user_id = get_current_user_id();
		$folders = self::get_folders( $user_id );
		if ( 'none' !== $target && ! isset( $folders[ $target ] ) ) {
			return $redirect_to;
		}
		$moved = self::move_attachments( (array) $post_ids, $target, $user_id );
		return add_query_arg( 'cmf_moved', count( $moved ), $redirect_to );
	}

	public static function bulk_notice() {
		global $pagenow;
		if ( 'upload.php' !== $pagenow || ! isset( $_GET['cmf_moved'] ) ) {
			return;
		}
		$count = absint( $_GET['cmf_moved'] );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: %d: number of files */
			esc_html( sprintf( _n( 'Moved %d file.', 'Moved %d files.', $count, 'client-media-folders' ), $count ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Assets (front end AND admin)
	 * ---------------------------------------------------------------- */

	public static function enqueue_assets() {
		if ( self::$assets_enqueued || ! self::user_allowed() ) {
			return;
		}
		if ( ! apply_filters( 'cmf_enqueue_assets', true ) ) {
			return;
		}
		self::$assets_enqueued = true;

		wp_enqueue_style( 'cmf', plugins_url( 'cmf.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'cmf', plugins_url( 'cmf.js', __FILE__ ), array( 'jquery', 'underscore', 'backbone', 'media-views' ), self::VERSION, true );

		wp_localize_script(
			'cmf',
			'CMF',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'userId'  => get_current_user_id(),
				'folders' => self::payload( get_current_user_id() ),
				'i18n'    => array(
					'folders'       => __( 'Folders', 'client-media-folders' ),
					'folder'        => __( 'Folder', 'client-media-folders' ),
					'allFiles'      => __( 'All files', 'client-media-folders' ),
					'unfiled'       => __( 'Unfiled', 'client-media-folders' ),
					'newFolder'     => __( '+ New folder', 'client-media-folders' ),
					'rename'        => __( 'Rename', 'client-media-folders' ),
					'delete'        => __( 'Delete', 'client-media-folders' ),
					'moveTo'        => __( 'Move selected to', 'client-media-folders' ),
					'move'          => __( 'Move', 'client-media-folders' ),
					'selectHint'    => __( 'Select one or more files first.', 'client-media-folders' ),
					'newPrompt'     => __( 'New folder name:', 'client-media-folders' ),
					'renamePrompt'  => __( 'Rename folder to:', 'client-media-folders' ),
					'deleteConfirm' => __( 'Delete this folder? Files inside will NOT be deleted; they move to "Unfiled".', 'client-media-folders' ),
					'moved'         => __( 'Moved %d.', 'client-media-folders' ),
					'movedPartial'  => __( 'Moved %1$d of %2$d (you can only move files you uploaded).', 'client-media-folders' ),
					'error'         => __( 'Something went wrong. Please try again.', 'client-media-folders' ),
				),
			)
		);
	}
}

CMF_Client_Media_Folders::init();
register_activation_hook( __FILE__, array( 'CMF_Client_Media_Folders', 'activate' ) );
