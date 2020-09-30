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

    private $additional_emails_field;


    public function run() {
        add_action( 'kntnt-form-shortcode-post', [ $this, 'handle_post' ] );
    }

    public function handle_post( $form_fields ) {
        Plugin::log( 'Form fields: %s', $form_fields );
        $this->additional_emails_field = Plugin::option( 'additional_emails_field' );
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

        if ( $this->cookie_contact && $this->cookie_contact->id ) {
            // Mautic is currently tracking the visitor and identified s/he by
            // id from the cookie.

            if ( $this->form_contact && $this->form_contact->email && $this->form_contact->email != $this->cookie_contact->email ) {
                // An email address have been provided that differs from the
                // email address that Mautic has associated with the visitor.
                // We need to manage the conflicting email addresses according
                // to settings.

                // What to do if the form contains a field mapped to the email
                // field of Mautic and the values of these two are not identical
                // for the current user.
                $email_collision_handling = Plugin::option( 'email_collision_handling', 'update' );

                if ( 'save' == $email_collision_handling ) {
                    // Push form fields, except the field mapped to the email
                    // field of Mautic, to the cookie contact. If a field for
                    // additional emails is provided, save the form email
                    // address in that field. If Mautic already has the form
                    // email on record, it will leave it as it is; thus
                    // maintaining two independent contacts for the same
                    // visitor.

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->cookie_contact, false );

                    // Add the form email address in the field for additional
                    // emails, if provided.
                    $this->if_additional_emails_add( $this->fields->email, $this->cookie_contact );

                    // Allow push to only the cookie contact.
                    $this->cookie_contact->push = true;
                    $this->form_contact->push = false;

                }
                else if ( 'save-bind' == $email_collision_handling ) {
                    // Works as 'save' with following addition: If Mautic
                    // already has the form email on record, and a field for
                    // additional emails is provided, the cookie contact email
                    // address is saved in that field for the form contact.

                    // Add the email address of cookie contact to the additional
                    // emails field of the form contact.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->form_contact );

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->cookie_contact, false );

                    // Save the form email address in the field for additional
                    // emails, if provided.
                    $this->if_additional_emails_add( $this->fields->email, $this->cookie_contact );

                    // Allow push to both the cookie contact and the form
                    // contact.
                    $this->cookie_contact->push = true;
                    $this->form_contact->push = true;

                }
                else if ( 'update' == $email_collision_handling ) {
                    // Push form fields, including the field mapped to the email
                    // field of Mautic, to the cookie contact. If a field for
                    // additional emails is provided, save the old cookie
                    // contact email address in that field. If Mautic already
                    // has the form email on record, Mautic will merge the two
                    // contacts.

                    // Save the form email address in the field for additional
                    // emails, if provided.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->cookie_contact );

                    // Copy form fields, including email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->cookie_contact, true );

                    // Allow push to only the cookie contact.
                    $this->cookie_contact->push = true;
                    $this->form_contact->push = false;

                }
                else if ( 'switch' == $email_collision_handling ) {
                    // Create the form contact if it doesn't exists. Push form
                    // fields to the form contact. If a field for additional
                    // emails is provided, save the original cookie contact
                    // email address in that field. Notice that Mautic will
                    // leave the cookie contact as it is; thus maintaining two
                    // independent contacts for the same visitor. Thus, the
                    // 'switch' option acts as the 'save' option but with the
                    // cookie and form contact switched.

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->form_contact );

                    // Add the cookie contact email address in the field for
                    // additional emails, if provided.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->form_contact );

                    // Allow push to only the form contact.
                    $this->cookie_contact->push = false;
                    $this->form_contact->push = true;

                    // Allow Mautic contact to be created if missing.
                    $this->form_contact->create = true;

                }
                else if ( 'switch-bind' == $email_collision_handling ) {
                    // Works as 'switch' with following addition: If a field for
                    // additional emails is provided, the form address is saved
                    // in that field for the cookie contact. Thus, the 'bind'
                    // option acts as the 'save-bind' option but with the cookie
                    // and form contact switched.

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->form_contact, false );

                    // Add the cookie contact email address in the field for
                    // additional emails, if provided.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->form_contact );

                    // Add the email address of form contact to the additional
                    // emails field of the cookie contact.
                    $this->if_additional_emails_add( $this->form_contact->email, $this->cookie_contact );

                    // Allow push to only the form contact.
                    $this->cookie_contact->push = true;
                    $this->form_contact->push = true;

                    // Allow creation of object if missing.
                    if ( is_null( $this->form_contact->id ) ) {
                        $this->form_contact->id = '';
                    }

                }
                else {
                    assert( false );
                }

            }
            else {
                // Either we don't have an email address provide by the form,
                // or it's identical to the tracked visitors email address.
                // Either way, push form fields, except the field mapped to the
                // email field of Mautic, to the cookie contact.

                // Copy form fields, excluding email address, to cookie
                // contact.
                $this->copy_form_fields_to( $this->cookie_contact, false );

                // Allow push to only the cookie contact.
                $this->cookie_contact->push = true;
                $this->form_contact->push = false;

            }

        }
        else {
            // Mautic isn't tracking the visitor.

            if ( $this->form_contact && $this->form_contact->id ) {
                // The provided email address is associated with a user

                // Copy form fields, including email address, to cookie
                // contact.
                $this->copy_form_fields_to( $this->form_contact, true );

                // Allow push to only the form contact.
                $this->cookie_contact->push = false;
                $this->form_contact->push = true;

                // Allow Mautic contact to be created if missing.
                $this->form_contact->create = true;

            }

        }
    }

    function copy_form_fields_to( $contact_obj, $include_email ) {

        // Copy all fields except the fields fore mail and additional emails.
        $contact_obj = (object) ( ( (array) $contact_obj ) + $this->fields );

        if ( $include_email && isset( $this->fields['email'] ) ) {

            // Include the email field
            $contact_obj->email = $this->fields['email'];

        }

        // Merge additional emails in the form into the field of additional
        // emails and Remove contact email from the field.
        add_additional_emails( $contact_obj, $this->additional_emails_field );

        return $contact_obj;

    }

    private function if_additional_emails_add( $emails, $contact_obj ) {

        if ( $this->additional_emails_field && ! empty( $emails ) ) {

            // Merge additional emails into the field of additional emails.
            $contact_obj->{$this->additional_emails_field} = $this->array_union( $contact_obj->{$this->additional_emails_field}, preg_split( '/\s+/', $emails ) );

            // Remove contact email from the field of additional emails.
            if ( ( $key = array_search( $contact_obj->email, $contact_obj->{$this->additional_emails_field} ) ) !== false ) {
                unset( $contact_obj->{$this->additional_emails_field}[ $key ] );
            }

        }

    }

    private function send_to_mautic() {
        // TODO
        Plugin::log('Cookie contact: %s', $this->cookie_contact);
        Plugin::log('Cookie contact: %s', $this->form_contact);
    }

    private function contact( $contact ) {
        // Constructs a contact object.
        $contact_obj = new stdClass;
        $contact_obj->create = false;
        $contact_obj->push = false;
        $contact_obj->id = isset( $contact['id'] ) ? $contact['id'] : null;
        $contact_obj->email = isset( $contact['email'] ) ? $contact['email'] : null;
        if ( $this->additional_emails_field ) {
            $contact_obj->{$this->additional_emails_field} = isset( $contact[ $this->additional_emails_field ] ) ? preg_split( '/\s+/', $contact[ $this->additional_emails_field ] ) : [];
        }
        return $contact_obj;
    }

    private function array_union( $array_1, $array_2 ) {
        return array_keys( array_flip( $array_1 ) + array_flip( $array_2 ) );
    }

}