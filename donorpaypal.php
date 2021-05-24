<?php
/*
    Plugin Name: Donor Tracker With Paypal Integration
    Plugin URI: https://denversteiner.com
    Description: A plugin for non-profits used to track donations. This integrates with Paypal as well as allows for manual entry.
    Author: Denver Steiner
    Author URI: https://denversteiner.com/wp-plugins/donorTracker
    Version: 1.0.0
*/

require_once('classes/Donation.php');
require_once('classes/Donor.php');
require_once('classes/DonationCategory.php');
require_once('vendor/TCPDF/TCPDF.php');

/* Resources: 
https://www.sitepoint.com/working-with-databases-in-wordpress/

*/
// it inserts the entry in the admin menu
add_action('admin_menu', 'donor_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'donor_plugin_create_tables' );

// creating the menu entries
function donor_plugin_create_menu_entry() {
	// icon image path that will appear in the menu
	$icon = plugins_url('/images/empy-plugin-icon-16.png', __FILE__);
	//add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	// adding the main manu entry
	add_menu_page('Donors', 'Donors', 'edit_posts', 'donor-index', 'donor_show_index', $icon);
	// adding the sub menu entry
	add_submenu_page( 'donor-index', 'Reports', 'Reports', 'edit_posts', 'donor-reports', 'donor_show_reports' );

}

// function triggered in add_menu_page
function donor_show_index() {
	include('donor-index.php');
}

// function triggered in add_submenu_page
function donor_show_reports() {
	include('donor-reports.php');
}


function load_initial_data(){
	global $wpdb;
	//$wpdb->query("TRUNCATE ".Donation::getTableName());	

	// $result=Donation::csvReadFile("2015-2018.CSV",$firstLineColumns=true);
	// print "<pre>"; print_r($result); print "</pre>";
	// print_r(Donation::replaceIntoList($result));

	// $result=Donation::csvReadFile("2019-2020-05-23.CSV",$firstLineColumns=true);
	// print "<pre>"; print_r($result); print "</pre>";
	// print_r(Donation::replaceIntoList($result));

	// $result=Donation::csvReadFile("2020-05-24-06-20.CSV",$firstLineColumns=true);
	// print "<pre>"; print_r($result); print "</pre>";
	// print_r(Donation::replaceIntoList($result));
	### Setup Required Template pages if they don't exists
	Donor::makeReceiptYearPageTemplate();
	
}

function nuke(){
	global $wpdb;
	$wpdb->show_errors();
	### used in testing to wipe out everything and recreate blank
	$wpdb->query("DROP TABLE IF EXISTS ".Donor::getTableName());
	$wpdb->query("DROP TABLE IF EXISTS ".Donation::getTableName());
	$wpdb->query("DROP TABLE IF EXISTS ".DonationCategory::getTableName());
	donor_plugin_create_tables();
}

function donor_plugin_create_tables() {	
	Donor::createTable();
	Donation::createTable();
	DonationCategory::createTable();

}