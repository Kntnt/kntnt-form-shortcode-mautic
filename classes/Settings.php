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
            'label' => __( "Email collision handling", 'kntnt-cta' ),
            'options' => [
                'save' => 'Save',
                'save-bind' => 'Save and cross-reference',
                'update' => 'Update',
                'switch' => 'Switch',
                'switch-bind' => 'Switch and cross-reference',
            ],
            'default' => 'update',
            'description' => __( 'Select what to do if the form contains a <em>new email</em> that differs from the <em>old email</em> of the tracked visitor. Select <strong>Save</strong> to save the new email in the <em>additional emails field</em> (see below) of the old email contact. Select <strong>Save and cross-reference</strong> to save the new email in the additional emails field of the old email contact, and to save the old email in the additional emails field of the new email contact (provided it exists). Select <strong>Update</strong> to let new email replace old email which is saved the additional emails field. This should cause Mautic to merge the tow contacts. Select <strong>Switch</strong> to save the old email in the additional emails field of the new email contact, which is created if necessary. Finally, select <strong>Switch and cross-reference</strong> to save the old email in the additional emails field of the new email contact, created if necessary, and to save the new email in the additional emails field of the old email contact.', 'kntnt-form-shortcode-mautic' ),
        ];

        $fields['additional_emails_field'] = [
            'type' => 'text',
            'label' => __( "Additional emails field", 'kntnt-cta' ),
            'description' => __( 'Mautic alias for a text area custom field to hold additional email addresses. Leave empty to disable this feature.', 'kntnt-form-shortcode-mautic' ),
        ];

        $fields['cookie'] = [
            'type' => 'text',
            'label' => __( "Mautic ID cookie", 'kntnt-cta' ),
            'description' => __( 'The name of Mautic\'s cookie with contact id.', 'kntnt-form-shortcode-mautic' ),
            'default' => 'mtc_id',
        ];

        $fields['submit'] = [
            'type' => 'submit',
        ];

        return $fields;

    }

}
