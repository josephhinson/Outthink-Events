<?php
/**
 * Events without a page of their own.
 *
 * Two kinds of event have no page:
 *   - Private events: in a "private" event category. Lists show them without
 *     details (by default only the date and location).
 *   - No-link events: "Details link to" set to "No link". Lists show them in full,
 *     just without a details link.
 *
 * Both still appear in upcoming lists. For visitors who can't edit events, they:
 *   - have no single page (/event/{slug}/ returns a 404)
 *   - are left out of search, feeds, archives, other front-end queries and core sitemaps
 *   - are left out of the Yoast SEO sitemap
 *   - are skipped by previous/next post links on other event pages
 *   - are hidden from the public /wp/v2/event REST API
 *
 * Editors still see everything, so they can preview and manage these events.
 *
 * Which categories count as private (slugs): filter `ot_events_private_categories`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outthink_Events_Privacy {

	/** Query var that lets the plugin's own queries include events without a page. */
	const INCLUDE_PRIVATE = 'ot_events_include_private';

	/** @var int[]|null Cached IDs of events without a page, for this request. */
	private static $hidden_ids = null;

	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'exclude_from_queries' ) );
		add_action( 'template_redirect', array( $this, 'block_single_page' ), 1 );
		add_filter( 'get_previous_post_where', array( $this, 'exclude_from_adjacent' ), 10, 5 );
		add_filter( 'get_next_post_where', array( $this, 'exclude_from_adjacent' ), 10, 5 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'exclude_from_yoast_sitemap' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'block_rest_single' ), 10, 3 );

		// Keep the cached ID list correct when events change within a request.
		add_action( 'save_post_' . Outthink_Events::POST_TYPE, array( __CLASS__, 'flush_cache' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush_cache' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_cache_on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_cache_on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_cache_on_meta' ), 10, 3 );
	}

	/**
	 * Category slugs that make an event private.
	 *
	 * @return string[]
	 */
	public static function private_categories() {
		return array_values( array_filter( (array) apply_filters( 'ot_events_private_categories', array( 'private' ) ) ) );
	}

	/**
	 * @param WP_Post|int $post Event.
	 * @return bool
	 */
	public static function is_private( $post ) {
		$categories = self::private_categories();
		return $categories && has_term( $categories, Outthink_Events::TAXONOMY, $post );
	}

	/**
	 * Whether an event has no page of its own (private, or "Details link to: No link").
	 *
	 * @param WP_Post|int $post Event.
	 * @return bool
	 */
	public static function has_no_page( $post ) {
		$post = get_post( $post );
		if ( ! $post || Outthink_Events::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return self::is_private( $post ) || 'none' === Outthink_Events::get_link_to( $post->ID );
	}

	/**
	 * Whether the current visitor should have these events hidden from them.
	 *
	 * @return bool
	 */
	public static function hide_for_current_user() {
		return ! current_user_can( 'edit_posts' );
	}

	/**
	 * IDs of all events without a page (any status).
	 *
	 * @return int[]
	 */
	public static function hidden_ids() {
		if ( null !== self::$hidden_ids ) {
			return self::$hidden_ids;
		}

		$base = array(
			'post_type'           => Outthink_Events::POST_TYPE,
			'post_status'         => 'any',
			'posts_per_page'      => -1,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'suppress_filters'    => false,
			self::INCLUDE_PRIVATE => true,
		);

		$ids = get_posts(
			$base + array(
				'meta_key'   => 'ot_e_link_to', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'none', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$categories = self::private_categories();
		if ( $categories ) {
			$ids = array_merge(
				$ids,
				get_posts(
					$base + array(
						'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'taxonomy' => Outthink_Events::TAXONOMY,
								'field'    => 'slug',
								'terms'    => $categories,
							),
						),
					)
				)
			);
		}

		self::$hidden_ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		return self::$hidden_ids;
	}

	/**
	 * @deprecated 1.4.0 Use hidden_ids(), which also covers "No link" events.
	 * @return int[]
	 */
	public static function private_ids() {
		return self::hidden_ids();
	}

	public static function flush_cache() {
		self::$hidden_ids = null;
	}

	/**
	 * @param int|int[] $meta_id   Unused.
	 * @param int       $object_id Unused.
	 * @param string    $meta_key  Meta key.
	 */
	public static function flush_cache_on_meta( $meta_id, $object_id, $meta_key ) {
		if ( 'ot_e_link_to' === $meta_key ) {
			self::flush_cache();
		}
	}

	/**
	 * Leave events without a page out of front-end queries (search, feeds,
	 * archives, page-builder post grids, core sitemaps, REST collections) for non-editors.
	 *
	 * @param WP_Query $query Query.
	 */
	public function exclude_from_queries( $query ) {
		if ( is_admin() || $query->get( self::INCLUDE_PRIVATE ) || ! self::hide_for_current_user() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$involves  = in_array( Outthink_Events::POST_TYPE, (array) $post_type, true )
			|| 'any' === $post_type
			|| $query->is_search()
			|| $query->get( Outthink_Events::TAXONOMY )
			|| $query->is_tax( Outthink_Events::TAXONOMY );
		if ( ! $involves ) {
			return;
		}

		$hidden = self::hidden_ids();
		if ( $hidden ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
		}
	}

	/**
	 * 404 the event's own page for visitors. WP_Query doesn't apply exclusions to
	 * single-post requests, so exclude_from_queries() can't cover this case.
	 */
	public function block_single_page() {
		if ( ! is_singular( Outthink_Events::POST_TYPE ) || ! self::hide_for_current_user() || ! self::has_no_page( get_queried_object_id() ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Skip events without a page in previous/next post links.
	 *
	 * @param string  $where          WHERE clause.
	 * @param bool    $in_same_term   Unused.
	 * @param array   $excluded_terms Unused.
	 * @param string  $taxonomy       Unused.
	 * @param WP_Post $post           Current post.
	 * @return string
	 */
	public function exclude_from_adjacent( $where, $in_same_term = false, $excluded_terms = array(), $taxonomy = '', $post = null ) {
		$post = get_post( $post );
		if ( ! $post || Outthink_Events::POST_TYPE !== $post->post_type || ! self::hide_for_current_user() ) {
			return $where;
		}
		$ids = self::hidden_ids();
		if ( $ids ) {
			$where .= ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		return $where;
	}

	/**
	 * @param int[] $ids Post IDs Yoast already excludes.
	 * @return int[]
	 */
	public function exclude_from_yoast_sitemap( $ids ) {
		return array_merge( (array) $ids, self::hidden_ids() );
	}

	/**
	 * Hide events without a page from /wp/v2/event/{id} for visitors (collections
	 * are already filtered by exclude_from_queries()).
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Result so far.
	 * @param array                           $handler  Route handler.
	 * @param WP_REST_Request                 $request  Request.
	 * @return mixed
	 */
	public function block_rest_single( $response, $handler, $request ) {
		if ( ! preg_match( '#^/wp/v2/event/(\d+)#', $request->get_route(), $m ) ) {
			return $response;
		}
		$id = (int) $m[1];
		if ( self::has_no_page( $id ) && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}
		return $response;
	}
}
