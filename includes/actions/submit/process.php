<?php
global $submit_errors;

function ld_submit__create_user( $username, $email ) {

    $password = wp_generate_password( 14, true );
    $user_id = wp_create_user( $username, $password, $email );

    if ( $user_id )
        wp_new_user_notification( $user_id, $password );

    return $user_id;
}


function ld_submit__create_listing( $name, $description, $cat_id, $user_id ) {

    $listing = array(
        'post_content'  => $description,
        'post_title'    => $name,
        'post_status'   => 'pending',
        'post_type'     => LDDLITE_POST_TYPE,
        'post_author'   => $user_id,
        'post_date'     => date( 'Y-m-d H:i:s' ),
        'tax_input'     => array( LDDLITE_TAX_CAT => $cat_id ),
    );

    return wp_insert_post( $listing );
}


function ld_submit__create_meta( $data, $post_id ) {

    $remove = array(
        'name',
        'description',
        'username', // These two should already be gone, but let's pretend
        'email',
    );

    $data = array_diff_key( $data, array_flip( $remove ) );

    foreach ( $data['url'] as $key => $value ) {
        $data[ 'url_' . $key ] = $value;
    }
    unset( $data['url'] );

    foreach ( $data as $key => $value ) {
        add_post_meta( $post_id, LDDLITE_PFX . $key, $value );
    }

}


function ld_sanitize__post( $data ) {

    $output['description'] = $data['ld_s_description'];
    $output['contact_email'] = sanitize_email( $data['ld_s_contact_email'] );
    $output['email'] = sanitize_email( $data['ld_s_email'] );
    unset( $data['ld_s_description'] );
    unset( $data['ld_s_contact_email'] );
    unset( $data['ld_s_email'] );

    foreach ( $data as $key => $value ) {
        if ( false !== strpos( $key, 'ld_s_' ) ) {
            $var = substr( $key, 5 );
            $output[ $var ] = is_array( $value ) ? $value : sanitize_text_field( wp_unslash( $value ) );
        }
    }

    if ( is_array( $output['url'] ) ) {
        foreach ( $output['url'] as $key => $value ) {
            if ( in_array( $key, array( 'facebook', 'linkedin' ) ) )
                $output['url'][ $key ] = ldl_sanitize_https( $value );
            else if ( 'twitter' == $key )
                $output['url'][ $key ] = ldl_sanitize_twitter( $value );
            else if ( strpos( $url, 'http') !== 0 )
                $output['url'][ $key ] = esc_url_raw( $value );
        }
    }

    return $output;
}


function ld_submit_validate_form( $data) {
    global $submit_errors;

    $submit_errors = new WP_Error;

    if ( empty( $data['title'] ) )
        ld_submit_add_errors( 'title_required' );

    ld_submit_validate_category( $data['category'] );

    if ( empty( $data['description'] ) )
        ld_submit_add_errors( 'description_required' );

    if ( !empty( $data['contact_email'] ) && !is_email( $data['contact_email'] ) )
        ld_submit_add_errors( 'contact_email_invalid' );

    if ( !empty( $data['contact_phone'] ) )
        ld_submit_validate_phone( $data['contact_phone'] );

    ld_submit_validate_user( $data['username'], $data['email'] );


    if ( empty( $data['address_one'] ) )
        ld_submit_add_errors( 'address_one_required' );

    if ( empty( $data['city'] ) )
        ld_submit_add_errors( 'city_required' );

    if ( empty( $data['subdivision'] ) )
        ld_submit_add_errors( 'subdivision_required' );

    if ( empty( $data['post_code'] ) )
        ld_submit_add_errors( 'post_code_required' );

    if ( !is_array( $data['url'] ) )
        $data['url'] = array();
    else
        $data['url'] = ld_submit_sanitize_urls( $data['url'] );

    $codes = $submit_errors->get_error_codes();

    if ( !empty( $codes ) )
        return $submit_errors;

    return true;
}


function ld_submit_validate_category( $cat_id ) {

    $ids = get_terms( LDDLITE_TAX_CAT, array('fields' => 'ids', 'get' => 'all') );

    if ( !in_array( $cat_id, $ids ) )
        ld_submit_add_errors( 'category_invalid' );

}


function ld_submit_validate_user( $username, $email ) {

    $r = false;

    if ( empty( $username ) ) {
        ld_submit_add_errors( 'username_required' );
        $r = true;
    }

    if ( empty( $email ) || !is_email( $email ) ) {
        ld_submit_add_errors( 'email_required' );
        $r = true;
    }

    if ( $r )
        return;

    if ( $username != sanitize_user( $username, true ) )
        ld_submit_add_errors( 'username_invalid', $username );

    if ( username_exists( $username ) )
        ld_submit_add_errors( 'username_exists', $username );

    if ( email_exists( $email ) )
        ld_submit_add_errors( 'email_exists', $email );

}


function ld_submit_validate_phone( $number, $key = 'contact_phone', $required = true ) {

    $number = ld_sanitize_phone( $number );

    if ( $required && empty( $number ) )
        ld_submit_add_errors( $key . '_required' );

    if ( strlen( $number ) < 7 || strlen( $number ) > 16 )
        ld_submit_add_errors( $key . '_invalid' );

}


function ld_submit_sanitize_urls( $urls ) {

    $hosts = array(
        'facebook' => 'www.facebook.com',
        'linkedin' => 'www.linkedin.com',
        'twitter' => 'twitter.com',
    );

    foreach( $urls as $type => $url ) {

        if ( empty( $url ) )
            break;

        // This helps parse i18n TLDs, but still forgives people entering just their twitter username
        if ( 'twitter' != $type )
            $url = esc_url( $url );

        $parsed = parse_url( $url );

        $scheme = ( 'website' == $type ) ? 'http' : 'https';
        $host = ( 'website' == $type ) ? $parsed['host'] : $hosts[ $type ];

        $path = '/' . sanitize_text_field( ltrim( $parsed['path'], '/' ) );

        $urls[ $type ] = $scheme . '://' . $host . $path;
    }

    return $urls;
}


function ld_submit_add_errors( $code, $data = null ) {
    global $submit_errors;

    if ( !is_wp_error( $submit_errors ) )
        $submit_errors = new WP_Error;

    $message = ld_submit_get_error_message( $code );

    if ( $message )
        $submit_errors->add( $code, $message, $data );

}


function ld_submit_get_error_message( $error_slug ) {

    $error_messages = array(
        'title_required'            => __( 'You need a title for your listing', ldl::$slug ),
        'category_invalid'          => __( 'Please select a category', ldl::$slug ),
        'description_required'      => __( 'Please add a description for your listing', ldl::$slug ),
        'contact_email_invalid'     => __( 'Please enter a valid email address', ldl::$slug ),
        'contact_phone_required'    => __( 'Please enter a phone number', ldl::$slug ),
        'contact_phone_invalid'     => __( 'That is not a valid phone number', ldl::$slug ),
        'username_required'         => __( 'A username is required', ldl::$slug ),
        'username_invalid'          => __( 'That is not a valid username', ldl::$slug ),
        'username_exists'           => __( 'That username already exists', ldl::$slug ),
        'email_required'            => __( 'An email address is required', ldl::$slug ),
        'email_exists'              => __( 'That email is already in use', ldl::$slug ),
        'address_one_required'      => __( 'Please enter your street address', ldl::$slug ),
        'city_required'             => __( 'Please enter a city', ldl::$slug ),
        'subdivision_required'      => __( 'Please enter a state', ldl::$slug ),
        'post_code_required'        => __( 'Please enter your zip', ldl::$slug ),
    );

    if ( array_key_exists( $error_slug, $error_messages ) )
        return $error_messages[ $error_slug ];

    return false;
}
