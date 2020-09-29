<?php


namespace Kntnt\Form_Shortcode_Mautic;


use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;
use stdClass;

final class Handler {

    private $mautic;

    private $segment; // TODO: UPDATE SEGMENT

    private $fields;

    private $cookie_contact;

    private $form_contact;

    public function run() {
        add_action( 'kntnt-form-shortcode-post', [ $this, 'handle_post' ] );
    }

    public function handle_post( $form_fields ) {
        Plugin::log( 'Form fields: %s', $form_fields );
        $this->setup_mautic_contacts_api();
        $this->set_segment( $form_fields );
        $this->set_fields( $form_fields );
        $this->cookie_contact = $this->find_cookie_contact();
        $this->form_contact = $this->find_form_contact();
        $this->prepare_fields();
        $this->send_to_mautic();
    }

    private function setup_mautic_contacts_api() {
        $api_url = Plugin::str_join( Plugin::option( 'url' ), 'api' );
        $auth = ( new ApiAuth )->newAuth( [
            'userName' => Plugin::option( 'username' ),
            'password' => Plugin::option( 'password' ),
        ], 'BasicAuth' );
        $this->mautic = ( new MauticApi )->newApi( 'contacts', $auth, $api_url );
        Plugin::log( 'API URL: %s', $api_url );
    }

    private function set_segment( $form_fields ) {
        if ( isset( $form_fields['mautic-segment'] ) ) {
            $this->segment = $form_fields['mautic-segment'];
            Plugin::log( 'Mautic segment: %s', $this->fields );
        }
        else {
            $this->segment = '';
        }
    }

    private function set_fields( $form_fields ) {
        if ( isset( $form_fields['mautic-fields'] ) ) {
            foreach ( $form_fields['mautic-fields'] as $form_id => $mautic_id ) {
                $this->fields[ $mautic_id ] = $form_fields[ $form_id ];
            }
            Plugin::log( 'Mautic fields: %s', $this->fields );
        }
        else {
            $this->fields = [];
        }
    }

    // If current visitor is tracked by Mautic, this method returns a contact
    // object with the attributes `id` and `email` (empty for unknown contacts).
    // If a field for additional emails is provided in the settings,
    // a corresponding attribute exists with an array of additional emails. If
    // current visitor isn't tracked by Mautic, or in case of communication
    // failure, null is returned.
    private function find_cookie_contact() {
        $cookie_name = Plugin::option( 'cookie' );
        if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
            Plugin::log( 'No Mautic cookie %s found.', $cookie_name );
            $contact = null;
        }
        else {
            $mautic_id = $_COOKIE[ $cookie_name ];
            $response = $this->mautic->get( $mautic_id );
            if ( 404 == $response['errors'][0]['code'] && false === strpos( $response['errors'][0]['message'], 'Item was not found' ) ) {
                $contact = null;
                Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
            }
            else if ( ! isset( $response['contact'] ) ) {
                $contact = null;
                Plugin::log( 'No contact with id %s.', $mautic_id );
            }
            else {
                $contact = $this->contact( $response['contact']['fields']['all'] );
                Plugin::log( 'Found contact with id %s and email "%s".', $contact->id, $contact->email );
            }
        }
        Plugin::log( 'Cookie contact: %s', Plugin::stringify( $contact ) );
        return $contact;
    }

    // If the form contains a field mapped to Mautic's email-field, this method
    // returns contact with the attribute `email` set to the provided email
    // address. The method also lookup the email address with Mautic. If found,
    // the attribute `id` is assigned the found contact's id, otherwise it's
    // null. If a field for additional emails is provided in the settings,
    // a corresponding attribute exists with an array of additional emails.
    // If the form don't contains a field mapped to Mautic's email-field,
    // or in case of communication failure, null is returned.
    private function find_form_contact() {
        if ( ! isset( $this->fields['email'] ) || ! ( $email = $this->fields['email'] ) ) {
            $contact = null;
            Plugin::log( 'No form field is mapped to the Mautic email field.' );
        }
        else if ( $email == $this->cookie_contact->email ) {
            $contact = $this->cookie_contact;
            Plugin::log( 'Provided email address is identical to the tracked users email address i Mautic.' );
        }
        else {
            $response = $this->mautic->getList( "email:$email" );
            if ( ! isset( $response['contacts'] ) ) {
                $contact = null;
                Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
            }
            else if ( 0 == $response['total'] ) {
                $contact = $this->contact( [ 'email' => $email ] );
                Plugin::log( 'No contact with email "%s".', $email );
            }
            else {
                $contact = $this->contact( array_shift( $response['contacts'] )['fields']['all'] );
                Plugin::log( 'Found contact with id %s and email "%s".', $contact->id, $contact->email );
            }
        }
        Plugin::log( 'Form contact: %s', Plugin::stringify( $contact ) );
        return $contact;
    }

    private function prepare_fields() {

        // Mautic alias for a text area custom filed to hold additional email
        // addresses. Leave empty to disable this feature.
        $additional_emails_field = Plugin::option( 'additional_emails_field' );

        // What to do if the form contains a field mapped to the email field of
        // Mautic and the values of these two are not identical for the current
        // user.
        //
        // - create: Create a new contact.
        // - update: Update contact email address. Save the old email address to
        //           the additional email field if provided below.
        // - save:   Save the email address provided to the additional email
        //           field if provided.
        $email_collision_handling = Plugin::option( 'email_collision_handling', 'create' );

        if ( $this->cookie_contact && $this->cookie_contact->id ) {
            // Mautic is currently tracking the visitor and identified s/he by
            // id from the cookie.

            if ( $this->form_contact && $this->form_contact->email && $this->form_contact->email != $this->cookie_contact->email ) {
                // An email address have been provided that differs from the
                // email address that mautic as associated with the visitor.

                if ( $this->form_contact->is ) {
                    // Mautic has tracked the visitor in the past when s/he
                    // was identified by the provided email address.

                    // TODO TODO  TODO  TODO  TODO  TODO  TODO  TODO  TODO

                }
                else {
                    // Mautic has no record of the provided email address.

                    if ( 'save' == $email_collision_handling && $additional_emails_field ) {
                        // The form fields should be pushed to cookie contact
                        // after the provided email address is moved to the
                        // field of additional email addresses.

                        // The fields should be pushed to the cookie contact.
                        $this->fields['id'] = $this->cookie_contact->id;

                        // Move the form email address to the field of additional
                        // email addresses
                        $this->fields[ $additional_emails_field ] = $this->array_union( $this->cookie_contact->$additional_emails_field, [ $this->form_contact->email ] );
                        unset( $this->fields['email'] );

                    }
                    else if ( 'update' == $email_collision_handling ) {
                        // The form fields should be pushed to cookie contact
                        // after the provided email address replace the cookie
                        // contact email addresses which should be saved in a
                        // field of additional email addresses if provided.

                        // The fields should be pushed to the cookie contact.
                        $this->fields['id'] = $this->cookie_contact->id;

                        // If field of additional email addresses is provided,
                        // save the cookie contact email address to it.
                        if ( $additional_emails_field ) {
                            $this->fields[ $additional_emails_field ] = [ $this->cookie_contact->email ];
                        }

                        // Replace the cookie contact email address with the
                        // form email address,
                        $this->cookie_contact->email = $this->form_contact->email;

                    }
                    else {
                        // Create new Mautic contact from form fields and save
                        // the cookie contact email address as additional email
                        // address if such field is provided.

                        // The fields should be pushed to a new contact.
                        $this->fields['id'] = null;

                        // If field of additional email addresses is provided,
                        // save the cookie contact email address to it.
                        if ( $additional_emails_field ) {
                            $this->fields[ $additional_emails_field ] = [ $this->cookie_contact->email ];
                        }

                    }

                }

            }
            else {
                // Either we don't have an email address provide by the form,
                // or it's identical to the tracked visitors email address.
                // Either way, the form fields should be pushed to cookie
                // contact.

                // The fields should be pushed to the cookie contact.
                $this->fields['id'] = $this->cookie_contact->id;

                // Since the cookie contact already has the current
                // users email address, we can as a precaution remove
                // the email filed.
                unset( $this->fields['email'] );

            }

        }
        else {
            // Mautic isn't tracking the visitor.

            if ( $this->form_contact && $this->form_contact->id ) {
                // The provided email address is associated with a user

                // The fields should be pushed to the contact associated with
                // the provided email.
                $this->fields['id'] = $this->form_contact->id;

            }

        }
    }

    private function send_to_mautic() {
        Plugin::log( $this->fields ); // TODO
    }

    // Constructs a contact object.
    private function contact( $contact ) {
        $contact_obj = new stdClass;
        $contact_obj->id = isset( $contact['id'] ) ? $contact['id'] : null;
        $contact_obj->email = isset( $contact['email'] ) ? $contact['email'] : null;
        if ( $additional_emails_field = Plugin::option( 'additional_emails_field' ) ) {
            $contact_obj->{$additional_emails_field} = isset( $contact[ $additional_emails_field ] ) ? preg_split( '/\s+/', $contact[ $additional_emails_field ] ) : [];
        }
        return $contact_obj;
    }

    private function array_union( $array_1, $array_2 ) {
        return array_keys( array_flip( $array_1 ) + array_flip( $array_2 ) );
    }

}