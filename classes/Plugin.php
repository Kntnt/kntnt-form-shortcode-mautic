<?php

namespace Kntnt\Form_Shortcode_Mautic;

// Uncomment following line to debug this plugin.
define( 'KNTNT_FORM_SHORTCODE_MAUTIC_DEBUG', true );

final class Plugin extends Abstract_Plugin {

    public static function peel_off( $key, &$array ) {
        if ( array_key_exists( $key, $array ) ) {
            $val = $array[ $key ];
            unset( $array[ $key ] );
        }
        else {
            $val = null;
        }

        return $val;
    }

    public function classes_to_load() {
        return [
            'public' => [
                'plugins_loaded' => [
                    'Extender',
                    'Handler',
                ],
            ],
            'admin' => [
                'init' => [
                    'Settings',
                ],
            ],
        ];
    }

    protected static function dependencies() { return [ 'kntnt-form-shortcode/kntnt-form-shortcode.php' => 'Kntnt Form Shortcode' ]; }

}
