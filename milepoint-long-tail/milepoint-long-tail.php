<?php
/*
Plugin Name: MilePoint Long-Tail
Description: Captures Gist AI chats and transforms them into SEO posts.
Version: 1.0
Author: pguardiario@gmail.com
*/

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-rest-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-content-template.php';

// Initialize display template
new MP_Content_Template();

// Initialize the REST API
add_action( 'rest_api_init', function() {
    $rest_handler = new MP_REST_Handler();
    $rest_handler->register_routes();
});


// Register the QA Custom Post Type
add_action( 'init', 'mp_register_qa_cpt' );

function mp_register_qa_cpt() {
    register_post_type('milepoint_qa', array(
        'labels'      => array(
            'name'          => 'Reader Q&A',
            'singular_name' => 'Q&A Article',
            'add_new'       => 'Add New Q&A'
        ),
        'public'             => true,
        'has_archive' => 'q-and-a', // The directory: milepoint.com/q-and-a/
        'rewrite'     => array('slug' => 'q-and-a'),
        'show_in_rest'       => true, // what does this do?
        'supports'           => array('title', 'editor', 'excerpt', 'custom-fields'),
        'menu_icon'          => 'dashicons-format-chat',
    ));
}


// Enqueue JS listener on the chat page
add_action('wp_enqueue_scripts', function() {
    // Check if we are on the page with the slug 'chat'
    if ( is_page('chat') ) {
        wp_enqueue_script(
            'mp-longtail-bridge',
            plugins_url('/assets/js/bridge-listener.js', __FILE__),
            array(),
            time(), // Cache busting for now
            true
        );

        // localize the url and the nonce
        wp_localize_script('mp-longtail-bridge', 'mpData', array(
            'rest_url' => esc_url_raw( rest_url( 'milepoint-v1/generate-post' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' )
        ));
    }
});

