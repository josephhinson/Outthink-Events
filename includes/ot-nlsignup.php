<?php
if (!function_exists('add_otnlsignup_widget')) {
	// Create the function to use in the action hook
	function add_otnlsignup_widget() {
	  wp_add_dashboard_widget( 'ot_nlsignup_content', __( 'Learn how to market your book' ), 'ot_nlsignup_content' );
		global $wp_meta_boxes;

		// Get the regular dashboard widgets array 
		// (which has our new widget already but at the end)
		$normal_dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

		// Backup and delete our new dashbaord widget from the end of the array

		$ot_nlsignup_content_backup = array('ot_nlsignup_content' => $normal_dashboard['ot_nlsignup_content']);
		unset($normal_dashboard['ot_nlsignup_content']);

		// Merge the two arrays together so our widget is at the beginning

		$sorted_dashboard = array_merge($ot_nlsignup_content_backup, $normal_dashboard);

		// Save the sorted array back into the original metaboxes 

		$wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;
	}
	// Hook into the wp_dashboar_setup to add the widget
	add_action('wp_dashboard_setup', 'add_otnlsignup_widget' );

	// This is the function of the contents of our Dashboard Widget
	function ot_nlsignup_content() {  ?>
		<iframe width="100%" height="300" src="http://outthinkgroup.com/nlsignup/" scrolling="no" frameborder="0" overflow="0"></iframe>
	<?php
	}	
}