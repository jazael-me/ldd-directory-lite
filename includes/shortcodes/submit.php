<?php

/**
 * Submit a listing view controller and other functionality
 *
 * @package   ldd_directory_lite
 * @author    LDD Web Design <info@lddwebdesign.com>
 * @license   GPL-2.0+
 * @link      http://lddwebdesign.com
 * @copyright 2014 LDD Consulting, Inc
 */


/**
 * This is a hot mess and is just going to wind up even more fragmented at this rate.
 * Start brainstorming how to rebuild this before it's too late.
 * BEGIN PROCESS.PHP
 **********************************************************************************************************************/


function ldl_submit__create_user( $username, $email ) {

    $password = wp_generate_password( 14, true );
    $user_id = wp_create_user( $username, $password, $email );

    if ( $user_id )
        wp_new_user_notification( $user_id, $password );

    return $user_id;
}


function ldl_submit__create_listing( $name, $description, $cat_ID, $user_id ) {

    $listing = array(
        'post_content'  => $description,
        'post_title'    => $name,
        'post_status'   => 'pending',
        'post_type'     => LDDLITE_POST_TYPE,
        'post_author'   => $user_id,
        'post_date'     => date( 'Y-m-d H:i:s' ),
    );

    $post_ID = wp_insert_post( $listing );

    if ( $post_ID ) {
        wp_set_object_terms( $post_ID, (int) $cat_ID, LDDLITE_TAX_CAT );
        return $post_ID;
    }

    return false;
}


function ldl_submit__create_meta( $data, $post_id ) {

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


function ldl_sanitize__post( $data ) {

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
                $output['url'][ $key ] = ldl_force_https( $value );
            else if ( 'twitter' == $key )
                $output['url'][ $key ] = ldl_sanitize_twitter( $value );
            else if ( strpos( $url, 'http') !== 0 )
                $output['url'][ $key ] = esc_url_raw( $value );
        }
    }

    return $output;
}


function ldl_submit_validate_form( $data) {
    global $submit_errors;

    $submit_errors = new WP_Error;

    if ( empty( $data['title'] ) )
        ldl_submit_add_errors( 'title_required' );

    ldl_submit_validate_category( $data['category'] );

    if ( empty( $data['description'] ) )
        ldl_submit_add_errors( 'description_required' );

    if ( !empty( $data['contact_email'] ) && !is_email( $data['contact_email'] ) )
        ldl_submit_add_errors( 'contact_email_invalid' );

    if ( !empty( $data['contact_phone'] ) )
        ldl_submit_validate_phone( $data['contact_phone'] );

    if ( !is_user_logged_in() )
        ldl_submit_validate_user( $data['username'], $data['email'] );


    if ( ldl_get_setting( 'submit_require_address' ) ) {
        if ( empty( $data['address_one'] ) )
            ldl_submit_add_errors( 'address_one_required' );

        if ( empty( $data['city'] ) )
            ldl_submit_add_errors( 'city_required' );

        if ( empty( $data['subdivision'] ) )
            ldl_submit_add_errors( 'subdivision_required' );

        if ( empty( $data['post_code'] ) )
            ldl_submit_add_errors( 'post_code_required' );
    }

    if ( !is_array( $data['url'] ) )
        $data['url'] = array();
    else
        $data['url'] = ldl_submit_sanitize_urls( $data['url'] );

    $codes = $submit_errors->get_error_codes();

    if ( !empty( $codes ) )
        return $submit_errors;

    return true;
}


function ldl_submit_validate_category( $cat_id ) {

    $ids = get_terms( LDDLITE_TAX_CAT, array('fields' => 'ids', 'get' => 'all') );

    if ( !in_array( $cat_id, $ids ) )
        ldl_submit_add_errors( 'category_invalid' );

}


function ldl_submit_validate_user( $username, $email ) {

    $r = false;

    if ( empty( $username ) ) {
        ldl_submit_add_errors( 'username_required' );
        $r = true;
    }

    if ( empty( $email ) || !is_email( $email ) ) {
        ldl_submit_add_errors( 'email_required' );
        $r = true;
    }

    if ( $r )
        return;

    if ( $username != sanitize_user( $username, true ) )
        ldl_submit_add_errors( 'username_invalid', $username );

    if ( username_exists( $username ) )
        ldl_submit_add_errors( 'username_exists', $username );

    if ( email_exists( $email ) )
        ldl_submit_add_errors( 'email_exists', $email );

}


function ldl_submit_validate_phone( $number, $key = 'contact_phone', $required = true ) {

    $number = ldl_sanitize_phone( $number );

    if ( $required && empty( $number ) )
        ldl_submit_add_errors( $key . '_required' );

    if ( strlen( $number ) < 7 || strlen( $number ) > 16 )
        ldl_submit_add_errors( $key . '_invalid' );

}


function ldl_submit_sanitize_urls( $urls ) {

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


function ldl_submit_add_errors( $code, $data = null ) {
    global $submit_errors;

    if ( !is_wp_error( $submit_errors ) )
        $submit_errors = new WP_Error;

    $message = ldl_submit_get_error_message( $code );

    if ( $message )
        $submit_errors->add( $code, $message, $data );

}


function ldl_submit_get_error_message( $error_slug ) {

    $error_messages = array(
        'title_required'            => __( 'You need a title for your listing', 'lddlite' ),
        'category_invalid'          => __( 'Please select a category', 'lddlite' ),
        'description_required'      => __( 'Please add a description for your listing', 'lddlite' ),
        'contact_email_invalid'     => __( 'Please enter a valid email address', 'lddlite' ),
        'contact_phone_required'    => __( 'Please enter a phone number', 'lddlite' ),
        'contact_phone_invalid'     => __( 'That is not a valid phone number', 'lddlite' ),
        'username_required'         => __( 'A username is required', 'lddlite' ),
        'username_invalid'          => __( 'That is not a valid username', 'lddlite' ),
        'username_exists'           => __( 'That username already exists', 'lddlite' ),
        'email_required'            => __( 'An email address is required', 'lddlite' ),
        'email_exists'              => __( 'That email is already in use', 'lddlite' ),
        'address_one_required'      => __( 'Please enter your street address', 'lddlite' ),
        'city_required'             => __( 'Please enter a city', 'lddlite' ),
        'subdivision_required'      => __( 'Please enter a state', 'lddlite' ),
        'post_code_required'        => __( 'Please enter your zip', 'lddlite' ),
    );

    if ( array_key_exists( $error_slug, $error_messages ) )
        return $error_messages[ $error_slug ];

    return false;
}


function ldl_submit__process( $data ) {
    $data = ldl_sanitize__post( $data );
    $valid = ldl_submit_validate_form( $data );

    if ( is_wp_error( $valid ) ) {

        $errors = array();
        $codes = $valid->get_error_codes();

        foreach ( $codes as $code ) {
            $key = substr( $code, 0, strrpos( $code, '_' ) );
            $errors[ $key ] = '<span class="fa fa-exclamation form-control-feedback"></span><span class="submit-error">' . $valid->get_error_message( $code ) . '</span>';
        }

    } else {

        // Create the user and insert a post for this listing
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
        } else {
            $user_id = ldl_submit__create_user( $data['username'], $data['email'] );
        }
        $post_id = ldl_submit__create_listing( $data['title'], $data['description'], $data['category'], $user_id );

        // Add all the post meta fields
        ldl_submit__create_meta( $data, $post_id );

        // Upload their logo if one was submitted
        if ( isset( $_FILES['ld_s_logo'] ) ) {
            // These files need to be included as dependencies when on the front end.
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );

            $attachment_id = media_handle_upload( 'ld_s_logo', 0 );
            set_post_thumbnail( $post_id, $attachment_id );
        }

        ldl_submit__email_admin( $data, $post_id );
        ldl_submit__email_owner( $data );

        $success = true;
        $data = array();

    }

}

/**
 * END OF PROCESS.PHP
 */




function ldl_submit__email_admin( array $data, $post_id ) {

    $subject = ldl_get_setting( 'email_toadmin_subject' );
    $message = ldl_get_setting( 'email_toadmin_body' );

    $message = str_replace( '{aprove_link}', admin_url( 'post.php?post=' . $post_id . '&action=edit' ), $message );
    $message = str_replace( '{title}', $data['title'], $message );
    $message = str_replace( '{description}', $data['description'], $message );

    ldl_mail( ldl_get_setting( 'email_notifications' ), $subject, $message );
}


function ldl_submit__email_owner( array $data ) {

    $subject = ldl_get_setting( 'email_onsubmit_subject' );
    $message = ldl_get_setting( 'email_onsubmit_body' );

    $message = str_replace( '{site_title}', get_bloginfo( 'name' ), $message );
    $message = str_replace( '{directory_title}', ldl_get_setting( 'directory_label' ), $message );
    $message = str_replace( '{directory_email}', ldl_get_setting( 'email_from_address' ), $message );
    $message = str_replace( '{title}', $data['title'], $message );
    $message = str_replace( '{description}', $data['description'], $message );

    ldl_mail( $data['email'], $subject, $message );
}


function ldl_action__submit( $term = false ) {
    global $post;

    wp_enqueue_script( 'lddlite-responsiveslides' );
    wp_enqueue_script( 'lddlite-submit' );

    $tpl = new stdClass();
    $tpl->assign( 'header', ldl_get_header( 0, 1 ) );
    $tpl->assign( 'home', remove_query_arg( array( 'show', 't' ) ) );


    $valid = false;

    $data = array();
    $urls = array();
    $errors = array();

    $success = false;

    if ( isset( $_POST['nonce_field'] ) && !empty( $_POST['nonce_field'] ) ) {

        if ( !wp_verify_nonce( $_POST['nonce_field'], 'submit-listing-nonce' ) || !empty( $_POST['ld_s_summary'] ) )
            die( "No, kitty! That's a bad kitty!" );

        $data = ldl_sanitize__post( $_POST );
        $valid = ldl_submit_validate_form( $data );

        if ( is_wp_error( $valid ) ) {

            $errors = array();
            $codes = $valid->get_error_codes();

            foreach ( $codes as $code ) {
                $key = substr( $code, 0, strrpos( $code, '_' ) );
                $errors[ $key ] = '<span class="fa fa-exclamation form-control-feedback"></span><span class="submit-error">' . $valid->get_error_message( $code ) . '</span>';
            }

        } else {

            // Create the user and insert a post for this listing
            if ( is_user_logged_in() ) {
                $user_id = get_current_user_id();
            } else {
                $user_id = ldl_submit__create_user( $data['username'], $data['email'] );
            }
            $post_id = ldl_submit__create_listing( $data['title'], $data['description'], $data['category'], $user_id );

            // Add all the post meta fields
            ldl_submit__create_meta( $data, $post_id );

            // Upload their logo if one was submitted
            if ( isset( $_FILES['ld_s_logo'] ) ) {
                // These files need to be included as dependencies when on the front end.
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );

                $attachment_id = media_handle_upload( 'ld_s_logo', 0 );
                set_post_thumbnail( $post_id, $attachment_id );
            }

            // Set these up so that they can be used on the success page as {{listing.field}}
            foreach ( $data as $key => $value ) {
                $data[ $key ] = htmlentities( $value );
            }

            ldl_submit__email_admin( $data, $post_id );
            ldl_submit__email_owner( $data );

            $success = true;
            $data = array();

        }

    }

    $tpl->assign( 'form_action', 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
    $tpl->assign( 'ajaxurl', admin_url( 'admin-ajax.php' ) );
    $tpl->assign( 'nonce', wp_nonce_field( 'submit-listing-nonce','nonce_field', 1, 0 ) );

    $settings =   array(
        'wpautop' => true,
        'media_buttons' => false,
        'textarea_name' => 'ld_s_description',
        'textarea_rows' => 4,
        'tabindex' => '',
        'editor_class' => 'form-control',
        'teeny' => true,
    );


    $category_args = array(
        'hide_empty'    => 0,
        'echo'          => 0,
        'selected'      => isset( $data['category'] ) ? $data['category'] : 0,
        'hierarchical'  => 1,
        'name'          => 'ld_s_category',
        'id'            => 'category',
        'class'         => 'form-control',
        'tab_index'     => 2,
        'taxonomy'      => LDDLITE_TAX_CAT,
    );

    $tpl->assign( 'allowed_tags', allowed_tags() );
    $tpl->assign( 'category_dropdown', wp_dropdown_categories( $category_args ) );

    $tpl->assign( 'use_tos', ldl_get_setting( 'submit_use_tos' ) );
    $tpl->assign( 'tos', ldl_get_setting( 'submit_tos' ) );


    if ( !empty( $data ) ) {

        if ( isset( $data['url'] ) ) {
            $urls = $data['url'];
            unset( $data['url'] );

        }

        $data = array_map( 'htmlentities', $data );
    }

    $tpl->assign( 'data', $data );
    $tpl->assign( 'errors', $errors );
    $tpl->assign( 'success', $success );

    $panel_general   = $tpl->draw( 'submit-general', 1 );
    $panel_geography = $tpl->draw( 'submit-geography', 1 );
    $panel_urls      = $tpl->draw( 'submit-urls', 1 );
    if ( !is_user_logged_in() )
        $panel_account   = $tpl->draw( 'submit-account', 1 );

    $tpl->assign( 'panel_general',   $panel_general );
    $tpl->assign( 'panel_geography', $panel_geography );
    $tpl->assign( 'panel_urls',      $panel_urls );
    $tpl->assign( 'panel_account',   $panel_account );



    return $tpl->draw( 'submit', 1 );

}


function ldl_shortcode__submit() {
    global $post;

    ldl_enqueue();

    wp_enqueue_script( 'lddlite-responsiveslides' );
    wp_enqueue_script( 'lddlite-submit' );

    $valid = false;

    $data = array();
    $urls = array();
    $errors = array();

    $success = false;

    if ( isset( $_POST['nonce_field'] ) && !empty( $_POST['nonce_field'] ) ) {

        if ( !wp_verify_nonce( $_POST['nonce_field'], 'submit-listing-nonce' ) || !empty( $_POST['ld_s_summary'] ) )
            die( "No, kitty! That's a bad kitty!" );

        ldl_submit__process( $_POST );

    }

    $category_args = array(
        'hide_empty'    => 0,
        'echo'          => 0,
        'selected'      => isset( $data['category'] ) ? $data['category'] : 0,
        'hierarchical'  => 1,
        'name'          => 'ld_s_category',
        'id'            => 'category',
        'class'         => 'form-control',
        'tab_index'     => 2,
        'taxonomy'      => LDDLITE_TAX_CAT,
    );
    set_query_var( 'category_args', $category_args );


    if ( !empty( $data ) ) {
        if ( isset( $data['url'] ) ) {
            $urls = $data['url'];
            unset( $data['url'] );
        }

        $data = array_map( 'htmlentities', $data );
    }

    set_query_var( 'data', $data );
    set_query_var( 'errors', $errors );
    set_query_var( 'success', $success );

    ldl_get_template_part( 'submit' );

}

add_shortcode( 'lddlite_submit', 'ldl_shortcode__submit' );
