<?php
/**
 * Private events: events in a "private" event category still appear in upcoming
 * lists (templates decide how; by default only the date and location show), but
 * nothing else on the site reveals them to visitors.
 *
 * For visitors who can't edit events, private events:
 *   - have no single page (/event/{slug}/ returns a 404)
 *   - are left out of search, feeds, archives, other front-end queries and core sitemaps
 *   - are left out of the Yoast SEO sitemap
 *   - are skipped by previous/next post links on other event pages
 *   - are hidden from the public /wp/v2/event REST API
 *
 * Editors still see everything, so they can preview and manage private events.
 *
 * Which categories count as private (slugs): filter `ot_events_private_categories`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outthink_Events_Privacy {

	/** Query var that lets the plugin's own list queries include private events. */
	const INCLUDE_PRIVATE = 'ot_events_include_private';

	/** @var int[]|null Cached IDs of private events for this request. */
	private static $private_ids = null;

	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'exclude_from_queries' ) );
		add_action( 'template_redirect', array( $this, 'block_single_page' ), 1 );
		add_filter( 'get_previous_post_where', array( $this, 'exclude_from_adjacent' ), 10, 5 );
		add_filter( 'get_next_post_where', array( $this, 'exclude_from_adjacent' ), 10, 5 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'exclude_from_yoast_sitemap' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'block_rest_single' ), 10, 3 );
		add_action( 'save_post_' . Outthink_Events::POST_TYPE, array( __CLASS__, 'flush_cache' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush_cache' ) );
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
	 * Whether the current visitor should have private events hidden from them.
	 *
	 * @return bool
	 */
	public static function hide_for_current_user() {
		return ! current_user_can( 'edit_posts' );
	}

	/**
	 * IDs of all private events (any status).
	 *
	 * @return int[]
	 */
	public static function private_ids() {
		if ( null === self::$private_ids ) {
			$categories        = self::private_categories();
			self::$private_ids = $categories ? array_map(
				'intval',
				get_posts(
					array(
						'post_type'             => Outthink_Events::POST_TYPE,
						'post_status'           => 'any',
						'posts_per_page'        => -1,
						'fields'                => 'ids',
						'no_found_rows'         => true,
						'suppress_filters'      => false,
						self::INCLUDE_PRIVATE   => true,
						'tax_query'             => array(
							array(
								'taxonomy' => Outthink_Events::TAXONOMY,
								'field'    => 'slug',
								'terms'    => $categories,
							),
						),
					)
				)
			) : array();
		}
		return self::$private_ids;
	}

	public static function flush_cache() {
		self::$private_ids = null;
	}

	/**
	 * Leave private events out of front-end queries (single pages, search, feeds,
	 * archives, page-builder post grids, core sitemaps) for non-editors.
	 *
	 * @param WP_Query $query Query.
	 */
	public function exclude_from_queries( $query ) {
		if ( is_admin() || $query->get( self::INCLUDE_PRIVATE ) || ! self::private_categories() || ! self::hide_for_current_user() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$taxonomy  = $query->get( Outthink_Events::TAXONOMY );
		$involves  = in_array( Outthink_Events::POST_TYPE, (array) $post_type, true )
			|| 'any' === $post_type
			|| $query->is_search()
			|| $taxonomy
			|| $query->is_tax( Outthink_Events::TAXONOMY );
		if ( ! $involves ) {
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => Outthink_Events::TAXONOMY,
			'field'    => 'slug',
			'terms'    => self::private_categories(),
			'operator' => 'NOT IN',
		);
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * 404 a private event's own page for visitors. WP_Query ignores tax_query on
	 * single-post requests, so exclude_from_queries() can't cover this case.
	 */
	public function block_single_page() {
		if ( ! is_singular( Outthink_Events::POST_TYPE ) || ! self::hide_for_current_user() || ! self::is_private( get_queried_object_id() ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Skip private events in previous/next post links.
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
		$ids = self::private_ids();
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
		return array_merge( (array) $ids, self::private_ids() );
	}

	/**
	 * Hide private events from /wp/v2/event/{id} for visitors (the collection
	 * endpoint is already filtered by exclude_from_queries()).
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
		if ( self::is_private( $id ) && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}
		return $response;
	}
}
