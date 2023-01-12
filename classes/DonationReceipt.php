<?php
require_once 'ModelLite.php';
require_once 'Donation.php';

class DonationReceipt extends ModelLite {
    protected $table = 'donation_receipt';
	protected $primaryKey = 'ReceiptId';
	### Fields that can be passed 
    protected $fillable = ["DonorId","KeyType","KeyId","Type","Address","DateSent","Content"];	    
	### Default Values
	protected $attributes = [        
        'KeyType' => 'Donation',
        'tType'=> 'e',
	];
	//public $incrementing = true;
    const CREATED_AT = 'DateSent';
    const UPDATED_AT = 'DateSent';

    protected $tinyIntDescriptions=[
        "Type"=>["e"=>"Email","p"=>"Pdf/Postal Mail"],        
    ];
  
    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
          $sql="CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
            `ReceiptId` int(11) NOT NULL AUTO_INCREMENT,
            `DonorId` int(11) NOT NULL COMMENT 'Donation/YearEnd',
            `KeyType` varchar(20) NOT NULL,
            `KeyId` int(11) NOT NULL COMMENT 'DonationId or Year',
            `Type` varchar(1) NOT NULL COMMENT 'e=email p=postal mail',
            `Address` text NOT NULL COMMENT 'email or postal address',
            `DateSent` datetime NOT NULL,
            `Content` text,
            PRIMARY KEY (`ReceiptId`)
            )"; 
          dbDelta( $sql );
    }

    function show_type(){
        if ($this->Type=="e") return "Email"; 
        elseif ($this->Type=="m") return "Mail";
        else return $this->Type;
    }

    static function displayReceipts($array,$columns=[]){
        if (!$array || sizeof($array)==0) return;
        $return="<table><tr><th>Type</th><th>Address</th><th>DateSent</th></tr>";
        foreach($array as $r){
            $return.="<tr><td>".$r->show_type()."</td><td>".$r->Address."</td><td>".$r->DateSent."</td></tr>";
        }
        $return.="</table>";
        return $return;
    }
}