<?php
defined( 'ABSPATH' ) || exit;

/**
 * The two AJAX endpoints the front-end script calls.
 *
 * Both are readable only for data the caller already owns: the Bookly form id
 * is the caller's own session token, and the order token is the unguessable
 * token Bookly itself hands to the browser on the complete step.
 */
class STMS_Ajax
{
    const NONCE_ACTION = 'stms_datalayer';

    public static function init()
    {
        add_action( 'wp_ajax_stms_flow_state', array( __CLASS__, 'flow_state' ) );
        add_action( 'wp_ajax_nopriv_stms_flow_state', array( __CLASS__, 'flow_state' ) );
        add_action( 'wp_ajax_stms_order_data', array( __CLASS__, 'order_data' ) );
        add_action( 'wp_ajax_nopriv_stms_order_data', array( __CLASS__, 'order_data' ) );
        add_action( 'wp_ajax_stms_customer', array( __CLASS__, 'customer' ) );
        add_action( 'wp_ajax_nopriv_stms_customer', array( __CLASS__, 'customer' ) );
        add_action( 'wp_ajax_stms_record_event', array( __CLASS__, 'record_event' ) );
        add_action( 'wp_ajax_nopriv_stms_record_event', array( __CLASS__, 'record_event' ) );
    }

    /**
     * Who the visitor is, as far as Bookly can tell: the caller only ever gets
     * back the customer of its own session, or its own logged-in account.
     */
    public static function customer()
    {
        self::check_nonce();

        $customer_id = STMS_Bookly_Data::customer_id( self::param( 'form_id' ) );

        wp_send_json_success( array(
            'customer_id' => $customer_id ? $customer_id : '',
            'logged_in' => is_user_logged_in(),
        ) );
    }

    /**
     * Stores the booking against the GA4 client id the browser read from gtag.
     *
     * The client is trusted for exactly three things it alone can know - the
     * GA4 client id, the flow id, and which event it pushed. The booking itself
     * is re-read from Bookly here, so a caller cannot record an order it does
     * not hold the session or the token for.
     */
    public static function record_event()
    {
        self::check_nonce();

        $payload = STMS_Bookly_Data::order_payload( self::param( 'form_id' ), self::param( 'order_token' ) );

        if ( $payload === null ) {
            wp_send_json_error( array( 'reason' => 'no_order' ) );
        }

        $stored = STMS_Events_Store::record( $payload, array(
            'flow_id' => self::param( 'flow_id' ),
            'ga_client_id' => self::param( 'ga_client_id' ),
            'event_name' => self::param( 'event_name' ),
        ) );

        wp_send_json_success( array( 'stored' => (bool) $stored ) );
    }

    /**
     * Cart snapshot for the booking in progress.
     */
    public static function flow_state()
    {
        self::check_nonce();

        $form_id = self::param( 'form_id' );
        $state = STMS_Bookly_Data::flow_state( $form_id );

        if ( $state === null ) {
            wp_send_json_error( array( 'reason' => 'no_state' ) );
        }

        wp_send_json_success( $state );
    }

    /**
     * Completed-booking payload.
     */
    public static function order_data()
    {
        self::check_nonce();

        $form_id = self::param( 'form_id' );
        $order_token = self::param( 'order_token' );
        $payload = STMS_Bookly_Data::order_payload( $form_id, $order_token );

        if ( $payload === null ) {
            wp_send_json_error( array( 'reason' => 'no_order' ) );
        }

        wp_send_json_success( $payload );
    }

    /**
     * Nonces expire on cached pages, so a stale nonce must not break tracking:
     * the tokens in the request are what actually scope the data. A missing
     * nonce is only rejected when the request carries no token at all.
     */
    private static function check_nonce()
    {
        $nonce = self::param( 'nonce' );

        if ( $nonce !== '' && wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }

        if ( self::param( 'form_id' ) === '' && self::param( 'order_token' ) === '' ) {
            wp_send_json_error( array( 'reason' => 'bad_request' ), 400 );
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private static function param( $key )
    {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
    }
}
