<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class autoptimizeAttributeParser
{
    public static function use_modern_processor(): bool {
        return false; //class_exists( 'WP_HTML_Tag_Processor' );
    }

    /**
     * Entry point for modifying HTML attributes.
     * Selects the most secure/efficient method available.
     */
    public static function modify( $tag, $swaps = [], $replacements = [], $force_touch = false): string
    {
        if ( self::use_modern_processor() ) {
            return self::modify_modern( $tag, $swaps, $replacements, $force_touch);
        }

        return self::modify_legacy( $tag, $swaps, $replacements );
    }

    /**
     * Modern implementation using the WP_HTML_Tag_Processor (WP 6.2+)
     */
    private static function modify_modern( $tag, $swaps, $replacements, $force_touch = false): string
    {
        $processor = new WP_HTML_Tag_Processor( $tag );

        if ( $processor->next_tag() ) {
            // 1. Handle Deletions (null values)
            foreach ( $replacements as $name => $value ) {
                if ( is_null( $value ) ) {
                    $processor->remove_attribute( $name );
                }
            }

            // If force_touch is enabled, re-set every attribute to force-add quotes
            if ( $force_touch ) {
                $attribute_names = $processor->get_attribute_names_with_prefix( '' );
                if ( $attribute_names ) {
                    foreach ( $attribute_names as $name ) {
                        $processor->set_attribute( $name, $processor->get_attribute( $name ) );
                    }
                }
            }

            // 2. Handle Swaps
            foreach ( $swaps as $old => $new ) {
                $current_value = $processor->get_attribute( $old );
                if ( ! is_null( $current_value ) ) {
                    $processor->set_attribute( $new, $current_value );
                    $processor->remove_attribute( $old );
                }
            }

            // 3. Handle Replacements/Additions
            foreach ( $replacements as $name => $value ) {
                if ( ! is_null( $value ) ) {
                    $processor->set_attribute( $name, $value );
                }
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * Legacy implementation using wp_kses_hair (WP 1.0+)
     */
    private static function modify_legacy( $tag, $swaps, $replacements ): string
    {
        $attr_string = preg_replace( '/^<[a-z0-9]+\s+|(\s?\/?>)$/i', '', $tag );
        
        $protocols = wp_allowed_protocols();
        $protocols[] = 'data'; // add data to ensure we don't break other lazyload solutions that might roam around.

        $attrs = wp_kses_hair( $attr_string, $protocols );

        // 1. Handle Deletions first to clear the deck
        foreach ( $replacements as $attr_name => $new_value ) {
            if ( is_null( $new_value ) ) {
                unset( $attrs[$attr_name] );
            }
        }

        // 2. Handle Swaps
        foreach ( $swaps as $old => $new ) {
            if ( isset( $attrs[$old] ) ) {
                $attrs[$new] = $attrs[$old];
                $attrs[$new]['name'] = $new;
                unset( $attrs[$old] );
            }
        }

        // 3. Handle Replacements & Additions
        foreach ( $replacements as $attr_name => $new_value ) {
            if ( ! is_null( $new_value ) ) {
                $attrs[$attr_name] = [
                    'name'  => $attr_name,
                    'value' => $new_value,
                ];
            }
        }

        // Rebuild
        preg_match( '/^<([a-z0-9]+)/i', $tag, $tag_name_match );
        $tag_name = $tag_name_match[1] ?? 'img';

        $rebuilt_tag = '<' . $tag_name;
        foreach ( $attrs as $name => $data ) {
            $value = esc_attr( $data['value'] );
            $rebuilt_tag .= " {$name}=\"{$value}\"";
        }

        $rebuilt_tag .= ( strpos( $tag, '/>' ) !== false ) ? ' />' : '>';

        return $rebuilt_tag;
    }

    /**
     * Entry point for retrieving attributes.
     * Returns a single value (string|null) if a string name is provided,
     * or an associative array if a regex is provided.
     */
    public static function get( $tag, $query ): string|array|null
    {
        if ( self::use_modern_processor() ) {
            return self::get_modern( $tag, $query );
        }

        return self::get_legacy( $tag, $query );
    }

    /**
     * Modern retrieval using Tag Processor
     */
    private static function get_modern( $tag, $query ): string|array|null
    {
        $processor = new WP_HTML_Tag_Processor( $tag );
        if ( ! $processor->next_tag() ) {
            return is_string( $query ) ? null : [];
        }

        // Single attribute request
        if ( is_string( $query ) && strpos( $query, '/' ) !== 0 ) {
            return $processor->get_attribute( $query );
        }

        // Regex / List request
        $results = [];
        $attribute_names = $processor->get_attribute_names_with_prefix( '' ); // Get all
        foreach ( $attribute_names as $name ) {
            if ( preg_match( $query, $name ) ) {
                $results[$name] = $processor->get_attribute( $name );
            }
        }
        return $results;
    }

    /**
     * Legacy retrieval using wp_kses_hair
     */
    private static function get_legacy( $tag, $query ): string|array|null
    {
        $attr_string = preg_replace( '/^<[a-z0-9]+\s+|(\s?\/?>)$/i', '', $tag );

        $protocols = wp_allowed_protocols();
        $protocols[] = 'data'; // add data to ensure we don't break other lazyload solutions that might roam around.

        $attrs = wp_kses_hair( $attr_string, $protocols );

        // Single attribute request
        if ( is_string( $query ) && strpos( $query, '/' ) !== 0 ) {
            return $attrs[$query]['value'] ?? null;
        }

        // Regex request
        $results = [];
        foreach ( $attrs as $name => $data ) {
            if ( preg_match( $query, $name ) ) {
                $results[$name] = $data['value'];
            }
        }
        return $results;
    }

    /**
     * Helper to rename attributes.
     * Supports:
     * - rename( $tag, 'src', 'data-src' )
     * - rename( $tag, ['src' => 'data-src', 'srcset' => 'data-srcset'] )
     */
    public static function rename( $tag, $old_name, $new_name = null ): string {
        if ( is_array( $old_name ) ) {
            $swaps = $old_name;
        } else {
            $swaps = [ $old_name => $new_name ];
        }

        return self::modify( $tag, $swaps );
    }

    /**
     * Helper to remove one or more attributes.
     * Supports:
     * - remove( $tag, 'style' )
     * - remove( $tag, ['style', 'onclick', 'onerror'] )
     */
    public static function remove( $tag, $attributes ): string {
        // array_fill_keys expects an array; (array) cast handles single strings safely.
        $replacements = array_fill_keys( (array) $attributes, null );

        return self::modify( $tag, [], $replacements );
    }

    /**
     * Helper to replace or add attribute values.
     * Supports:
     * - replace( $tag, 'class', 'lazyload' )
     * - replace( $tag, ['class' => 'lazyload', 'src' => 'placeholder.png'] )
     */
    public static function replace( $tag, $attribute, $value = null ): string {
        if ( is_array( $attribute ) ) {
            $replacements = $attribute;
        } else {
            $replacements = [ $attribute => $value ];
        }

        return self::modify( $tag, [], $replacements );
    }

    /**
     * Helper to rebuild a HTML tag from a string
     * Can be used to fix minified HTML that is missing quotes around attributes
     * Supports:
     * - replace( $tag )
     */
    public static function rebuild( $tag ): string
    {
        return self::modify( $tag, [], [], true );
    }

    /**
     * Retrieves a single attribute value.
     * @param string $tag The HTML tag string.
     * @param string $name The attribute name to find.
     * @return string|null The attribute value or null if not found.
     */
    public static function get_attribute( string $tag, string $name ): ?string
    {
        $result = self::get( $tag, $name );
        return is_string( $result ) ? $result : null;
    }

    /**
     * Retrieves a list of attributes matching a regex or prefix.
     * @param string $tag The HTML tag string.
     * @param string $regex The regex pattern to match keys against (e.g., '/^data-/').
     * @return array Associative array of [name => value].
     */
    public static function get_attributes( string $tag, string $regex ): array
    {
        $result = self::get( $tag, $regex );
        return is_array( $result ) ? $result : [];
    }

    /**
     * Modifies a single specific attribute via callback.
     * If callback returns null, the attribute is removed.
     */
    public static function modify_attribute( string $tag, string $name, callable $callback ): string
    {
        $value = self::get_attribute( $tag, $name );
        $new_value = $callback( $name, $value );

        if ( $new_value === $value ) {
            return $tag;
        }

        return self::modify( $tag, [], [ $name => $new_value ] );
    }

    /**
     * Modifies multiple attributes matching a regex via callback.
     */
    public static function modify_attributes( string $tag, string $regex, callable $callback ): string
    {
        $attrs = self::get_attributes( $tag, $regex );

        $replacements = [];

        foreach ( $attrs as $name => $value ) {
            $new_value = $callback( $name, $value );

            if ( $new_value !== $value ) {
                $replacements[ $name ] = $new_value;
            }
        }

        return empty( $replacements ) ? $tag : self::modify( $tag, [], $replacements );
    }
}
