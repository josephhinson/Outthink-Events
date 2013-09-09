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
	        <h2>Out:think Events Options</h2>
	        <form action="options.php" method="POST">
	            <?php settings_fields( 'ot_events' ); ?>
	            <?php do_settings_sections( 'ot_events' ); ?>
	            <?php submit_button(); ?>
	        </form>
	    </div>
	    <?php
	}
	public function active() {
		/* get wp version */
		global $wp_version;
		$otpu =  new OTEvents_Plugin_Updater();		
		$updater_data =$otpu->updater->updater_data();

		/* get current domain */
		$domain = $updater_data['domain'];
		$userinfo = get_option('ot-plugin-validation');
		$key = $userinfo['email'];
		$username = $userinfo['user'];

		$valid = "invalid";

		if( empty($key) || empty($username) ) return $valid;

		/* Get data from server */
		$remote_url = add_query_arg( array( 'plugin_repo' => $updater_data['repo_slug'], 'ahr_check_key' => 'validate_key' ), $updater_data['repo_uri'] );
		$remote_request = array( 'timeout' => 20, 'body' => array( 'key' => md5( $key ), 'login' => $username, 'autohosted' => $updater_data['autohosted'] ), 'user-agent' => 'WordPress/' . $wp_version . '; ' . $updater_data['domain'] );
		$raw_response = wp_remote_post( $remote_url, $remote_request );

		/* get response */
		$response = '';
		if ( !is_wp_error( $raw_response ) && ( $raw_response['response']['code'] == 200 ) )
			$response = trim( wp_remote_retrieve_body( $raw_response ) );

		/* if call to server sucess */
		if ( !empty( $response ) ){

			/* if key is valid */
			if ( $response == 'valid' ) $valid = 'valid';

			/* if key is not valid */
			elseif ( $response == 'invalid' ) $valid = 'invalid';

			/* if response is value is not recognized */
			else $valid = 'unrecognized';
		}

		return $valid;
	}
}
$OT_Events_Settings = new OT_Events_Settings();