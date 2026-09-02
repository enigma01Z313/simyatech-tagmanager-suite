<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reads booking data out of Bookly through Bookly's own public API.
 *
 * Nothing here writes to Bookly, and every entry point degrades to null when
 * Bookly is missing or its API changed, so the JS side can fall back.
 */
class STMS_Bookly_Data
{
    /**
     * @return bool
     */
    public static function is_available()
    {
        return class_exists( '\Bookly\Lib\UserBookingData' )
            && class_exists( '\Bookly\Lib\Entities\Order' );
    }

    /**
     * Bookly customer of the logged-in visitor, if that visitor has one.
     *
     * @return int 0 when nobody is logged in or no customer is linked
     */
    public static function logged_in_customer_id()
    {
        if ( ! is_user_logged_in() || ! class_exists( '\Bookly\Lib\Entities\Customer' ) ) {
            return 0;
        }

        try {
            return (int) \Bookly\Lib\Entities\Customer::query()
                ->where( 'wp_user_id', get_current_user_id() )
                ->fetchVar( 'id' );
        } catch ( \Exception $e ) {
            return 0;
        }
    }

    /**
     * Bookly customer behind a booking in progress.
     *
     * A logged-in visitor is known straight away. A guest is only identifiable
     * once the details step has put an email / phone into the session, and
     * only when that matches a customer Bookly already stores - Bookly itself
     * creates the record at save time, so before that there is nothing to
     * report.
     *
     * @param string $form_id Bookly form token
     * @return int 0 when the visitor cannot be identified yet
     */
    public static function customer_id( $form_id )
    {
        $customer_id = self::logged_in_customer_id();

        if ( $customer_id || ! self::is_available() || $form_id === '' ) {
            return $customer_id;
        }

        try {
            $user_data = new \Bookly\Lib\UserBookingData( $form_id );
            if ( ! $user_data->load() ) {
                return 0;
            }

            // getCustomer() only looks the customer up; it never writes.
            $customer = $user_data->getCustomer();

            return ( $customer && $customer->isLoaded() ) ? (int) $customer->getId() : 0;
        } catch ( \Exception $e ) {
            return 0;
        }
    }

    /**
     * Snapshot of the cart for the booking currently in progress. Used for the
     * payment-started event, where nothing is written to the database yet.
     *
     * @param string $form_id Bookly form token
     * @return array|null
     */
    public static function flow_state( $form_id )
    {
        if ( ! self::is_available() || $form_id === '' ) {
            return null;
        }

        try {
            $user_data = new \Bookly\Lib\UserBookingData( $form_id );
            if ( ! $user_data->load() ) {
                return null;
            }

            $items = $user_data->cart->getItems();
            $cart_info = $user_data->cart->getInfo();

            $services = array();
            $slots = array();
            $therapist = '';

            foreach ( $items as $item ) {
                $service = $item->getService();
                if ( $service ) {
                    $title = method_exists( $service, 'getTranslatedTitle' )
                        ? $service->getTranslatedTitle()
                        : $service->getTitle();
                    if ( $title !== '' && ! in_array( $title, $services, true ) ) {
                        $services[] = $title;
                    }
                }
                foreach ( (array) $item->getSlots() as $slot ) {
                    if ( isset( $slot[2] ) && $slot[2] ) {
                        $slots[] = self::iso_datetime( $slot[2] );
                    }
                }
                if ( $therapist === '' ) {
                    $staff = $item->getStaff();
                    if ( $staff ) {
                        $therapist = method_exists( $staff, 'getTranslatedName' )
                            ? $staff->getTranslatedName()
                            : $staff->getFullName();
                    }
                }
            }

            $state = array(
                'sessions' => count( $items ),
                'total' => round( (float) $cart_info->getTotal(), 2 ),
                'currency' => self::currency(),
                'coupon' => self::flow_coupon( $user_data, $cart_info ),
                'services' => $services,
                'therapist' => (string) $therapist,
                'slots' => $slots,
            );

            return apply_filters( 'stms_flow_state', $state, $form_id );
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * Everything the booking-completed event needs, read back from the saved
     * order.
     *
     * @param string $form_id Bookly form token (session owner), may be empty
     * @param string $order_token Token returned by Bookly's complete step
     * @return array|null
     */
    public static function order_payload( $form_id, $order_token )
    {
        if ( ! self::is_available() ) {
            return null;
        }

        try {
            $order_id = self::resolve_order_id( $form_id, $order_token );
            if ( ! $order_id ) {
                return null;
            }

            $rows = \Bookly\Lib\Entities\CustomerAppointment::query( 'ca' )
                ->select( 'ca.id AS ca_id, ca.status, ca.created_at, ca.payment_id, ca.customer_id, ca.compound_token, ca.collaborative_token, a.start_date, s.title AS service_title, a.custom_service_name, st.full_name AS staff_name, c.email AS customer_email' )
                ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
                ->leftJoin( 'Service', 's', 's.id = COALESCE(ca.compound_service_id, ca.collaborative_service_id, a.service_id)' )
                ->leftJoin( 'Staff', 'st', 'st.id = a.staff_id' )
                ->leftJoin( 'Customer', 'c', 'c.id = ca.customer_id' )
                ->where( 'ca.order_id', $order_id )
                ->sortBy( 'a.start_date' )
                ->fetchArray();

            if ( ! $rows ) {
                return null;
            }

            // Compound / collaborative services are stored as several rows that
            // together are one booked session - collapse them like Bookly does.
            $sessions = array();
            foreach ( $rows as $row ) {
                if ( ! empty( $row['compound_token'] ) ) {
                    $key = 'compound-' . $row['compound_token'];
                } elseif ( ! empty( $row['collaborative_token'] ) ) {
                    $key = 'collaborative-' . $row['collaborative_token'];
                } else {
                    $key = 'ca-' . $row['ca_id'];
                }
                if ( ! isset( $sessions[ $key ] ) ) {
                    $sessions[ $key ] = $row;
                }
            }
            $sessions = array_values( $sessions );
            $first = $sessions[0];

            $services = array();
            $therapists = array();
            $slot_starts = array();
            foreach ( $sessions as $session ) {
                $title = ( $session['service_title'] !== null && $session['service_title'] !== '' )
                    ? $session['service_title']
                    : (string) $session['custom_service_name'];
                if ( $title !== '' && ! in_array( $title, $services, true ) ) {
                    $services[] = $title;
                }
                if ( $session['staff_name'] && ! in_array( $session['staff_name'], $therapists, true ) ) {
                    $therapists[] = $session['staff_name'];
                }
                if ( $session['start_date'] ) {
                    $slot_starts[] = self::iso_datetime( $session['start_date'] );
                }
            }

            $payment = self::find_payment( $order_id, $first['payment_id'] );
            $order_total = $payment ? round( (float) $payment['total'], 2 ) : 0.0;
            $session_count = count( $sessions );

            $payload = array(
                'booking_id' => (int) $first['ca_id'],
                'client_id' => $first['customer_id'] ? (int) $first['customer_id'] : '',
                'status' => (string) $first['status'],
                'payment_status' => $payment ? (string) $payment['status'] : '',
                'order_id' => trim( (string) $first['created_at'] ) . '|' . (string) $first['customer_email'],
                'sessions_in_order' => $session_count,
                'order_total' => $order_total,
                'session_value' => self::session_value( $payment, $order_total, $session_count ),
                'currency' => self::currency(),
                'service' => implode( ', ', $services ),
                'therapist' => implode( ', ', $therapists ),
                'slot_start' => implode( ', ', $slot_starts ),
                'payment_method' => $payment ? self::normalize_gateway( $payment['type'] ) : '',
                'coupon' => $payment ? self::payment_coupon( $payment ) : '',
            );

            return apply_filters( 'stms_order_payload', $payload, $order_id, $sessions );
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * The session's own order wins; the order token is the fallback for when the
     * Bookly session is already gone (gateway redirect, reload).
     *
     * @param string $form_id
     * @param string $order_token
     * @return int
     */
    private static function resolve_order_id( $form_id, $order_token )
    {
        $order_id = 0;

        if ( $form_id !== '' ) {
            $user_data = new \Bookly\Lib\UserBookingData( $form_id );
            if ( $user_data->load() ) {
                $order_id = (int) $user_data->getOrderId();
            }
        }

        if ( ! $order_id && $order_token !== '' ) {
            $order_id = (int) \Bookly\Lib\Entities\Order::query()
                ->where( 'token', $order_token )
                ->fetchVar( 'id' );
        }

        return $order_id;
    }

    /**
     * @param int $order_id
     * @param int|null $payment_id
     * @return array|null Payment fields as a plain array
     */
    private static function find_payment( $order_id, $payment_id )
    {
        if ( $payment_id ) {
            $payment = \Bookly\Lib\Entities\Payment::find( $payment_id );
            if ( $payment ) {
                return $payment->getFields();
            }
        }

        // fetchRow() already returns a plain array of columns.
        $row = \Bookly\Lib\Entities\Payment::query()
            ->where( 'order_id', $order_id )
            ->fetchRow();

        return $row ? $row : null;
    }

    /**
     * Price of a single session: the per-item price Bookly stored with the
     * payment, falling back to an even split of the order total.
     *
     * @param array|null $payment
     * @param float $order_total
     * @param int $session_count
     * @return float
     */
    private static function session_value( $payment, $order_total, $session_count )
    {
        if ( $payment && ! empty( $payment['details'] ) ) {
            $details = json_decode( $payment['details'], true );
            if ( isset( $details['items'][0]['service_price'] ) ) {
                return round( (float) $details['items'][0]['service_price'], 2 );
            }
        }

        return $session_count > 0 ? round( $order_total / $session_count, 2 ) : 0.0;
    }

    /**
     * @param array $payment
     * @return string
     */
    private static function payment_coupon( $payment )
    {
        if ( ! empty( $payment['details'] ) ) {
            $details = json_decode( $payment['details'], true );
            if ( ! empty( $details['coupon']['code'] ) ) {
                return (string) $details['coupon']['code'];
            }
        }

        if ( ! empty( $payment['coupon_id'] ) && class_exists( '\BooklyCoupons\Lib\Entities\Coupon' ) ) {
            $code = \BooklyCoupons\Lib\Entities\Coupon::query()
                ->where( 'id', $payment['coupon_id'] )
                ->fetchVar( 'code' );
            if ( $code ) {
                return (string) $code;
            }
        }

        return '';
    }

    /**
     * @param \Bookly\Lib\UserBookingData $user_data
     * @param \Bookly\Lib\CartInfo $cart_info
     * @return string
     */
    private static function flow_coupon( $user_data, $cart_info )
    {
        $coupon = $cart_info->getCoupon();
        if ( $coupon && method_exists( $coupon, 'getCode' ) ) {
            return (string) $coupon->getCode();
        }

        return (string) $user_data->getCouponCode();
    }

    /**
     * Bookly gateway slug -> the value the dataLayer expects.
     *
     * @param string $type
     * @return string
     */
    public static function normalize_gateway( $type )
    {
        $map = apply_filters( 'stms_gateway_map', array(
            'card' => 'stripe',
            'stripe' => 'stripe',
            'cloud_stripe' => 'stripe',
            'paypal' => 'paypal',
            'paypal_checkout' => 'paypal',
        ) );

        $type = (string) $type;

        return isset( $map[ $type ] ) ? $map[ $type ] : $type;
    }

    /**
     * @return string ISO 4217 code, e.g. USD
     */
    public static function currency()
    {
        if ( class_exists( '\Bookly\Lib\Config' ) ) {
            return (string) \Bookly\Lib\Config::getCurrency();
        }

        return (string) get_option( 'bookly_pmt_currency', '' );
    }

    /**
     * "2021-09-07 21:00:00" -> "2021-09-07T21:00:00"
     *
     * @param string $date
     * @return string
     */
    private static function iso_datetime( $date )
    {
        return str_replace( ' ', 'T', trim( (string) $date ) );
    }
}
