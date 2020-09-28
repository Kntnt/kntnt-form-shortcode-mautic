<?php


namespace Kntnt\Form_Shortcode_Mautic;


final class Handler {

    private $mautic_segment; // TODO: UPDATE SEGMENT

    private $mautic_fields;

    private $mautic;

    private $email_collision_handling;

    private $additional_emails_field;

    public function run() {

        $this->email_collision_handling = Plugin::option( 'email_collision_handling', 'create' );
        $this->additional_emails_field = Plugin::option( 'additional_emails_field', null );

        add_action( 'kntnt-form-shortcode-post', [ $this, 'handle_post' ] );

    }

    public function handle_post( $form_fields ) {
        Plugin::log( 'Form fields: %s', $form_fields );
        $this->set_mautic_segment( $form_fields );
        $this->set_mautic_fields( $form_fields );
        $this->setup_mautic_contacts_api();
        $this->send_to_mautic();
    }

    private function set_mautic_segment( $form_fields ) {
        if ( isset( $form_fields['mautic-segment'] ) ) {
            $this->mautic_segment = $form_fields['mautic-segment'];
            Plugin::log( 'Mautic segment: %s', $this->mautic_fields );
        }
        else {
            $this->mautic_segment = '';
        }
    }

    private function set_mautic_fields( $form_fields ) {
        if ( isset( $form_fields['mautic-fields'] ) ) {
            foreach ( $form_fields['mautic-fields'] as $form_id => $mautic_id ) {
                $this->mautic_fields[ $mautic_id ] = $form_fields[ $form_id ];
            }
            Plugin::log( 'Mautic fields: %s', $this->mautic_fields );
        }
        else {
            $this->mautic_fields = [];
        }
    }

    private function setup_mautic_contacts_api() {
        $auth = ( new \Mautic\Auth\ApiAuth )->newAuth( [
            'userName' => Plugin::option( 'username' ),
            'password' => Plugin::option( 'password' ),
        ], 'BasicAuth' );
        $api_url = Plugin::str_join( Plugin::option( 'url' ), 'api' );
        Plugin::log( 'API URL: %s', $api_url );
        $this->mautic = ( new \Mautic\MauticApi )->newApi( 'contacts', $auth, $api_url );
    }

    private function send_to_mautic() {

        $mautic_contact_from_cookie = $this->get_mautic_contact_from_cookie();
        $mautic_contact_from_email = $this->get_mautic_contact_from_email();

        if ( $mautic_contact_from_cookie && null == $mautic_contact_from_email ) {
            $this->merge_contacts( $mautic_contact_from_cookie, $this->form_contact() );
            $this->mautic_update();
        }
        else if ( null == $mautic_contact_from_cookie && $mautic_contact_from_email ) {
            $this->merge_contacts( $mautic_contact_from_email, $this->form_contact() );
            $this->mautic_update();
        }
        else if ( $mautic_contact_from_cookie && $mautic_contact_from_email && in_array( $this->email_collision_handling, [ 'update', 'add' ] ) ) {
            $this->merge_contacts( $mautic_contact_from_cookie, $mautic_contact_from_email );
            $this->merge_contacts( $mautic_contact_from_cookie, $this->form_contact() );
            $this->mautic_update();
        }
        else {
            $this->mautic_create();
        }

    }

    private function get_mautic_contact_from_cookie() {
        $cookie_name = Plugin::option( 'cookie' );
        if ( isset( $_COOKIE[ $cookie_name ] ) && ( $mautic_id = $_COOKIE[ $cookie_name ] ) ) {
            $response = $this->mautic->get( $mautic_id );
            if ( isset( $response['contact'] ) ) {
                $contact = $this->contact( $response['contact'] );
                Plugin::log( 'Found contact with id %s and email "%s".', $contact->id, $contact->email );
                return $contact;
            }
            if ( 404 != $response['errors'][0]['code'] || false === strpos( $response['errors'][0]['message'], 'Item was not found' ) ) {
                Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
                return null;
            }
            Plugin::log( 'No contact with id %s.', $mautic_id );
            return null;
        }
        Plugin::log( 'Can\'t find Mautic id cookie %s.', $cookie_name );
        return null;
    }

    private function get_mautic_contact_from_email() {
        if ( isset( $this->mautic_fields['email'] ) && ( $email = $this->mautic_fields['email'] ) ) {
            $response = $this->mautic->getList( "email:$email" );
            if ( isset( $response['contacts'] ) ) {
                if ( $response['total'] > 0 ) {
                    $contact = $this->contact( array_shift( $response['contacts'] ) );
                    Plugin::log( 'Found contact with id %s and email "%s".', $contact->id, $contact->email );
                    return $contact;
                }
                Plugin::log( 'No contact with email "%s".', $email );
                return null;
            }
            Plugin::error( 'Error when connecting to Mautic: %s', $response['errors'][0]['message'] );
            return null;
        }
        return null;
    }

    private function merge_contacts( $dst_contact, $src_contact = null ) {
        if ( $src_contact->email && $src_contact->email != $dst_contact->email ) {
            if ( 'save' == $this->email_collision_handling && $this->additional_emails_field ) {
                $this->mautic_fields['$additional_emails_field'][] = $src_contact->email;
            }
            else if ( 'update' == $this->email_collision_handling ) {
                if ( $this->additional_emails_field ) {
                    $this->mautic_fields['additional_emails_field'][] = $dst_contact->email;
                }
                $this->mautic_fields['email'] = $src_contact->email;
            }
            else {
                $this->mautic_fields['email'] = $src_contact->email;
            }
        }
    }

    private function contact( $contact ) {
        $contact_obj = new \stdClass();
        $contact_obj->id = $contact['fields']['all']['id'];
        $contact_obj->email = $contact['fields']['all']['email'];
        if ( $this->additional_emails_field ) {
            $contact_obj->$this->additional_emails_field = explode( '\n', $contact['fields']['all'][ $this->additional_emails_field ]\n);
        }
        return $contact_obj;
    }

    private function form_contact() {
        $contact_obj = new \stdClass();
        $contact_obj->id = null;
        $contact_obj->email = isset( $this->mautic_fields['email'] ) ? $this->mautic_fields['email'] : null;;
        return $contact_obj;
    }

    private function mautic_update() {
        Plugin::log( $this->mautic_fields ); // TODO
    }

    private function mautic_create() {
        Plugin::log( $this->mautic_fields ); // TODO
    }

}