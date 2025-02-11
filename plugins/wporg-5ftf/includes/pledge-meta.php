<?php
/**
 * This file handles the operations related to registering and handling pledge meta values for the CPT.
 */

namespace WordPressDotOrg\FiveForTheFuture\PledgeMeta;

use WordPressDotOrg\FiveForTheFuture;
use WordPressDotOrg\FiveForTheFuture\{ Contributor, Email, Pledge, PledgeForm, XProfile };
use WP_Post, WP_Error;

defined( 'WPINC' ) || die();

const META_PREFIX = FiveForTheFuture\PREFIX . '_';

add_action( 'init',                   __NAMESPACE__ . '\register_pledge_meta' );
add_action( 'init',                   __NAMESPACE__ . '\schedule_cron_jobs' );
add_action( 'admin_init',             __NAMESPACE__ . '\add_meta_boxes' );
add_action( 'save_post',              __NAMESPACE__ . '\save_pledge', 10, 2 );
add_action( 'admin_enqueue_scripts',  __NAMESPACE__ . '\enqueue_assets' );
add_action( 'transition_post_status', __NAMESPACE__ . '\maybe_update_single_cached_pledge_data', 10, 3 );
add_action( 'update_all_cached_pledge_data', __NAMESPACE__. '\update_all_cached_pledge_data' );

// Both hooks must be used because `updated` doesn't fire if the post meta didn't previously exist.
add_action( 'updated_postmeta', __NAMESPACE__ . '\update_generated_meta', 10, 4 );
add_action( 'added_post_meta',  __NAMESPACE__ . '\update_generated_meta', 10, 4 );

/**
 * Define pledge meta fields and their properties.
 *
 * @param string $context Optional. The part of the config to return. 'user_input', 'generated', or 'all'.
 *
 * @return array
 */
function get_pledge_meta_config( $context = 'all' ) {
	$user_input = array(
		'org-description'  => array(
			'single'            => true,
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_description',
			'show_in_rest'      => true,
			'php_filter'        => FILTER_UNSAFE_RAW,
		),
		'org-name'         => array(
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
			'php_filter'        => FILTER_SANITIZE_STRING,
		),
		'org-url'          => array(
			'single'            => true,
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
			'php_filter'        => FILTER_VALIDATE_URL,
		),
		'org-pledge-email' => array(
			'single'            => true,
			'sanitize_callback' => 'sanitize_email',
			'show_in_rest'      => false,
			'php_filter'        => FILTER_VALIDATE_EMAIL,
		),
	);

	$generated = array(
		'org-domain'                    => array(
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
		),
		'pledge-email-confirmed'        => array(
			'single'            => true,
			'sanitize_callback' => 'wp_validate_boolean',
			'show_in_rest'      => false,
		),
		'pledge-confirmed-contributors' => array(
			'single'            => true,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
		),
		'pledge-total-hours'            => array(
			'single'            => true,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
		),
	);

	switch ( $context ) {
		case 'user_input':
			$return = $user_input;
			break;
		case 'generated':
			$return = $generated;
			break;
		default:
			$return = array_merge( $user_input, $generated );
			break;
	}

	return $return;
}

/**
 * Sanitize description fields.
 *
 * @param string $insecure
 *
 * @return string
 */
function sanitize_description( $insecure ) {
	$secure = wp_kses_data( $insecure );
	$secure = wpautop( $secure );
	$secure = wp_unslash( wp_rel_nofollow( $secure ) );

	return $secure;
}

/**
 * Register post meta keys for the custom post type.
 *
 * @return void
 */
function register_pledge_meta() {
	$meta = get_pledge_meta_config();

	foreach ( $meta as $key => $args ) {
		$meta_key = META_PREFIX . $key;

		register_post_meta( Pledge\CPT_ID, $meta_key, $args );
	}
}

/**
 * Schedule cron jobs.
 *
 * This needs to run on the `init` action, because Cavalcade isn't fully loaded before that, and events
 * wouldn't be scheduled.
 *
 * @see https://dotorg.trac.wordpress.org/changeset/15351/
 */
function schedule_cron_jobs() {
	if ( ! wp_next_scheduled( 'update_all_cached_pledge_data' ) ) {
		wp_schedule_event( time(), 'hourly', 'update_all_cached_pledge_data' );
	}
}

/**
 * Regularly update the cached data for all pledges.
 *
 * Outside of this cron job, it's only updated when a contributor post changes status, but it's possible for
 * a contributor to edit their profile's # of hours at any time. If we didn't regularly check and update it,
 * then it could be permanently out of date.
 */
function update_all_cached_pledge_data() {
	$pledges = get_posts( array(
		'post_type'   => Pledge\CPT_ID,
		'post_status' => 'publish',
		'numberposts' => 1000,
	) );

	foreach ( $pledges as $pledge ) {
		update_single_cached_pledge_data( $pledge->ID );
	}
}

/**
 * Adds meta boxes for the custom post type.
 *
 * @return void
 */
function add_meta_boxes() {
	add_meta_box(
		'pledge-email',
		__( 'Pledge Email', 'wordpressorg' ),
		__NAMESPACE__ . '\render_meta_boxes',
		Pledge\CPT_ID,
		'normal',
		'high'
	);

	add_meta_box(
		'org-info',
		__( 'Organization Information', 'wordpressorg' ),
		__NAMESPACE__ . '\render_meta_boxes',
		Pledge\CPT_ID,
		'normal',
		'high'
	);

	add_meta_box(
		'pledge-contributors',
		__( 'Contributors', 'wordpressorg' ),
		__NAMESPACE__ . '\render_meta_boxes',
		Pledge\CPT_ID,
		'normal',
		'high'
	);
}

/**
 * Builds the markup for all meta boxes
 *
 * @param WP_Post $pledge
 * @param array   $box
 */
function render_meta_boxes( $pledge, $box ) {
	$readonly  = ! current_user_can( 'edit_page', $pledge->ID );
	$is_manage = true;

	$data = array();
	foreach ( get_pledge_meta_config() as $key => $config ) {
		$data[ $key ] = get_post_meta( $pledge->ID, META_PREFIX . $key, $config['single'] );
	}

	$contributors = Contributor\get_pledge_contributors_data( $pledge->ID );

	echo '<div class="pledge-form">';

	switch ( $box['id'] ) {
		case 'pledge-email':
			require FiveForTheFuture\get_views_path() . 'inputs-pledge-org-email.php';
			break;

		case 'org-info':
			require FiveForTheFuture\get_views_path() . 'inputs-pledge-org-info.php';
			break;

		case 'pledge-contributors':
			require FiveForTheFuture\get_views_path() . 'manage-contributors.php';
			break;
	}

	echo '</div>';
}

/**
 * Save the pledge data.
 *
 * This only fires when the pledge post itself is created or updated.
 *
 * @param int     $pledge_id
 * @param WP_Post $pledge
 */
function save_pledge( $pledge_id, $pledge ) {
	$get_action      = filter_input( INPUT_GET, 'action' );
	$post_action     = filter_input( INPUT_POST, 'action' );
	$ignored_actions = array( 'trash', 'untrash', 'restore' );

	/*
	 * This is only intended to run when the front end form and wp-admin forms are submitted, not when posts are
	 * programmatically updated.
	 */
	if ( 'Submit Pledge' !== $post_action && 'editpost' !== $post_action ) {
		return;
	}

	if ( $get_action && in_array( $get_action, $ignored_actions, true ) ) {
		return;
	}

	if ( ! $pledge instanceof WP_Post || Pledge\CPT_ID !== $pledge->post_type ) {
		return;
	}

	// if ( ! current_user_can( 'edit_pledge', $pledge_id ) ) {} -- todo re-enable once setup cap mapping or whatever.

	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'auto-draft' === $pledge->post_status ) {
		return;
	}

	$submitted_meta = PledgeForm\get_form_submission();

	if ( is_wp_error( has_required_pledge_meta( $submitted_meta ) ) ) {
		return;
	}

	save_pledge_meta( $pledge_id, $submitted_meta );

	if ( filter_input( INPUT_POST, 'resend-pledge-confirmation' ) ) {
		Email\send_pledge_confirmation_email(
			filter_input( INPUT_GET, 'resend-pledge-id', FILTER_VALIDATE_INT ),
			get_page_by_path( 'for-organizations' )->ID
		);
	}
}

/**
 * Save the pledge's meta fields.
 *
 * @param int   $pledge_id
 * @param array $new_values
 *
 * @return void
 */
function save_pledge_meta( $pledge_id, $new_values ) {
	$config = get_pledge_meta_config();

	foreach ( $new_values as $key => $value ) {
		if ( array_key_exists( $key, $config ) ) {
			$meta_key = META_PREFIX . $key;

			// Since the sanitize callback is called during this function, it could still end up
			// saving an empty value to the database.
			update_post_meta( $pledge_id, $meta_key, $value );
		}
	}
}

/**
 * Updated some generated meta values based on changes in user input meta values.
 *
 * This is hooked to the `updated_{$meta_type}_meta` action, which only fires if a submitted post meta value
 * is different from the previous value. Thus here we assume the values of specific meta keys are changed
 * when they come through this function.
 *
 * @param int    $meta_id
 * @param int    $object_id
 * @param string $meta_key
 * @param mixed  $_meta_value
 *
 * @return void
 */
function update_generated_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
	$post_type = get_post_type( $object_id );

	if ( Pledge\CPT_ID !== $post_type ) {
		return;
	}

	switch ( $meta_key ) {
		case META_PREFIX . 'org-name':
			if ( 'updated_postmeta' === current_action() ) {
				wp_update_post( array(
					'ID'         => $object_id,
					'post_title' => $_meta_value,
				) );
			}
			break;

		case META_PREFIX . 'org-url':
			$domain = get_normalized_domain_from_url( $_meta_value );
			update_post_meta( $object_id, META_PREFIX . 'org-domain', $domain );
			break;

		case META_PREFIX . 'pledge-email':
			delete_post_meta( $object_id, META_PREFIX . 'pledge-email-confirmed' );
			break;
	}
}

/**
 * Update the cached pledge data when a contributor post changes statuses.
 *
 * Note that contributor posts should always be trashed instead of deleted completely when a contributor is
 * removed from a pledge.
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 *
 * @return void
 */
function maybe_update_single_cached_pledge_data( $new_status, $old_status, WP_Post $post ) {
	if ( Contributor\CPT_ID !== get_post_type( $post ) ) {
		return;
	}

	if ( $new_status === $old_status ) {
		return;
	}

	$pledge = get_post( $post->post_parent );

	if ( $pledge instanceof WP_Post ) {
		update_single_cached_pledge_data( $pledge->ID );
	}
}

/**
 * Update the cached data for the given pledge.
 *
 * This is saved so that it can be easily queried against, and also to make stats calculations easier.
 *
 * @param int $pledge_id
 */
function update_single_cached_pledge_data( $pledge_id ) {
	$pledge_data = XProfile\get_aggregate_contributor_data_for_pledge( $pledge_id );

	update_post_meta( $pledge_id, META_PREFIX . 'pledge-confirmed-contributors', $pledge_data['contributors'] );
	update_post_meta( $pledge_id, META_PREFIX . 'pledge-total-hours', $pledge_data['hours'] );
}

/**
 * Check that an array contains values for all required keys.
 *
 * @return bool|WP_Error True if all required values are present. Otherwise WP_Error.
 */
function has_required_pledge_meta( array $submission ) {
	$error = new WP_Error();

	$required = array_keys( get_pledge_meta_config( 'user_input' ) );

	foreach ( $required as $key ) {
		if ( ! isset( $submission[ $key ] ) || is_null( $submission[ $key ] ) ) {
			$error->add(
				'required_field_empty',
				sprintf(
					__( 'The <code>%s</code> field does not have a value.', 'wporg' ),
					sanitize_key( $key )
				)
			);
		} elseif ( false === $submission[ $key ] ) {
			$error->add(
				'required_field_invalid',
				sprintf(
					__( 'The <code>%s</code> field has an invalid value.', 'wporg' ),
					sanitize_key( $key )
				)
			);
		}
	}

	if ( ! empty( $error->get_error_messages() ) ) {
		return $error;
	}

	return true;
}

/**
 * Get the metadata for a given pledge, or a default set if no pledge is provided.
 *
 * @param int    $pledge_id
 * @param string $context
 * @return array Pledge data
 */
function get_pledge_meta( $pledge_id = 0, $context = '' ) {
	// Get existing pledge, if it exists.
	$pledge = get_post( $pledge_id );

	$keys = get_pledge_meta_config( $context );
	$meta = array();

	// Get POST'd submission, if it exists.
	$submission = PledgeForm\get_form_submission();

	foreach ( $keys as $key => $config ) {
		if ( isset( $submission[ $key ] ) ) {
			$meta[ $key ] = $submission[ $key ];
		} elseif ( $pledge instanceof WP_Post ) {
			$meta_key     = META_PREFIX . $key;
			$meta[ $key ] = get_post_meta( $pledge->ID, $meta_key, true );
		} else {
			$meta[ $key ] = $config['default'] ?: '';
		}
	}

	return $meta;
}

/**
 * Isolate the domain from a given URL and remove the `www.` if necessary.
 *
 * @param string $url
 *
 * @return string
 */
function get_normalized_domain_from_url( $url ) {
	$domain = wp_parse_url( $url, PHP_URL_HOST );
	$domain = preg_replace( '#^www\.#', '', $domain );

	return $domain;
}

/**
 * Enqueue CSS file for admin page.
 *
 * @return void
 */
function enqueue_assets() {
	$ver = filemtime( FiveForTheFuture\PATH . '/assets/css/admin.css' );
	wp_register_style( '5ftf-admin', plugins_url( 'assets/css/admin.css', __DIR__ ), [], $ver );

	$ver = filemtime( FiveForTheFuture\PATH . '/assets/js/admin.js' );
	wp_register_script( '5ftf-admin', plugins_url( 'assets/js/admin.js', __DIR__ ), [ 'jquery', 'wp-util' ], $ver );

	$script_data = [
		'pledgeId'    => get_the_ID(),
		'manageNonce' => wp_create_nonce( 'manage-contributors' ),
	];
	wp_add_inline_script(
		'5ftf-admin',
		sprintf(
			'var FiveForTheFuture = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $script_data ) )
		),
		'before'
	);

	$current_page = get_current_screen();
	if ( Pledge\CPT_ID === $current_page->id ) {
		wp_enqueue_style( '5ftf-admin' );
		wp_enqueue_script( '5ftf-admin' );
	}
}
