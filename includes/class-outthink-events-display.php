<?php
/**
 * Front-end display: templates, shortcodes, the block, single event details and
 * the REST endpoint that keeps cached pages current.
 *
 * Every list is rendered by one template (templates/list.php or templates/widget.php,
 * overridable in a theme at outthink-events/{view}.php) inside a wrapper like:
 *
 *   <div class="ot-events ot-events--list" data-ot-events data-view="list" data-limit="5" data-category="a,b">
 *
 * assets/js/ot-events-front.js then removes events whose day has passed (using each
 * event's data-ot-expires time) and swaps in fresh HTML from the REST endpoint, so
 * lists stay correct on pages served from a cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outthink_Events_Display {

	const REST_NAMESPACE = 'outthink-events/v1';
	const VIEWS          = array( 'list', 'widget' );

	/** @var bool True while rendering a list, so the_content on listed events skips the single-page details. */
	private static $rendering_list = false;

	/** @var bool Guards against the single-page details filter running inside itself. */
	private static $rendering_single = false;

	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'the_content', array( $this, 'add_single_details' ), 20 );
		add_shortcode( 'events', array( $this, 'shortcode_events' ) );
		add_shortcode( 'event_details', array( $this, 'shortcode_event_details' ) );
	}

	public function register_assets() {
		wp_register_style( 'ot-events', OT_EVENTS_URL . 'assets/css/ot-events.css', array(), OT_EVENTS_VERSION );
		wp_register_script(
			'ot-events-front',
			OT_EVENTS_URL . 'assets/js/ot-events-front.js',
			array(),
			OT_EVENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'ot-events-front',
			'otEvents',
			array( 'restUrl' => rest_url( self::REST_NAMESPACE . '/events' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Normalize list arguments from a shortcode, block or REST request.
	 *
	 * @param array $args Raw arguments.
	 * @return array{limit: int, category: string[], view: string}
	 */
	public static function normalize_args( $args ) {
		$args     = wp_parse_args(
			(array) $args,
			array(
				'limit'    => 0,
				'category' => array(),
				'view'     => 'list',
			)
		);
		$category = is_array( $args['category'] ) ? $args['category'] : explode( ',', (string) $args['category'] );
		$category = array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', $category ) ) ) );

		return array(
			'limit'    => max( 0, (int) $args['limit'] ),
			'category' => $category,
			'view'     => in_array( $args['view'], self::VIEWS, true ) ? $args['view'] : 'list',
		);
	}

	/**
	 * Render the inside of a list (no wrapper).
	 *
	 * @param array $args Normalized arguments.
	 * @return array{html: string, count: int}
	 */
	public static function render_inner( $args ) {
		self::$rendering_list = true;
		$events               = array();
		foreach ( Outthink_Events::get_upcoming_events( $args ) as $post ) {
			$events[] = Outthink_Events::prepare_event( $post, 'list' === $args['view'] );
		}
		$html                 = self::render_template( $args['view'], $events, $args );
		self::$rendering_list = false;

		return array(
			'html'  => $html,
			'count' => count( $events ),
		);
	}

	/**
	 * Render a complete list with its wrapper and enqueue its CSS and JS.
	 *
	 * @param array $args             limit, category, view.
	 * @param bool  $is_block         Use the block editor's wrapper attributes (alignment, spacing).
	 * @return string
	 */
	public static function render( $args, $is_block = false ) {
		$args  = self::normalize_args( $args );
		$inner = self::render_inner( $args );

		if ( 'list' === $args['view'] ) {
			wp_enqueue_style( 'ot-events' );
		}
		wp_enqueue_script( 'ot-events-front' );

		$attributes = array(
			'class'          => 'ot-events ot-events--' . $args['view'],
			'data-ot-events' => '',
			'data-view'      => $args['view'],
			'data-limit'     => (string) $args['limit'],
			'data-category'  => implode( ',', $args['category'] ),
		);
		// Lets the refresh keep "Edit This Event" links for logged-in editors.
		if ( is_user_logged_in() ) {
			$attributes['data-nonce'] = wp_create_nonce( 'wp_rest' );
		}

		if ( $is_block ) {
			$wrapper = get_block_wrapper_attributes( $attributes );
		} else {
			$wrapper = '';
			foreach ( $attributes as $name => $value ) {
				$wrapper .= sprintf( ' %s="%s"', $name, esc_attr( $value ) );
			}
		}

		return '<div ' . trim( $wrapper ) . '>' . $inner['html'] . '</div>';
	}

	/**
	 * Find a template, preferring the theme's outthink-events/{name}.php.
	 *
	 * @param string $name Template name without extension.
	 * @return string Absolute path.
	 */
	public static function locate_template( $name ) {
		$file = locate_template( 'outthink-events/' . $name . '.php' );
		if ( ! $file ) {
			$file = OT_EVENTS_DIR . 'templates/' . $name . '.php';
		}
		return apply_filters( 'ot_events_template', $file, $name );
	}

	/**
	 * @param string $name   Template name.
	 * @param array  $events Prepared events (see Outthink_Events::prepare_event()).
	 * @param array  $args   Normalized arguments.
	 * @return string
	 */
	private static function render_template( $name, $events, $args ) {
		$file = self::locate_template( $name );
		ob_start();
		( static function ( $events, $args ) use ( $file ) {
			include $file;
		} )( $events, $args );
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------ */

	/**
	 * [events limit="5" category="speaking,workshops"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_events( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 0,
				'category' => '',
			),
			$atts,
			'events'
		);
		return self::render( $atts );
	}

	/**
	 * [event_details] or [event_details id="123"]: the date/time/location block for one event.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_event_details( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'event_details' );
		$post = get_post( $atts['id'] ? (int) $atts['id'] : null );
		if ( ! $post || Outthink_Events::POST_TYPE !== $post->post_type ) {
			return '';
		}
		return $this->render_single_details( $post );
	}

	/* ---------------------------------------------------------------------
	 * Single event pages
	 * ------------------------------------------------------------------ */

	/**
	 * Put the event details above the content on single event pages.
	 *
	 * Skipped when the content already contains [event_details]. Turn off with:
	 * add_filter( 'ot_events_auto_single_details', '__return_false' );
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function add_single_details( $content ) {
		if ( self::$rendering_list || self::$rendering_single ) {
			return $content;
		}
		if ( ! is_singular( Outthink_Events::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		if ( has_shortcode( get_post()->post_content, 'event_details' ) || ! apply_filters( 'ot_events_auto_single_details', true, get_post() ) ) {
			return $content;
		}
		return $this->render_single_details( get_post() ) . $content;
	}

	/**
	 * @param WP_Post $post Event.
	 * @return string
	 */
	private function render_single_details( $post ) {
		self::$rendering_single = true;
		wp_enqueue_style( 'ot-events' );
		$event                  = Outthink_Events::prepare_event( $post, false );
		$file                   = self::locate_template( 'single' );
		ob_start();
		( static function ( $event ) use ( $file ) {
			include $file;
		} )( $event );
		self::$rendering_single = false;
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Block
	 * ------------------------------------------------------------------ */

	public function register_block() {
		register_block_type(
			OT_EVENTS_DIR . 'blocks/events',
			array(
				'render_callback' => static function ( $attributes ) {
					return self::render(
						array(
							'limit'    => $attributes['limit'] ?? 0,
							'category' => $attributes['category'] ?? array(),
						),
						true
					);
				},
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_events' ),
				'args'                => array(
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
						'default' => 0,
					),
					'category' => array(
						'type'    => 'string',
						'default' => '',
					),
					'view'     => array(
						'type'    => 'string',
						'enum'    => self::VIEWS,
						'default' => 'list',
					),
				),
			)
		);
	}

	/**
	 * GET /wp-json/outthink-events/v1/events?limit=5&category=a,b&view=list
	 *
	 * Returns the inner list HTML. Sent with no-cache headers; the front-end script
	 * also adds an hourly cache-busting parameter for hosts that cache the API anyway.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_events( $request ) {
		$args  = self::normalize_args(
			array(
				'limit'    => $request['limit'],
				'category' => $request['category'],
				'view'     => $request['view'],
			)
		);
		$inner = self::render_inner( $args );

		$response = rest_ensure_response(
			array(
				'html'      => $inner['html'],
				'count'     => $inner['count'],
				'generated' => time(),
			)
		);
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0' );
		return $response;
	}
}
