<?php
/**
 * Plugin Name:       Out:Think Events
 * Plugin URI:        https://outthinkgroup.com/
 * Description:       A simple way to add events and show them in an upcoming list that stays current, even on cached pages.
 * Version:           1.3.0
 * Requires at least: 6.3
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

define( 'OT_EVENTS_VERSION', '1.3.0' );
define( 'OT_EVENTS_FILE', __FILE__ );
define( 'OT_EVENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OT_EVENTS_URL', plugin_dir_url( __FILE__ ) );

require_once OT_EVENTS_DIR . 'includes/class-outthink-events.php';
require_once OT_EVENTS_DIR . 'includes/class-outthink-events-display.php';
require_once OT_EVENTS_DIR . 'includes/class-outthink-events-privacy.php';
require_once OT_EVENTS_DIR . 'includes/class-ot-events-widget.php';

$OutthinkEvents        = new Outthink_Events();
$OutthinkEventsDisplay = new Outthink_Events_Display();
$OutthinkEventsPrivacy = new Outthink_Events_Privacy();

register_activation_hook(
	__FILE__,
	static function () use ( $OutthinkEvents ) {
		$OutthinkEvents->register_content_types();
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
