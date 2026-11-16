<?php
/**
 * Upcoming events list: [events] shortcode and the Upcoming Events block.
 *
 * Override by copying to yourtheme/outthink-events/list.php.
 *
 * Variables:
 *   $events  array[] Prepared events, soonest first (keys: see Outthink_Events::prepare_event()).
 *   $args    array   limit, category (slugs), view.
 *
 * To keep events rolling off on cached pages, an override must keep:
 *   - $event['attributes'] printed on the element for each event
 *   - data-ot-group on any element grouping events (removed when it has none left)
 *   - data-ot-empty on the empty-state message, data-ot-hide-when-empty on what to hide when empty
 *
 * @package Outthink_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$months = array();
foreach ( $events as $event ) {
	$months[ $event['month_key'] ][] = $event;
}
$row = 0;
?>
<p class="ot-events__empty" data-ot-empty<?php echo $events ? ' hidden' : ''; ?>><?php esc_html_e( 'No upcoming events.', 'outthink-events' ); ?></p>
<?php if ( $events ) : ?>
<table class="ot_event_list" data-ot-hide-when-empty>
	<thead>
		<tr>
			<th><?php esc_html_e( 'Date', 'outthink-events' ); ?></th>
			<th><?php esc_html_e( 'Location', 'outthink-events' ); ?></th>
			<th><?php esc_html_e( 'Event', 'outthink-events' ); ?></th>
			<th class="e_details"><?php esc_html_e( 'Details', 'outthink-events' ); ?></th>
		</tr>
	</thead>
	<?php foreach ( $months as $month_events ) : ?>
	<tbody class="ot-month" data-ot-group>
		<tr class="month month-<?php echo esc_attr( $month_events[0]['month_slug'] ); ?>">
			<td colspan="4"><?php echo esc_html( $month_events[0]['month'] ); ?></td>
		</tr>
		<?php foreach ( $month_events as $event ) : ?>
		<tr class="ot_e_data<?php echo ( 0 === $row++ % 2 ) ? ' even' : ''; ?>"<?php echo $event['attributes']; // phpcs:ignore WordPress.Security.EscapeOutput -- built by prepare_event(). ?>>
			<td class="e_date"><?php echo esc_html( $event['day'] ); ?></td>
			<td class="e_location"><?php echo esc_html( $event['location'] ); ?></td>
			<td class="e_venue"><?php echo esc_html( $event['title'] ); ?></td>
			<td class="e_details">
				<?php echo $event['content']; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output. ?>
				<?php if ( $event['time'] || $event['details_url'] ) : ?>
				<span class="event_details">
					<?php if ( $event['time'] ) : ?>
						<span class="event_time"><?php echo esc_html( $event['time'] ); ?></span>
					<?php endif; ?>
					<?php if ( $event['time'] && $event['details_url'] ) : ?> - <?php endif; ?>
					<?php if ( $event['details_url'] ) : ?>
						<span class="event_link"><a href="<?php echo esc_url( $event['details_url'] ); ?>"<?php echo $event['details_external'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php esc_html_e( 'More Info', 'outthink-events' ); ?> &rarr;</a></span>
					<?php endif; ?>
				</span>
				<?php endif; ?>
				<?php if ( $event['edit_url'] ) : ?>
				<span class="edit_link"><a href="<?php echo esc_url( $event['edit_url'] ); ?>"><?php esc_html_e( 'Edit This Event', 'outthink-events' ); ?></a></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
	<?php endforeach; ?>
</table>
<?php endif; ?>
