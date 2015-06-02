<?php 
/**
* Plugin Name: Out:Think Events
* Plugin URI: http://outthinkgroup.com/
* Description: This plugin provides a simple interface to add events in an upcoming list.
* Version: 1.0
* Author: Joseph Hinson
* Author URI: http://outthinkgroup.com
* 
*     Copyright 2013 - Out:think Group  (email : joseph@outthinkgroup.com)
* 
*     This program is free software; you can redistribute it and/or modify
*     it under the terms of the GNU General Public License as published by
*     the Free Software Foundation; either version 2 of the License, or
*     (at your option) any later version.
* 
*     This program is distributed in the hope that it will be useful,
*     but WITHOUT ANY WARRANTY; without even the implied warranty of
*     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*     GNU General Public License for more details.
* 
*     You should have received a copy of the GNU General Public License
*     along with this program; if not, write to the Free Software
*     Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
* */

/**
* OT Events
*/
class Outthink_Events
{
	
	public function __construct()
	{
		$this->post_type = 'event';
		add_action('init', array($this, 'init'), 0 );
		/* Define the custom box for Event Data */
		// WP 3.0+
		add_action('add_meta_boxes', array($this, 'metabox'), 1);
		/* Do something with the data entered */
		add_action('save_post', array($this, 'save_postdata'), 1);
		// add the shortcode
		add_shortcode("events", array($this, "shortcode"));
		// initializes the widget on WordPress Load
		add_action('widgets_init', array($this, 'widget_init'));
		/* hook updater to init */
		add_action( 'init', array($this, 'events_plugin_updater_init') );
	}

	/**
	 * Load and Activate Plugin Updater Class.
	 */
	function events_plugin_updater_init() {
		/* Load Plugin Updater */
		require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 'includes/plugin-updater.php' );
		$userinfo = get_option('ot-plugin-validation');
		/* Updater Config */
		$config = array(
			'base'      => plugin_basename( __FILE__ ), //required
			'username'    => $userinfo['user'], // user login name in your site.
			'key' => $userinfo['email'],
			'repo_uri'  => 'http://outthinkgroup.com/',
			'repo_slug' => 'outthink-events',
		);

		/* Load Updater Class */
		new OTEvents_Plugin_Updater( $config );
//		$this->updater = new OTEvents_Plugin_Updater( $config );
	}
	
	function init() {
		//Adding Custom Post Type called "Events"
		register_post_type($this->post_type,
			array(
				'label' => 'Events',
				'description' => '',
				'public' => true,
				'show_ui' => true,
				'show_in_menu' => true,
				'capability_type' => 'post',
				'hierarchical' => false,
				'rewrite' => array('slug' => ''),
				'query_var' => true,
				'supports' => array('title','editor','excerpt','custom-fields',),
				'labels' => array (
					'name' => 'Events',
					'singular_name' => 'Event',
					'menu_name' => 'Events',
					'add_new' => 'Add Event',
					'add_new_item' => 'Add New Event',
					'edit' => 'Edit',
					'edit_item' => 'Edit Event',
					'new_item' => 'New Event',
					'view' => 'View Event',
					'view_item' => 'View Event',
					'search_items' => 'Search Events',
					'not_found' => 'No Events Found',
					'not_found_in_trash' => 'No Events Found in Trash',
					'parent' => 'Parent Event',
				),
			)
		); // end register post type
		
		// Add new taxonomy, make it hierarchical (like categories)
			$labels = array(
				'name'              => _x( 'Event Categories', 'taxonomy general name' ),
				'singular_name'     => _x( 'Event Category', 'taxonomy singular name' ),
				'search_items'      => __( 'Search Categories' ),
				'all_items'         => __( 'All Categories' ),
				'parent_item'       => __( 'Parent Category' ),
				'parent_item_colon' => __( 'Parent Category:' ),
				'edit_item'         => __( 'Edit Category' ),
				'update_item'       => __( 'Update Category' ),
				'add_new_item'      => __( 'Add New Category' ),
				'new_item_name'     => __( 'New Category Name' ),
				'menu_name'         => __( 'Event Category' ),
			);

			$args = array(
				'hierarchical'      => true,
				'labels'            => $labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'event-cateogory' ),
			);

			register_taxonomy( 'event-category', array( 'event' ), $args );
			
	}
	// Should be called above from "add_action"
	function widget_init() {
		register_widget( 'OT_Events_Widget' );
	}
	
	
	// Shortcode to show upcoming events in pages:
	function shortcode($atts, $content = null) { 
		extract(shortcode_atts(array(), $atts));
		global $post;
		$ot_events = get_posts('numberposts=-1&meta_key=ot_e_date&orderby=meta_value&order=ASC&post_type=event&post_status=publish'); 
		$master_return = ''; // this is the variable that actually gets returned.
		ob_start(); ?>
		<style type="text/css" media="screen">

		.cbp_tmtimeline {
			margin: 30px 0 0 0;
			padding: 0;
			list-style: none;
			position: relative;
		} 
		.cbp_tmtimeline li {
			position: relative;
		}
		/* The line */
		.cbp_tmtimeline:before {
			content: '';
			position: absolute;
			top: 0;
			bottom: 0;
			width: 10px;
			background: #afdcf8;
			left: 20%;
			margin-left: -10px;
		}

		/* The date/time */
		.cbp_tmtimeline > li .cbp_tmtime {
			display: block;
			width: 25%;
			padding-right: 100px;
			position: absolute;
		}

		.cbp_tmtimeline > li .cbp_tmtime span {
			display: block;
			text-align: right;
		}

		.cbp_tmtimeline > li .cbp_tmtime span:first-child {
			font-size: 0.9em;
			color: #bdd0db;
		}

		.cbp_tmtimeline > li .cbp_tmtime span:last-child {
			font-size: 2.9em;
			color: #3594cb;
		}

		.cbp_tmtimeline > li:nth-child(odd) .cbp_tmtime span:last-child {
			color: #6cbfee;
		}

		/* Right content */
		.cbp_tmtimeline > li .cbp_tmlabel {
			margin: 0 0 15px 25%;
			background: #3594cb;
			color: #fff;
			padding: 2em;
			font-size: 1.2em;
			font-weight: 300;
			line-height: 1.4;
			position: relative;
			border-radius: 5px;
		}

		.cbp_tmtimeline > li:nth-child(odd) .cbp_tmlabel {
			background: #6cbfee;
		}

		.cbp_tmtimeline > li .cbp_tmlabel h2 { 
			margin-top: 0px;
			padding: 0 0 10px 0;
			border-bottom: 1px solid rgba(255,255,255,0.4);
		}

		/* The triangle */
		.cbp_tmtimeline > li .cbp_tmlabel:after {
			right: 100%;
			border: solid transparent;
			content: " ";
			height: 0;
			width: 0;
			position: absolute;
			pointer-events: none;
			border-right-color: #3594cb;
			border-width: 10px;
			top: 10px;
		}

		.cbp_tmtimeline > li:nth-child(odd) .cbp_tmlabel:after {
			border-right-color: #6cbfee;
		}

		/* The icons */
		.cbp_tmtimeline > li .cbp_tmicon {
			width: 40px;
			height: 40px;
			speak: none;
			font-style: normal;
			font-weight: normal;
			font-variant: normal;
			text-transform: none;
			font-size: 1.4em;
			line-height: 40px;
			-webkit-font-smoothing: antialiased;
			position: absolute;
			color: #fff;
			background: #46a4da;
			border-radius: 50%;
			box-shadow: 0 0 0 8px #afdcf8;
			text-align: center;
			left: 20%;
			top: 0;
			margin: 0 0 0 -25px;
		}

		.cbp_tmicon-phone:before {
			content: "\e000";
		}

		.cbp_tmicon-screen:before {
			content: "\e001";
		}

		.cbp_tmicon-mail:before {
			content: "\e002";
		}

		.cbp_tmicon-earth:before {
			content: "\e003";
		}

		/* Example Media Queries */
		@media screen and (max-width: 65.375em) {

			.cbp_tmtimeline > li .cbp_tmtime span:last-child {
				font-size: 1.5em;
			}
		}

		@media screen and (max-width: 47.2em) {
			.cbp_tmtimeline:before {
				display: none;
			}

			.cbp_tmtimeline > li .cbp_tmtime {
				width: 100%;
				position: relative;
				padding: 0 0 20px 0;
			}

			.cbp_tmtimeline > li .cbp_tmtime span {
				text-align: left;
			}

			.cbp_tmtimeline > li .cbp_tmlabel {
				margin: 0 0 30px 0;
				padding: 1em;
				font-weight: 400;
				font-size: 95%;
			}

			.cbp_tmtimeline > li .cbp_tmlabel:after {
				right: auto;
				left: 20px;
				border-right-color: transparent;
				border-bottom-color: #3594cb;
				top: -20px;
			}

			.cbp_tmtimeline > li:nth-child(odd) .cbp_tmlabel:after {
				border-right-color: transparent;
				border-bottom-color: #6cbfee;
			}

			.cbp_tmtimeline > li .cbp_tmicon {
				position: relative;
				float: right;
				left: auto;
				margin: -55px 5px 0 0px;
			}	
		}

			
		</style>
		<ul class="cbp_tmtimeline">
			<?php foreach ($ot_events as $event):
				$origDate = get_post_meta($event->ID, 'ot_e_date', true);			
				if ($origDate) {
					$month = date('F', $origDate);
					$day = date('j', $origDate);
					$eventTime = $origDate;
					$venue = $event->post_title;
					$link = get_post_meta($event->ID, 'ot_e_link', true);
					$location = get_post_meta($event->ID, 'ot_e_location', true); 
					$details = $event->post_content;
					$time = get_post_meta($event->ID, 'ot_e_time', true);
					$editlink = '';
					if (is_user_logged_in()) {
						$editlink = ' <span class="edit_link"><a href="'.get_edit_post_link( $event->ID).'">Edit This Event</a></span>';
					}
					// Let's check to see if this date is not already passed:
					if ($origDate > strtotime('yesterday')) {
						$dtime = date('Y-m-d h:ia', $origDate);
						$dmy = date('n/d/y', $origDate);
						$time = date('h:ia', $origDate);
				?>
				<li>
					<time class="cbp_tmtime" datetime="<?php echo $dtime; ?>"><span><?php echo $dmy;?></span> <span><?php echo $time; ?></span></time>
					<div class="cbp_tmicon fa fa-calendar"></div>
					<div class="cbp_tmlabel">
						<h2><?php echo $event->post_title; ?></h2>
						<p><?php echo $event->post_content; ?><?php echo $editlink; ?></p>
					</div>
				</li>
				
				<?php  } // endif
			} // endif ?>
			<?php endforeach;

		$master_return = ob_get_clean();
			return $master_return;
	}
	
	/* Adds a box to the main column on the Post and Page edit screens */
	function metabox() {
	    add_meta_box( 'ot_events_sectionid', __( 'Event Details', 'ot_events_textdomain' ), array($this, 'custom_metabox'),'event', 'normal', 'high');
	}

	/* Prints the box content */
	function custom_metabox() {

	  // Use nonce for verification
	  wp_nonce_field( plugin_basename(__FILE__), 'ot_events_noncename' );

		global $post;
		$ot_e_link 		= get_post_meta($post->ID, 'ot_e_link', true);
		$ot_e_date		= get_post_meta($post->ID, 'ot_e_date', true);
		$ot_e_location	= get_post_meta($post->ID, 'ot_e_location', true);
		$ot_e_time 		= get_post_meta($post->ID, 'ot_e_time', true);

	  // The actual fields for data entry ?>
	<table border="0" cellspacing="5" cellpadding="5" width="100%">
		<tr>
		<td>
			<p>
				<label for="ot_e_location">Event Location</label><br>
				<input type="text" name="ot_e_location" value="<? echo $ot_e_location; ?>" id="ot_e_location">			
			</p>
			<p>
				<label for="ot_e_date">Event Date <small>(accepts logical dates, like March 1, 2012, or 03/01/2012)</small></label><br>
				<input type="text" name="ot_e_date" value="<? if (!empty($ot_e_date)) { echo date('m/d/Y h:ia',$ot_e_date); } ?>" id="ot_e_date">
			</p>
			<p>
				<label for="ot_e_link">Event Link: <small>Please include http:// or the link will result in a 404 on your site</small></label><br>
				<input size="60" type="text" name="ot_e_link" value="<?php echo $ot_e_link; ?>" id="ot_e_link">
			</p>
			<p>
				<label for="ot_e_time">Event Time: <small>(Freeform styling, 7pm ET, or 9-5pm)</small></label><br>
				<input type="text" name="ot_e_time" value="<?php echo $ot_e_time; ?>" id="ot_e_time">
			
			</p>
		</td>
		</tr>
	</table>
  
	<?php
	}
   
	/* When the post is saved, saves our custom data */
	function save_postdata( $post_id ) {

	  // verify this came from the our screen and with proper authorization,
	  // because save_post can be triggered at other times

	  if ( !wp_verify_nonce( $_POST['ot_events_noncename'], plugin_basename(__FILE__) ) )
	      return $post_id;
	  // verify if this is an auto save routine. 
	  // If it is our form has not been submitted, so we dont want to do anything
	  if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) 
	      return $post_id;

	  // Check permissions
	  if ( !current_user_can( 'edit_post', $post_id ) )
			return $post_id;

	  // OK, we're authenticated: we need to find and save the data

		$ot_e_location = $_POST['ot_e_location'];
		$ot_e_date = strtotime($_POST['ot_e_date']);
		$ot_e_link = $_POST['ot_e_link'];
		$ot_e_time = $_POST['ot_e_time'];

	  // update the data
		
		update_post_meta($post_id, 'ot_e_location', $ot_e_location);
		update_post_meta($post_id, 'ot_e_date', $ot_e_date);
		update_post_meta($post_id, 'ot_e_link', $ot_e_link);
		update_post_meta($post_id, 'ot_e_time', $ot_e_time);	
	}
	
} // end class Outthink_Events

// This is a widget for Upcoming Events	
// new class to extend WP_Widget function
class OT_Events_Widget extends WP_Widget {
	/** Widget setup.  */
	function OT_Events_Widget() {
		/* Widget settings. */
		$widget_ops = array(
			'classname' => 'ot_events_widget',
			'description' => __('Upcoming Events', 'ot_events_widget') );

		/* Widget control settings. */
		$control_ops = array( 'width' => 300, 'height' => 350, 'id_base' => 'ot_events_widget' );

		/* Create the widget. */
		$this->WP_Widget( 'ot_events_widget', __('Upcoming Events Widget', 'Options'), $widget_ops, $control_ops );
	}
	/**
	* How to display the widget on the screen. */
	function widget( $args, $instance ) {
		extract( $args );
		$title = apply_filters('widget_title', $instance['title'] );
		$number = $instance['number'];

		/* Before widget (defined by themes). */
		echo $before_widget;
		if ( $title )
			echo $before_title . $title . $after_title;
	
		/* Display name from widget settings if one was input. */
	
		// Settings from the widget
		?>
		<?php
		// TODO: Create Parameters for the events so that there's more to it.
		// loading up the $events variable to pass as argument to the get_posts, will order by meta value.
		
		$events = array(
			'post_type' => 'event',
			'orderby' => 'meta_value',
			'order' => 'ASC',
			'meta_key' => 'ot_e_date',
			'posts_per_page' => '-1',
		);
		$events = get_posts($events);
		$c = 1;
		if (!empty($events)) :
			foreach ($events as $event) {
				// loading up the data
				$date = get_post_meta($event->ID, 'ot_e_date', true);
				$location = get_post_meta($event->ID, 'ot_e_location', true);
				$link = get_post_meta($event->ID, 'ot_e_link', true);
				// checking to make sure the date is NOT
				if ($date > strtotime('today') && $c <= $number) { ?>
					<div class="ot-event-post">					
						<h4><?php echo $event->post_title; ?></h4>
						<?php if ($date or $link or $location): ?>
							<p>
							<?php if (!empty($location)): ?>
								<?php echo $location; ?><br />
							<?php endif; ?>
							<?php if (!empty($date)): ?>
								<span class="date">
								 <?php echo date('l, F jS, Y', $date); ?></span><br />
								 <span class="time"><?php echo get_post_meta($event->ID, 'ot_e_time', true); ?></span>
							<?php endif; ?>
							<?php if (!empty($link)): ?><br>
								<strong><a href="<?php echo $link; ?>">Learn More >></a></strong>
							<?php endif; ?>
						</p>
						<?php endif; ?>
					</div>
				<?php
				$date = '';
				$location = '';
				$link = '';
				$c++; } // end check for "upcoming" and tick counter				
			} // endforeach
		else : ?>
			<p>No upcoming events.</p>
		<?php endif;
		/* After widget (defined by themes). */
		echo $after_widget;
	}

	/**
	 * Update the widget settings.
	 */
	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags for title and name to remove HTML (important for text inputs). */
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['number'] = $new_instance['number'];

		return $instance;
	}

/**
 * Displays the widget settings controls on the widget panel.
 * Make use of the get_field_id() and get_field_name() function
 * when creating your form elements. This handles the confusing stuff.
*/
function form($instance) {
	$defaults = array( 
		'title' => __('Upcoming Events', 'ot_events_widget'),
		'number' => '3',
	);
	$instance = wp_parse_args( (array) $instance, $defaults ); ?>
	<!-- Widget Title: Text Input -->
	<p>
		<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e('Title:', 'ot_events_widget'); ?></label><br>
		<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo $instance['title']; ?>">
	</p>
	<p>
		<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php _e('Number of Events:', 'ot_events_widget'); ?></label><br>
		<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" value="<?php echo $instance['number']; ?>">
	</p>
	<?php
	}
} // END OT_Events

$OutthinkEvents = new Outthink_Events();

require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 'includes/options.php' );
include 'includes/ot-nlsignup.php';