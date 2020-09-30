<?php

namespace Kntnt\Form_Shortcode_Mautic;

class Settings extends Abstract_Settings {

    /**
     * Returns the settings menu title.
     */
    protected function menu_title() {
        return __( 'KFS Mautic', 'kntnt-form-shortcode-mautic' );
    }

    /**
     * Returns the settings page title.
     */
    protected function page_title() {
        return __( 'Mautic add-on for Kntnt Form Shortcode', 'kntnt-form-shortcode-mautic' );
    }

    /**
     * Returns all fields used on the settings page.
     */
    protected function fields() {

        $fields['url'] = [
            'type' => 'url',
            'label' => __( "Mautic URL", 'kntnt-form-shortcode-mautic' ),
            'description' => __( 'URL to Mautic, e.g. </code>https://mautic.example.com/</code>.', 'kntnt-form-shortcode-mautic' ),
        ];

        $fields['username'] = [
            'type' => 'text',
            'label' => __( "Username", 'kntnt-form-shortcode-mautic' ),
            'description' => __( 'Login for the Mautic account this plugin will use to update fields.', 'kntnt-form-shortcode-mautic' ),
        ];

        $fields['password'] = [
            'type' => 'password',
            'label' => __( "Password", 'kntnt-form-shortcode-mautic' ),
            'description' => __( 'Password for the Mautic account this plugin will use to update fields.', 'kntnt-form-shortcode-mautic' ),
        ];

        $fields['email_collision_handling'] = [
            'type' => 'select',
            'label' => __( "Email collision", 'kntnt-cta' ),
            'description' => __( 'Select what to do if the form contains a field mapped to the email field of Mautic and the values of these two are not identical for the current user.', 'kntnt-form-shortcode-mautic' ),
            'options' => [
                'update' => 'Update',
                'save' => 'Save',
                'save-bind' => 'Save and bind',
                'switch' => 'Switch and save.',
                'join' => 'Switch, save and bind.',
            ],
            'default' => 'update',
        ];

        $fields['additional_emails_field'] = [
            'type' => 'text',
            'label' => __( "Additional emails field", 'kntnt-cta' ),
            'description' => __( 'Mautic alias for a text area custom filed to hold additional email addresses. Leave empty to disable this feature.', 'kntnt-form-shortcode-mautic' ),
        ];

        $fields['cookie'] = [
            'type' => 'text',
            'label' => __( "ID cookie", 'kntnt-cta' ),
            'description' => __( 'The name of Mautic\'s cookie with contact id.', 'kntnt-form-shortcode-mautic' ),
            'default' => 'mtc_id',
        ];

        $fields['submit'] = [
            'type' => 'submit',
        ];

        return $fields;

    }

}
