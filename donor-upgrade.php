<?php 
function donor_press_upgrade(){
	global $donor_press_db_version;
	$current_db_version=get_option( "donor_press_db_version");
	
	if (!$current_db_version || $current_db_version<'0.0.3'){
		donor_press_upgrade_003();
	}
	
	if ($current_db_version<'0.0.4'){ 
		donor_press_upgrade_004();
	}

	if ($current_db_version){
		update_option( "donor_press_db_version", $donor_press_db_version );
	}else{
		add_option( "donor_press_db_version", $donor_press_db_version );
	}
	Donor::display_notice("Donor Press Database Upgraded from ".$current_db_version." to ".$donor_press_db_version);

}

function donor_press_upgrade_003(){
	DonorType::create_table();
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".Donor::get_table_name()."` ADD COLUMN `TypeId` NOT NULL DEFAULT '0' AFTER `Country`;";
	$wpdb->query( $aSQL );
	$aSQL="ALTER TABLE `".Donation::get_table_name()."` CHANGE `NotTaxDeductible` `TransactionType` INT NULL DEFAULT '0' COMMENT '0=TaxExempt 1=NotTaxExcempt 2=Service -1=Expense';";
	$wpdb->query(  $aSQL );
}

function donor_press_upgrade_004(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".DonorType::get_table_name()."` CHANGE `QBAccountId` `QBItemId` INT NULL DEFAULT '0';";
	$wpdb->query( $aSQL );	
	$aSQL="ALTER TABLE `".Donation::get_table_name()."` ADD COLUMN `QBOPaymentId` INT NULL DEFAULT NULL AFTER `QBOInvoiceId`;";	
	$wpdb->query( $aSQL );
}

function donor_press_upgrade_005(){
	$aSQL="ALTER TABLE `".Donation::get_table_name()."`
	CHANGE COLUMN `Gross` `Gross` DECIMAL(10,2) NOT NULL ,
	CHANGE COLUMN `Net` `Net` DECIMAL(10,2) NULL DEFAULT NULL ;";
	$wpdb->query( $aSQL );	
}
