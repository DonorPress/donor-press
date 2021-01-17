<?php
require_once("Donor.php");
require_once("DonationCategory.php");
class Donation extends ModelLite
{
    protected $table = 'Donation';
	protected $primaryKey = 'DonationId';
	### Fields that can be passed //,"Time","TimeZone"
    protected $fillable = ["Date","DateDeposited","DonorId","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","TransactionID","AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber","Subject","Note","PaymentSource"];	 

    protected $paypal = ["Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","From Email Address","To Email Address","Transaction ID","Address Status","Item Title","Item ID","Option 1 Name","Option 1 Value","Option 2 Name","Option 2 Value","Reference Txn ID","Invoice Number","Custom Number","Quantity","Receipt ID","Balance","Contact Phone Number","Subject","Note","Payment Source"];
    protected $csvHeaders = ["DepositDate","CheckDate","CheckNumber","Name1","Name2","Gross","Account","ReceiptNeeded","Note","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];

    protected $duplicateCheck=["Date","Gross","FromEmailAddress","TransactionID"]; //check these fields before reinserting a matching entry.
   
    protected $tinyIntDescriptions=[
        "Status"=>["9"=>"Completed","7"=>"Pending","0"=>"Unknown","-1"=>"Deleted","-2"=>"Denied"],
        "AddressStatus"=>[0=>"Not Set",-1=>"Non-Confirmed",1=>"Confirmed"],
        "PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"PayPal"],
        "Type"=>[0=>"Other",1=>"Donation Payment",2=>"Website Payment",5=>"Subscription Payment",-2=>"General Currency Conversion",-1=>"General Withdrawal"],
        "Currency"=>["USD"=>"USD","CAD"=>"CAD","GBP"=>"GBP","EUR"=>"EUR","AUD"=>"AUD"]
    ];

    ### Default Values
	protected $attributes = [        
        'Currency' => 'USD',
        'Type'=>'1',
        'Status'=>'9',
        'AddressStatus'=>1,
        'PaymentSource'=>0
	];

    // public $incrementing = true;
    const DONATION_TO_DONOR = ['Name'=>'Name','Name2'=>'Name2','FromEmailAddress'=>'Email','ContactPhoneNumber'=>'Phone','Address1'=>'Address1', 'Address2'=>'Address2','City'=>'City','Region'=>'Region',	'PostalCode'=>'PostalCode',	'Country'=>'Country'];

	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
    
    
    public function donationToDonor($override=array()){
        ### When uploading new dontations, this will find existings donors, or create new ones automatically.
        global $wpdb,$donationToDonor; //cache donor lookups to speed up larger imports.       
        if ($this->DonorId>0){
            $this->suggestDonorChanges($override);
            return $this->DonorId;
        } 
        if (!$this->Name && $this->FromEmailAddress){ //if no name, but a email address (often the case for withdrawel account)
            $this->Name= $this->FromEmailAddress;
        }
        if (!$this->Name && $override['Name']){
            $this->Name=$override['Name'];
        }
        if (!$this->Name && $override['Email']){
            $this->Name=$override['Email'];
        }
        
        ## Attempt to Match on these key fields first.
        $matchFields=['Email'=>'FromEmailAddress','Name'=>'Name','Name2'=>'Name']; //,'Phone'=>'ContactPhoneNumber'
       
        foreach($matchFields as $donorField=>$donationField){

            if (trim($this->$donationField)){
                if ($donationToDonor[$donorField][$this->$donationField]){
                    $this->DonorId=$donationToDonor[$donorField][$this->$donationField];
                    $this->suggestDonorChanges($override);
                    return  $this->DonorId;
                }
                switch($donorField){
                    case "Email":
                    case "Name":
                    case "Name1":
                    case "Name2":                        
                        $where="REPLACE(LOWER(`".$donorField."`), ' ', '') = '".addslashes(strtolower(str_replace(" ","",$this->$donationField)))."'";
                    break;
                    default:
                    $where="`".$donorField."` = '".addslashes($this->$donationField)."'";
                    break;
                    // 
                }
                $donor = $wpdb->get_row( $wpdb->prepare( "SELECT DonorId,MergedId FROM ".Donor::s()->getTable()." WHERE ".$where." Order By MergedId" ) );
                // if ($this->Name=="Denver Steiner"){
                //     self::dump($this->Name." - ".$this->FromEmailAddress);
                //     print "<div>SELECT DonorId,MergedId FROM ".Donor::s()->getTable()." WHERE ".$where." Order By MergedId ->". $donor->DonorId." on ".$this->Date."</div>";
                // }
                if ($donor->MergedId>0) $donor->DonorId=$donor->MergedId; //If this entry has been merged, send the merged entry. It's possible the merged entry will have a merged entry, but we don't check for that here. Handle this with a cleanup page.
                if ($donor->DonorId>0){                
                    $donationToDonor[$donorField][$this->$donationField]=$donor->DonorId;
                    $this->DonorId=$donor->DonorId;
                    $this->suggestDonorChanges($override,$donorField."=>".$this->$donationField);
                    return $this->DonorId;
                }           
            }
        }

        ### Insert or Update Entry with existing Values
        $newEntry=[];
        
        ### Pull info from the donation:       
        foreach(self::DONATION_TO_DONOR as $donationField=>$donorField){
            if ($this->$donationField && in_array($donorField,Donor::getFillable())){ //if field passed in AND it is a field on Donor Table
                $newEntry[$donorField]=$this->$donationField;
            }
        }
        ### Pull Info from the override
        if (sizeof($override)>0){
            foreach($override as $field=>$value){
                if ($value && in_array($field,Donor::getFillable())){
                 $newEntry[$field]=$value;
                }
            }
        }
       
        //self::dump($newEntry);
       // self::dd($newEntry);
       $newEntry=$this->newDonorEntryPopulateFromDonation($override);
        if (sizeof($newEntry)>0){
            $donor=new Donor($newEntry);
            $donor->save();
            if ($this->Name=="Denver Steiner"){
                print "<h2>Made Id: ".$donor->DonorId."</h2>";
            }
            $this->DonorId=$donor->DonorId;
            return $donor->DonorId;
        }

    }

    public function newDonorEntryPopulateFromDonation($override=array()){
        $newEntry=[];
        
        ### Pull info from the donation:       
        foreach(self::DONATION_TO_DONOR as $donationField=>$donorField){
            if ($this->$donationField && in_array($donorField,Donor::getFillable())){ //if field passed in AND it is a field on Donor Table
                $newEntry[$donorField]=$this->$donationField;
            }
        }
        ### Pull Info from the override
        if (sizeof($override)>0){
            foreach($override as $field=>$value){
                if ($value && in_array($field,Donor::getFillable())){
                 $newEntry[$field]=$value;
                }
            }
        }
        return $newEntry;
    }
    public function suggestDonorChanges($override=array(),$matchOn=""){
        if (!$this->DonorId) return false;
        global $suggestDonorChanges;
        $newEntry=$this->newDonorEntryPopulateFromDonation($override);
        if ($this->DonorId){ //first pull in exising values            
            $donor=Donor::get(array('DonorId='.$this->DonorId));
            if ($donor){
                foreach(Donor::getFillable() as $field){
                    if ($field=="Name" && $newEntry[$field]==$newEntry['Email']){
                        continue; // skip change suggestion if Name was made the e-mail address 
                    }
                    if ($donor[0]->$field!=$newEntry[$field] && $newEntry[$field]){
                        $suggestDonorChanges[$this->DonorId]['Name']['c']=$donor[0]->Name;//Cache this to save a lookup later
                        if($matchOn) $suggestDonorChanges[$this->DonorId]['MatchOn'][]=$matchOn;
                        $suggestDonorChanges[$this->DonorId][$field]['c']=$donor[0]->$field;
                        $suggestDonorChanges[$this->DonorId][$field]['n'][$newEntry[$field]]++; //support multiple differences
                    }                
                }
            }
        }
        return $suggestDonorChanges[$this->DonorId];        
    }

    static public function csvUploadCheck(){
        if(isset($_FILES['fileToUpload'])){
            if ($_POST['nuke']=="true"){
                nuke(); 
            } 
            $originalFile=basename($_FILES["fileToUpload"]["name"]);
            $target_file = $tmpfname = tempnam(sys_get_temp_dir(), 'CSV'); //plugin_dir_path(__FILE__)."uploads/".$csvFile;	
            //print	$target_file;
            if (file_exists($target_file)){ 
                unlink($target_file);
            }
            print '<div class="notice notice-success is-dismissible">';
            if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
                echo "The file ". $originalFile. " has been uploaded.";               
                if ($_REQUEST['submit']=="Upload NonPaypal"){
                    //print "Ran A".$_REQUEST['submit'];
                    $result=self::csvReadFileCheck($target_file,$firstLineColumns=true);
                   
                }else{
                   // print "Ran B".$_REQUEST['submit'];
                    $result=self::csvReadFile($target_file,$firstLineColumns=true);
                   
                }
                
               // print "<pre>"; print_r($result); print "</pre>";
                //exit();
                 if ($stats=self::replaceIntoList($result)){//inserted'=>sizeof($iSQL),'skipped'
                     echo "<div>Inserted ".$stats['inserted']." records. Skipped ".$stats['skipped']." repeats.</div>";
                     unlink($target_file); //don't keep it on the server...
                 }
                 global $suggestDonorChanges;
                 //self::dump($suggestDonorChanges);
                 if (sizeof($suggestDonorChanges)>0){
                     print "<h2>The following changes are suggested</h2><form method='post'>";
                     print "<table border='1'><tr><th>#</th><th>Name</th><th>Change</th></tr>";
                     foreach ($suggestDonorChanges as $donorId => $changes){
                         print "<tr><td><a target='lookup' href='?page=".$_GET['page']."&DonorId=".$donorId."'>".$donorId."</td><td>".$changes['Name']['c']."</td><td>";
                         foreach($changes as $field=>$values){
                            if ($values['n']){                               
                                //krsort($values['n']);
                                $i=0;
                                foreach($values['n'] as $value=>$count){
                                    print "<div><label><input ".($i==0?" checked ":"")." type='checkbox' name='changes[]' value=\"".addslashes($donorId."||".$field."||".$value)."\"/> <strong>".$field.":</strong> ".($values['c']?$values['c']:"(blank)")." -> ".$value.(sizeof($values['n'])>1?" (".$count.")":"")."</label></div>";
                                    $i++;                                    
                                }
                            }                         
                         }
                         print "</td><td>";
                         if ($changes['MatchOn']){
                             print_r($changes['MatchOn']);
                         }
                         print "</td></tr>";

                     }
                     print "</table><button type='submit' name='Function' value='MakeDonorChanges'>Make Donor Changes</button></form>";
                     //self::dump($suggestDonorChanges);

                 }
            } else {
                echo "Sorry, there was an error uploading your file: ".$originalFile;
            }
            print "</div>";
        }
    }

    static public function csvReadFileCheck($csvFile,$firstLineColumns=true){
        $donorMap=["Name1"=>"Name","Name2"=>"Name2","Note"=>"Note"];
        $donationMap=["Name1"=>"Name","Email"=>"FromEmailAddress","DepositDate"=>"DateDeposited","CheckDate"=>"Date","CheckNumber"=>"TransactionID","Name1"=>"Name","Gross"=>"Gross","ReceiptNeeded"=>"ReceiptId"];
        $row=0;
        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                //print "data:"; self::dump($data);
                if ($firstLineColumns && $row==0){
                    $headerRow= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $data);		
                }else{
                    $entry=[];
                    $csvLine=[];
                    $donorFill=[];
                    $donationFill=[];
                    for ($c=0; $c < sizeof($data); $c++) {
                        $fieldName=preg_replace("/[^a-zA-Z0-9]+/", "", $headerRow[$c]);//str_replace(" ","",$headerRow[$c]);                        
                        //$csvLine[$fieldName]=$data[$c];
                        $v=$data[$c];
                        switch($fieldName){
                            case "Date":
                            case "CheckDate":
                            case "DepositDate":
                                $v=date("Y-m-d H:i:s",strtotime($v));
                                break;
                            case "Gross":
                                $v=str_replace(",","",$v);
                                break;
                        }
                        if ($donorMap[$fieldName]){
                            $donorFill[$donorMap[$fieldName]]=$v;
                        }else{
                            $donorFill[$fieldName]=$v; // use actual field name if not called out above - a bit ugly, because more fields then it will use, but will sort itelf out if fillable.
                        }
                        if ($donationMap[$fieldName]){
                            $donationFill[$donationMap[$fieldName]]=$v;
                        }else{
                            $donationFill[$fieldName]=$v;
                        }
                    } 
                    
                    $donationFill['PaymentSource']=1;
                                       
                    $obj=new self($donationFill);             
                   
                    $obj->donationToDonor($donorFill,true);  //Will Set DonorId on Donation Table.    
                    // print "<pre>"; print_r($obj); print "</pre>";
                    // print "<pre>"; print_r($donorFill); print "</pre>";
                    // exit();  
                    //self::dd($obj);         
                    $q[]=$obj;
                }	
                $row++;
            }
            fclose($handle);
        }	
        return $q;

	
	

//Account		
// ReceiptNeeded	TaxReporting	ReceiptId
// Note		                        Note
// Email	        Email	        FromEmailAddress
// Phone	        Phone	
// Address1	        Address1	
// Address2	        Address2	
// City	            City	
// Region	        Region	
// PostalCode	    PostalCode	
// Country	    Country	
// PaymentSource		1

    }

    static public function csvReadFile($csvFile,$firstLineColumns=true){//2019-2020-05-23.CSV
        
        //self::createTable();
        $dbHeaders=self::s()->fillable; 
        $dbHeaders[]="ItemTitle";
        //if ($type=="paypal"){
            $headerRow=self::s()->paypal; 
        // }else{
        //     $headerRow=self::s()->csvHeaders;
        // }
        
        

        $tinyInt=self::s()->tinyIntDescriptions; 
        $row=0;
        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                //print "data:"; self::dump($data);
                if ($firstLineColumns && $row==0){
                    $headerRow= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $data);		
                }else{
                    $entry=[];
                    $paypal=[];
                    for ($c=0; $c < sizeof($data); $c++) {
                        $fieldName=preg_replace("/[^a-zA-Z0-9]+/", "", $headerRow[$c]);//str_replace(" ","",$headerRow[$c]);                        
                        $paypal[$fieldName]=$data[$c];
                    }
                    //self::dump($paypal);
                    foreach($paypal as $fieldName=>$v){
                        if (in_array($fieldName,$dbHeaders)){
                            switch($fieldName){
                                case "Date":
                                    $v=date("Y-m-d H:i:s",strtotime($v." ".$paypal['Time']." ".$paypal['TimeZone']));
                                   // print "<pre>".$v; print_r($paypal); print "</pre>"; exit();
                                    $entry['DateDeposited']=$v;
                                break;
                                case "Gross":
                                case "Fee":
                                case "Net":
                                case "Balance":
                                    $v=str_replace(",","",$v);
                                break;
                                case "AddressStatus":
                                case "Status":
                                case "PaymentSource":    
                                case  "Type":
                                    $check=array_flip($tinyInt[$fieldName]);
                                    if ($check[$v]){
                                        $v=$check[$v];
                                    }else{
                                        if ($fieldName=="Type"){
                                            $entry["TypeOther"]=$v;
                                        }
                                        $v=0;
                                    }         
                                break;
                                case "ItemTitle":
                                    if ($paypal['Subject']==$paypal['ItemTitle']){
                                        $paypal['Subject']=""; //remove redundancy. Wouldn't have to do this if we end up using Subject for something.
                                    }
                                    $entry['CategoryId']=DonationCategory::getCategoryId($paypal['ItemTitle']);
                                    
                                break;
                            }                            
                            $entry[$fieldName]=$v;
                        }									
                    }
                    ## Skip these types of entries.
                    if ($entry['Status']==-2 || $entry['Type']==-2 || 
                    in_array($entry['TypeOther'],array('Hold on Balance for Dispute Investigation','General Currency Conversion','Payment Review Hold','Payment Review Release'))){ //Denied
                       // status = denied or type is currency conversion or type other equals..
                        continue; // skip denied entries.
                    }
                    
                    if (in_array($entry['Type'],array("Payment Refund","Payment Reversal"))){
                        $entry['Name']=''; //Paypal
                        $from= $entry['ToEmailAddress'];//=> donations@masmariposas.org
                        $entry['ToEmailAddress']=$entry['FromEmailAddress'];
                        $entry['FromEmailAddress']=$from;
                        unset($from);
              
                       // $email swap...
                        //test
                    }
                    if ($entry['TransactionID']=='035750284D106734G'){
                       // self::dump($entry);
                    }

                    // self::dump($entry);
                    $obj=new self($entry);
                    // self::dump($obj);
                    $obj->donationToDonor();  //Will Set DonorId on Donation Table.      
                    //self::dd($obj);         
                    $q[]=$obj;
                }	
                $row++;
            }
            fclose($handle);
        }	
        return $q;
    }

    static public function requestHandler(){
        if(isset($_FILES['fileToUpload'])){
            self::csvUploadCheck();
            return true;
        }elseif ($_GET['f']=="AddDonation"){           
            $donation=new Donation;
            if ($_GET['DonorId']){
               $donor=Donor::getById($_GET['DonorId']);
               $donorText=" for Donor #".$donor->DonorId." ".$donor->Name;
            }
            print "<h2>Add donation".$donorText."</h2>";
            $donation->DonorId=$donor->DonorId;
            $donation->Name=$donor->Name;
            $donation->PaymentSource=1;
            $donation->Type=1; 
            $donation->Status=9;

            $donation->editSimpleForm();           
            return true;
        }elseif ($_GET['DonationId']){	
            if ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
                $donation=new Donation($_POST);
                if ($donation->save()){
                   print "<div class=\"notice notice-success is-dismissible\">Donation #".$donation->showField("DonationId")." saved.</div>";
                }
            }
            $donation=Donation::getById($_REQUEST['DonationId']);	
            ?>
            <div id="pluginwrap">
                <div><a href="?page=<?=$_GET['page']?>">Return</a></div>
                <h1>Donation #<?=$donation->DonationId?></h1><?	
                if ($_REQUEST['edit']){
                    $donation->editForm();
                }else{
                    ?><div><a href="?page=<?=$_GET['page']?>&DonationId=<?=$donation->DonationId?>&edit=t">Edit Donation</a></div><?
                    $donation->view();
                }
            ?></div><?
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
            $donation=new Donation($_POST);
            if ($donation->save()){
               print "<div class=\"notice notice-success is-dismissible\">Donation #".$donation->showField("DonationId")." saved.</div>";
            }
            return true;
        }else{
            return false;
        }
    }

    public function selectDropDown($field,$showKey=true,$allowBlank=false){
        ?><select name="<?=$field?>"><?
        if ($allowBlank){
            ?><option></option<?
        }
        foreach($this->tinyIntDescriptions[$field] as $key=>$label){
            ?><option value="<?=$key?>"<?=$key==$this->$field?" selected":""?>><?=($showKey?$key." - ":"").$label?></option><?
        }
        ?></select><?
    }
    public function editSimpleForm(){  
        $hiddenFields=['DonationId','Fee','Net','ToEmailAddress','ReceiptID','AddressStatus']; //these fields more helpful when using paypal import, but are redudant/not necessary when manually entering a transaction
        ?>
        <form method="post" action="?page=donor-reports&amp;DonationId=">
        <input type="hidden" name="table" value="Donation">
        <? foreach ($hiddenFields as $field){?>
		    <input type="hidden" name="<?=$field?>" value="<?=$this->$field?>"/>
        <? } ?>
        <table><tbody>
        <tr><td align="right">Total Amount</td><td><input required type="number" step=".01" name="Gross" value="<?=$this->Gross?>"><? $this->selectDropDown('Currency',false);?></td></tr> 
        <tr><td align="right">Check #/Transaction ID</td><td><input type="txt" name="TransactionID" value=""></td></tr>
        <tr><td align="right">Check/Sent Date</td><td><input type="date" name="Date" value="<?=($this->Date?$this->Date:date("Y-m-d"))?>"></td></tr>
        <tr><td align="right">Date Deposited</td><td><input type="date" name="DateDeposited" value="<?=($this->DateDeposited?$this->DateDeposited:date("Y-m-d"))?>"></td></tr>
        
        <tr><td align="right">DonorId</td><td><?
        if ($this->DonorId){
            ?><input type="hidden" name="DonorId" value="<?=$this->DonorId?>"> #<?=$this->DonorId?><?
        }else{
            ?><input type="text" name="DonorId" value="<?=$this->DonorId?>"> Todo: Make a chooser or allow blank, and/or create after this step. <?
        }
        ?></td></tr>
        <tr><td align="right">Name</td><td><input type="text" name="Name" value="<?=$this->Name?>"></td></tr>
        <tr><td align="right">Email Address</td><td><input type="email" name="FromEmailAddress" value="<?=$this->FromEmailAddress?>"></td></tr>
        <tr><td align="right">Phone Number</td><td><input type="tel" name="ContactPhoneNumber" value="<?=$this->ContactPhoneNumber?>"></td></tr>

        <tr><td align="right">Payment Source</td><td> <? $this->selectDropDown('PaymentSource');?></td></tr>
        <tr><td align="right">Type</td><td> <? $this->selectDropDown('Type');?></td></tr>        
        <tr><td align="right">Status</td><td><? $this->selectDropDown('Status');?></td></tr>

       <!-- <tr><td align="right">Address Status</td><td><? $this->selectDropDown('AddressStatus');?></td></tr> -->
       <tr><td align="right">Category</td><td><select name="CategoryId"><?
            $donationCategory=DonationCategory::get(array('(ParentId=0 OR ParentId IS NULL)'),'Category');
            foreach($donationCategory as $cat){
                ?><option value="<?=$cat->CategoryId?>"<?=$cat->CategoryId==$this->CategoryId?" selected":""?>><?=$cat->Category?></option><?
            }
       ?></select></td></tr>
       <tr><td align="right">Subject</td><td><input type="text" name="Subject" value="<?=$this->Subject?>"></td></tr>
        <tr><td align="right">Note</td><td><textarea name="Note"><?=$this->Note?></textarea></td></tr>
           

        
        <tr></tr><tr><td colspan="2"><button type="submit" name="Function" value="Save">Save</button><button type="submit">Cancel</button></td></tr>
		</tbody></table>
		</form>
        <?
    }
    static public function createTable(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php'); 
    	global $wpdb;
        //$charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `".self::getTableName()."` (
                `DonationId` int(11) NOT NULL AUTO_INCREMENT,
                `Date` datetime NOT NULL,               
                `DateDeposited` date DEFAULT NULL,
                `DonorId` int(11) DEFAULT NULL,
                `Name` varchar(29) NOT NULL,
                `Type` tinyint DEFAULT NULL,
                `TypeOther` varchar(30) DEFAULT NULL,
                `Status` TINYINT DEFAULT NULL,
                `Currency` varchar(3) DEFAULT NULL,
                `Gross` float(10,2) NOT NULL,
                `Fee` decimal(6,2) DEFAULT NULL,
                `Net` varchar(10) DEFAULT NULL,
                `FromEmailAddress` varchar(70) NOT NULL,
                `ToEmailAddress` varchar(26) DEFAULT NULL,
                `TransactionID` varchar(17) DEFAULT NULL,
                `AddressStatus` tinyint DEFAULT NULL,
                `CategoryId` tinyint DEFAULT NULL,
                `ReceiptID` varchar(16) DEFAULT NULL,
                `ContactPhoneNumber` varchar(11) DEFAULT NULL,
                `Subject` varchar(50) DEFAULT NULL,
                `Note` varchar(256) DEFAULT NULL,
                `PaymentSource` tinyint DEFAULT NULL,
                `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`DonationId`),
                KEY `Date` (`Date`,`Name`,`FromEmailAddress`,`TransactionID`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8;";

                /*
                Additional Fields as we expand this:
                Bank: 1- Paypal 2-> Checking... etc. Track bank balances.
                Repurpose - "Status"*/
       
        dbDelta( $sql );

    }
}