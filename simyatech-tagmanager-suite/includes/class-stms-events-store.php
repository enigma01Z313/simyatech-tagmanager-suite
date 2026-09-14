<?php
defined( 'ABSPATH' ) || exit;

/**
 * The booking-events table: one row per booking, holding the GA4 client id.
 *
 * GA4 never reports its own client id back in an event, and the dataLayer must
 * not carry an email address, so neither side alone can answer "which channel
 * brought this booking". This table is the bridge: the browser hands over the
 * GA4 client id it can read from gtag, everything else is re-read from Bookly
 * on the server, and the two are stored against the booking. The reconciliation
 * key (creation time + customer email) lives here and only here.
 *
 * The row is keyed by booking id, so a retry, a reload or a gateway round-trip
 * updates the same row instead of adding another one.
 */
class STMS_Events_Store
{
    const DB_VERSION = '1.0';
    const OPTION_DB_VERSION = 'stms_db_version';

    /** Events that may be recorded. */
    private static $events = array( 'bookly_booking_completed', 'bookly_booking_pending' );

    public static function init()
    {
        // Covers the upgrade path: activation already installs the table, but a
        // plugin updated in place never fires that hook.
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
    }

    /**
     * @return string
     */
    public static function table()
    {
        global $wpdb;

        // The spec's reconciliation SQL names this table, so the name it asks
        // for is the default rather than a plugin-prefixed one.
        return $wpdb->prefix . apply_filters( 'stms_events_table', 'daroon_booking_events' );
    }

    public static function maybe_install()
    {
        if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
            self::install();
        }
    }

    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_key varchar(191) NOT NULL DEFAULT '',
  flow_id char(32) NOT NULL DEFAULT '',
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ga_client_id varchar(64) NOT NULL DEFAULT '',
  event_name varchar(64) NOT NULL DEFAULT '',
  status varchar(32) NOT NULL DEFAULT '',
  payment_status varchar(32) NOT NULL DEFAULT '',
  order_total decimal(15,4) NOT NULL DEFAULT 0,
  currency char(8) NOT NULL DEFAULT '',
  event_sent tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY booking_id (booking_id),
  KEY ga_client_id (ga_client_id),
  KEY order_id (order_id)
) {$collate};" );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
    }

    /**
     * Write (or refresh) the row for a booking.
     *
     * Everything money- or booking-shaped comes from the payload the server
     * just read out of Bookly; only the GA4 client id, the flow id and which of
     * the two events was pushed come from the browser, because only the browser
     * can know them.
     *
     * @param array $payload STMS_Bookly_Data::order_payload()
     * @param array $context flow_id / ga_client_id / event_name from the browser
     * @return bool
     */
    public static function record( $payload, $context )
    {
        global $wpdb;

        $booking_id = isset( $payload['booking_id'] ) ? (int) $payload['booking_id'] : 0;

        if ( ! $booking_id ) {
            return false;
        }

        $event_name = isset( $context['event_name'] ) ? (string) $context['event_name'] : '';
        if ( ! in_array( $event_name, self::$events, true ) ) {
            $event_name = ! empty( $payload['confirmed'] )
                ? 'bookly_booking_completed'
                : 'bookly_booking_pending';
        }

        $order_id = isset( $payload['order_id'] ) ? (int) $payload['order_id'] : 0;
        $now = current_time( 'mysql' );

        $row = array(
            'booking_id' => $booking_id,
            'order_id' => $order_id,
            'order_key' => STMS_Bookly_Data::order_key( $order_id ),
            'flow_id' => self::flow_id( isset( $context['flow_id'] ) ? $context['flow_id'] : '' ),
            'customer_id' => isset( $payload['customer_id'] ) ? (int) $payload['customer_id'] : 0,
            'ga_client_id' => self::ga_client_id( isset( $context['ga_client_id'] ) ? $context['ga_client_id'] : '' ),
            'event_name' => $event_name,
            'status' => isset( $payload['status'] ) ? (string) $payload['status'] : '',
            'payment_status' => isset( $payload['payment_status'] ) ? (string) $payload['payment_status'] : '',
            'order_total' => isset( $payload['order_total'] ) ? (float) $payload['order_total'] : 0,
            'currency' => isset( $payload['currency'] ) ? (string) $payload['currency'] : '',
            // The caller pushes to the dataLayer first and records afterwards,
            // so by the time this runs the event has been sent.
            'event_sent' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        );

        $table = self::table();

        // One row per booking. A reload, a retry, or a pending booking that
        // later completes updates the row it already has; the GA4 client id is
        // only overwritten when the browser actually supplied one, so a later
        // write from a context that could not read it cannot erase it.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (booking_id, order_id, order_key, flow_id, customer_id, ga_client_id, event_name, status, payment_status, order_total, currency, event_sent, created_at, updated_at)
             VALUES (%d, %d, %s, %s, %d, %s, %s, %s, %s, %f, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                order_id = VALUES(order_id),
                order_key = VALUES(order_key),
                flow_id = VALUES(flow_id),
                customer_id = VALUES(customer_id),
                ga_client_id = IF(VALUES(ga_client_id) = '', ga_client_id, VALUES(ga_client_id)),
                event_name = VALUES(event_name),
                status = VALUES(status),
                payment_status = VALUES(payment_status),
                order_total = VALUES(order_total),
                currency = VALUES(currency),
                event_sent = VALUES(event_sent),
                updated_at = VALUES(updated_at)",
            $row['booking_id'],
            $row['order_id'],
            $row['order_key'],
            $row['flow_id'],
            $row['customer_id'],
            $row['ga_client_id'],
            $row['event_name'],
            $row['status'],
            $row['payment_status'],
            $row['order_total'],
            $row['currency'],
            $row['event_sent'],
            $row['created_at'],
            $row['updated_at']
        );

        return $wpdb->query( $sql ) !== false;
    }

    /**
     * A GA4 client id is two numbers joined by a dot ("1234567890.1234567890").
     * Anything else is something the browser made up, and is dropped.
     *
     * @param string $value
     * @return string
     */
    private static function ga_client_id( $value )
    {
        $value = trim( (string) $value );

        return preg_match( '/^\d{1,20}\.\d{1,20}$/', $value ) ? $value : '';
    }

    /**
     * @param string $value
     * @return string
     */
    private static function flow_id( $value )
    {
        $value = trim( (string) $value );

        return preg_match( '/^[a-z0-9]{32}$/', $value ) ? $value : '';
    }
}
