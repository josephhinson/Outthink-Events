<?php
/**
 * Upcoming Events widget body (the theme supplies the widget wrapper and title).
 *
 * Override by copying to yourtheme/outthink-events/widget.php.
 * Same variables and roll-off markers as list.php. $event['content'] is empty in this view.
 *
 * @package Outthink_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="ot-events__empty" data-ot-empty<?php echo $events ? ' hidden' : ''; ?>><?php esc_html_e( 'No upcoming events.', 'outthink-events' ); ?></p>
<?php foreach ( $events as $event ) : ?>
<div class="ot-event-post<?php echo $event['is_private'] ? ' is-private' : ''; ?>"<?php echo $event['attributes']; // phpcs:ignore WordPress.Security.EscapeOutput -- built by prepare_event(). ?>>
	<h4><?php echo $event['is_private'] ? esc_html__( 'Private Event', 'outthink-events' ) : esc_html( $event['title'] ); ?></h4>
	<p>
		<?php if ( $event['venue'] && ! $event['is_private'] ) : ?>
			<span class="venue"><?php echo esc_html( $event['venue'] ); ?></span><br>
		<?php endif; ?>
		<?php if ( $event['location'] ) : ?>
			<span class="location"><?php echo esc_html( $event['location'] ); ?></span><br>
		<?php endif; ?>
		<span class="date"><?php echo esc_html( $event['date_label'] ); ?></span><br>
		<span class="time"><?php echo esc_html( $event['time'] ); ?></span>
		<?php if ( $event['details_url'] ) : ?>
			<br><strong><a href="<?php echo esc_url( $event['details_url'] ); ?>"<?php echo $event['details_external'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php esc_html_e( 'Learn More', 'outthink-events' ); ?> &raquo;</a></strong>
		<?php endif; ?>
	</p>
</div>
<?php endforeach; ?>
