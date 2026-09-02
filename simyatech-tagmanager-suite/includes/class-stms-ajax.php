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
    }

    /**
     * Who the visitor is, as far as Bookly can tell: the caller only ever gets
     * back the customer of its own session, or its own logged-in account.
     */
    public static function customer()
    {
        self::check_nonce();

        $client_id = STMS_Bookly_Data::customer_id( self::param( 'form_id' ) );

        wp_send_json_success( array(
            'client_id' => $client_id ? $client_id : '',
            'logged_in' => is_user_logged_in(),
        ) );
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
