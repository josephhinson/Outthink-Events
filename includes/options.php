<?php	
class OT_Events_Settings{
	
	public function __construct() {
		add_action( 'admin_menu', array($this, 'admin_menu') );
		add_action( 'admin_init', array($this, 'admin_init') );
	}

	// initialize admin menu:
	function admin_menu() {
	    add_options_page( 'Out:think Events', 'Out:think Events', 'manage_options', 'ot_events', array($this, 'options_page') );
	}

	function admin_init() {
		$userinfo = (array)get_option('ot-plugin-validation');

		register_setting( 'ot_events', 'ot-plugin-validation' );

	    add_settings_section( 'section-one', 'Registration Info', array($this, 'section_one_callback'), 'ot_events' );
		// adding the Username Field
		add_settings_field( 'user', 'Username', array($this, 'text_input'), 'ot_events', 'section-one', array(
		    'name' => 'ot-plugin-validation[user]',
		    'value' => $userinfo['user'],
		) );
		add_settings_field( 'email', 'Email', array($this, 'text_input'), 'ot_events', 'section-one', array(
		    'name' => 'ot-plugin-validation[email]',
		    'value' => $userinfo['email'],
		) );
	}
	function section_one_callback() { ?>
		<p>Enter your Out:think Group username and email to enable automatic updates of this plugin.</p>
		<?php		
	}

	function text_input( $args ) {
	    $name = esc_attr( $args['name'] );
	    $value = esc_attr( $args['value'] );
	    echo "<input type='text' name='$name' value='$value' />";
		echo $args['help'];
	}

	function options_page() { ?>
	    <div class="wrap">
	        <h2>Out:think Reviews Options</h2>
	        <form action="options.php" method="POST">
	            <?php settings_fields( 'ot_events' ); ?>
	            <?php do_settings_sections( 'ot_events' ); ?>
	            <?php submit_button(); ?>
	        </form>
	    </div>
	    <?php
	}
}
$OT_Events_Settings = new OT_Events_Settings();