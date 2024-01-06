<?php
/*
    Plugin Name: Donor Press - A Donation Tracking System
    Plugin URI: https://donorpress.com/
    Description: A plugin for non-profits used to track donations and send donation acknowledgements and year end receipts. This integrates with Paypal as well as allows for manual entry. Syncing data to Quickbooks is also in beta.
    Author: Denver Steiner
    Author URI: https://donorpress.com/author/
    Version: 0.0.9
*/
global $donor_press_db_version;
$donor_press_db_version='0.0.9';

### recommended to run "composer install" on the plugin directory to add PDF and other functionality, but not required
if (file_exists(__DIR__ . '/vendor/autoload.php')){
	require_once __DIR__ . '/vendor/autoload.php';
}
require_once 'classes/Donation.php';
require_once 'classes/Donor.php';
require_once 'classes/DonorType.php';
require_once 'classes/DonationCategory.php';
require_once 'classes/DonationUpload.php';
require_once 'classes/DonorTemplate.php';
require_once 'classes/CustomVariables.php'; 
require_once 'classes/QuickBooks.php';
require_once 'classes/Paypal.php';
/* Resources: 
https://www.sitepoint.com/working-with-databases-in-wordpress/
https://webdesign.tutsplus.com/tutorials/create-a-custom-wordpress-plugin-from-scratch--net-2668
*/
// it inserts the entry in the admin menu
//wp_enqueue_style( 'style', get_stylesheet_uri() );
add_action('admin_menu', 'donor_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'donor_plugin_create_tables' );



function donor_header_check() {
	global $donor_press_db_version;
	if (!session_id()) session_start();

	require_once "donor-upgrade.php";	
	
	## When Code base is updated, make sure database upgrade is run
	if (get_option( "donor_press_db_version")!=$donor_press_db_version){
		require_once "donor-upgrade.php";
		donor_press_upgrade();
	}

	if (Donor::input('redirect','get')){
		$qb=new QuickBooks();
		$qb->check_redirects(Donor::input('redirect','get'));
	}
	## download functions before page is loaded
	if (Donor::input('Function')){
		switch(Donor::input('Function')){
			case "pdfTemplatePreview":
				$template=new DonorTemplate($_POST);
            	$template->post_excerpt=DonorTemplate::post_to_settings($_POST);
				$template->settings_decode();
				$template->pdf_preview(); 
			break;
			case "DonationReceiptPdf":
				$donation=Donation::find(Donor::input('DonationId','request'));	
				$donation->pdf_receipt(stripslashes_deep(Donor::input('customMessage','post')));
			break;	
			case 'BackupDonorPress':		
				CustomVariables::backup(true);
				break;
			break;
			case "YearEndReceiptPdf":
				$donor=Donor::find(Donor::input('DonorId','request'));
				$donor->year_receipt_pdf(Donor::input('Year'),stripslashes_deep(Donor::input('customMessage')));
				break;
			case 'SendYearEndPdf':
				Donor::YearEndReceiptMultiple(Donor::input('Year'),Donor::input('pdf','post'),Donor::input('limit'),Donor::input('blankBack','request'),Donor::input('preview','request')?false:true);
			break;
			case 'PdfLabelDonationReceipts':			
				Donation::label_by_id(Donor::input('EmailDonationId','post'),Donor::input('col','post'),Donor::input('row','post'),Donor::input('limit'));
			break;
			case 'PrintYearEndLabels':
				Donor::YearEndLabels(Donor::input('Year'),Donor::input('pdf','post'),Donor::input('col','post'),Donor::input('row','post'),Donor::input('limit'));
			break;
			case 'ExportAllDonors':
				Donor::get_mail_list();
			break;
			case 'ExportDonorList':
				Donor::get_mail_list(["D.DonorId IN (".implode(",",Donor::input('pdf','post')).")"]);
			break;
			case 'DonationListCsv':
				Donation::report();
			break;

			case 'QuickbookSessionKill';
				$qb=new QuickBooks();
				$qb->clearSession();
				return header("Location: ?page=donor-quickbooks");
			break;
		}
	}

	if (Donor::input('donorAutocomplete','get')){
		Donor::autocomplete(Donor::input('query','get'));
		exit();
	}
	## add style
	wp_enqueue_style('donorPressPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ), false);
	//wp_enqueue_style('donorPressPluginAutoComplete', plugins_url( '/css/autocomplete.min.css', __FILE__ ), false);
	wp_enqueue_script('donorPressPluginDefault', plugins_url( '/js/donorpress.js', __FILE__ ), false);
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
	if (Quickbooks::is_setup())	add_submenu_page( 'donor-index', 'QuickBooks', 'QuickBooks', 'edit_posts', 'donor-quickbooks', 'donor_show_quickbooks',4);
	if (Paypal::is_setup()) add_submenu_page( 'donor-index', 'Paypal', 'Paypal', 'edit_posts', 'donor-paypal', 'donor_show_paypal',4);
	add_submenu_page( 'donor-index', 'Settings', 'Settings', 'edit_posts', 'donor-settings', 'donor_show_settings',5);
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
	Donor::make_receipt_year_template();
}

function donor_press_tables(){
	return ["Donor","Donation","DonationReceipt","DonationCategory","DonorType"];
}


function nuke(){
	CustomVariables::nuke_it(['droptable'=>"t",'dropfields'=>"t",'rebuild'=>"t"]);
}

function donor_plugin_create_tables() {	
	global $donor_press_db_version;
	$tableNames=donor_press_tables();
	foreach($tableNames as $table){
		$table::create_table();
	}
	add_option( "donor_press_db_version", $donor_press_db_version );
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

function loadTestData($count=20){
	if (!class_exists("Faker\Factory")){
		Donor::display_error("Faker Class is not installed. You must run 'composer install' on the donor-press plugin directory to get this to function.");         
		//    
		return false;
	}
	for($i=0;$i<$count;$i++){
		$faker = Faker\Factory::create();
		$donor=new Donor();
		$primarySex=$faker->numberBetween(0, 1);
		$single=$faker->numberBetween(0, 1);
		$lastName=$faker->lastName;
		
		$donor->Name=($primarySex==0?$faker->firstNameMale:$faker->firstNameFemale) . ' ' .$lastName ;
		if ($single==1){
			$donor->Name2= ($primarySex==1?$faker->firstNameMale:$faker->firstNameFemale) . ' ' .$lastName;
		}
		
		$donor->Email=$faker->email;
		$donor->Address1=$faker->streetAddress;
		if($faker->numberBetween(0, 10)==1){
			$donor->Address2=$faker->secondaryAddress;
		}
		$donor->City=$faker->city;
		$donor->Region=$faker->stateAbbr;
		$donor->PostalCode=$faker->postcode;
		$donor->Country='US';
		$donor->Phone=$faker->phoneNumber;
		$donor->Email=$faker->email;
		$donor->AddressStatus=1;
		$donor->EmailStatus=1;
		if($faker->numberBetween(0, 20)==1){
			$donor->AddressStatus=-1;
		}
		if($faker->numberBetween(0, 20)==1){
			$donor->EmailStatus=-1;
		}
		
		$donor->save();
		### add one time donations
		$donationTotal=$faker->numberBetween(1, 7);
		for($d=0;$d< $donationTotal;$d++){
			$donation=new Donation();
			$donation->DonorId=$donor->DonorId;
			$donation->Date=$faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d');
			$donation->DateDeposited=date("Y-m-d",strtotime($donation->Date." +".$faker->numberBetween(0, 10)." days"));
			$donation->CreatedAt=date("Y-m-d H:i:s",strtotime($donation->DateDeposited." +".$faker->numberBetween(0, 60*24)." minutes"));
			$donation->UpdatedAt=$donation->CreatedAt;
			$donation->Name=$donor->Name;
			$donation->Gross=$faker->numberBetween(2, 10, 1000);
			$donation->Fee=0;
			$donation->Net=$donation->Gross;
			$donation->Currency=$faker->currencyCode;
			$donation->PaymentSource='USD'; 
			$donation->TransactionType=0;//set to tax deductible
			$donation->TransactionID=$faker->numberBetween(100, 2000);
			$donation->Type=1;
			if ($faker->numberBetween(0, 3)==1){
				$donation->note="Note from Donor";//$faker->sentence();
			}
			
			$donation->save();
		}
		$monthlyGift=$faker->numberBetween(0, 1);
		if ($monthlyGift==1){			
			$startDate=$faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d');
			$amount=$faker->numberBetween(2, 40)*5;
			/* Add monthly giving from that start date till now */
			$months=round((strtotime(date("Y-m-d"))-strtotime($startDate))/(60*60*24*30));
			for($m=0;$m<$months;$m++){				
				$donation=new Donation();
				$donation->DonorId=$donor->DonorId;
				$donation->Date=date("Y-m-d",strtotime($startDate." +".$m." months"));
				$donation->DateDeposited=date("Y-m-d",strtotime($donation->Date." +".$faker->numberBetween(0, 10)." days"));
				$donation->CreatedAt=date("Y-m-d H:i:s",strtotime($donation->DateDeposited." +".$faker->numberBetween(0, 60*24)." minutes"));
				$donation->UpdatedAt=$donation->CreatedAt;
				$donation->Name=$donor->Name;
				$donation->Gross=$amount;
				$donation->Fee=round($amount*.03,2)*-1;
				$donation->Net=$donation->Gross+$donation->Fee;
				$donation->Currency='USD';
				$donation->FromEmailAddress=$donor->Email;
				$donation->PaymentSource=10; //set to check
				$donation->TransactionType=0;//set to tax deductible
				$donation->TransactionID= bin2hex(random_bytes(5));	
				$donation->Type=5; //subscription
				$donation->save();
			}
		}
		

	}
}



