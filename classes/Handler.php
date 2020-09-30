<?php

namespace Kntnt\Form_Shortcode_Mautic;

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

final class Handler {

    private $contacts_api;

    private $segments_api;

    private $segment_id;

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
        $this->setup_mautic_contact_api();
        $this->setup_mautic_segment_api();
        $this->set_segment( $form_fields );
        $this->set_fields( $form_fields );
        $this->find_cookie_contact();
        $this->find_form_contact();
        $this->prepare_fields();
        $this->mautic_push();
    }

    private function setup_mautic_contact_api() {
        $api_url = Plugin::str_join( Plugin::option( 'url' ), 'api' );
        $auth = ( new ApiAuth )->newAuth( [
            'userName' => Plugin::option( 'username' ),
            'password' => Plugin::option( 'password' ),
        ], 'BasicAuth' );
        $this->contacts_api = ( new MauticApi )->newApi( 'contacts', $auth, $api_url );
        Plugin::log( 'Mautic Contacts API at %s', $api_url );
    }

    private function setup_mautic_segment_api() {
        $api_url = Plugin::str_join( Plugin::option( 'url' ), 'api' );
        $auth = ( new ApiAuth )->newAuth( [
            'userName' => Plugin::option( 'username' ),
            'password' => Plugin::option( 'password' ),
        ], 'BasicAuth' );
        $this->segments_api = ( new MauticApi )->newApi( 'segments', $auth, $api_url );
        Plugin::log( 'Mautic Segments API at %s', $api_url );
    }

    private function set_segment( $form_fields ) {
        if ( isset( $form_fields['mautic-segment'] ) ) {
            if ( is_numeric( $form_fields['mautic-segment'] ) && ( ( (int) $form_fields['mautic-segment'] ) ) == $form_fields['mautic-segment'] ) {
                $this->segment_id = (int) $form_fields['mautic-segment'];
            }
            else {
                $this->segment_id = $this->mautic_segment_id_from_search_string( $form_fields['mautic-segment'] );
            }
            Plugin::log( 'Mautic segment: %s', $this->segment_id );
        }
        else {
            $this->segment_id = null;
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
        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            $this->cookie_contact = $this->mautic_contact_by_id( $_COOKIE[ $cookie_name ] );
        }
        else {
            Plugin::log( 'No Mautic cookie %s found.', $cookie_name );
            $this->cookie_contact = null;
        }
    }

    // If the form contains a field mapped to Mautic's email-field, this method
    // returns a contact object with the attribute `email` set to the provided
    // email address. The method also lookup the email address with Mautic. If
    // found, the attribute `id` is assigned the found contact's id, otherwise
    //  it's null. If a field for additional emails is provided in the settings,
    // a corresponding attribute exists with an array of additional emails.
    // If the form don't contains a field mapped to Mautic's email-field,
    // or in case of communication failure, null is returned.
    private function find_form_contact() {
        if ( ! isset( $this->fields['email'] ) || ! ( $email = $this->fields['email'] ) ) {
            $this->form_contact = null;
            Plugin::log( 'No form field is mapped to the Mautic email field.' );
        }
        else if ( isset( $this->cookie_contact->email ) && $email == $this->cookie_contact->email ) {
            $this->form_contact = $this->cookie_contact;
            Plugin::log( 'Provided email address is identical to the tracked users email address i Mautic.' );
        }
        else {
            $this->form_contact = $this->mautic_contact_by_email( $email );
        }
    }

    private function prepare_fields() {

        if ( $this->cookie_contact && $this->cookie_contact->id ) {
            // Mautic is currently tracking the visitor and identified s/he by
            // id from the cookie.

            Plugin::log( 'Mautic is currently tracking the visitor and identified s/he by id %s from the cookie.', $this->cookie_contact->id );

            if ( $this->form_contact && $this->form_contact->email && $this->form_contact->email != $this->cookie_contact->email ) {
                // An email address have been provided that differs from the
                // email address that Mautic has associated with the visitor.
                // We need to manage the conflicting email addresses according
                // to settings.

                Plugin::log( 'Conflicting email addresses; form contact email address %s differs from cookie contact email address %s.', $this->form_contact->email, $this->cookie_contact->email );

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

                    Plugin::log( 'Managing conflict by the SAVE rule.' );

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->cookie_contact, false );

                    // Add the form email address in the field for additional
                    // emails, if provided.
                    $this->if_additional_emails_add( $this->fields['email'], $this->cookie_contact );

                    // Allow push to only the cookie contact.
                    $this->cookie_contact->push = true;

                }
                else if ( 'save-bind' == $email_collision_handling ) {
                    // Works as 'save' with following addition: If Mautic
                    // already has the form email on record, and a field for
                    // additional emails is provided, the cookie contact email
                    // address is saved in that field for the form contact.

                    Plugin::log( 'Managing conflict by the SAVE-BIND rule.' );

                    // Add the email address of cookie contact to the additional
                    // emails field of the form contact.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->form_contact );

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->cookie_contact, false );

                    // Save the form email address in the field for additional
                    // emails, if provided.
                    $this->if_additional_emails_add( $this->fields['email'], $this->cookie_contact );

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

                    Plugin::log( 'Managing conflict by the UPDATE rule.' );

                    // Save the form email address in the field for additional
                    // emails, if provided.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->cookie_contact );

                    // Copy form fields, including email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->cookie_contact, true );

                    // Allow push to only the cookie contact.
                    $this->cookie_contact->push = true;

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

                    Plugin::log( 'Managing conflict by the SWITCH rule.' );

                    // Copy form fields, excluding email address, to cookie
                    // contact.
                    $this->copy_form_fields_to( $this->form_contact, false );

                    // Add the cookie contact email address in the field for
                    // additional emails, if provided.
                    $this->if_additional_emails_add( $this->cookie_contact->email, $this->form_contact );

                    // Allow push to only the form contact.
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

                    Plugin::log( 'Managing conflict by the SWITCH-BIND rule.' );

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

                    // Allow Mautic contact to be created if missing.
                    $this->form_contact->create = true;

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

                Plugin::log( 'Either we don\'t have an email address provide by the form, or it\'s identical to the tracked visitors email address.' );

                // Copy form fields, excluding email address, to cookie
                // contact.
                $this->copy_form_fields_to( $this->cookie_contact, false );

                // Allow push to only the cookie contact.
                $this->cookie_contact->push = true;

            }

        }
        else {
            // Mautic isn't tracking the visitor.

            Plugin::log( 'Mautic isn\'t tracking the visitor.' );

            // Copy form fields, including email address, to cookie
            // contact.
            $this->copy_form_fields_to( $this->form_contact, true );

            // Allow push to only the form contact.
            $this->form_contact->push = true;

            // Allow Mautic contact to be created if missing.
            $this->form_contact->create = true;

        }
    }

    private function copy_form_fields_to( &$contact, $include_email ) {

        // Copy all fields except the fields fore mail and additional emails.
        $modified_contact = (object) ( ( (array) $contact ) + $this->fields );

        if ( $include_email && isset( $this->fields['email'] ) ) {

            // Include the email field
            $modified_contact->email = $this->fields['email'];
        }

        // Merge additional emails in the form into the field of additional
        // emails and Remove contact email from the field.
        if ( isset( $this->fields[ $this->additional_emails_field ] ) ) {
            $this->if_additional_emails_add( $this->fields[ $this->additional_emails_field ], $modified_contact );
        }

        // Return the modified contact.
        $contact = $modified_contact;

    }

    private function if_additional_emails_add( $emails, &$contact_obj ) {
        if ( $this->additional_emails_field && ! empty( $emails ) ) {
            // Merge additional emails into the field of additional emails.
            $union_of_emails = array_keys( array_flip( $contact_obj->{$this->additional_emails_field} ) + array_flip( preg_split( '/\s+/', $emails ) ) );
            $contact_obj->{$this->additional_emails_field} = $union_of_emails;
        }
    }

    private function mautic_segment_id_from_search_string( $search_string ) {
        $search_string = "+$search_string";
        $response = $this->segments_api->getList( $search_string );
        if ( isset( $response['errors'] ) ) {
            $segment_id = null;
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
        }
        else if ( 0 == $response['total'] ) {
            $segment_id = null;
            Plugin::log( 'No segment matched the search string "%s".', $search_string );
        }
        else {
            $segment = array_shift( $response['lists'] );
            $segment_id = $segment['id'];
            Plugin::log( 'Found segment "%s" (%s) with id %s.', $segment['name'], $segment['alias'], $segment['id'] );
        }
        return $segment_id;
    }

    private function mautic_contact_by_id( $mautic_id ) {
        $response = $this->contacts_api->get( $mautic_id );
        if ( isset( $response['errors'] ) && 404 == $response['errors'][0]['code'] && false !== strpos( $response['errors'][0]['message'], 'Item was not found' ) ) {
            $contact = null;
            Plugin::log( 'No contact with id %s.', $mautic_id );
        }
        else if ( isset( $response['errors'] ) ) {
            $contact = null;
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
        }
        else {
            $contact = $this->contact( $response['contact']['fields']['all'] );
            Plugin::log( 'Found contact with id %s and email "%s".', $contact->id, $contact->email );
        }
        return $contact;
    }

    private function mautic_contact_by_email( $email ) {
        $search_string = "+email:$email";
        $response = $this->contacts_api->getList( $search_string );
        if ( isset( $response['errors'] ) ) {
            $contact = null;
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
        }
        else if ( 0 == $response['total'] ) {
            $contact = $this->contact( [ 'email' => $email ] );
            Plugin::log( 'No contact matched the search string "%s".', $search_string );
        }
        else {
            $segment = array_shift( $response['contacts'] );
            $contact = $segment['fields']['all'];
            Plugin::log( 'Found contact with id %s and email "%s".', $contact->id, $contact->email );
        }
        return $contact;
    }

    private function mautic_push() {

        if ( isset( $this->cookie_contact ) && $this->cookie_contact->push ) {
            Plugin::log( 'Sending cookie contact to Mautic' );
            $this->mautic_update_contact( $this->cookie_contact );
        }

        if ( isset( $this->form_contact ) && $this->form_contact->push ) {
            Plugin::log( 'Sending form contact to Mautic' );
            if ( $this->form_contact->id ) {
                $this->mautic_update_contact( $this->form_contact );
            }
            else if ( $this->form_contact->create ) {
                $this->mautic_create_contact( $this->form_contact );
            }
        }

    }

    private function mautic_create_contact( $contact ) {

        $contact = $this->mautic_contact( $contact );

        Plugin::log( 'Send CREATE request to Mautic for %s', $contact );

        $response = $this->contacts_api->create( $contact );
        if ( isset( $response['contact'] ) ) {
            Plugin::error( 'Successfully created Mautic contact with id %s.', $response['contact']['id'] );
            $this->mautic_add_contact_to_segment( $response['contact']['id'] );
        }
        else {
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
        }

    }

    private function mautic_update_contact( $contact ) {

        $id = $contact->id;
        $contact = $this->mautic_contact( $contact );

        Plugin::log( 'Send UPDATE request to Mautic for %s', $contact );

        $response = $this->contacts_api->edit( $id, $contact, false );
        if ( isset( $response['contact'] ) ) {
            Plugin::error( 'Successfully updated Mautic contact with id %s.', $response['contact']['id'] );
            $this->mautic_add_contact_to_segment( $response['contact']['id'] );
        }
        else {
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
        }

    }

    private function mautic_add_contact_to_segment( $contact_id ) {

        $response = $this->segments_api->addContact( $this->segment_id, $contact_id );
        if ( isset( $response['errors'] ) && 404 == $response['errors'][0]['code'] && false === strpos( $response['errors'][0]['message'], 'Item was not found' ) ) {
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
        }
        else if ( ! isset( $response['success'] ) ) {
            Plugin::log( 'No segment with id %s.', $this->segment_id );
        }
        else {
            Plugin::error( 'Successfully added contact %s to segment %s.', $contact_id, $this->segment_id );
        }

    }

    private function contact( $contact ) {

        $contact_obj = new \stdClass;

        $contact_obj->create = false;
        $contact_obj->push = false;

        $contact_obj->id = isset( $contact['id'] ) ? $contact['id'] : null;

        $contact_obj->email = isset( $contact['email'] ) ? $contact['email'] : null;

        if ( $this->additional_emails_field ) {
            $contact_obj->{$this->additional_emails_field} = isset( $contact[ $this->additional_emails_field ] ) ? preg_split( '/\s+/', $contact[ $this->additional_emails_field ] ) : [];
        }

        return $contact_obj;

    }

    private function mautic_contact( $contact ) {

        $contact->ipAddress = $_SERVER['REMOTE_ADDR'];
        $contact->lastActive = gmdate( 'Y-m-d H:m:i' );

        unset( $contact->create );
        unset( $contact->push );

        if ( empty( $contact->id ) ) {
            unset( $contact->id );
        }

        if ( empty( $contact->email ) ) {
            unset( $contact->email );
        }

        if ( $this->additional_emails_field ) {
            $additional_emails = &$contact->{$this->additional_emails_field};
            if ( empty( $additional_emails ) ) {
                unset( $additional_emails );
            }
            else {
                $additional_emails = join( "\n", $additional_emails );
            }
        }

        return (array) $contact;

    }

}