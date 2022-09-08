<?php
/*custom variables 
INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (NULL, 'donation_Organization', 'Mas Mariposas', 'yes'), (NULL, 'donation_ContactName', 'Denver Steiner', 'yes'), (NULL, 'donation_ContactTitle', 'Treasurer', 'yes'), (NULL, 'donation_ContactEmail', 'donations@masmariposas.org', 'yes'), (NULL, 'donation_FederalId', '47-3336305', 'yes');
*/
$SQL="Select `option_name`, `option_value` FROM wp_options WHERE option_name LIKE 'donation_%'";
require_once("Donor.php");
require_once("DonationCategory.php");
class Donation extends ModelLite
{
    protected $table = 'Donation';
	protected $primaryKey = 'DonationId';
	### Fields that can be passed //,"Time","TimeZone"
    protected $fillable = ["Date","DateDeposited","DonorId","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","TransactionID","AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber","Subject","Note","PaymentSource","NotTaxExcempt"];	 

    protected $paypal = ["Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","From Email Address","To Email Address","Transaction ID","Address Status","Item Title","Item ID","Option 1 Name","Option 1 Value","Option 2 Name","Option 2 Value","Reference Txn ID","Invoice Number","Custom Number","Quantity","Receipt ID","Balance","Contact Phone Number","Subject","Note","Payment Source"];

    protected $paypalPPGF = ["Payout Date","Donation Date","Donor First Name","Donor Last Name","Donor Email","Program Name","Reference Information","Currency Code","Gross Amount","Total Fees","Net Amount","Transaction ID"];

    protected $csvHeaders = ["DepositDate","CheckDate","CheckNumber","Name1","Name2","Gross","Account","ReceiptNeeded","Note","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];

    protected $duplicateCheck=["Date","Gross","FromEmailAddress","TransactionID"]; //check these fields before reinserting a matching entry.
   
    protected $tinyIntDescriptions=[
        "Status"=>["9"=>"Completed","7"=>"Pending","0"=>"Unknown","-1"=>"Deleted","-2"=>"Denied"],
        "AddressStatus"=>[0=>"Not Set",-1=>"Non-Confirmed",1=>"Confirmed"],
        "PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"ACH/Bank Transfer"],
        "Type"=>[0=>"Other",1=>"Donation Payment",2=>"Website Payment",5=>"Subscription Payment",-2=>"General Currency Conversion",-1=>"General Withdrawal","-3"=>"Expense"],
        "Currency"=>["USD"=>"USD","CAD"=>"CAD","GBP"=>"GBP","EUR"=>"EUR","AUD"=>"AUD"],
        "NotTaxExcempt"=>["0"=>"Tax Exempt","1"=>"Not Tax Excempt (Donor Advised fund, etc)"]
    ];

    protected $dateFields=[
        "CreatedAt"=>"Upload",
        "DateDeposited"=>"Deposit",
        "Date"=>"Donated"
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
                $donor = $wpdb->get_row( $wpdb->prepare("SELECT DonorId,MergedId FROM ".Donor::s()->getTable()." WHERE ".$where." Order By MergedId"));
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
            $this->DonorId=$donor->DonorId;
            return $donor->DonorId;
        }

    }

    public function stats($where=array()){
        global $wpdb;
        DonationCategory::consolidateCategories();

        $SQL="SELECT COUNT(DISTINCT DonorId) as TotalDonors, Count(*) as TotalDonations,SUM(`Gross`) as TotalRaised FROM `wp_donation` DD WHERE ".implode(" AND ",$where);
        $results = $wpdb->get_results($SQL);
        ?><table border=1><tr><th colspan=2>Period Stats</th><th>Avg</th></tr><?
        foreach ($results as $r){
            ?><tr><td>Total Donors</td><td align=right><?php print $r->TotalDonors?></td><td align=right>$<?php print number_format($r->TotalRaised/$r->TotalDonors,2)?> avg per Donor</td></tr>
            <tr><td>Donation Count</td><td align=right><?php print $r->TotalDonations?></td><td align=right><?php print number_format($r->TotalDonations/$r->TotalDonors,2)?> avg # per Donor</td></tr>
            <tr><td>Donation Total</td><td align=right><?php print number_format($r->TotalRaised,2)?></td><td align=right>$<?php print number_format($r->TotalRaised/$r->TotalDonations,2)?> average Donation</td></tr>
            
            <?
        }
         ?></table><?

        $GroupFields=array('Type'=>'Type','Category'=>'CategoryId',"Source"=>'PaymentSource',"Month"=>"month(date)");
        $tinyInt=self::s()->tinyIntDescriptions;

        //load all donation categories since this is DB and not hardcoded.
        $result=DonationCategory::get();
        foreach($result as $r){
            $tinyInt['CategoryId'][$r->CategoryId]=$r->Category;
        }
        foreach($GroupFields as $gfa=>$gf){   
            $SQL="SELECT $gf as $gfa, COUNT(DISTINCT DonorId) as TotalDonors, Count(*) as TotalDonations,SUM(`Gross`) as TotalRaised FROM `wp_donation` DD WHERE ".implode(" AND ",$where)." Group BY $gf";
            $results = $wpdb->get_results($SQL);
            if (sizeof($results)>0){
                ?><table border=1><tr><th><?php print $gfa?></th><th>Total</th><th>Donations</th><th>Donors</th></tr><?
                foreach ($results as $r){
                    ?><tr><td><?php print $r->$gfa.($tinyInt[$gf][$r->$gfa]?" - ". $tinyInt[$gf][$r->$gfa]:"")?></td>
                    <td align=right>$<?php print number_format($r->TotalRaised,2)?></td>
                    <td align=right><?php print number_format($r->TotalDonations)?></td>
                    <td align=right><?php print number_format($r->TotalDonors)?></td>
                    </tr><?

                }?></table><?
            }
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
                    if (strtoupper($donor[0]->$field)!=strtoupper($newEntry[$field]) && $newEntry[$field]){
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

    static public function donationUploadGroups(){
        global $wpdb;
        $SQL="SELECT `CreatedAt`,MIN(`DateDeposited`) as DepositedMin, MAX(`DateDeposited`) as DepositedMax,COUNT(*) as C,Count(R.ReceiptId) as ReceiptSentCount
        FROM `wp_donation` D
        LEFT JOIN `wp_donationreceipt` R
        ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE 1
        Group BY `CreatedAt` Order BY `CreatedAt` DESC LIMIT 20";
         $results = $wpdb->get_results($SQL);
         ?><h2>Upload Groups</h2>
         <form method="get" action=""><input type="hidden" name="page" value="<?=$_GET['page']?>" />
			Summary From <input type="date" name="df" value="<?=$_GET['df']?>"/> to <input type="date" name="dt" value="<?=$_GET['dt']?>"/> Date Field: <select name="dateField">
            <? foreach (self::s()->dateFields as $field=>$label){?>
                <option value="<?=$field?>"<?=$_GET['dateField']==$field?" selected":""?>><?=$label?> Date</option>
            <? } ?>
            </select>
             <button type="submit" name="SummaryView" value="t">View Summary</button></form>
         <table border=1><tr><th>Upload Date</th><th>Donation Deposit Date Range</th><th>Count</th><th></th></tr><?
         foreach ($results as $r){?>
             <tr><td><?php print $r->CreatedAt?></td><td align=right><?php print $r->DepositedMin.($r->DepositedMax!==$r->DepositedMin?" to ".$r->DepositedMax:"")?></td><td><?php print $r->ReceiptSentCount." of ".$r->C?></td><td><a href="?page=<?php print $_GET['page']?>&UploadDate=<?php print $r->CreatedAt?>">View All</a> <?php print ($r->ReceiptSentCount<$r->C?" | <a href='?page=".$_GET['page']."&UploadDate=".$r->CreatedAt."&unsent=t'>View Unsent</a>":"")?>| <a href="?page=<?php print $_GET['page']?>&SummaryView=t&UploadDate=<?php print $r->CreatedAt?>">View Summary</a></td></tr><?
            
         }?></table><?
    }

    static public function viewDonations($where=[],$settings=array()){ //$type[$r->Type][$r->DonorId]++;
        if (sizeof($where)==0){
           self::DisplayError("No Criteria Given");
        }
        global $wpdb;
        $donorIdList=array();
        
        if ($settings['unsent']){
            $where[]="R.ReceiptId IS NULL";           
        }
        print "Criteria: ".implode(", ",$where);
        $SQL="Select D.*,R.Type as ReceiptType,R.Address,R.DateSent,R.ReceiptId
          FROM `wp_donation` D
        LEFT JOIN `wp_donationreceipt` R ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE ".implode(" AND ", $where)." Order BY D.Date DESC,  D.DonationId DESC;";
        
        //print $SQL;
        $donations = $wpdb->get_results($SQL);
        foreach ($donations as $r){
            $donorIdList[$r->DonorId]++;
            $type[$r->Type][$r->DonorId][$r->DonationId]=$r;
        }
        //
        if (sizeof($donorIdList)>0){
            $donors=Donor::get(array("DonorId IN ('".implode("','",array_keys($donorIdList))."')"),'',array('key'=>true));
            // Find if first time donation
            $result=$wpdb->get_results("Select DonorId, Count(*) as C From wp_donation where DonorId IN ('".implode("','",array_keys($donorIdList))."') Group BY DonorId");
            foreach ($result as $r){
                $donorCount[$r->DonorId]=$r->C;
            }

        }
        //self::dd($donors);
        if (sizeof($donations)>0){   
            if ( $settings['summary']){
                ksort($type);
                foreach ($type as $t=>$donationsByType){
                    $total=0;
                    ?><h2><?php print self::s()->tinyIntDescriptions["Type"][$t]?></h2>
                    <table border=1><tr><th>Donor</th><th>E-mail</th><th>Date</th><th>Gross</th><th>CategoryId</th><th>Note</th><th>LifeTime</th></tr><?
                    foreach($donationsByType as $donations){
                        $donation=new Donation($donations[key($donations)]);
                        ?><tr><td  rowspan="<?php print sizeof($donations)?>"><?
                        if ($donors[$donation->DonorId]){
                            //print $donors[$donation->DonorId]->displayKey()." ".
                            print $donors[$donation->DonorId]->NameCheck();
                        }else print $donation->DonorId;
                    
                        ?></td>
                        <td rowspan="<?php print sizeof($donations)?>"><?php print $donors[$donation->DonorId]->displayEmail('Email')?></td><?
                        $count=0;
                        foreach($donations as $r){                          
                            if ($count>0){
                                $donation=new Donation($r);
                                print "<tr>";
                            } 
                           ?><td><?php print $donation->Date?></td><td align=right><?php print $donation->showfield('Gross')?> <?php print $donation->Currency?></td><td><?
                            if ($donation->CategoryId) print $donation->showfield("CategoryId");
                            else print $donation->Subject;
                            ?></td><td><?php print $donation->showfield("Note")?></td>  
                            <td <?php print $donorCount[$donation->DonorId]==1?" style='background-color:orange;'":""?>><?  print "x".$donorCount[$donation->DonorId].($donorCount[$donation->DonorId]==1?" FIRST TIME!":"")."";?> </td>               
                            </tr><?
                            $total+=$donation->Gross;
                            $count++;
                        }
                    }
                    ?><tfoot><tr><td colspan=3>Totals:</td><td align=right><?php print number_format($total,2)?></td><td></td><td></td><td></td></tr></tfoot></table><?
                }

            }else{?>
                <form method="post"><button type="submit" name="Function" value="EmailDonationReceipts">Send E-mail Receipts</button>
                <table border=1><tr><th></th><th>Donation</th><th>Date</th><th>DonorId</th><th>Gross</th><th>CategoryId</th><th>Note</th></tr><?
                foreach($donations as $r){
                    $donation=new Donation($r);
                    ?><tr><td><?
                    if ($r->ReceiptType){
                        print "Sent: ".$r->ReceiptType." ".$r->Address;
                    }else{
                        ?> <input type="checkbox" name="EmailDonationId[]" value="<?php print $donation->DonationId?>" checked/> <a href="">Custom Response</a><?
                    }?></td><td><?php print $donation->displayKey()?></td><td><?php print $donation->Date?></td><td <?php print $donorCount[$donation->DonorId]==1?" style='background-color:orange;'":""?>><?
                    if ($donors[$donation->DonorId]){
                        print $donors[$donation->DonorId]->displayKey()." ".$donors[$donation->DonorId]->NameCheck();
                    }else print $donation->DonorId;
                    print " (x".$donorCount[$donation->DonorId]
                    .($donorCount[$donation->DonorId]==1?" FIRST TIME!":"")
                    .")";
                    ?></td><td><?php print $donation->showfield('Gross')?> <?php print $donation->Currency?></td><td><?
                    if ($donation->CategoryId) print $donation->showfield("CategoryId",false);
                    else print $donation->Subject;
                    ?></td><td><?php print $donation->showfield("Note")?></td><td><?php print $donation->showfield("Type")?></td>
                
                    </tr><?
                }
                ?></table><?
            }

        }
    
        if (!$settings['summary']){
           // $all=self::get($where);
           // print self::showResults($all);
        }
        
    }

    static public function csvUploadCheck(){
        $timeNow=time();
        if(isset($_FILES['fileToUpload'])){
            if ($_POST['nuke']=="true"){
                //nuke(); 
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
                    $result=self::csvReadFile($target_file,$firstLineColumns=true,$timeNow);
                   
                }
                
                //print "<pre>"; print_r($result); print "</pre>";
                //exit();
                 if ($stats=self::replaceIntoList($result)){//inserted'=>sizeof($iSQL),'skipped'
                     echo "<div>Inserted ".$stats['inserted']." records. Skipped ".$stats['skipped']." repeats.</div>";
                     unlink($target_file); //don't keep it on the server...
                 }
                 global $suggestDonorChanges;
                 //self::dump($suggestDonorChanges);
                 if ($suggestDonorChanges && sizeof($suggestDonorChanges)>0){
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
                     //To do: timezone off. by -5
                     print "<hr>";
                     print "<div><a target='viewSummary' href='?page=donor-reports&UploadDate=".date("Y-m-d H:i:s",$timeNow)."'>View All</a> | <a target='viewSummary' href='?page=donor-reports&SummaryView=t&UploadDate=".date("Y-m-d H:i:s",$timeNow)."'>View Summary</a></div>";
                 }


                 if ($_POST['uploadSummary']=="true"){

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

    static public function csvReadFile($csvFile,$firstLineColumns=true,$timeNow=""){//2019-2020-05-23.CSV
        if (!$timeNow) $timeNow=time();
        //self::createTable();
        $dbHeaders=self::s()->fillable; 
        $dbHeaders[]="ItemTitle";        
        //if ($type=="paypal"){
            $headerRow=self::s()->paypal; 
            //$headerRow=self::s()->$paypalPPGF
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
                    ## move ItemID to ItemTitle if it exists and ItemTitle does not
                    if ($paypal['ItemID'] && !$paypal['ItemTitle']){
                        $paypal['ItemTitle']=$paypal['ItemID'];
                        $paypal['ItemID']='';
                    }
                    ###  helps handle a file from PPGF -> Paypal Giving file try to handle. Soure: https://www.paypal.com/givingfund/activity
                    $fieldShift=["CurrencyCode"=>"Currency","DonorEmail"=>'FromEmailAddress',"ReferenceInformation"=>"Note","DonationDate"=>"Date","GrossAmount"=>"Gross","TotalFees"=>"Fee","NetAmount"=>"Net"];                  
            
                    if (($paypal['DonorFirstName'] || $paypal['DonorLastName']) && !$paypal['Name']){
                        $paypal['Name']=trim($paypal['DonorFirstName']." ".$paypal['DonorLastName']); 

                        $entry['DateDeposited']=date("Y-m-d",strtotime($v." ".$paypal['PayoutDate']));  
                        
                        $entry['CategoryId']=DonationCategory::getCategoryId($paypal['ProgramName']);
                    }  
                    //self::dd($paypal);             
                    foreach($paypal as $fieldName=>$v){
                        if ($fieldShift[$fieldName]){
                            $fieldName=$fieldShift[$fieldName];                           
                        } 
                       
                        if (in_array($fieldName,$dbHeaders)){
                            switch($fieldName){
                                case "ItemTitle":
                                    if ($paypal['Subject']==$paypal['ItemTitle']){
                                        $paypal['Subject']=""; //remove redundancy. Wouldn't have to do this if we end up using Subject for something.
                                    }                                   
                                    $entry['CategoryId']=DonationCategory::getCategoryId($paypal['ItemTitle']);
                                    
                                break;
                                case "DonationDate":
                                    $entry['Date']=date("Y-m-d H:i:s",strtotime($v));
                                    break;
                                case "Date":
                                    $v=date("Y-m-d H:i:s",strtotime($v." ".$paypal['Time']." ".$paypal['TimeZone']));
                                   // print "<pre>".$v; print_r($paypal); print "</pre>"; exit();
                                    if (!$entry['DateDeposited']) $entry['DateDeposited']=$v;
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
                            }                            
                            $entry[$fieldName]=$v;
                        }									
                    }
                    $entry[self::CREATED_AT]=$timeNow;
                    
                    ## Skip these types of entries.
                    if ($entry['Status']==-2 || $entry['Type']==-2 || 
                    in_array($entry['TypeOther'],array('Hold on Balance for Dispute Investigation','General Currency Conversion','Payment Review Hold','Payment Review Release'))){ //Denied
                       // status = denied or type is currency conversion or type other equals..
                        continue; // skip denied entries.
                    }

                    if ($entry['TransactionID']=='81M276915N4017057'){
                       //self::dump($entry);
                    }
                    
                    if ($entry['TypeOther'] && in_array($entry['TypeOther'],array("Payment Refund","Payment Reversal"))){
                        $entry['Name']=''; //Paypal
                        $from= $entry['ToEmailAddress'];//=> donations@masmariposas.org
                        $entry['ToEmailAddress']=$entry['FromEmailAddress'];
                        $entry['FromEmailAddress']=$from;
                        unset($from);
              
                       // $email swap...
                        //test
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
            $donation=new Donation();
            if ($_GET['DonorId']){
               $donor=Donor::getById($_GET['DonorId']);
               $donorText=" for Donor #".$donor->DonorId." ".$donor->Name;
            }
            print "<h2>Add donation".$donorText."</h2>";
            $donation->DonorId=$donor->DonorId;
            $donation->Name=$donor->Name;
            $donation->FromEmailAddress=$donor->Email;
            $donation->ContactPhoneNumber=$donor->Phone;
            $donation->PaymentSource=1;
            $donation->Type=1; 
            $donation->Status=9;

            $donation->editSimpleForm();           
            return true;
        }elseif($_GET['f']=="ViewPaymentSourceYearSummary" && $_GET['Year']){
            self::showSummaryByPaymentSource($_GET['PaymentSource'],$_GET['Year']);
            print "coming soon";
            return true;
            
        }elseif ($_POST['Function']=="Cancel" && $_POST['table']=="Donation"){
            $donor=Donor::getById($_POST['DonorId']);
            $donor->view();
            return true;
        }elseif ($_REQUEST['DonationId']){	
            if ($_POST['Function']=="Delete" && $_POST['table']=="Donation"){
                $donation=new Donation($_POST);
                if ($donation->delete()){
                    self::DisplayNotice("Donation #".$donation->showField("DonationId")." for $".$this->Gross." from ".$this->Name." on ".$this->Date." deleted");
                    //$donation->fullView();
                    return true;
                }
            }
            if ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
                $donation=new Donation($_POST);
                if ($donation->save()){
                    self::DisplayNotice("Donation #".$donation->showField("DonationId")." saved.");
                    $donation->fullView();
                    return true;
                }
            }
            $donation=Donation::getById($_REQUEST['DonationId']);	
            $donation->fullView();
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
            $donation=new Donation($_POST);            
            if ($donation->save()){
                self::DisplayNotice("Donation #".$donation->showField("DonationId")." saved.");
                $donation->fullView();
            }
            return true;
        }elseif($_GET['UploadDate'] || $_GET['SummaryView']){           
            if ($_POST['Function']=="EmailDonationReceipts" && sizeof($_POST['EmailDonationId'])>0){
                foreach($_POST['EmailDonationId'] as $donationId){
                    $donation=Donation::getById($donationId);
                    //print self::dump($donation);
                    if ($donation->FromEmailAddress){
                        print $donation->emailDonationReceipt($donation->FromEmailAddress);
                        //print "send to : ".$donation->FromEmailAddress. " On Donation: ".$donationId."<br>";
                    }else{
                        print "not sent to: ".$donationId." ".$donation->Name."<br>";
                    }                   
                }                
            }
            ?>
             <div id="pluginwrap">
                    <div><a href="?page=<?php print $_GET['page']?>">Return</a></div><?
                    $where=[];
                    if ($_GET['UploadDate']){
                        $where[]="`CreatedAt`='".$_GET['UploadDate']."'";
                    }
                    $dateField=(self::s()->dateFields[$_GET['dateField']]?$_GET['dateField']:key(self::s()->dateFields));
                    if ($_GET['df']){
                        $where[]="`$dateField`>='".$_GET['df']."'";
                    }
                    if ($_GET['dt']){
                        $where[]="`$dateField`<='".$_GET['dt']."'";
                    }                    
                    self::viewDonations($where,
                        array(
                            'unsent'=>$_GET['unsent']=="t"?true:false,
                            'summary'=>$_GET['SummaryView']?true:false
                            )
                        );                    
             ?></div><?
             exit();
            return true;
        }else{
            return false;
        }
    }

    public function fullView(){
        ?>
            <div id="pluginwrap">
                <div><a href="?page=<?php print $_GET['page']?>">Return</a></div>
                <h1>Donation #<?php print $this->DonationId?></h1><?	
                if ($_REQUEST['edit']){
                    $this->editForm();
                }else{
                    ?><div><a href="?page=<?php print $_GET['page']?>&DonationId=<?php print $this->DonationId?>&edit=t">Edit Donation</a></div><?
                    $this->view();
                    $this->receiptForm();                   
                }
            ?></div><?
    }

    public function selectDropDown($field,$showKey=true,$allowBlank=false){
        ?><select name="<?php print $field?>"><?
        if ($allowBlank){
            ?><option></option<?
        }
        foreach($this->tinyIntDescriptions[$field] as $key=>$label){
            ?><option value="<?php print $key?>"<?php print $key==$this->$field?" selected":""?>><?php print ($showKey?$key." - ":"").$label?></option><?
        }
        ?></select><?
    }
    public function editSimpleForm(){  
        $hiddenFields=['DonationId','Fee','Net','ToEmailAddress','ReceiptID','AddressStatus']; //these fields more helpful when using paypal import, but are redudant/not necessary when manually entering a transaction
        ?>
        <form method="post" action="?page=donor-reports&DonationId=">
        <input type="hidden" name="table" value="Donation">
        <? foreach ($hiddenFields as $field){?>
		    <input type="hidden" name="<?php print $field?>" value="<?php print $this->$field?>"/>
        <? } ?>
        <table><tbody>
        <tr><td align="right">Total Amount</td><td><input required type="number" step=".01" name="Gross" value="<?php print $this->Gross?>"><? $this->selectDropDown('Currency',false);?></td></tr> 
        <tr><td align="right">Check #/Transaction ID</td><td><input type="txt" name="TransactionID" value=""></td></tr>
        <tr><td align="right">Check/Sent Date</td><td><input type="date" name="Date" value="<?php print ($this->Date?$this->Date:date("Y-m-d"))?>"></td></tr>
        <tr><td align="right">Date Deposited</td><td><input type="date" name="DateDeposited" value="<?php print ($this->DateDeposited?$this->DateDeposited:date("Y-m-d"))?>"></td></tr>
        
        <tr><td align="right">DonorId</td><td><?
        if ($this->DonorId){
            ?><input type="hidden" name="DonorId" value="<?php print $this->DonorId?>"> #<?php print $this->DonorId?><?
        }else{
            ?><input type="text" name="DonorId" value="<?php print $this->DonorId?>"> Todo: Make a chooser or allow blank, and/or create after this step. <?
        }
        ?></td></tr>
        <tr><td align="right">Name</td><td><input type="text" name="Name" value="<?php print $this->Name?>"></td></tr>
        <tr><td align="right">Email Address</td><td><input type="email" name="FromEmailAddress" value="<?php print $this->FromEmailAddress?>"></td></tr>
        <tr><td align="right">Phone Number</td><td><input type="tel" name="ContactPhoneNumber" value="<?php print $this->ContactPhoneNumber?>"></td></tr>

        <tr><td align="right">Payment Source</td><td> <? $this->selectDropDown('PaymentSource');?></td></tr>
        <tr><td align="right">Type</td><td> <? $this->selectDropDown('Type');?></td></tr>        
        <tr><td align="right">Status</td><td><? $this->selectDropDown('Status');?></td></tr>

       <!-- <tr><td align="right">Address Status</td><td><? $this->selectDropDown('AddressStatus');?></td></tr> -->
       <tr><td align="right">Category</td><td><select name="CategoryId"><?
            $donationCategory=DonationCategory::get(array('(ParentId=0 OR ParentId IS NULL)'),'Category');
            foreach($donationCategory as $cat){
                ?><option value="<?php print $cat->CategoryId?>"<?php print $cat->CategoryId==$this->CategoryId?" selected":""?>><?php print $cat->Category?></option><?
            }
       ?></select></td></tr>
       <tr><td align="right">Subject</td><td><input type="text" name="Subject" value="<?php print $this->Subject?>"></td></tr>
        <tr><td align="right">Note</td><td><textarea name="Note"><?php print $this->Note?></textarea></td></tr>
        <tr><td align="right">Tax Excempt</td><td><?=$this->selectDropDown('NotTaxExcempt')?><div><em>Set to "Not Tax Exempt" if they have already been giving credit for the donation by donating through a donor advised fund.</div></td></tr>
        <tr></tr><tr><td colspan="2"><button type="submit" class="Primary" name="Function" value="Save">Save</button><button type="submit" name="Function" class="Secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->DonationId){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?
        }
        ?>
    </td></tr>
		</tbody></table>
		</form>
        <?
    }

    function DonationReceiptEmail(){
        if ($this->emailBuilder) return;
        $this->emailBuilder=new stdClass();
        
        if ($this->NotTaxExcempt==1){            
            $page = get_page_by_path( 'no-tax-thank-you',OBJECT);  
            if (!$page){ ### Make the template page if it doesn't exist.
                self::makeReceiptNoTaxTemplate();
                $page = get_page_by_path('no-tax-thank-you',OBJECT);  
                self::DisplayNotice("Page /no-tax-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
            }
        }else{
            $page = get_page_by_path( 'receipt-thank-you',OBJECT);  
            if (!$page){ ### Make the template page if it doesn't exist.
                self::makeReceiptThankYouTemplate();
                $page = get_page_by_path('receipt-thank-you',OBJECT);  
                self::DisplayNotice("Page /receipt-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
            }
        }
         
        if (!$page){ ### Make the template page if it doesn't exist.
            self::makeReceiptThankYouTemplate();
            $page = get_page_by_path('receipt-thank-you',OBJECT);  
            self::DisplayNotice("Page /receipt-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $organization=get_option( 'donation_Organization');
        if (!$organization) $organization=get_bloginfo('name');
        if (!$this->Donor)  $this->Donor=Donor::getById($this->DonorId);
        $address=$this->Donor->MailingAddress();

        $subject=$page->post_title;
        $body=$page->post_content;
        $body=trim(str_replace("##Organization##",$organization,$body));
        $body=str_replace("##Name##",$this->Donor->Name.($this->Donor->Name2?" & ".$this->Donor->Name2:""),$body);
        $body=str_replace("##Year##",$year,$body);
        $body=str_replace("##Gross##","$".number_format($this->Gross,2),$body);
       // $body=str_replace("<p>##ReceiptTable##</p>",$ReceiptTable,$body);
        //$body=str_replace("##ReceiptTable##",$ReceiptTable,$body);
        if (!$address){ //remove P
            $body=str_replace("<p>##Address##</p>",$address,$body);
        }
        $body=str_replace("##Address##",$address,$body);

        $body=str_replace("##Date##",date("F j, Y",strtotime($this->Date)),$body);
        $body=trim(str_replace("##FederalId##", get_option( 'donation_FederalId' ),$body)); 
        $body=trim(str_replace("##ContactName##", get_option( 'donation_ContactName' ),$body)); 
        $body=trim(str_replace("##ContactTitle##", get_option( 'donation_ContactTitle' ),$body)); 
        $body=trim(str_replace("##ContactEmail##", get_option( 'donation_ContactEmail' ),$body)); 

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        
        $subject=trim(str_replace("##Organization##",$organization,$subject));
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body;        
    }

    static function makeReceiptNoTaxTemplate(){
        $page = get_page_by_path( 'no-tax-thank-you',OBJECT );  
        if (!$page){
            $postarr['ID']=0;
            $postarr['post_content']='            
            <!-- wp:paragraph -->
            <p><strong>Dear ##Name##,</strong></p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            Thank you for recommending that ##Organization## receive a generous gift of ##Gross## on ##Date##! We received the grant, and the funds will make a profound difference.
            <!-- /wp:paragraph -->        
    
            <!-- wp:paragraph -->
            <p>Thank you again.</p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            <p>Best regards,</p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            <strong>##ContactName##</strong><br>
            ##ContactTitle##<br>
            <a href="mailto:##ContactEmail#">##ContactEmail##</a>
            <!-- /wp:paragraph -->

            <!-- wp:paragraph -->
            Please note, this is not a tax receipt. You may be eligible to claim a tax deduction for your contribution to fund this grant was sent from.
            <!-- /wp:paragraph -->';
            
            $postarr['post_title']='Thank You For Your ##Organization## Gift';
            $postarr['post_status']='private';
            $postarr['post_type']='page';
            $postarr['post_name']='no-tax-thank-you';
            return wp_insert_post($postarr);            
        }
    }
    static function makeReceiptThankYouTemplate(){
        $page = get_page_by_path( 'receipt-thank-you',OBJECT );  
        if (!$page){
            $postarr['ID']=0;
            $postarr['post_content']='            
            <!-- wp:paragraph -->
            <p><strong>Dear ##Name##,</strong></p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            <p>Thank you for your generous giving to ##Organization##.</p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            <p>Your contributions help make it possible for us continue making a difference.</p>
            <!-- /wp:paragraph -->
                        
            <!-- wp:paragraph -->
            <p>##Organization# is a 501(c)(3) non-profit organization, so your donation of <strong>##Gross##</strong> received on <strong>##Date## </strong>is tax-deductible [ID Here]. No goods or services were provided in exchange for your contribution. Please keep this receipt for your records.</p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            <p>Thank you again.</p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->
            <p>Best regards,</p>
            <!-- /wp:paragraph -->
            
            <!-- wp:paragraph -->           
            <p><strong>##ContactName##</strong><br>
            ##ContactTitle##<br>
            <a href="mailto:##ContactEmail#">##ContactEmail##</a></p>
            <!-- /wp:paragraph -->';
            $postarr['post_title']='Thank You For Your ##Organization## Donation';
            $postarr['post_status']='private';
            $postarr['post_type']='page';
            $postarr['post_name']='receipt-thank-you';
            return wp_insert_post($postarr);            
        }    
    }

    public function emailDonationReceipt($email="",$customMessage=null,$subject=null){
        //print "<pre>".$customMessage."</pre><h2>builder</h2><pre>".$this->emailBuilder->body."</pre>";
        //exit();
        $this->DonationReceiptEmail();
        if (!$email){
            return false;         
        }
        if (wp_mail($email, $subject?$subject:$this->emailBuilder->subject, $customMessage?$customMessage:$this->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){ 
            if ($customMessage && $customMessage==$this->emailBuilder->body){
                $customMessage=null; //don't bother saving if template hasn't changed.
            }
            $notice="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".$email."</div>";
            $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"DonationId","KeyId"=>$this->DonationId,"Type"=>"e","Address"=>$email,"DateSent"=>date("Y-m-d H:i:s"),"Content"=>$customMessage));
            $dr->save();
        }
        return $notice;
    }

    public function pdfReceipt($customMessage=null){   
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false); 
        $file=$this->receiptFileInfo();
        $path=$file['path'];//dn_plugin_base_dir()."receipts/DonationReceipt-".$this->DonorId."-".$this->DonationId.".pdf"; //not acceptable on live server...
        $pdf->AddPage();
        $this->DonationReceiptEmail();
        $pdf->writeHTML($customMessage?$customMessage:$this->emailBuilder->body, true, false, true, false, '');        
                         
        if ($pdf->Output($path, 'F')){
            return true;
        }else return false;
    }


    public function receiptForm(){  
            
        $this->DonationReceiptEmail();            
        if ($_POST['Function']=="SendDonationReceipt" && $_POST['Email']){
            print $this->emailDonationReceipt($_POST['Email'],stripslashes_deep($_POST['customMessage']),stripslashes_deep($_POST['EmailSubject']));
            
        }elseif ($_POST['Function']=="DonationReceiptPdf"){
            $this->pdfReceipt(stripslashes_deep($_POST['customMessage']));
        }
        print "<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";

        $file=$this->receiptFileInfo();
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","KeyType='DonationId'","KeyId='".$this->DonationId."'"));
        $bodyContent=$receipts[0]->Content?$receipts[0]->Content:$this->emailBuilder->body; //retrieve last saved custom message
        $bodyContent=$_POST['customMessage']?stripslashes_deep($_POST['customMessage']):$bodyContent; //Post value overrides this though.
       
        //$receipts[0]->content
   
        print DonationReceipt::showResults($receipts,"You have not sent this donor a Receipt.");

        $emailToUse=($_POST['Email']?$_POST['Email']:$this->FromEmailAddress);
        if (!$emailToUse) $emailToUse=$this->Donor->Email;
        ?><form method="post">
            <h2>Send Receipt</h2>
            <input type="hidden" name="DonationId" value="<?=$this->DonationId?>">
            <div>Send Receipt to: <input type="email" name="Email" value="<?=$emailToUse?>">
                <button type="submit" name="Function" value="SendDonationReceipt">Send E-mail Receipt</button> <button type="submit" name="Function" value="DonationReceiptPdf">Generate PDF</button>
                <? if (file_exists($file['path'])){
                    print  ' Download Pdf: <a target="pdf" href="'.$file['link'].'">'.$file['file'].'</a>';
                }?>        
            </div>
            <div><a target='pdf' href='post.php?post=<?=$this->emailBuilder->pageID?>&action=edit'>Edit Template</a></div>
            <div style="font-size:18px;"><strong>Email Subject:</strong> <input style="font-size:18px; width:500px;" name="EmailSubject" value="<?=$_POST['EmailSubject']?stripslashes_deep($_POST['EmailSubject']):$this->emailBuilder->subject?>"/>
            <?php wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false)); ?>
        </form>
        <?php    
    }

    function receiptFileInfo(){
        $file=substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-DT'.$this->DonationId.'.pdf';
        $path=dn_plugin_base_dir()."/receipts/".$file;
        $link=site_url().str_replace($_SERVER['DOCUMENT_ROOT'],"/",$path);
        return array('path'=>$path,'file'=>$file,'link'=>$link);
    }

    static function showSummaryByPaymentSource($paymentSource,$year){
        global $wpdb;
        $SQL="Select DT.DonationId,D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, DT.`Date`,DT.DateDeposited,DT.Gross,DT.TransactionID,DT.Subject,DT.Note,DT.PaymentSource       
        FROM ".Donor::getTableName()." D INNER JOIN ".Donation::getTableName()." DT ON D.DonorId=DT.DonorId 
        WHERE YEAR(Date)='".$year."' AND  Status>=0  AND PaymentSource='".$paymentSource."' Order BY DT.Date,DonationId";
        //AND Type>=0
        //print $SQL;
        $results = $wpdb->get_results($SQL);
        //return;
        ?><table border=1><tr><th>DonationId</th><th>Name</th><th>Transaction Id</th><th>Amount</th><th>Date</th><th>Deposit Date</th></tr><?
        foreach ($results as $r){?>
            <tr>
            <td><a target="donation" href="?page=donor-reports&DonationId=<?php print $r->DonationId?>"><?php print $r->DonationId?></a> | <a target="donation" href="?page=donor-reports&DonationId=<?php print $r->DonationId?>&edit=t">Edit</a></td>
                <td><?php print $r->Name?></td>
            <td><?php print $r->TransactionID?></td>
            <td align=right><?php print number_format($r->Gross,2)?></td>
            <td><?php print $r->Date?></td>
            <td><?php print $r->DateDeposited?></td>
            </tr><?
        }
        ?></table><?
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
            `Type` tinyint(4) DEFAULT NULL,
            `TypeOther` varchar(30) DEFAULT NULL,
            `Status` tinyint(4) DEFAULT NULL,
            `Currency` varchar(3) DEFAULT NULL,
            `Gross` float(10,2) NOT NULL,
            `Fee` decimal(6,2) DEFAULT NULL,
            `Net` varchar(10) DEFAULT NULL,
            `FromEmailAddress` varchar(70) NOT NULL,
            `ToEmailAddress` varchar(26) DEFAULT NULL,
            `TransactionID` varchar(17) DEFAULT NULL,
            `AddressStatus` tinyint(4) DEFAULT NULL,
            `CategoryId` tinyint(4) DEFAULT NULL,
            `ReceiptID` varchar(16) DEFAULT NULL,
            `ContactPhoneNumber` varchar(20) DEFAULT NULL,
            `Subject` varchar(50) DEFAULT NULL,
            `Note` TEXT DEFAULT NULL,
            `PaymentSource` tinyint(4) DEFAULT NULL,
            `NotTaxExcempt` TINYINT NULL DEFAULT '0' COMMENT '0=TaxExempt 1=Not Tax Excempt'
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,            
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,            
            PRIMARY KEY (`DonationId`),
            KEY `Date` (`Date`,`Name`,`FromEmailAddress`,`TransactionID`)
          )";

                /*
                Additional Fields as we expand this:
                Bank: 1- Paypal 2-> Checking... etc. Track bank balances.
                Repurpose - "Status"*/
       
        dbDelta( $sql );

    }
}