<?php
/**
 * Upcoming Events widget. The body comes from templates/widget.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo Outthink_Events_Display::render( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template.
			array(
				'view'     => 'widget',
				'limit'    => absint( $instance['number'] ) ? absint( $instance['number'] ) : 3,
				'category' => $instance['category'],
			)
		);
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title'    => sanitize_text_field( $new_instance['title'] ?? '' ),
			'number'   => absint( $new_instance['number'] ?? 3 ),
			'category' => implode( ',', Outthink_Events_Display::normalize_args( array( 'category' => $new_instance['category'] ?? '' ) )['category'] ),
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
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'category' ) ); ?>"><?php esc_html_e( 'Category slugs (optional, comma-separated):', 'outthink-events' ); ?></label><br>
			<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'category' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'category' ) ); ?>" value="<?php echo esc_attr( $instance['category'] ); ?>">
		</p>
		<?php
	}

	private function defaults() {
		return array(
			'title'    => __( 'Upcoming Events', 'outthink-events' ),
			'number'   => 3,
			'category' => '',
		);
	}
}
