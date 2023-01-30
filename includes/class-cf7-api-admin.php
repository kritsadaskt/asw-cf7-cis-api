<?php


// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
  die;
}

require plugin_dir_path( __DIR__ ).'vendor/autoload.php';

use GuzzleHttp\Client;

class QS_CF7_api_admin{

  /**
   * Holds the plugin options
   * @var [type]
   */
  private $options;

  /**
   * Holds athe admin notices class
   * @var [QS_Admin_notices]
   */
  private $admin_notices;

  /**
   * PLugn is active or not
   */
  private $plugin_active;
  /**
   * API errors array
   * @var [type]
   */
  private $api_errors;

  public function __construct(){

    $this->textdomain = 'qs-cf7-api';

    $this->admin_notices = new QS_Admin_notices();

    $this->api_errors = array();

    $this->register_hooks();

  }
  /**
   * Check if contact form 7 is active
   * @return [type] [description]
   */
  public function verify_dependencies(){
    if( ! is_plugin_active('contact-form-7/wp-contact-form-7.php') ){
      $notice = array(
        'id'                  => 'cf7-not-active',
        'type'                => 'warning',
        'notice'              => __( 'Contact form 7 api integrations requires CONTACT FORM 7 Plugin to be installed and active' ,$this->textdomain ),
        'dismissable_forever' => false
      );

      $this->admin_notices->wp_add_notice( $notice );
    }
  }
  /**
   * Registers the required admin hooks
   * @return [type] [description]
   */
  public function register_hooks(){
    /**
     * Check if required plugins are active
     * @var [type]
     */
    add_action( 'admin_init', array( $this, 'verify_dependencies' ) );

    /*before sending email to user actions */
    add_action( 'wpcf7_before_send_mail', array( $this , 'qs_cf7_send_data_to_api' ) );

    add_action( 'wpcf7_mail_sent', array( $this , 'qs_pcf7_mail_sent') , 10, 1 );

	  /* adds another tab to contact form 7 screen */
    add_filter( "wpcf7_editor_panels" ,array( $this , "add_integrations_tab" ) , 1 , 1 );

    /* actions to handle while saving the form */
    add_action( "wpcf7_save_contact_form" ,array( $this , "qs_save_contact_form_details") , 10 , 1 );

    add_filter( "wpcf7_contact_form_properties" ,array( $this , "add_sf_properties" ) , 10 , 2 );

    //add_action( 'wp_footer' ,array( $this , 'redirect_cf7' ) );

    add_filter('wpcf7_validate_email*', array($this, "qs_validation_email_blacklist"), 20, 2 );

	add_action( 'wp_enqueue_scripts' ,array( $this , "qs_load_scripts" ) );

  }

  function qs_validation_email_blacklist( $result, $tag ) {
    //Define Blacklist Email
    $bList = array(
      "Chochompu.1502@gmail.com",
      "chochompu.1502@gmail.com",
      "Zz656633@gmail.com",
      "h.f@gmail.con",
      "h.f@gmail.com",
      "hhhu.ss@gmail.com",
      "narongchai.t@gmail.com",
      "kmnvcxz2804@gmail.com",
      "Zz6562633@gmail.com",
      "Zz656633@gmail.com",
      "viyada@gmail.com",
    );

    if ('contact-email' == $tag->name) {
      $apply_email = isset($_POST['contact-email']) ? trim($_POST['contact-email']) : '';

      if (in_array($apply_email, $bList)) {
//        ChromePhp::log($apply_email);
        $result->invalidate($tag, "Something went wrong :(");
        //die();
      }
    }

    return $result;
  }

  function qs_load_scripts() {
	  wp_enqueue_script( 'wpcf7-redirect-script', QS_CF7_API_ADMIN_JS_URL . 'qs-cf7-api-redirect-script.js', array(), null, true );
	  wp_localize_script( 'wpcf7-redirect-script', 'wpcf7_redirect_forms', $this->get_forms() );
  }

  /**
   * Sets the form additional properties
   * @param [type] $properties   [description]
   * @param [type] $contact_form [description]
   */
  function add_sf_properties( $properties , $contact_form ){

    //add mail tags to allowed properties
    $properties["wpcf7_api_data"]     = isset($properties["wpcf7_api_data"]) ? $properties["wpcf7_api_data"]         : array();
    $properties["wpcf7_api_data_map"] = isset($properties["wpcf7_api_data_map"]) ? $properties["wpcf7_api_data_map"] : array();
    $properties["template"]           = isset($properties["template"]) ? $properties["template"]                     : '';
    $properties["json_template"]      = isset($properties["json_template"]) ? $properties["json_template"]                     : '';

    return $properties;
  }

  /**
   * Adds a new tab on conract form 7 screen
   * @param [type] $panels [description]
   */
  function add_integrations_tab($panels) {

    $integration_panel = array(
      'title'    => __( 'API Integration' , $this->textdomain ),
      'callback' => array( $this, 'wpcf7_integrations' )
    );

    $panels["qs-cf7-api-integration"] = $integration_panel;

    return $panels;

  }
  /**
   * Collect the mail tags from the form
   * @return [type] [description]
   */
  function get_mail_tags( $post ){
    $tags = apply_filters( 'qs_cf7_collect_mail_tags' , $post->scan_form_tags() );

    foreach ( (array) $tags as $tag ) {
      $type = trim( $tag['type'], ' *' );
      if ( empty( $type ) || empty( $tag['name'] ) ) {
        continue;
      } elseif ( ! empty( $args['include'] ) ) {
        if ( ! in_array( $type, $args['include'] ) ) {
          continue;
        }
      } elseif ( ! empty( $args['exclude'] ) ) {
        if ( in_array( $type, $args['exclude'] ) ) {
          continue;
        }
      }
      $mailtags[] = $tag;
    }

    return $mailtags;
  }
  /**
   * The admin tab display, settings and instructions to the admin user
   * @param  [type] $post [description]
   * @return [type]       [description]
   */
  function wpcf7_integrations( $post ) {

    $wpcf7_api_data                 = $post->prop( 'wpcf7_api_data' );

    $wpcf7_api_data["base_cis_url"]     = isset( $wpcf7_api_data["base_cis_url"] ) ? $wpcf7_api_data["base_cis_url"] : 'http://asw1.dyndns.org:8083/api/Customer/SaveOtherSource/';
    $wpcf7_api_data["base_crm_url"]     = isset( $wpcf7_api_data["base_crm_url"] ) ? $wpcf7_api_data["base_crm_url"] : '';
    $wpcf7_api_data["base_sms_url"]     = isset( $wpcf7_api_data["base_sms_url"] ) ? $wpcf7_api_data["base_sms_url"] : 'https://www.corp-sms.com/CorporateSMS/SMSReceiverXML';

    $wpcf7_api_data["base_sms_user"]      = isset( $wpcf7_api_data["base_sms_user"] ) ? $wpcf7_api_data["base_sms_user"] : '';
    $wpcf7_api_data["base_sms_pass"]      = isset( $wpcf7_api_data["base_sms_pass"] ) ? $wpcf7_api_data["base_sms_pass"] : '';
    $wpcf7_api_data["base_sms_sender"]    = isset( $wpcf7_api_data["base_sms_sender"] ) ? $wpcf7_api_data["base_sms_sender"] : '';
    $wpcf7_api_data["base_sms_text"]      = isset( $wpcf7_api_data["base_sms_text"] ) ? $wpcf7_api_data["base_sms_text"] : '';


    $wpcf7_api_data["send_to_cis"]  = isset( $wpcf7_api_data["send_to_cis"] ) ? $wpcf7_api_data["send_to_cis"]   : '';
    $wpcf7_api_data["send_to_crm"]  = isset( $wpcf7_api_data["send_to_crm"] ) ? $wpcf7_api_data["send_to_crm"]   : '';
    $wpcf7_api_data["send_to_sms"]  = isset( $wpcf7_api_data["send_to_sms"] ) ? $wpcf7_api_data["send_to_sms"]   : '';

    $wpcf7_api_data["page_redirect_url"]  = isset( $wpcf7_api_data["page_redirect_url"] ) ? $wpcf7_api_data["page_redirect_url"]   : '';
    $wpcf7_api_data["page_redirect"]  = isset( $wpcf7_api_data["page_redirect"] ) ? $wpcf7_api_data["page_redirect"]   : '';



?>


        <h2><?php echo esc_html( __( 'API Integration', $this->textdomain ) ); ?></h2>

        <fieldset>
          <?php do_action( 'before_base_fields' , $post ); ?>

          <div class="cf7_row">
              <label for="wpcf7-sf-send_to_cis">
                  <input type="checkbox" id="wpcf7-sf-send_to_cis" name="wpcf7-sf[send_to_cis]" <?php checked( $wpcf7_api_data["send_to_cis"] , "on" );?>/>
                  <?php _e( 'Send to CIS ?' , $this->textdomain );?>
              </label>
          </div>

          <div class="cf7_row">
              <label for="wpcf7-sf-base_cis_url">
                  <?php _e( 'Base url' , $this->textdomain );?>
                  <input type="text" id="wpcf7-sf-base_cis_url" name="wpcf7-sf[base_cis_url]" class="large-text" value="<?php echo $wpcf7_api_data["base_cis_url"];?>" />
              </label>
          </div>

          <hr>

          <div class="cf7_row">
              <label for="wpcf7-sf-send_to_crm">
                  <input type="checkbox" id="wpcf7-sf-send_to_crm" name="wpcf7-sf[send_to_crm]" <?php checked( $wpcf7_api_data["send_to_crm"] , "on" );?>/>
                  <?php _e( 'Send to CRM ?' , $this->textdomain );?>
              </label>
          </div>
          <div class="cf7_row">
              <label for="wpcf7-sf-base_crm_url">
                  <?php _e( 'Base url' , $this->textdomain );?>
                  <input type="text" id="wpcf7-sf-base_crm_url" name="wpcf7-sf[base_crm_url]" class="large-text" value="<?php echo $wpcf7_api_data["base_crm_url"];?>" />
              </label>
          </div>

          <hr>

          <div class="cf7_row">
              <label for="wpcf7-sf-send_to_sms">
                  <input type="checkbox" id="wpcf7-sf-send_to_sms" name="wpcf7-sf[send_to_sms]" <?php checked( $wpcf7_api_data["send_to_sms"] , "on" );?>/>
                  <?php _e( 'Send to SMS ?' , $this->textdomain );?>
              </label>
          </div>
          <div class="cf7_row">
              <label for="wpcf7-sf-base_sms_url">
                  <?php _e( 'Base url' , $this->textdomain );?>
                  <input type="text" id="wpcf7-sf-base_sms_url" name="wpcf7-sf[base_sms_url]" class="large-text" value="<?php echo $wpcf7_api_data["base_sms_url"];?>" />
              </label>
          </div>

          <div class="cf7_row">
              <label for="wpcf7-sf-base_sms_user">
                  <?php _e( 'Username' , $this->textdomain );?>
                  <input type="text" id="wpcf7-sf-base_sms_user" name="wpcf7-sf[base_sms_user]" class="large-text" value="<?php echo $wpcf7_api_data["base_sms_user"];?>" />
              </label>
          </div>
          <div class="cf7_row">
              <label for="wpcf7-sf-base_sms_pass">
                  <?php _e( 'Password' , $this->textdomain );?>
                  <input type="text" id="wpcf7-sf-base_sms_pass" name="wpcf7-sf[base_sms_pass]" class="large-text" value="<?php echo $wpcf7_api_data["base_sms_pass"];?>" />
              </label>
          </div>
          <div class="cf7_row">
              <label for="wpcf7-sf-base_sms_sender">
                  <?php _e( 'Sender Name' , $this->textdomain );?>
                  <input type="text" id="wpcf7-sf-base_sms_sender" name="wpcf7-sf[base_sms_sender]" class="large-text" value="<?php echo $wpcf7_api_data["base_sms_sender"];?>" />
              </label>
          </div>
          <div class="cf7_row">
              <label for="wpcf7-sf-base_sms_text">
                  <?php _e( 'SMS Text' , $this->textdomain );?>
                  <textarea rows="10" id="wpcf7-sf-base_sms_text" name="wpcf7-sf[base_sms_text]"><?php echo htmlspecialchars($wpcf7_api_data["base_sms_text"]); ?></textarea>
              </label>
          </div>

            <hr>

            <div class="cf7_row">
                <label for="wpcf7-sf-page_redirect">
                    <input type="checkbox" id="wpcf7-sf-page_redirect" name="wpcf7-sf[page_redirect]" <?php checked( $wpcf7_api_data["page_redirect"] , "on" );?>/>
			        <?php _e( 'Redirect Thank You page ?' , $this->textdomain );?>
                </label>
            </div>

            <div class="cf7_row">
                <label for="wpcf7-sf-page_redirect_url">
			        <?php _e( 'Post ID / URL to Thank You page' , $this->textdomain );?>
                    <input type="text" id="wpcf7-sf-page_redirect_url" name="wpcf7-sf[page_redirect_url]" class="large-text" value="<?php echo $wpcf7_api_data["page_redirect_url"];?>" />
                    <p>ระบุ Post ID เป็นตัวเลข หรือ URL (ในรูปแบบ https นำหน้า) ที่ต้องการสั่งให้เปลี่ยนหน้า</p>
                </label>
            </div>

            <?php do_action( 'after_base_fields' , $post ); ?>

        </fieldset>
<?php
  }

  /**
   * Saves the API settings
   * @param  [type] $contact_form [description]
   * @return [type]               [description]
   */
  public function qs_save_contact_form_details( $contact_form ){

    $properties = $contact_form->get_properties();

    $properties['wpcf7_api_data']     = isset( $_POST["wpcf7-sf"] ) ? $_POST["wpcf7-sf"] : '';
    $properties['wpcf7_api_data_map'] = isset( $_POST["qs_wpcf7_api_map"] ) ? $_POST["qs_wpcf7_api_map"] : '';
    $properties['template']           = isset( $_POST["template"] ) ? $_POST["template"] : '';
    $properties['json_template']      = isset( $_POST["json_template"] ) ? $_POST["json_template"] : '';

    if ( ! add_post_meta( $contact_form->ID(), '_is_redirect', $properties['wpcf7_api_data']['page_redirect'], true ) ) {
        update_post_meta ( $contact_form->ID(), '_is_redirect', $properties['wpcf7_api_data']['page_redirect'] );
    }

    if ( ! add_post_meta( $contact_form->ID(), '_redirect_url', $properties['wpcf7_api_data']['page_redirect_url'], true ) ) {
        update_post_meta ( $contact_form->ID(), '_redirect_url', $properties['wpcf7_api_data']['page_redirect_url'] );
    }

    $contact_form->set_properties( $properties );

  }

  /**
   * The handler that will send the data to the api
   * @param  [type] $WPCF7_ContactForm [description]
   * @return [type]                    [description]
   */
  public function qs_cf7_send_data_to_api( $WPCF7_ContactForm ) {

    date_default_timezone_set('Asia/Bangkok');

    $submission = WPCF7_Submission::get_instance();

    $url                       = $submission->get_meta( 'url' );
    $this->post                = $WPCF7_ContactForm;
    $qs_cf7_data               = $WPCF7_ContactForm->prop( 'wpcf7_api_data' );
    $qs_cf7_data['debug_log']  = true; //always save last call results for debugging

    $posted_data = $submission->get_posted_data();

	$log = new Logger(plugin_dir_path( __DIR__ )."log/cis.html");
	$sms_log = new Logger(plugin_dir_path( __DIR__ )."log/sms.html");
	// $test = new Logger(plugin_dir_path( __DIR__ )."log/test.html"); # for debug any variables

    /* check if the form is marked to be sent via API */
    if( isset( $qs_cf7_data["send_to_cis"] ) && $qs_cf7_data["send_to_cis"] == "on" && $submission ){

      $contactAccept = 'true';

      if($posted_data['contact-accept'] === '') {
         $contactAccept = 'false';
      }

      $record['fields'] = array(
        'ProjectID'           => $posted_data['project-id'],
        'ContactChannelID'    => 21,
        'ContactTypeID'       => 35,
        'RefID'               => $posted_data['ref-id'],
        'Fname'               => $posted_data['contact-name'],
        'Lname'               => $posted_data['contact-surname'],
        'Tel'                 => $posted_data['contact-tel'],
        'Email'               => $posted_data['contact-email'],
        'Ref'                 => $posted_data['ref'],
        'RefDate'             => date("Y-m-d H:i:s"),
        'FollowUpID'          => 42,
        'utm_source'          => $posted_data['utm_source'],
        'utm_medium'          => $posted_data['utm_medium'],
        'utm_campaign'        => $posted_data['utm_campaign'],
        'utm_term'            => $posted_data['utm_term'],
        'utm_content'         => $posted_data['utm_content'],
        'PriceInterest'       => implode("",$posted_data['price-rate']),
        'ModelInterest'       => implode("",$posted_data['room-type']),
        'PromoCode'           => $posted_data['promo-code'],
        'FlagPersonalAccept'  => 'true',
        'FlagContactAccept'   => $contactAccept,
        'AppointDate'         => $posted_data['AppointDate'],
        'AppointTime'         => $posted_data['AppointTime'] ? implode("",$posted_data['AppointTime']) : "",
        'AppointTimeEnd'      => $posted_data['AppointTimeEnd'] ? implode("",$posted_data['AppointTimeEnd']) : ""
      );

      // $gg = json_encode($record['fields']); # change array to string
      // $test->setTimestamp("Y-m-d H:i:s"); # set timestamp
      // $test->putLog($gg); # for debug $record['fields']


      $record['url'] = $qs_cf7_data['base_cis_url'];

      do_action( 'qs_cf7_api_before_sent_to_api' , $record );

      $response = $this->send_lead($record, true);

      if( is_wp_error( $response ) ) {
        $log->setTimestamp("Y-m-d H:i:s");
        $log->putLog('[Form ID: ]'.$WPCF7_ContactForm->id().' Server Error');
      } else {
        $obj = json_decode($response['body']);
        $status = $obj->Success ? 'Success' : 'Fail';
        $user_email = explode('@', $posted_data['contact-email']);
        $hidden_user = substr($user_email[0], 0, -3) . 'xxx';
        $hidden_number = substr($posted_data['contact-tel'], 0, -4) . 'xxxx';

        // $test->setTimestamp("Y-m-d H:i:s"); # for set Timestamp
        // $test->putLog($response['body']); # for debug $response from CIS

        if($status) {
          $log->setTimestamp("Y-m-d H:i:s");
          $log->putLog('[Form ID : '.$WPCF7_ContactForm->id().' | Email : '.$hidden_user.'@'.$user_email[1].'] CIS : '.$status);

          /* check if the form is marked to be sent via SMS */
          if( isset( $qs_cf7_data["send_to_sms"] ) && $qs_cf7_data["send_to_sms"] == "on" && $submission ){

            $record = array(
              'tel'                 => $posted_data['contact-tel'],
              'username'            => $qs_cf7_data['base_sms_user'],
              'password'            => $qs_cf7_data['base_sms_pass'],
              'sender'              => $qs_cf7_data['base_sms_sender'],
              'text'                => $qs_cf7_data['base_sms_text'],
            );

            $url = $qs_cf7_data['base_sms_url'];

            do_action( 'qs_cf7_api_before_sent_to_sms' , $record, $url );

            $response = $this->send_sms($record, $url);

            $status = strip_tags($response->STATUS[0]);
            $detail = strip_tags($response->DETAIL[0]);

            if( $status !== "000" ){
              $sms_log->setTimestamp("Y-m-d H:i:s");
              $sms_log->putLog('[Form ID : '.$WPCF7_ContactForm->id().' | Tel : '.$hidden_number.'] SMS : Fail ('.$detail.')');
            } else {
              $sms_log->setTimestamp("Y-m-d H:i:s");
              $sms_log->putLog('[Form ID : '.$WPCF7_ContactForm->id().' | Tel : '.$hidden_number.'] SMS : Success');
              do_action( 'qs_cf7_api_after_sent_to_api' , $record , $response );
            }
          } else {
            do_action( 'qs_cf7_api_after_sent_to_api' , $record , $response );
          }
        } else {
          $log->setTimestamp("Y-m-d H:i:s");
          $log->putLog('[Form ID : '.$WPCF7_ContactForm->id().' | Email : '.$hidden_user.'@'.$user_email[1].'] CIS : '.$status.' ('.$obj->Message.')');
        }
      }
    }
  }

  /**
   * Send the lead using wp_remote
   * @param  [type]  $record [description]
   * @param  boolean $debug  [description]
   * @param  string  $method [description]
   * @return [type]          [description]
   */

  private function send_lead( $record , $debug = true ){
    global $wp_version;

    $lead = $record["fields"];
    $url  = $record["url"];

    $args = array(
      'timeout'     => 10,
      'redirection' => 5,
      'httpversion' => '1.0',
      'user-agent'  => 'WordPress/' . $wp_version . '; ' . home_url(),
      'blocking'    => true,
      'headers'     => array(
        'Authorization'   =>  'Basic Y3VzdG9tZXJtYW5hZ2VtZW50OmN1c3RvbWVybWFuYWdlbWVudEAyMDE4'
      ),
      'cookies'     => array(),
      'body'        => $lead,
      'compress'    => false,
      'decompress'  => true,
      'sslverify'   => true,
      'stream'      => false,
      'filename'    => null
    );

    $args   = apply_filters( 'qs_cf7_api_get_args' , $args );

    $url    = apply_filters( 'qs_cf7_api_post_url' , $url );

    $result = wp_remote_post( $url , $args );

    do_action('after_qs_cf7_api_send_lead' , $result , $record );

    return $result;

  }

  private function send_sms( $record, $url ) {

    $auth = base64_encode($record['username'].':'.$record['password']);
    $tel = $record['tel'];
    $key = md5($record['tel'].'100');
    $sender = $record['sender'];
    $text = $record['text'];

    $xml = "<?xml version=\"1.0\" encoding=\"tis-620\"?>\n<corpsms_request>\n<key>$key</key>\n<sender>$sender</sender>\n<mtype>T</mtype>\n<msg>$text</msg>\n<tid>100</tid>\n<recipients>\n<msisdn>$tel</msisdn>\n</recipients>\n</corpsms_request>";

    $options = [
      'headers' => [
          'Authorization'   =>  'Basic '.$auth,
          'Content-Type'    =>  'text/xml; charset=UTF8',
      ],
      'body' => $xml,
      'version' => 1.1
    ];

    $client = new Client();
  	$response = $client->request('POST', $url, $options);

    ob_start();
    print_r($response);
    error_log(ob_get_clean());

    $body = $response->getBody();
    $content = $body->getContents();

    libxml_use_internal_errors(true);
    $xml_result = simplexml_load_string($content);

    return $xml_result;

  }

  function get_forms() {
	  $args  = array(
		  'post_type'        => 'wpcf7_contact_form',
		  'posts_per_page'   => -1,
		  'suppress_filters' => true,
	  );
	  $query = new WP_Query( $args );

	  $forms = array();

	  if ( $query->have_posts() ) :

		  while ( $query->have_posts() ) :
			  $query->the_post();

			  $post_id = get_the_ID();

			  $is_redirect = get_post_meta( $post_id, '_is_redirect', true );
			  $redirect_url = get_post_meta( $post_id, '_redirect_url', true );

			  $forms[ $post_id ]['post_id'] = $post_id;
			  $forms[ $post_id ]['is_redirection'] = $is_redirect == "on";

			  $forms[ $post_id ]['thankyou_page_url'] = is_numeric($redirect_url) ? get_permalink( $redirect_url ) : $redirect_url;

		  endwhile;
		  wp_reset_postdata();
	  endif;
	  return $forms;
  }

}
