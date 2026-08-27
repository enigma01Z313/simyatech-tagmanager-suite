<?php
defined( 'ABSPATH' ) || exit;

/**
 * Slug + language resolution, with WPML and Polylang support.
 *
 * "base slug" is the slug of the English version of the current page:
 *  - on an English page it is the page's own slug,
 *  - on a translated page it is the slug of its English translation,
 *  - when the page has no English version it is an empty string.
 */
class STMS_Language
{
    /**
     * Language code of the page currently being rendered (e.g. "en", "de").
     *
     * @return string
     */
    public static function current_language()
    {
        $lang = '';

        if ( has_filter( 'wpml_current_language' ) ) {
            $lang = (string) apply_filters( 'wpml_current_language', null );
        } elseif ( function_exists( 'pll_current_language' ) ) {
            $lang = (string) pll_current_language( 'slug' );
        }

        if ( $lang === '' ) {
            $lang = substr( (string) get_locale(), 0, 2 );
        }

        return (string) apply_filters( 'stms_current_language', $lang );
    }

    /**
     * The language code that stands for English on this site.
     *
     * @return string
     */
    public static function english_code()
    {
        return (string) apply_filters( 'stms_english_language_code', 'en' );
    }

    /**
     * Slug of the page currently being rendered.
     *
     * @return string
     */
    public static function page_slug()
    {
        $slug = '';
        $object = get_queried_object();

        if ( is_singular() && $object instanceof WP_Post ) {
            $slug = $object->post_name;
        } elseif ( $object instanceof WP_Term ) {
            $slug = $object->slug;
        } elseif ( $object instanceof WP_Post_Type ) {
            $slug = $object->name;
        } elseif ( is_home() ) {
            $blog_page = (int) get_option( 'page_for_posts' );
            $slug = $blog_page ? (string) get_post_field( 'post_name', $blog_page ) : '';
        }

        if ( $slug === '' ) {
            $slug = self::slug_from_url();
        }

        return (string) apply_filters( 'stms_page_slug', $slug );
    }

    /**
     * Slug of the English version of the current page, or '' when there is none.
     *
     * @return string
     */
    public static function base_slug()
    {
        $english = self::english_code();
        $slug = '';

        if ( self::current_language() === $english ) {
            // Already the English page — its own slug is the base slug.
            $slug = self::page_slug();
        } else {
            $object = get_queried_object();

            if ( is_singular() && $object instanceof WP_Post ) {
                $translation_id = self::translated_post_id( $object->ID, $object->post_type, $english );
                if ( $translation_id ) {
                    $slug = (string) get_post_field( 'post_name', $translation_id );
                }
            } elseif ( $object instanceof WP_Term ) {
                $translation_id = self::translated_term_id( $object->term_id, $object->taxonomy, $english );
                if ( $translation_id ) {
                    $term = get_term( $translation_id, $object->taxonomy );
                    if ( $term instanceof WP_Term ) {
                        $slug = $term->slug;
                    }
                }
            }
        }

        return (string) apply_filters( 'stms_base_slug', $slug );
    }

    /**
     * @param int $post_id
     * @param string $post_type
     * @param string $language
     * @return int 0 when there is no translation
     */
    private static function translated_post_id( $post_id, $post_type, $language )
    {
        if ( has_filter( 'wpml_object_id' ) ) {
            // false => do not fall back to the original language.
            return (int) apply_filters( 'wpml_object_id', $post_id, $post_type, false, $language );
        }

        if ( function_exists( 'pll_get_post' ) ) {
            return (int) pll_get_post( $post_id, $language );
        }

        return 0;
    }

    /**
     * @param int $term_id
     * @param string $taxonomy
     * @param string $language
     * @return int 0 when there is no translation
     */
    private static function translated_term_id( $term_id, $taxonomy, $language )
    {
        if ( has_filter( 'wpml_object_id' ) ) {
            return (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $language );
        }

        if ( function_exists( 'pll_get_term' ) ) {
            return (int) pll_get_term( $term_id, $language );
        }

        return 0;
    }

    /**
     * Last path segment of the current URL — used when the request has no
     * queried object of its own (search pages, custom endpoints, …).
     *
     * @return string
     */
    private static function slug_from_url()
    {
        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
        $segments = array_values( array_filter( explode( '/', (string) $path ), 'strlen' ) );

        return $segments ? sanitize_title( end( $segments ) ) : '';
    }
}
