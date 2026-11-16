<?php
/**
 * Event data: post type, taxonomy, meta, the editor box, date handling and queries.
 *
 * Event dates are stored in the `ot_e_date` meta key as a Unix timestamp for
 * midnight UTC of the event day, so they are always formatted in UTC.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outthink_Events {

	const POST_TYPE = 'event';
	const TAXONOMY  = 'event-category';
	const NONCE     = 'ot_events_noncename';

	/** @var string Kept for backwards compatibility with code that read $OutthinkEvents->post_type. */
	public $post_type = self::POST_TYPE;

	public function __construct() {
		add_action( 'init', array( $this, 'register_content_types' ), 0 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_postdata' ), 10, 2 );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ot_events_parse_date', array( $this, 'ajax_parse_date' ) );
	}

	/**
	 * One-time cleanup when the plugin is updated: version 1.1.0 removed the
	 * old auto-updater, so its saved registration details are no longer used.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'ot_events_version' ) === OT_EVENTS_VERSION ) {
			return;
		}
		delete_option( 'ot-plugin-validation' );
		update_option( 'ot_events_version', OT_EVENTS_VERSION );
	}

	/**
	 * Register the post type, taxonomy and post meta.
	 */
	public function register_content_types() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Events', 'outthink-events' ),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-calendar-alt',
				'capability_type' => 'post',
				'hierarchical'    => false,
				'rewrite'         => array( 'slug' => 'event' ),
				'query_var'       => true,
				'supports'        => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
				'labels'          => array(
					'name'               => __( 'Events', 'outthink-events' ),
					'singular_name'      => __( 'Event', 'outthink-events' ),
					'menu_name'          => __( 'Events', 'outthink-events' ),
					'add_new'            => __( 'Add Event', 'outthink-events' ),
					'add_new_item'       => __( 'Add New Event', 'outthink-events' ),
					'edit_item'          => __( 'Edit Event', 'outthink-events' ),
					'new_item'           => __( 'New Event', 'outthink-events' ),
					'view_item'          => __( 'View Event', 'outthink-events' ),
					'search_items'       => __( 'Search Events', 'outthink-events' ),
					'not_found'          => __( 'No Events Found', 'outthink-events' ),
					'not_found_in_trash' => __( 'No Events Found in Trash', 'outthink-events' ),
				),
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'event-category' ),
				'labels'            => array(
					'name'              => _x( 'Event Categories', 'taxonomy general name', 'outthink-events' ),
					'singular_name'     => _x( 'Event Category', 'taxonomy singular name', 'outthink-events' ),
					'search_items'      => __( 'Search Categories', 'outthink-events' ),
					'all_items'         => __( 'All Categories', 'outthink-events' ),
					'parent_item'       => __( 'Parent Category', 'outthink-events' ),
					'parent_item_colon' => __( 'Parent Category:', 'outthink-events' ),
					'edit_item'         => __( 'Edit Category', 'outthink-events' ),
					'update_item'       => __( 'Update Category', 'outthink-events' ),
					'add_new_item'      => __( 'Add New Category', 'outthink-events' ),
					'new_item_name'     => __( 'New Category Name', 'outthink-events' ),
					'menu_name'         => __( 'Event Category', 'outthink-events' ),
				),
			)
		);

		$meta = array(
			'ot_e_venue'    => array( 'string', 'sanitize_text_field' ),
			'ot_e_location' => array( 'string', 'sanitize_text_field' ),
			'ot_e_link'     => array( 'string', 'esc_url_raw' ),
			'ot_e_time'     => array( 'string', 'sanitize_text_field' ),
			'ot_e_date'     => array( 'integer', 'absint' ),
			'ot_e_link_to'  => array( 'string', array( __CLASS__, 'sanitize_link_to' ) ),
		);
		foreach ( $meta as $key => list( $type, $sanitize ) ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	public function register_widget() {
		register_widget( 'OT_Events_Widget' );
	}

	/* ---------------------------------------------------------------------
	 * Queries and event data
	 * ------------------------------------------------------------------ */

	/**
	 * Midnight UTC timestamp for "today" in the site's timezone, comparable with stored event dates.
	 *
	 * @return int
	 */
	public static function today() {
		return (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
	}

	/**
	 * Published events dated today or later, soonest first.
	 *
	 * @param array $args {
	 *     @type int      $limit    Maximum number of events; 0 or -1 for all.
	 *     @type string[] $category Event category slugs; events in any of them are included.
	 * }
	 * @return WP_Post[]
	 */
	public static function get_upcoming_events( $args = array() ) {
		// Backwards compatibility: get_upcoming_events( 3 ).
		if ( ! is_array( $args ) ) {
			$args = array( 'limit' => (int) $args );
		}
		$args = wp_parse_args(
			$args,
			array(
				'limit'    => 0,
				'category' => array(),
			)
		);

		$query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $args['limit'] > 0 ? (int) $args['limit'] : -1,
			'no_found_rows'  => true,
			// Private events stay in lists; templates show them without details.
			Outthink_Events_Privacy::INCLUDE_PRIVATE => true,
			'meta_query'     => array(
				'event_date' => array(
					'key'     => 'ot_e_date',
					'value'   => self::today(),
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'        => array(
				'event_date' => 'ASC',
				'title'      => 'ASC',
			),
		);

		if ( ! empty( $args['category'] ) ) {
			$query['tax_query'] = array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_values( (array) $args['category'] ),
				),
			);
		}

		return get_posts( apply_filters( 'ot_events_query_args', $query, $args ) );
	}

	/**
	 * Everything a template needs about one event, already formatted.
	 *
	 * @param WP_Post|int $post         Event post.
	 * @param bool        $with_content Whether to render the post content (runs the_content filters).
	 * @return array
	 */
	public static function prepare_event( $post, $with_content = true ) {
		$post      = get_post( $post );
		$timestamp = (int) get_post_meta( $post->ID, 'ot_e_date', true );
		$venue_url = (string) get_post_meta( $post->ID, 'ot_e_link', true );
		$link_to   = self::get_link_to( $post->ID );
		$utc       = new DateTimeZone( 'UTC' );
		$permalink = get_permalink( $post );

		$terms      = get_the_terms( $post, self::TAXONOMY );
		$categories = array();
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		$expires    = $timestamp ? self::expires_at( $timestamp ) : 0;
		$is_private = Outthink_Events_Privacy::is_private( $post );

		$event = array(
			'id'               => $post->ID,
			'title'            => get_the_title( $post ),
			'content'          => $with_content ? apply_filters( 'the_content', $post->post_content ) : '',
			'excerpt'          => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
			'venue'            => (string) get_post_meta( $post->ID, 'ot_e_venue', true ),
			'location'         => (string) get_post_meta( $post->ID, 'ot_e_location', true ),
			'time'             => (string) get_post_meta( $post->ID, 'ot_e_time', true ),
			'timestamp'        => $timestamp,
			'iso_date'         => $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '',
			'day'              => $timestamp ? wp_date( 'j', $timestamp, $utc ) : '',
			'weekday'          => $timestamp ? wp_date( 'l', $timestamp, $utc ) : '',
			'month'            => $timestamp ? wp_date( 'F', $timestamp, $utc ) : '',
			'month_short'      => $timestamp ? wp_date( 'M', $timestamp, $utc ) : '',
			'month_slug'       => $timestamp ? strtolower( gmdate( 'F', $timestamp ) ) : '',
			'month_key'        => $timestamp ? gmdate( 'Y-m', $timestamp ) : '',
			'year'             => $timestamp ? gmdate( 'Y', $timestamp ) : '',
			'date_label'       => $timestamp ? wp_date( 'l, F jS, Y', $timestamp, $utc ) : '',
			'is_past'          => $timestamp && $timestamp < self::today(),
			'expires'          => $expires,
			'permalink'        => $permalink,
			'venue_url'        => $venue_url,
			'link_to'          => $link_to,
			// Private events have no page to link to.
			'details_url'      => $is_private ? '' : ( 'venue' === $link_to ? $venue_url : $permalink ),
			'details_external' => ! $is_private && 'venue' === $link_to,
			'is_private'       => $is_private,
			'categories'       => $categories,
			'edit_url'         => current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : '',
			// Print on the element that represents this event so it can roll off on cached pages.
			'attributes'       => $expires ? sprintf( ' data-ot-expires="%d"', $expires ) : '',
		);

		return apply_filters( 'ot_events_prepare_event', $event, $post );
	}

	/**
	 * Unix time when an event's day ends in the site's timezone (when it should disappear).
	 *
	 * @param int $timestamp Stored midnight-UTC event date.
	 * @return int
	 */
	public static function expires_at( $timestamp ) {
		$day = new DateTimeImmutable( gmdate( 'Y-m-d', $timestamp ), wp_timezone() );
		return $day->modify( '+1 day' )->getTimestamp();
	}

	/**
	 * Where "details" links for an event go: 'local' (event page) or 'venue' (external link).
	 * Unset defaults to the venue link when there is one.
	 *
	 * @param int $post_id Event ID.
	 * @return string
	 */
	public static function get_link_to( $post_id ) {
		$venue_url = get_post_meta( $post_id, 'ot_e_link', true );
		$link_to   = get_post_meta( $post_id, 'ot_e_link_to', true );
		if ( ! in_array( $link_to, array( 'local', 'venue' ), true ) ) {
			$link_to = $venue_url ? 'venue' : 'local';
		}
		if ( 'venue' === $link_to && ! $venue_url ) {
			$link_to = 'local';
		}
		return $link_to;
	}

	/**
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_link_to( $value ) {
		return in_array( $value, array( 'local', 'venue' ), true ) ? $value : '';
	}

	/* ---------------------------------------------------------------------
	 * Editor box
	 * ------------------------------------------------------------------ */

	public function add_metabox() {
		add_meta_box(
			'ot_events_sectionid',
			__( 'Event Details', 'outthink-events' ),
			array( $this, 'render_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Current post.
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( 'ot_events_save', self::NONCE );

		$date     = (int) get_post_meta( $post->ID, 'ot_e_date', true );
		$venue    = get_post_meta( $post->ID, 'ot_e_venue', true );
		$location = get_post_meta( $post->ID, 'ot_e_location', true );
		$link     = get_post_meta( $post->ID, 'ot_e_link', true );
		$time     = get_post_meta( $post->ID, 'ot_e_time', true );
		$link_to  = self::get_link_to( $post->ID );
		?>
		<div class="ot-events-fields">
			<div class="ot-events-field">
				<label for="ot_e_venue"><?php esc_html_e( 'Venue', 'outthink-events' ); ?></label>
				<input type="text" name="ot_e_venue" id="ot_e_venue" placeholder="<?php esc_attr_e( 'e.g. The Fox Theatre', 'outthink-events' ); ?>" value="<?php echo esc_attr( $venue ); ?>">
			</div>
			<div class="ot-events-field">
				<label for="ot_e_location"><?php esc_html_e( 'Event Location', 'outthink-events' ); ?></label>
				<input type="text" name="ot_e_location" id="ot_e_location" placeholder="<?php esc_attr_e( 'e.g. St Louis, MO', 'outthink-events' ); ?>" value="<?php echo esc_attr( $location ); ?>">
			</div>

			<div class="ot-events-field">
				<label for="ot_e_date"><?php esc_html_e( 'Event Date', 'outthink-events' ); ?></label>
				<input type="text" name="ot_e_date" id="ot_e_date" autocomplete="off"
					placeholder="<?php esc_attr_e( 'e.g. March 1, 2027 · 3/1/27 · next Friday', 'outthink-events' ); ?>"
					value="<?php echo $date ? esc_attr( gmdate( 'F j, Y', $date ) ) : ''; ?>">
				<span id="ot_e_date_preview" class="description" aria-live="polite"><?php echo $date ? esc_html( self::describe_date( $date ) ) : ''; ?></span>
			</div>
			<div class="ot-events-field">
				<label for="ot_e_time"><?php esc_html_e( 'Event Time', 'outthink-events' ); ?></label>
				<input type="text" name="ot_e_time" id="ot_e_time" placeholder="<?php esc_attr_e( 'Freeform, e.g. 7pm ET or 9–5pm', 'outthink-events' ); ?>" value="<?php echo esc_attr( $time ); ?>">
			</div>

			<div class="ot-events-field">
				<label for="ot_e_link"><?php esc_html_e( 'Venue Link', 'outthink-events' ); ?></label>
				<input type="url" name="ot_e_link" id="ot_e_link" placeholder="https://" value="<?php echo esc_attr( $link ); ?>">
			</div>
			<fieldset class="ot-events-field">
				<legend><?php esc_html_e( 'Details link to', 'outthink-events' ); ?></legend>
				<div class="ot-events-radios">
					<label><input type="radio" name="ot_e_link_to" value="local" <?php checked( $link_to, 'local' ); ?>> <?php esc_html_e( 'Local details page', 'outthink-events' ); ?></label>
					<label><input type="radio" name="ot_e_link_to" value="venue" <?php checked( $link_to, 'venue' ); ?>> <?php esc_html_e( 'Venue link', 'outthink-events' ); ?></label>
				</div>
				<span class="description"><?php esc_html_e( 'Where "More Info" links in event lists go. Uses the local page if there is no venue link.', 'outthink-events' ); ?></span>
			</fieldset>
		</div>
		<?php
	}

	/**
	 * Save the editor box fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_postdata( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'ot_events_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'ot_e_venue'    => sanitize_text_field( wp_unslash( $_POST['ot_e_venue'] ?? '' ) ),
			'ot_e_location' => sanitize_text_field( wp_unslash( $_POST['ot_e_location'] ?? '' ) ),
			'ot_e_link'     => esc_url_raw( wp_unslash( $_POST['ot_e_link'] ?? '' ) ),
			'ot_e_time'     => sanitize_text_field( wp_unslash( $_POST['ot_e_time'] ?? '' ) ),
			'ot_e_link_to'  => self::sanitize_link_to( sanitize_key( wp_unslash( $_POST['ot_e_link_to'] ?? '' ) ) ),
		);

		// An unreadable date keeps the previously saved one rather than wiping it.
		$raw_date = sanitize_text_field( wp_unslash( $_POST['ot_e_date'] ?? '' ) );
		$date     = self::parse_date( $raw_date );
		if ( '' === $raw_date || $date ) {
			$fields['ot_e_date'] = $date;
		}

		foreach ( $fields as $key => $value ) {
			if ( '' === $value || 0 === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Dates
	 * ------------------------------------------------------------------ */

	/**
	 * Convert a freeform date to a midnight-UTC timestamp, or 0 if it can't be parsed.
	 *
	 * Accepts anything strtotime() does ("March 1, 2027", "3/1/27", "next Friday").
	 * Relative dates are worked out from today in the site's timezone. A date
	 * typed without a year ("March 1") that has already passed this year means
	 * the next one, since events are upcoming.
	 *
	 * @param string $value Date string.
	 * @return int
	 */
	public static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			return 0;
		}

		$timestamp = (int) strtotime( $date->format( 'Y-m-d' ) . ' 00:00:00 UTC' );

		$parts   = date_parse( $value );
		$no_year = false === $parts['year'] && false !== $parts['month'] && false !== $parts['day'] && empty( $parts['relative'] );
		if ( $no_year && $timestamp < self::today() ) {
			$timestamp = (int) strtotime( '+1 year', $timestamp );
		}

		return $timestamp;
	}

	/**
	 * Human-readable confirmation of a stored date, e.g. "Monday, March 1, 2027".
	 *
	 * @param int $timestamp Midnight-UTC timestamp.
	 * @return string
	 */
	public static function describe_date( $timestamp ) {
		$label = wp_date( 'l, F j, Y', $timestamp, new DateTimeZone( 'UTC' ) );
		if ( $timestamp < self::today() ) {
			/* translators: %s: formatted date. */
			$label = sprintf( __( '%s (in the past)', 'outthink-events' ), $label );
		}
		return $label;
	}

	/**
	 * Load the date-preview script on the event edit screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || self::POST_TYPE !== get_current_screen()->post_type ) {
			return;
		}
		wp_enqueue_style( 'ot-events-admin', OT_EVENTS_URL . 'assets/css/ot-events-admin.css', array(), OT_EVENTS_VERSION );
		wp_enqueue_script( 'ot-events-admin', OT_EVENTS_URL . 'assets/js/ot-events-admin.js', array(), OT_EVENTS_VERSION, true );
		wp_localize_script(
			'ot-events-admin',
			'otEventsAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ot_events_parse_date' ),
				'unreadable' => __( "Couldn't read that date. The saved date won't change.", 'outthink-events' ),
			)
		);
	}

	/**
	 * AJAX: tell the editor how a typed date will be read.
	 */
	public function ajax_parse_date() {
		check_ajax_referer( 'ot_events_parse_date', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		$timestamp = self::parse_date( sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) ) );
		if ( ! $timestamp ) {
			wp_send_json_error();
		}
		wp_send_json_success(
			array(
				'label' => self::describe_date( $timestamp ),
				'past'  => $timestamp < self::today(),
			)
		);
	}
}
