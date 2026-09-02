<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end assets: the dataLayer bootstrap and the tracker script.
 */
class STMS_Assets
{
    const HANDLE = 'stms-datalayer';

    public static function init()
    {
        // As early as possible, so anything that pushes later has an array.
        add_action( 'wp_head', array( __CLASS__, 'bootstrap_data_layer' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function bootstrap_data_layer()
    {
        if ( is_admin() ) {
            return;
        }

        echo "<script>window.dataLayer = window.dataLayer || [];</script>\n";
    }

    public static function enqueue()
    {
        if ( is_admin() ) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            STMS_URL . 'assets/js/datalayer.js',
            array( 'jquery' ),
            STMS_VERSION,
            true
        );

        wp_localize_script( self::HANDLE, 'STMSData', self::config() );
    }

    /**
     * @return array
     */
    public static function config()
    {
        $page = STMS_Page_Meta::values();
        $customer_id = STMS_Bookly_Data::logged_in_customer_id();

        return apply_filters( 'stms_js_config', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( STMS_Ajax::NONCE_ACTION ),
            'page' => $page,
            // A logged-in visitor is identified before the first push; a guest
            // is looked up over AJAX once the details step has run.
            'loggedIn' => is_user_logged_in(),
            'customerId' => $customer_id ? $customer_id : '',
            // URL segment that marks a therapist single page: /team/<name>
            'therapistSegment' => apply_filters( 'stms_therapist_path_segment', 'team' ),
            // base slug of the generic booking page
            'appointmentSlug' => apply_filters( 'stms_appointment_base_slug', 'appointment' ),
            'debug' => STMS_Plugin::debug(),
        ) );
    }
}
