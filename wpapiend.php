<?php
/*
Plugin Name: WP API Endpoint
Plugin URI: https://github.com/sofyansitorus/WP-API-Endpoint/
Description: WordPress API Endpoint plugin for Android
Version: 1.0.0.
Author: Sofyan Sitorus
Author Email: sofyansitorus@gmail.com
Author URI: https://github.com/sofyansitorus/
*/

class WP_API_Endpoint {

	/*--------------------------------------------*
	 * Constants
	 *--------------------------------------------*/
	const name = 'WP API Endpoint';
	const slug = 'wpapiend';

	private $_action;
	private $_response = array();
	private $_post = array();

	private $_user;

	private $_token_key = '_user_token';
	private $_token_exp_key = '_user_token_exp';
	private $_token_exp = 86400; // Token expiry times
	
	
	/** Hook WordPress
	*	@return void
	*/
	public function __construct(){

		//register an activation hook for the plugin
		register_activation_hook( __FILE__, array($this, '_flush_rewrite_rules'));

		//register a deactivation hook for the plugin
		register_deactivation_hook( __FILE__, array($this, '_flush_rewrite_rules'));
		
		//add rewrite rule
		add_action('init', array($this, '_add_rewrite_rule'), 0);

		//Setup localization
		add_action('init', array($this, '_load_plugin_textdomain'), 0);

		//add custom query vars
		add_filter('query_vars', array($this, '_add_query_vars'), 0);
		
		//add HTTP request handler
		add_action('parse_request', array($this, '_parse_request'), 0);

	}

	/**
	 * Flush the rewrite rules
	 */
	public function _flush_rewrite_rules(){
		flush_rewrite_rules();
	}
	
	/** Add custom rewrite rule
	*	@return void
	*/
	public function _add_rewrite_rule(){
		add_rewrite_rule('^api/?([a-z]+)?/?','index.php?__api=$matches[1]','top');
	}
	
	/** Add public query vars
	*	@param array $vars List of current public query vars
	*	@return array $vars 
	*/
	public function _add_query_vars($vars){
		$vars[] = '__api';
		return $vars;
	}

	/**	Parse Requests
	*	This is where we hijack all API requests
	* 	If $_GET['__api'] is set, we kill WP and serve up the json response
	*	@return die if API request
	*/
	public function _parse_request(){
		global $wp;
		if(isset($wp->query_vars['__api'])){
			$this->_action = $wp->query_vars['__api'];
			$this->_post = json_decode(file_get_contents("php://input"),true); // Get raw data for JSON Request Body
			$this->_handle_request();
		}
	}
	
	/** Handle Requests
	*	@return void 
	*/
	private function _handle_request(){
		if(!empty($this->_action)){
			$func = 'process_'.$this->_action;
			if(method_exists($this, $func) && is_callable(array($this, $func))){
				call_user_func(array($this, $func));
				$this->_send_response();
			}else{
				http_response_code(501);
				die(__('Invalid request parameter.', self::slug));
			}
		}
	}
	
	/** Response Handler
	*	This sends a JSON response to the browser
	*/
	private function _send_response(){
		header('content-type: application/json; charset=utf-8');
	    wp_send_json($this->_response);
	}

	/** Get posted data
	*	@return void
	*/
	public function _post($key){
		return isset($this->_post[$key]) ? sanitize_text_field($this->_post[$key]) : false;
	}

	/** Setup localization
	*	@return void
	*/
	public function _load_plugin_textdomain(){
		load_plugin_textdomain( self::slug, false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
	}
	
	// Generate Token
	private function _generate_token($user_id){
		return sha1($user_id.time().uniqid(mt_rand(), true));
	}

	// Store Token
	private function _store_token($user_id, $token=''){
		if(!$token){
			$token = $this->_generate_token($user_id);
		}
		update_user_meta( $user_id, $this->_token_key, $token );
		update_user_meta( $user_id, $this->_token_exp_key, (current_time( 'timestamp' ) + $this->_token_exp) );
		return $token;
	}

	// Check Token
	private function _check_token(){
		$token = $this->_post('token');
		if($token){
			$args = array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => $this->_token_key,
						'value'   => $token,
						'compare' => '='
					),
					array(
						'key'     => $this->_token_exp_key,
						'value'   => current_time( 'timestamp' ),
						'type'    => 'numeric',
						'compare' => '>='
					)
				)
			);

			// Query for users based on the meta data
			$user_query = new WP_User_Query($args);

			// Get the results from the query, returning the first user
			$users = $user_query->get_results();

			if($users){
				$this->_user = $users[0];
				return true;
			}
		}
		$this->_response = array(
			'status' => "failed",
			'message' => __('Invalid token.', self::slug)
		);
		return false;
	}

	//Process /api/login/ request
	private function process_login(){
		$username = $this->_post('username');
		$password = $this->_post('password');
		if($username && $password){
			$data = array(
				'user_login' => $username,
				'user_password' => $password
			);
			$this->_user = wp_signon( $data, false );
			if ( is_wp_error($this->_user) ){
				switch ($this->_user->get_error_code()) {
					case 'invalid_username':
						$message = __('Invalid username.', self::slug);
						break;
					case 'incorrect_password':
						$message = __('The password you entered is incorrect.', self::slug);
						break;								
					default:
						$message = __('Invalid username or password.', self::slug);
						break;
				}
				$this->_response = array('status' => "failed", 'message' => $message);
			}else{
				$this->_response = array(
					'status' => "ok",
					'token' => $this->_store_token($this->_user->ID),
					'user_login' => $this->_user->user_login,
					'user_nicename' => $this->_user->user_nicename,
					'display_name' => $this->_user->display_name,
					'user_registered' => $this->_user->user_registered
				);
			}
		} else {
			$this->_response = array(
				'status' => "failed",
				'message' => __('Username and password is required', self::slug),
			);
		}
	}

	//Process /api/getcategories/ request
	private function process_getcategories(){
		if($this->_check_token()){
			$categories_arr = array();
			$categories = get_categories();
			if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
				foreach ( $categories as $category ) {
					$categories_arr[] = array(
						'id' => $category->term_id,
						'name' => $category->name,
						'description' => $category->description,
						'count' => $category->count
					);
				}
			}
			$this->_response = array(
				'status' => "ok",
				'categories' => $categories_arr
			);
		}
	}

	//Process /api/getposts/ request
	private function process_getposts(){
		if($this->_check_token()){
			$posts_arr = array();
			// The Query
			$the_query = new WP_Query(
				array(
					'post_type' => 'post'
				) 
			);

			// The Loop
			if ( $the_query->have_posts() ) {
				while ( $the_query->have_posts() ) {
					$the_query->the_post();
					$posts_arr[] = array(
						'id' => get_the_ID()
						'title' => get_the_title(),
						'excerpt' => get_the_excerpt(),
						'content' => get_the_content()
					);
				}
			}
			/* Restore original Post Data */
			wp_reset_postdata();

			$this->_response = array(
				'status' => "ok",
				'posts' => $posts_arr
			);
		}
	}
  
} // end class
new WP_API_Endpoint();
