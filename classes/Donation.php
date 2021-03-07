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
        "Type"=>[0=>"Other",1=>"Donation Payment",2=>"Website Payment",5=>"Subscription Payment",-2=>"General Currency Conversion",-1=>"General Withdrawal","-3"=>"Expense"],
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

    public function stats($dateFrom="",$dateTo=""){
        global $wpdb;
        DonationCategory::consolidateCategories();
        $where=array("Type>0");
        if ($dateFrom) $where[]="Date>='".$dateFrom." 00:00:00'";
	    if ($dateTo) $where[]="Date<='".$dateTo."  23:59:59'";
        $SQL="SELECT COUNT(DISTINCT DonorId) as TotalDonors, Count(*) as TotalDonations,SUM(`Gross`) as TotalRaised FROM `wp_donation` WHERE ".implode(" AND ",$where);
        $results = $wpdb->get_results($SQL);
        ?><table border=1><tr><th colspan=2>Period Stats</th><th>Avg</th></tr><?
        foreach ($results as $r){
            ?><tr><td>Total Donors</td><td align=right><?=$r->TotalDonors?></td><td align=right>$<?=number_format($r->TotalRaised/$r->TotalDonors,2)?> avg per Donor</td></tr>
            <tr><td>Donation Count</td><td align=right><?=$r->TotalDonations?></td><td align=right><?=number_format($r->TotalDonations/$r->TotalDonors,2)?> avg # per Donor</td></tr>
            <tr><td>Donation Total</td><td align=right><?=number_format($r->TotalRaised,2)?></td><td align=right>$<?=number_format($r->TotalRaised/$r->TotalDonations,2)?> average Donation</td></tr>
            
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

            $SQL="SELECT $gf as $gfa, COUNT(DISTINCT DonorId) as TotalDonors, Count(*) as TotalDonations,SUM(`Gross`) as TotalRaised FROM `wp_donation` WHERE ".implode(" AND ",$where)." Group BY $gf";
            $results = $wpdb->get_results($SQL);
            ?><table border=1><tr><th><?=$gfa?></th><th>Total</th><th>Donations</th><th>Donors</th></tr><?
            foreach ($results as $r){
                ?><tr><td><?=$r->$gfa.($tinyInt[$gf][$r->$gfa]?" - ". $tinyInt[$gf][$r->$gfa]:"")?></td>
                <td align=right>$<?=number_format($r->TotalRaised,2)?></td>
                <td align=right><?=number_format($r->TotalDonations)?></td>
                <td align=right><?=number_format($r->TotalDonors)?></td>
                </tr><?

            }?></table><?
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

    static public function donationUploadGroups(){
        global $wpdb;
        $SQL="SELECT `CreatedAt`,MIN(`DateDeposited`) as DepositedMin, MAX(`DateDeposited`) as DepositedMax,COUNT(*) as C,Count(R.ReceiptId) as ReceiptSentCount
        FROM `wp_donation` D
        LEFT JOIN `wp_donationreceipt` R
        ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE 1
        Group BY `CreatedAt` Order BY `CreatedAt` DESC LIMIT 20";
         $results = $wpdb->get_results($SQL);
         ?><h2>Upload Groups</h2><table border=1><tr><th>Upload Date</th><th>Donation Deposit Date Range</th><th>Count</th><th></th></tr><?
         foreach ($results as $r){?>
             <tr><td><?=$r->CreatedAt?></td><td align=right><?=$r->DepositedMin.($r->DepositedMax!==$r->DepositedMin?" to ".$r->DepositedMax:"")?></td><td><?=$r->ReceiptSentCount." of ".$r->C?></td><td><a href="?page=<?=$_GET['page']?>&UploadDate=<?=$r->CreatedAt?>">View All</a> <?=($r->ReceiptSentCount<$r->C?" | <a href='?page=".$_GET['page']."&UploadDate=".$r->CreatedAt."&unsent=t'>View Unsent</a>":"")?>| <a href="?page=<?=$_GET['page']?>&SummaryView=t&UploadDate=<?=$r->CreatedAt?>">View Summary</a></td></tr><?
            
         }?></table><?
    }

    static public function viewDonationsByUploadDate($date,$settings=array()){ //$type[$r->Type][$r->DonorId]++;
        global $wpdb;
        $donorIdList=array();
        $where[]="`CreatedAt`='".$date."'";
        if ($settings['unsent']){
            $where[]="R.ReceiptId IS NULL";           
        }
        $SQL="Select D.*,R.Type as ReceiptType,R.Address,R.DateSent,R.ReceiptId
          FROM `wp_donation` D
        LEFT JOIN `wp_donationreceipt` R ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE ".implode(" AND ", $where);
        //print $SQL;
        $donations = $wpdb->get_results($SQL);
        foreach ($donations as $r){
            $donorIdList[$r->DonorId]++;
            $type[$r->Type][$r->DonorId]=$r;
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
                foreach ($type as $t=>$donatiosnByType){
                    $total=0;
                    ?><h2><?=self::s()->tinyIntDescriptions["Type"][$t]?></h2>
                    <table border=1><tr><th>Date</th><th>Donor</th><th>Gross</th><th>CategoryId</th><th>Note</th></tr><?
                    foreach($donatiosnByType as $r){
                        $donation=new Donation($r);
                        ?><tr><td><?=$donation->Date?></td><td <?=$donorCount[$donation->DonorId]==1?" style='background-color:orange;'":""?>><?
                        if ($donors[$donation->DonorId]){
                            print $donors[$donation->DonorId]->displayKey()." ".$donors[$donation->DonorId]->NameCheck();
                        }else print $donation->DonorId;
                        print " (x".$donorCount[$donation->DonorId]
                        .($donorCount[$donation->DonorId]==1?" FIRST TIME!":"")
                        .")";
                        ?></td><td align=right><?=$donation->showfield('Gross')?> <?=$donation->Currency?></td><td><?
                        if ($donation->CategoryId) print $donation->showfield("CategoryId");
                        else print $donation->Subject;
                        ?></td><td><?=$donation->showfield("Note")?></td>                  
                        </tr><?
                        $total+=$donation->Gross;
                    }
                    ?><tfoot><tr><td colspan=2>Totals:</td><td align=right><?=number_format($total,2)?></td><td></td><td></td></tr></tfoot></table><?
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
                        ?> <input type="checkbox" name="EmailDonationId[]" value="<?=$donation->DonationId?>" checked/> <a href="">Custom Response</a><?
                    }?></td><td><?=$donation->displayKey()?></td><td><?=$donation->Date?></td><td <?=$donorCount[$donation->DonorId]==1?" style='background-color:orange;'":""?>><?
                    if ($donors[$donation->DonorId]){
                        print $donors[$donation->DonorId]->displayKey()." ".$donors[$donation->DonorId]->NameCheck();
                    }else print $donation->DonorId;
                    print " (x".$donorCount[$donation->DonorId]
                    .($donorCount[$donation->DonorId]==1?" FIRST TIME!":"")
                    .")";
                    ?></td><td><?=$donation->showfield('Gross')?> <?=$donation->Currency?></td><td><?
                    if ($donation->CategoryId) print $donation->showfield("CategoryId");
                    else print $donation->Subject;
                    ?></td><td><?=$donation->showfield("Note")?></td><td><?=$donation->showfield("Type")?></td>
                
                    </tr><?
                }
                ?></table><?
            }

        }
    

        $all=self::get($where);
        print self::showResults($all);
        
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
                    ## move ItemID to ItemTitle if it exists and ItemTitle does not
                    if ($paypal['ItemID'] && !$paypal['ItemTitle']){
                        $paypal['ItemTitle']=$paypal['ItemID'];
                        $paypal['ItemID']='';
                    }

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
                    self::DisplayNotice("Donation #".$donation->showField("DonationId")." saved.");
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
                    print $donation->receiptForm();
                }
            ?></div><?
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
            $donation=new Donation($_POST);
            if ($donation->save()){
                self::DisplayNotice("Donation #".$donation->showField("DonationId")." saved.");
            }
            return true;
        }elseif($_GET['UploadDate']){           
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
                    <div><a href="?page=<?=$_GET['page']?>">Return</a></div><?
                    self::viewDonationsByUploadDate($_GET['UploadDate'],
                        array(
                            'unsent'=>$_GET['unsent']=="t"?true:false,
                            'summary'=>$_GET['SummaryView']?true:false
                            )
                        );                    
             ?></div><?
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

    function DonationReceiptEmail(){
        $this->emailBuilder=new stdClass();
        $page = get_page_by_path( 'receipt-thank-you',OBJECT);  
        //$this->dump($page);
        if (!$page){ ### Make the template page if it doesn't exist.
            self::makeReceiptYearPageTemplate();
            $page = get_page_by_path('receipt-thank-you',OBJECT);  
            self::DisplayNotice("Page /receipt-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $organization=get_bloginfo('name'); // If different than what is wanted, should overwrite template
        if (!$this->Donor)  $this->Donor=Donor::getById($this->DonorId);
        $address=$this->Donor->MailingAddress();

        $subject=$page->post_title;
        $body=$page->post_content;
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

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        $subject=trim(str_replace("##Organization##",$organization,$subject));
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body;        
    }

    public function emailDonationReceipt($email=""){
        $this->DonationReceiptEmail();
        if (!$email){
            return false;         
        }
        if (wp_mail($email, $this->emailBuilder->subject, $this->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){ 
            $notice="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".$email."</div>";
            $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"DonationId","KeyId"=>$this->DonationId,"Type"=>"e","Address"=>$_POST['Email'],"DateSent"=>date("Y-m-d H:i:s")));
            $dr->save();
        }
        return $notice;
    }


    public function receiptForm(){
           //require ( WP_PLUGIN_DIR.'/tcpdf-wrapper/lib/tcpdf/tcpdf.php' );        
        $this->DonationReceiptEmail();            
        if ($_POST['Function']=="SendDonationReceipt" && $_POST['Email']){
            $form.=$this->emailDonationReceipt($_POST['Email']);
            //$form.=$sendResult['notice'];
        }

        $file=$this->receiptFileInfo();
        ### Form View
        $form.='<div class="no-print"><hr><form method="post">Send Receipt to: <input type="email" name="Email" value="'.($_POST['Email']?$_POST['Email']:$this->Email).'"/><button type="submit" name="Function" value="SendDonationReceipt">Send E-mail</button>
        <button type="submit" name="Function" value="DonationReceiptPdf">Generate PDF</button>';
        if (file_exists($file['path'])){
            $form.=' View <a target="pdf" href="'.$file['link'].'">'.$file['file'].'</a>';
        }
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","KeyType='DonationId'","KeyId='".$this->DonationId."'"));
        $form.=DonationReceipt::showResults($receipts);
        $form.='</form>';
        $form.="<div><a target='pdf' href='post.php?post=".$this->emailBuilder->pageID."&action=edit'>Edit Template</div></div>";
        
        $homeLinks="<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        return $homeLinks."<h2>".$this->emailBuilder->subject."</h2>".$this->emailBuilder->body.$form;
    
    }

    function receiptFileInfo(){
        $file=substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-DT'.$this->DonationId.'.pdf';
        $link="/wp-content/plugins/WPDonorPaypal/receipts/".$file;
        return array('path'=>$_SERVER['DOCUMENT_ROOT'].$link,'file'=>$file,'link'=>$link);
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