<?php


namespace Kntnt\Form_Shortcode_Mautic;


final class Extender {

    private $mautic_segments = [];

    private $mautic_fields = [];

    public function run() {

        // Extend form
        add_filter( 'kntnt-form-shortcode-form-defaults', [ $this, 'add_mautic_segment_default' ] );
        add_filter( 'kntnt-form-shortcode-attributes', [ $this, 'peel_off_mautic_segment' ], 10, 2 );
        add_filter( 'kntnt-form-shortcode-content-after', [ $this, 'add_mautic_data_as_hidden_fields' ], 10, 2 );

        // Extend fields
        add_filter( 'kntnt-form-shortcode-field-defaults-map', [ $this, 'add_mautic_field_default' ] );
        add_filter( 'kntnt-form-shortcode-field-attributes', [ $this, 'peel_off_mautic_field_and_set_data_attribute' ], 10, 4 );

    }

    public function add_mautic_segment_default( $defaults ) {
        $defaults['mautic-segment'] = null;
        return $defaults;
    }

    public function peel_off_mautic_segment( $atts, $form_id ) {
        if ( $mautic_segment = Plugin::peel_off( 'mautic-segment', $atts ) ) {
            $this->mautic_segments[ $form_id ] = $mautic_segment;
            Plugin::log( 'mautic-segment[%s] = %s', $form_id, $mautic_segment );
        }
        return $atts;
    }

    public function add_mautic_data_as_hidden_fields( $content, $form_id ) {

        if ( isset( $this->mautic_segments[ $form_id ] ) ) {
            $field = strtr( '<input type="hidden" id="{form-id}-mautic-segment" name="{form-id}[mautic-segment]" value="{value}">', [
                '{form-id}' => $form_id,
                '{value}' => $this->mautic_segments[ $form_id ],
            ] );
            $content .= $field;
            Plugin::log( $field );
        }

        if ( isset( $this->mautic_fields[ $form_id ] ) ) {
            foreach ( $this->mautic_fields[ $form_id ] as $field_id => $mautic_field ) {
                $field = strtr( '<input type="hidden" id="{form-id}-mautic-fields-{field-id}" name="{form-id}[mautic-fields][{field-id}]" value="{value}">', [
                    '{form-id}' => $form_id,
                    '{field-id}' => $field_id,
                    '{value}' => $mautic_field,
                ] );
                $content .= $field;
                Plugin::log( $field );
            }
        }

        return $content;

    }

    public function add_mautic_field_default( $defaults ) {
        $defaults['mautic-field'] = [ 'text' => null, 'textarea' => null, 'email' => null, 'url' => null, 'tel' => null, 'hidden' => null ];
        return $defaults;
    }

    public function peel_off_mautic_field_and_set_data_attribute( $atts, $type, $field_id, $form_id ) {
        if ( $mautic_field = Plugin::peel_off( 'mautic-field', $atts ) ) {
            $this->mautic_fields[ $form_id ][ $field_id ] = $mautic_field;
            Plugin::log( 'mautic_fields[%s][%s] = %s', $form_id, $field_id, $mautic_field );
        }
        return $atts;
    }

}