<?php
/*
    Plugin Name: Donor Press - A Donation Tracking System
    Plugin URI: https://denversteiner.com/donorpress/
    Description: A plugin for non-profits used to track donations and send donation acknowledgements and year end receipts. This integrates with Paypal as well as allows for manual entry. Syncing data to Quickbooks is also in beta.
    Author: Denver Steiner
    Author URI: https://denversteiner.com/donorpress/
    Version: 0.0.2
*/
### recommended to run "composer install" on the plugin directory to add PDF and other functionality, but not required
if (file_exists(__DIR__ . '/vendor/autoload.php')){
	require_once __DIR__ . '/vendor/autoload.php';
}
require_once 'classes/Donation.php';
require_once 'classes/Donor.php';
require_once 'classes/DonationCategory.php';
require_once 'classes/DonorTemplate.php';
require_once 'classes/CustomVariables.php'; 
require_once 'classes/QuickBooks.php';
/* Resources: 
https://www.sitepoint.com/working-with-databases-in-wordpress/
https://webdesign.tutsplus.com/tutorials/create-a-custom-wordpress-plugin-from-scratch--net-2668
*/
// it inserts the entry in the admin menu
//wp_enqueue_style( 'style', get_stylesheet_uri() );
add_action('admin_menu', 'donor_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'donor_plugin_create_tables' );



function donor_header_check() {
	if (!session_id()) session_start();	
	if ($_GET['redirect']){
		$qb=new Quickbooks();
		$qb->check_redirects($_GET['redirect']);
	}
	## download functions before page is loaded
	if ($_POST['Function']=="DonationReceiptPdf"){
		$donation=Donation::get_by_id($_REQUEST['DonationId']);	
		$donation->pdf_receipt(stripslashes_deep($_POST['customMessage']));      
	}
	## add style
	wp_enqueue_style('donorPressPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ), false);
}

//wp_register_style( 'donorPressPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ) );
add_action( 'admin_init', 'donor_header_check',1);




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
	add_submenu_page( 'donor-index', 'QuickBooks', 'QuickBooks', 'edit_posts', 'donor-quickbooks', 'donor_show_quickbooks',4);
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

function donor_show_quickbooks() {
	include('donor-quickbooks.php');
}


function dn_plugin_base_dir(){
	return str_replace("\\","/",dirname(__FILE__));
}

function load_initial_data(){
	Donation::db()->query("TRUNCATE ".Donation::get_table_name());	
	Donor::make_receipt_year_template();
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


function generate_email_list(){
	print "I am here"; exit();
	Donor::get_email_list();
}




## Search record
//add_action( 'wp_ajax_searchDonorList', 'searchDonorList_callback' );
//add_action( 'wp_ajax_nopriv_searchDonorList', 'searchDonorList_callback' );
// add_action("init", "ur_theme_start_session", 1);
// function ur_theme_start_session(){
// 	if (!session_id()) session_start();
// }

// function wpdocs_register_plugin_styles() {
// 	wp_register_style( 'donor-press', plugins_url( 'donor-press/css/style.css' ) );
// 	wp_enqueue_style( 'donor-press' );
// }
// // Register style sheet.
// add_action( 'wp_enqueue_scripts', 'wpdocs_register_plugin_styles' );



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
