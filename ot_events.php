<?php
/**
 * Plugin Name:       Out:Think Events
 * Plugin URI:        https://outthinkgroup.com/
 * Description:       This plugin provides a simple interface to add events in an upcoming list.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Joseph Hinson
 * Author URI:        https://outthinkgroup.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       outthink-events
 * Update URI:        false
 *
 * Copyright 2013 - Out:think Group
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OT_EVENTS_VERSION', '1.1.1' );
define( 'OT_EVENTS_FILE', __FILE__ );
define( 'OT_EVENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OT_EVENTS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Registers the Event post type, its meta, the [events] shortcode and the widget.
 *
 * Event dates are stored in the `ot_e_date` meta key as a Unix timestamp for
 * midnight UTC of the event day, so they are always formatted with gmdate().
 */
class Outthink_Events {

	const POST_TYPE = 'event';
	const TAXONOMY  = 'event-category';
	const NONCE     = 'ot_events_noncename';

	/** @var string Kept for backwards compatibility with code that read $OutthinkEvents->post_type. */
	public $post_type = self::POST_TYPE;

	public function __construct() {
		add_action( 'init', array( $this, 'register_content_types' ), 0 );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_postdata' ), 10, 2 );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ot_events_parse_date', array( $this, 'ajax_parse_date' ) );
		add_shortcode( 'events', array( $this, 'shortcode' ) );
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
			'ot_e_location' => array( 'string', 'sanitize_text_field' ),
			'ot_e_link'     => array( 'string', 'esc_url_raw' ),
			'ot_e_time'     => array( 'string', 'sanitize_text_field' ),
			'ot_e_date'     => array( 'integer', 'absint' ),
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

	/**
	 * Register the front-end stylesheet; it is enqueued only when the shortcode renders.
	 */
	public function register_assets() {
		wp_register_style( 'ot-events', OT_EVENTS_URL . 'assets/css/ot-events.css', array(), OT_EVENTS_VERSION );
	}

	public function register_widget() {
		register_widget( 'OT_Events_Widget' );
	}

	/**
	 * Midnight UTC timestamp for "today" in the site's timezone, comparable with stored event dates.
	 *
	 * @return int
	 */
	public static function today() {
		return (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
	}

	/**
	 * Fetch published events dated today or later, soonest first.
	 *
	 * @param int $limit Number of events, or -1 for all.
	 * @return WP_Post[]
	 */
	public static function get_upcoming_events( $limit = -1 ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
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
			)
		);
	}

	/**
	 * [events] shortcode: a table of upcoming events grouped by month.
	 *
	 * @return string
	 */
	public function shortcode() {
		wp_enqueue_style( 'ot-events' );

		$output = '<table class="ot_event_list">
			<tr>
				<th>' . esc_html__( 'Date', 'outthink-events' ) . '</th>
				<th>' . esc_html__( 'Location', 'outthink-events' ) . '</th>
				<th>' . esc_html__( 'Event', 'outthink-events' ) . '</th>
				<th class="e_details">' . esc_html__( 'Details', 'outthink-events' ) . '</th>
			</tr>';

		$current_month = '';
		$row           = 0;

		foreach ( self::get_upcoming_events() as $event ) {
			$timestamp = (int) get_post_meta( $event->ID, 'ot_e_date', true );
			$link      = get_post_meta( $event->ID, 'ot_e_link', true );
			$location  = get_post_meta( $event->ID, 'ot_e_location', true );
			$time      = get_post_meta( $event->ID, 'ot_e_time', true );

			$month = gmdate( 'F Y', $timestamp );
			if ( $month !== $current_month ) {
				$output       .= sprintf(
					'<tr class="month month-%s"><td colspan="4">%s</td></tr>',
					esc_attr( strtolower( gmdate( 'F', $timestamp ) ) ),
					esc_html( date_i18n( 'F', $timestamp, true ) )
				);
				$current_month = $month;
			}

			$details = '';
			if ( $time || $link ) {
				$parts = array();
				if ( $time ) {
					$parts[] = '<span class="event_time">' . esc_html( $time ) . '</span>';
				}
				if ( $link ) {
					$parts[] = sprintf(
						'<span class="event_link"><a href="%s" target="_blank" rel="noopener">%s</a></span>',
						esc_url( $link ),
						esc_html__( 'More Info', 'outthink-events' ) . ' &rarr;'
					);
				}
				$details = '<span class="event_details">' . implode( ' - ', $parts ) . '</span>';
			}

			$edit_link = '';
			if ( current_user_can( 'edit_post', $event->ID ) ) {
				$edit_link = sprintf(
					'<span class="edit_link"><a href="%s">%s</a></span>',
					esc_url( get_edit_post_link( $event->ID ) ),
					esc_html__( 'Edit This Event', 'outthink-events' )
				);
			}

			$output .= sprintf(
				'<tr class="ot_e_data%s">
					<td class="e_date">%s</td>
					<td class="e_location">%s</td>
					<td class="e_venue">%s</td>
					<td class="e_details">%s%s%s</td>
				</tr>',
				( 0 === $row % 2 ) ? ' even' : '',
				esc_html( gmdate( 'j', $timestamp ) ),
				esc_html( $location ),
				esc_html( get_the_title( $event ) ),
				apply_filters( 'the_content', $event->post_content ),
				$details,
				$edit_link
			);
			++$row;
		}

		return $output . '</table>';
	}

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
		$location = get_post_meta( $post->ID, 'ot_e_location', true );
		$link     = get_post_meta( $post->ID, 'ot_e_link', true );
		$time     = get_post_meta( $post->ID, 'ot_e_time', true );
		?>
		<p>
			<label for="ot_e_location"><?php esc_html_e( 'Event Location', 'outthink-events' ); ?></label><br>
			<input type="text" class="regular-text" name="ot_e_location" id="ot_e_location" value="<?php echo esc_attr( $location ); ?>">
		</p>
		<p>
			<label for="ot_e_date"><?php esc_html_e( 'Event Date', 'outthink-events' ); ?></label><br>
			<input type="text" class="regular-text" name="ot_e_date" id="ot_e_date" autocomplete="off"
				placeholder="<?php esc_attr_e( 'e.g. March 1, 2027 · 3/1/27 · next Friday', 'outthink-events' ); ?>"
				value="<?php echo $date ? esc_attr( gmdate( 'F j, Y', $date ) ) : ''; ?>">
			<br><span id="ot_e_date_preview" class="description" aria-live="polite"><?php
			if ( $date ) {
				echo esc_html( self::describe_date( $date ) );
			}
			?></span>
		</p>
		<p>
			<label for="ot_e_link"><?php esc_html_e( 'Event Link', 'outthink-events' ); ?></label><br>
			<input type="url" class="large-text" name="ot_e_link" id="ot_e_link" placeholder="https://" value="<?php echo esc_attr( $link ); ?>">
		</p>
		<p>
			<label for="ot_e_time"><?php esc_html_e( 'Event Time', 'outthink-events' ); ?> <small><?php esc_html_e( '(freeform, e.g. 7pm ET or 9-5pm)', 'outthink-events' ); ?></small></label><br>
			<input type="text" class="regular-text" name="ot_e_time" id="ot_e_time" value="<?php echo esc_attr( $time ); ?>">
		</p>
		<?php
	}

	/**
	 * Save the meta box fields.
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
			'ot_e_location' => sanitize_text_field( wp_unslash( $_POST['ot_e_location'] ?? '' ) ),
			'ot_e_link'     => esc_url_raw( wp_unslash( $_POST['ot_e_link'] ?? '' ) ),
			'ot_e_time'     => sanitize_text_field( wp_unslash( $_POST['ot_e_time'] ?? '' ) ),
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
		$label = date_i18n( 'l, F j, Y', $timestamp, true );
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

/**
 * Upcoming Events widget.
 */
class OT_Events_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'ot_events_widget',
			__( 'Upcoming Events Widget', 'outthink-events' ),
			array(
				'classname'             => 'ot_events_widget',
				'description'           => __( 'Upcoming Events', 'outthink-events' ),
				'show_instance_in_rest' => true,
			),
			array(
				'width'   => 300,
				'height'  => 350,
				'id_base' => 'ot_events_widget',
			)
		);
	}

	/**
	 * @param array $args     Theme-supplied wrapper markup.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );
		$title    = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );
		$number   = absint( $instance['number'] ) ? absint( $instance['number'] ) : 3;
		$events   = Outthink_Events::get_upcoming_events( $number );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		if ( $events ) {
			foreach ( $events as $event ) {
				$date     = (int) get_post_meta( $event->ID, 'ot_e_date', true );
				$location = get_post_meta( $event->ID, 'ot_e_location', true );
				$link     = get_post_meta( $event->ID, 'ot_e_link', true );
				$time     = get_post_meta( $event->ID, 'ot_e_time', true );
				?>
				<div class="ot-event-post">
					<h4><?php echo esc_html( get_the_title( $event ) ); ?></h4>
					<p>
						<?php if ( $location ) : ?>
							<?php echo esc_html( $location ); ?><br>
						<?php endif; ?>
						<span class="date"><?php echo esc_html( date_i18n( 'l, F jS, Y', $date, true ) ); ?></span><br>
						<span class="time"><?php echo esc_html( $time ); ?></span>
						<?php if ( $link ) : ?>
							<br><strong><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Learn More', 'outthink-events' ); ?> &raquo;</a></strong>
						<?php endif; ?>
					</p>
				</div>
				<?php
			}
		} else {
			echo '<p>' . esc_html__( 'No upcoming events.', 'outthink-events' ) . '</p>';
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'number' => absint( $new_instance['number'] ?? 3 ),
		);
	}

	public function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'outthink-events' ); ?></label><br>
			<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $instance['title'] ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Number of Events:', 'outthink-events' ); ?></label><br>
			<input type="number" min="1" step="1" class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php echo esc_attr( $instance['number'] ); ?>">
		</p>
		<?php
	}

	private function defaults() {
		return array(
			'title'  => __( 'Upcoming Events', 'outthink-events' ),
			'number' => 3,
		);
	}
}

$OutthinkEvents = new Outthink_Events();

register_activation_hook(
	__FILE__,
	static function () use ( $OutthinkEvents ) {
		$OutthinkEvents->register_content_types();
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
