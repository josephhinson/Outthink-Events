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
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_data' ) );
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
	 * @return array{limit: int, category: string[], view: string, layout: string}
	 */
	public static function normalize_args( $args ) {
		$args     = wp_parse_args(
			(array) $args,
			array(
				'limit'    => 0,
				'category' => array(),
				'view'     => 'list',
				'layout'   => 'default',
			)
		);
		$category = is_array( $args['category'] ) ? $args['category'] : explode( ',', (string) $args['category'] );
		$category = array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', $category ) ) ) );

		return array(
			'limit'    => max( 0, (int) $args['limit'] ),
			'category' => $category,
			'view'     => in_array( $args['view'], self::VIEWS, true ) ? $args['view'] : 'list',
			'layout'   => sanitize_key( (string) $args['layout'] ) ? sanitize_key( (string) $args['layout'] ) : 'default',
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

		// Unknown layouts fall back to the default; leave editors a hint in the source.
		if ( 'default' !== $args['layout'] && ! isset( self::get_layouts( $args['view'] )[ $args['layout'] ] ) && current_user_can( 'edit_posts' ) ) {
			$html = sprintf(
				'<!-- Out:Think Events: no "%1$s" layout found (looked for outthink-events/%2$s-%1$s.php); using the default. -->',
				esc_html( $args['layout'] ),
				esc_html( $args['view'] )
			) . $html;
		}

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
			'class'          => 'ot-events ot-events--' . $args['view'] . ' ot-events--layout-' . $args['layout'],
			'data-ot-events' => '',
			'data-view'      => $args['view'],
			'data-layout'    => $args['layout'],
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
	 * With a layout, looks for {name}-{layout}.php first (a registered file, then the
	 * child theme, parent theme and plugin), falling back to {name}.php.
	 *
	 * @param string $name   Template name without extension: list, widget or single.
	 * @param string $layout Layout slug, or 'default'.
	 * @return string Absolute path.
	 */
	public static function locate_template( $name, $layout = 'default' ) {
		$file = '';
		if ( 'default' !== $layout ) {
			$layouts = self::get_layouts( $name );
			if ( isset( $layouts[ $layout ] ) ) {
				$file = $layouts[ $layout ]['file'];
			}
		}
		if ( ! $file ) {
			$file = locate_template( 'outthink-events/' . $name . '.php' );
		}
		if ( ! $file ) {
			$file = OT_EVENTS_DIR . 'templates/' . $name . '.php';
		}
		return apply_filters( 'ot_events_template', $file, $name, $layout );
	}

	/**
	 * Layouts available for a view, keyed by slug: ['label' => string, 'file' => string].
	 *
	 * Found automatically as outthink-events/{view}-{slug}.php in the child theme,
	 * parent theme and plugin templates/ (in that order of priority). A
	 * "Layout Name: Tour Dates" line in the file's header comment sets the label.
	 * Plugins can add or change layouts with the `ot_events_layouts` filter.
	 * "default" ({view}.php) is always available.
	 *
	 * @param string $view list or widget.
	 * @return array<string, array{label: string, file: string}>
	 */
	public static function get_layouts( $view = 'list' ) {
		// Cache the folder scan only; the filter runs on every call.
		static $scanned = array();
		if ( ! isset( $scanned[ $view ] ) ) {
			$scanned[ $view ] = self::scan_layouts( $view );
		}

		$layouts = array(
			'default' => array(
				'label' => __( 'Default', 'outthink-events' ),
				'file'  => '',
			),
		) + $scanned[ $view ];

		return (array) apply_filters( 'ot_events_layouts', $layouts, $view );
	}

	/**
	 * Find {view}-{slug}.php files in the plugin, parent theme and child theme.
	 *
	 * @param string $view list or widget.
	 * @return array<string, array{label: string, file: string}>
	 */
	private static function scan_layouts( $view ) {
		$found = array();
		$dirs  = array_unique(
			array(
				OT_EVENTS_DIR . 'templates',
				get_template_directory() . '/outthink-events',
				get_stylesheet_directory() . '/outthink-events',
			)
		);
		// Later directories win, so the child theme overrides the parent and the plugin.
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '/' . $view . '-*.php' ) as $file ) {
				$slug = sanitize_key( substr( basename( $file, '.php' ), strlen( $view ) + 1 ) );
				if ( ! $slug || 'default' === $slug ) {
					continue;
				}
				$header         = get_file_data( $file, array( 'label' => 'Layout Name' ) );
				$found[ $slug ] = array(
					'label' => $header['label'] ? $header['label'] : ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
					'file'  => $file,
				);
			}
		}

		return $found;
	}

	/**
	 * @param string $name   Template name.
	 * @param array  $events Prepared events (see Outthink_Events::prepare_event()).
	 * @param array  $args   Normalized arguments.
	 * @return string
	 */
	private static function render_template( $name, $events, $args ) {
		$file = self::locate_template( $name, $args['layout'] ?? 'default' );
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
				'layout'   => 'default',
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
							'layout'   => $attributes['layout'] ?? 'default',
						),
						true
					);
				},
			)
		);
	}

	/**
	 * Give the block's Layout dropdown the list of available layouts.
	 */
	public function block_editor_data() {
		$options = array();
		foreach ( self::get_layouts( 'list' ) as $slug => $layout ) {
			$options[] = array(
				'value' => $slug,
				'label' => $layout['label'],
			);
		}
		wp_add_inline_script(
			generate_block_asset_handle( 'outthink/events', 'editorScript' ),
			'window.otEventsBlock = ' . wp_json_encode( array( 'layouts' => $options ) ) . ';',
			'before'
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
					'layout'   => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]*$',
						'default' => 'default',
					),
				),
			)
		);
	}

	/**
	 * GET /wp-json/outthink-events/v1/events?limit=5&category=a,b&view=list&layout=cards
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
				'layout'   => $request['layout'],
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
