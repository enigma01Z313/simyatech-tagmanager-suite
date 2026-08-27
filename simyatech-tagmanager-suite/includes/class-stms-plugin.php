<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin's pieces.
 */
class STMS_Plugin
{
    /** @var STMS_Plugin */
    private static $instance;

    /** @var bool */
    private $booted = false;

    /**
     * @return STMS_Plugin
     */
    public static function instance()
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init()
    {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        STMS_Page_Meta::init();
        STMS_Assets::init();
        STMS_Ajax::init();
    }

    /**
     * Debug mode: logs every push to the browser console.
     *
     * @return bool
     */
    public static function debug()
    {
        $debug = isset( $_GET['stms_debug'] ) && $_GET['stms_debug'] !== '0';

        return (bool) apply_filters( 'stms_debug', $debug );
    }
}
