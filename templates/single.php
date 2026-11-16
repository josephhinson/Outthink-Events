<?php
/**
 * Event details shown above the content on single event pages (and by [event_details]).
 *
 * Override by copying to yourtheme/outthink-events/single.php.
 *
 * Variables:
 *   $event  array  One prepared event (see Outthink_Events::prepare_event()); $event['content'] is empty here.
 *
 * @package Outthink_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $event['timestamp'] && ! $event['time'] && ! $event['location'] && ! $event['venue_url'] ) {
	return;
}
?>
<div class="ot-event-details<?php echo $event['is_past'] ? ' is-past' : ''; ?>">
	<?php if ( $event['is_past'] ) : ?>
		<p class="ot-event-details__notice"><?php esc_html_e( 'This event has already taken place.', 'outthink-events' ); ?></p>
	<?php endif; ?>
	<dl>
		<?php if ( $event['timestamp'] ) : ?>
			<dt><?php esc_html_e( 'Date', 'outthink-events' ); ?></dt>
			<dd class="ot-event-details__date"><time datetime="<?php echo esc_attr( $event['iso_date'] ); ?>"><?php echo esc_html( $event['date_label'] ); ?></time></dd>
		<?php endif; ?>
		<?php if ( $event['time'] ) : ?>
			<dt><?php esc_html_e( 'Time', 'outthink-events' ); ?></dt>
			<dd class="ot-event-details__time"><?php echo esc_html( $event['time'] ); ?></dd>
		<?php endif; ?>
		<?php if ( $event['location'] ) : ?>
			<dt><?php esc_html_e( 'Location', 'outthink-events' ); ?></dt>
			<dd class="ot-event-details__location"><?php echo esc_html( $event['location'] ); ?></dd>
		<?php endif; ?>
	</dl>
	<?php if ( $event['venue_url'] ) : ?>
		<p class="ot-event-details__link"><a href="<?php echo esc_url( $event['venue_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Event Website', 'outthink-events' ); ?> &rarr;</a></p>
	<?php endif; ?>
</div>
