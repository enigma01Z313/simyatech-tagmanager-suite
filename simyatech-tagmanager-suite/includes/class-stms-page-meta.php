<?php
defined( 'ABSPATH' ) || exit;

/**
 * Prints the hidden per-page markers that GTM (and this plugin's JS) read:
 * the page slug, the English "base" slug and the language code.
 */
class STMS_Page_Meta
{
    const CONTAINER_ID = 'stms-page-meta';

    public static function init()
    {
        // wp_body_open keeps the markers available as early as possible;
        // wp_footer is the fallback for themes that don't support it.
        add_action( 'wp_body_open', array( __CLASS__, 'render' ), 1 );
        add_action( 'wp_footer', array( __CLASS__, 'render' ), 1 );
    }

    /**
     * Values shared by the hidden markers and the JS config.
     *
     * @return array
     */
    public static function values()
    {
        static $values;

        if ( $values === null ) {
            $values = array(
                'page_slug' => STMS_Language::page_slug(),
                'base_slug' => STMS_Language::base_slug(),
                'language' => STMS_Language::current_language(),
            );
        }

        return $values;
    }

    public static function render()
    {
        static $rendered = false;

        if ( $rendered || is_admin() ) {
            return;
        }
        $rendered = true;

        $values = self::values();
        ?>
        <div id="<?php echo esc_attr( self::CONTAINER_ID ) ?>" class="stms-page-meta" style="display:none" aria-hidden="true">
            <input type="hidden" class="stms-page-slug" id="stms-page-slug" name="stms_page_slug" value="<?php echo esc_attr( $values['page_slug'] ) ?>"/>
            <input type="hidden" class="stms-base-slug" id="stms-base-slug" name="stms_base_slug" value="<?php echo esc_attr( $values['base_slug'] ) ?>"/>
            <input type="hidden" class="stms-page-language" id="stms-page-language" name="stms_page_language" value="<?php echo esc_attr( $values['language'] ) ?>"/>
        </div>
        <?php
    }
}
