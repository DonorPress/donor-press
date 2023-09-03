<?php 
function donor_press_upgrade(){
	global $donor_press_db_version;
	$current_db_version=get_option( "donor_press_db_version");

	if (!$current_db_version || donor_upgrade_version_value($current_db_version)<donor_upgrade_version_value('0.0.3')){
		donor_press_upgrade_003();
	}

	if (donor_upgrade_version_value($current_db_version)<donor_upgrade_version_value('0.0.4')){ 
		donor_press_upgrade_004();
	}
	if (donor_upgrade_version_value($current_db_version)<donor_upgrade_version_value('0.0.5')){ 
		donor_press_upgrade_005();
	}

	if (donor_upgrade_version_value($current_db_version)<donor_upgrade_version_value('0.0.6')){ 
		donor_press_upgrade_006();
	}

	if (donor_upgrade_version_value($current_db_version)<donor_upgrade_version_value('0.0.7')){ 
		donor_press_upgrade_007();
	}

	if ($current_db_version){
		update_option( "donor_press_db_version", $donor_press_db_version );
	}else{
		add_option( "donor_press_db_version", $donor_press_db_version );
	}
	Donor::display_notice("Donor Press Database Upgraded from ".$current_db_version." to ".$donor_press_db_version);
}

//function version_check($current_db_version,)
function donor_upgrade_version_value($version){
	//example: 1.2.3 = 1*100^2+2*100^1+3*100^0=10203  1.2.30 = 10230. This assumes a max max of two digts. 99.99.99=999999
	$segment=explode(".",$version);
	$sum=0;
	for($i=0;$i<sizeof($segment);$i++){
		$sum+=$segment[$i]*pow(100,sizeof($segment)-$i-1);
	}
	return $sum;
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
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".Donation::get_table_name()."`
	CHANGE COLUMN `Gross` `Gross` DECIMAL(10,2) NOT NULL ,
	CHANGE COLUMN `Net` `Net` DECIMAL(10,2) NULL DEFAULT NULL ;";
	$wpdb->query( $aSQL );	
}

function donor_press_upgrade_006(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".DonationReceipt::get_table_name()."`
	ADD COLUMN `Subject` varchar(256);";
	$wpdb->query( $aSQL );	
}

function donor_press_upgrade_007(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".DonationCategory::get_table_name()."`
	ADD COLUMN `TransactionType` INT NULL DEFAULT NULL AFTER `TemplateId`;";
	$wpdb->query( $aSQL );	
}
