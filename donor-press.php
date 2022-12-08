<?php
/*
    Plugin Name: Donor Press - A Donation Tracking System
    Plugin URI: https://denversteiner.com/wp-plugins/donorpress
    Description: A plugin for non-profits used to track donations and send donation acknowledgements and year end receipts. This integrates with Paypal as well as allows for manual entry.
    Author: Denver Steiner
    Author URI: https://denversteiner.com/wp-plugins/donorpress
    Version: 0.1.0
*/

require_once __DIR__ . '/vendor/autoload.php';
require_once 'classes/Donation.php';
require_once 'classes/Donor.php';
require_once 'classes/DonationCategory.php';
require_once 'classes/DonorTemplate.php';
require_once 'classes/CustomVariables.php'; 

/* Resources: 
https://www.sitepoint.com/working-with-databases-in-wordpress/
https://webdesign.tutsplus.com/tutorials/create-a-custom-wordpress-plugin-from-scratch--net-2668
*/
// it inserts the entry in the admin menu
//wp_enqueue_style( 'style', get_stylesheet_uri() );
add_action('admin_menu', 'donor_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'donor_plugin_create_tables' );

// creating the menu entries
function donor_plugin_create_menu_entry() {
	// icon image path that will appear in the menu
	$icon = plugins_url('/images/DonorPressWPIcon2.svg', __FILE__);
	//add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	// adding the main manu entry
	add_menu_page('Donors', 'Donors', 'edit_posts', 'donor-index', 'donor_show_index', $icon);
	// adding the sub menu entry
	add_submenu_page( 'donor-index', 'Reports', 'Reports', 'edit_posts', 'donor-reports', 'donor_show_reports',2 );
	add_submenu_page( 'donor-index', 'Settings', 'Settings', 'edit_posts', 'donor-settings', 'donor_show_settings',3);
	add_submenu_page( 'donor-index', 'Paypal', 'Paypal', 'edit_posts', 'donor-paypal', 'donor_show_paypal',4);
}


## Search record
add_action( 'wp_ajax_searchDonorList', 'searchDonorList_callback' );
add_action( 'wp_ajax_nopriv_searchDonorList', 'searchDonorList_callback' );
add_action("init", "ur_theme_start_session", 1);


// function wpdocs_register_plugin_styles() {
// 	wp_register_style( 'donor-press', plugins_url( 'donor-press/css/style.css' ) );
// 	wp_enqueue_style( 'donor-press' );
// }
// // Register style sheet.
// add_action( 'wp_enqueue_scripts', 'wpdocs_register_plugin_styles' );

function donor_press_styles() {
    wp_enqueue_style( 'donor-press-styles',  plugin_dir_url( __FILE__ ) . '/css/style.css');                      
}
add_action( 'wp_enqueue_scripts', 'donor_press_styles' );


function ur_theme_start_session()
{
	if (!session_id())
        session_start();
}



function searchDonorList_callback() {
	$request = $_POST['request'];
	$searchText = strtoupper($_POST['searchText']);
	print json_encode(Donor::get(array("(UPPER(Name) LIKE '%".$searchText."%' 
	OR UPPER(Name2)  LIKE '%".$searchText."%'
	OR UPPER(Email) LIKE '%".$searchText."%'
	OR UPPER(Phone) LIKE '%".$searchText."%')"
	,"(MergedId =0 OR MergedId IS NULL)")));
   	wp_die(); 
}

// function triggered in add_menu_page
function donor_show_index() {
	include('donor-index.php');
}

// function triggered in add_submenu_page
function donor_show_reports() {
	include('donor-reports.php');
}

function donor_show_settings() {
	include('donor-settings.php');
}

function donor_show_paypal() {
	include('donor-paypal.php');
}

function dn_plugin_base_dir(){
	return str_replace("\\","/",dirname(__FILE__));
}

function load_initial_data(){
	global $wpdb;
	//$wpdb->query("TRUNCATE ".Donation::get_table_name());
	// $result=Donation::cvs_read_file("2015-2018.CSV",$firstLineColumns=true);
	// print "<pre>"; print_r($result); print "</pre>";
	// print_r(Donation::replaceIntoList($result));
	Donor::makeReceiptYearPageTemplate();
}

function nuke(){
	global $wpdb;
	$wpdb->show_errors();
	### used in testing to wipe out everything and recreate blank
	$wpdb->query("DROP TABLE IF EXISTS ".Donor::get_table_name());
	$wpdb->query("DROP TABLE IF EXISTS ".Donation::get_table_name());
	$wpdb->query("DROP TABLE IF EXISTS ".DonationReceipt::get_table_name());
	$wpdb->query("DROP TABLE IF EXISTS ".DonationCategory::get_table_name());
	donor_plugin_create_tables();
}

function donor_plugin_create_tables() {	
	Donor::create_table();
	Donation::create_table();
	DonationReceipt::create_table();
	DonationCategory::create_table();
}