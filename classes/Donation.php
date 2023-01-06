<?php
require_once 'Donor.php';
require_once 'DonationCategory.php';
require_once 'DonorTemplate.php';
class Donation extends ModelLite
{
    protected $table = 'Donation';
	protected $primaryKey = 'DonationId';
	### Fields that can be passed //,"Time","TimeZone"
    public $fillable = ["Date","DateDeposited","DonorId","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","Source","SourceId","TransactionID","AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber","Subject","Note","PaymentSource","NotTaxExcempt","QBOInvoiceId"];	 

    protected $paypal = ["Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","From Email Address","To Email Address","Transaction ID","Address Status","Item Title","Item ID","Option 1 Name","Option 1 Value","Option 2 Name","Option 2 Value","Reference Txn ID","Invoice Number","Custom Number","Quantity","Receipt ID","Balance","Contact Phone Number","Subject","Note","Payment Source"];

    protected $paypalPPGF = ["Payout Date","Donation Date","Donor First Name","Donor Last Name","Donor Email","Program Name","Reference Information","Currency Code","Gross Amount","Total Fees","Net Amount","Transaction ID"];

    protected $csvHeaders = ["DepositDate","CheckDate","CheckNumber","Name1","Name2","Gross","Account","ReceiptNeeded","Note","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];

    public $flat_key = ["Date","Name","Gross","FromEmailAddress","TransactionID"];
    protected $duplicateCheck=["Date","Gross","FromEmailAddress","TransactionID"]; //check these fields before reinserting a matching entry.
   
    protected $tinyIntDescriptions=[
        "Status"=>["9"=>"Completed","7"=>"Pending","0"=>"Unknown","-1"=>"Deleted","-2"=>"Denied"],
        "AddressStatus"=>[0=>"Not Set",-1=>"Non-Confirmed",1=>"Confirmed"],
        "PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"ACH/Bank Transfer","10"=>"Paypal"],
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

    const UPLOAD_PATTERN = [
        'Last, Name1 & Name2'=>'Name|Name2',
        'City, Region Postal Country'=>'City|Region|PostalCode|Country'
    ];

    const UPLOAD_AUTO_SELECT = ['name'=>'Name','check'=>'TransactionID','date'=>'Date','deposit'=>'DateDeposited','amount'=>'Gross','total'=>'Gross','note'=>'Note','comment'=>'Note','address'=>'Address1','address1'=>'Address1','address2'=>'Address2','e-mail'=>'Email','Phone'=>'Phone'];    
    
    static public function from_paypal_api_detail($detail){               
        $transaction=$detail->transaction_info;
        $payer=$detail->payer_info;
        $donation=new self();
        $donation->Source='paypal';
        $donation->SourceId=$transaction->paypal_account_id;
        $donation->TransactionID=$transaction->transaction_id;
        $donation->Date=date("Y-m-d H:i:s",strtotime($transaction->transaction_initiation_date));
        $donation->DateDeposited=date("Y-m-d",strtotime($transaction->transaction_initiation_date));
        $donation->Gross=$transaction->transaction_amount->value;
        $donation->Currency=$transaction->transaction_amount->currency_code;
        $donation->Fee=$transaction->fee_amount->value;
        $donation->Net=$donation->Gross+$donation->Fee;              
        $donation->Subject=$transaction->transaction_subject;
        $donation->Note=$transaction->transaction_note;
        $donation->Name=$payer->payer_name->alternate_full_name;
        if (!$donation->Name && $transaction->bank_reference_id){
            $donation->Name="Bank ".$transaction->bank_reference_id;
            if (!$donation->SourceId) $donation->SourceId=$transaction->bank_reference_id;
        }
        //if (!$payer->payer_name->alternate_full_name) self::dd($payer->payer_name);
        $donation->FromEmailAddress=$payer->email_address;
        $donation->PaymentSource=10;
        $donation->Type=self::transaction_event_code_to_type($transaction->transaction_event_code);

        if (!$donation->FromEmailAddress && $donation->Type<0){ 
            //Detect deposit situation or some sort of negative transaction. 
            //Default to email set as contact email.
            $donation->FromEmailAddress=self::get_deposit_email();
        }
        //Fields we should drop:
        $donation->AddressStatus=$payer->address_status=="Y"?1:-1;    
        $donation->NotTaxExcempt =0;

        return $donation;

        ####
        //$donation->Status=$transaction->transaction_status; //?
        //calculated -> "Type",
        ###
        //"DonorId",,"Status","ToEmailAddress",""AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber",];	 

    }

    static public function get_deposit_email(){
        $email=CustomVariables::get_option('ContactEmail');
        if (!$email) $email='deposit';
        return $email;
    }

    static public function transaction_event_code_to_type($transaction_event_code){
        //https://developer.paypal.com/docs/reports/reference/tcodes/
        switch($transaction_event_code){
            case "T0002": return 5; break; //subscription Payment
            case "T0013": return 1; break;
            case "T0400": return -1; break; //bank transfer
            default: return 0; break;
        }
    }

    public function donation_to_donor($override=array()){
        ### When uploading new dontations, this will find existings donors, or create new ones automatically.
        global $wpdb,$donation_to_donor; //cache donor lookups to speed up larger imports.       
        if ($this->DonorId>0){
            $this->suggest_donor_changes($override);
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
                if ($donation_to_donor[$donorField][$this->$donationField]){
                    $this->DonorId=$donation_to_donor[$donorField][$this->$donationField];
                    $this->suggest_donor_changes($override);
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
                }
                $donor = $wpdb->get_row( $wpdb->prepare("SELECT DonorId,MergedId FROM ".Donor::s()->get_table()." WHERE ".$where." Order By MergedId"));
                if ($donor->MergedId>0) $donor->DonorId=$donor->MergedId; //If this entry has been merged, send the merged entry. It's possible the merged entry will have a merged entry, but we don't check for that here. Handle this with a cleanup page.
                if ($donor->DonorId>0){                
                    $donation_to_donor[$donorField][$this->$donationField]=$donor->DonorId;
                    $this->DonorId=$donor->DonorId;
                    $this->suggest_donor_changes($override,$donorField."=>".$this->$donationField);
                    return $this->DonorId;
                }           
            }
        }

        ### Insert or Update Entry with existing Values
        $newEntry=[];
        
        ### Pull info from the donation:       
        foreach(self::DONATION_TO_DONOR as $donationField=>$donorField){
            if ($this->$donationField && in_array($donorField,Donor::get_fillable())){ //if field passed in AND it is a field on Donor Table
                $newEntry[$donorField]=$this->$donationField;
            }
        }
        ### Pull Info from the override
        if (sizeof($override)>0){
            foreach($override as $field=>$value){
                if ($value && in_array($field,Donor::get_fillable())){
                 $newEntry[$field]=$value;
                }
            }
        }
       
        //self::dump($newEntry);
       // self::dd($newEntry);
       $newEntry=$this->new_from_donation($override);
        if (sizeof($newEntry)>0){
            $donor=new Donor($newEntry);
            $donor->save();            
            $this->DonorId=$donor->DonorId;
            return $donor->DonorId;
        }

    }

    static public function stats($where=array()){
        $wpdb=self::db();  
        DonationCategory::consolidate_categories();

        $SQL="SELECT COUNT(DISTINCT DonorId) as TotalDonors, Count(*) as TotalDonations,SUM(`Gross`) as TotalRaised FROM ".Donation::get_table_name()." DD WHERE ".implode(" AND ",$where);
        $results = $wpdb->get_results($SQL);
        ?><table class="dp"><tr><th colspan=2>Period Stats</th><th>Avg</th></tr><?php
        foreach ($results as $r){
            ?><tr><td>Total Donors</td><td align=right><?php print $r->TotalDonors?></td><td align=right>$<?php print $r->TotalDonors<>0?number_format($r->TotalRaised/$r->TotalDonors,2):"-"?> avg per Donor</td></tr>
            <tr><td>Donation Count</td><td align=right><?php print $r->TotalDonations?></td><td align=right><?php print $r->TotalDonors<>0?number_format($r->TotalDonations/$r->TotalDonors,2):"-"?> avg # per Donor</td></tr>
            <tr><td>Donation Total</td><td align=right><?php print number_format($r->TotalRaised,2)?></td><td align=right>$<?php print $r->TotalDonations<>0?number_format($r->TotalRaised/$r->TotalDonations,2):"-"?> average Donation</td></tr>
            
            <?php
        }
         ?></table><?php

        $GroupFields=array('Type'=>'Type','Category'=>'CategoryId',"Source"=>'PaymentSource',"Month"=>"month(date)");
        $tinyInt=self::s()->tinyIntDescriptions;

        //load all donation categories since this is DB and not hardcoded.
        $result=DonationCategory::get();
        foreach($result as $r){
            $tinyInt['CategoryId'][$r->CategoryId]=$r->Category;
        }
        foreach($GroupFields as $gfa=>$gf){   
            $SQL="SELECT $gf as $gfa, COUNT(DISTINCT DonorId) as TotalDonors, Count(*) as TotalDonations,SUM(`Gross`) as TotalRaised FROM ".Donation::get_table_name()." DD WHERE ".implode(" AND ",$where)." Group BY $gf";
            $results = $wpdb->get_results($SQL);
            if (sizeof($results)>0){
                ?><table class="dp"><tr><th><?php print $gfa?></th><th>Total</th><th>Donations</th><th>Donors</th></tr><?php
                foreach ($results as $r){
                    ?><tr><td><?php print $r->$gfa.($tinyInt[$gf][$r->$gfa]?" - ". $tinyInt[$gf][$r->$gfa]:"")?></td>
                    <td align=right>$<?php print number_format($r->TotalRaised,2)?></td>
                    <td align=right><?php print number_format($r->TotalDonations)?></td>
                    <td align=right><?php print number_format($r->TotalDonors)?></td>
                    </tr><?php

                }?></table><?php
            }
        }
    }
         
        
    public function new_from_donation($override=array()){
        $newEntry=[];
        
        ### Pull info from the donation:       
        foreach(self::DONATION_TO_DONOR as $donationField=>$donorField){
            if ($this->$donationField && in_array($donorField,Donor::get_fillable())){ //if field passed in AND it is a field on Donor Table
                $newEntry[$donorField]=$this->$donationField;
            }
        }
        ### Pull Info from the override
        if (sizeof($override)>0){
            foreach($override as $field=>$value){
                if ($value && in_array($field,Donor::get_fillable())){
                 $newEntry[$field]=$value;
                }
            }
        }
        return $newEntry;
    }
    public function suggest_donor_changes($override=array(),$matchOn=""){
        if (!$this->DonorId) return false;
        global $suggest_donor_changes;
        $newEntry=$this->new_from_donation($override);
        if ($this->DonorId){ //first pull in exising values            
            $donor=Donor::get(array('DonorId='.$this->DonorId));
            if ($donor){
                foreach(Donor::get_fillable() as $field){
                    if ($field=="Name" && $newEntry[$field]==$newEntry['Email']){
                        continue; // skip change suggestion if Name was made the e-mail address 
                    }
                    if (strtoupper($donor[0]->$field)!=strtoupper($newEntry[$field]) && $newEntry[$field]){
                        $suggest_donor_changes[$this->DonorId]['Name']['c']=$donor[0]->Name;//Cache this to save a lookup later
                        if($matchOn) $suggest_donor_changes[$this->DonorId]['MatchOn'][]=$matchOn;
                        $suggest_donor_changes[$this->DonorId][$field]['c']=$donor[0]->$field;
                        $suggest_donor_changes[$this->DonorId][$field]['n'][$newEntry[$field]]++; //support multiple differences
                    }                
                }
            }
        }
        return $suggest_donor_changes[$this->DonorId];        
    }

    static public function donation_upload_groups(){
        $wpdb=self::db();  
        $SQL="SELECT `CreatedAt`,MIN(`DateDeposited`) as DepositedMin, MAX(`DateDeposited`) as DepositedMax,COUNT(*) as C,Count(R.ReceiptId) as ReceiptSentCount
        FROM ".Donation::get_table_name()." D
        LEFT JOIN ".DonationReceipt::get_table_name()." R
        ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE 1
        Group BY `CreatedAt` Order BY `CreatedAt` DESC LIMIT 20";
         $results = $wpdb->get_results($SQL);
         ?><h2>Upload Groups</h2>
         <form method="get" action=""><input type="hidden" name="page" value="<?php print $_GET['page']?>" />
			Summary From <input type="date" name="df" value="<?php print $_GET['df']?>"/> to <input type="date" name="dt" value="<?php print $_GET['dt']?>"/> Date Field: <select name="dateField">
            <?php foreach (self::s()->dateFields as $field=>$label){?>
                <option value="<?php print $field?>"<?php print $_GET['dateField']==$field?" selected":""?>><?php print $label?> Date</option>
            <?php } ?>
            </select>
             <button type="submit" name="SummaryView" value="t">View Summary</button></form>
         <table class="dp"><tr><th>Upload Date</th><th>Donation Deposit Date Range</th><th>Count</th><th></th></tr><?php
         foreach ($results as $r){?>
             <tr><td><?php print $r->CreatedAt?></td><td align=right><?php print $r->DepositedMin.($r->DepositedMax!==$r->DepositedMin?" to ".$r->DepositedMax:"")?></td><td><?php print $r->ReceiptSentCount." of ".$r->C?></td><td><a href="?page=<?php print $_GET['page']?>&UploadDate=<?php print urlencode($r->CreatedAt)?>">View All</a> <?php print ($r->ReceiptSentCount<$r->C?" | <a href='?page=".$_GET['page']."&UploadDate=".$r->CreatedAt."&unsent=t'>View Unsent</a>":"")?>| <a href="?page=<?php print $_GET['page']?>&SummaryView=t&UploadDate=<?php print urlencode($r->CreatedAt)?>">View Summary</a></td></tr><?php
            
         }?></table><?php
    }

    static public function view_donations($where=[],$settings=array()){ //$type[$r->Type][$r->DonorId]++;       
        if (sizeof($where)==0){
           self::display_error("No Criteria Given");
        }
        $wpdb=self::db();  
        $donorIdList=array();
        
        if ($settings['unsent']){
            $where[]="R.ReceiptId IS NULL";           
        }
        print "Criteria: ".implode(", ",$where);
        $SQL="Select D.*,R.Type as ReceiptType,R.Address,R.DateSent,R.ReceiptId
          FROM ".Donation::get_table_name()." D
        LEFT JOIN ".DonationReceipt::get_table_name()." R ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE ".implode(" AND ", $where)." Order BY D.Date DESC,  D.DonationId DESC;";
   
        $donations = $wpdb->get_results($SQL);
        foreach ($donations as $r){
            $donorIdList[$r->DonorId]++;
            $type[$r->Type][$r->DonorId][$r->DonationId]=$r;
        }
        //
        if (sizeof($donorIdList)>0){
            $donors=Donor::get(array("DonorId IN ('".implode("','",array_keys($donorIdList))."')"),'',array('key'=>true));
            // Find if first time donation
            $result=$wpdb->get_results("Select DonorId, Count(*) as C From ".Donation::get_table_name()." where DonorId IN ('".implode("','",array_keys($donorIdList))."') Group BY DonorId");
            foreach ($result as $r){
                $donorCount[$r->DonorId]=$r->C;
            }
        }
        
        if (sizeof($donations)>0){   
            if ( $settings['summary']){
                ksort($type);
                foreach ($type as $t=>$donationsByType){                    
                    $total=0;
                    ?><h2><?php print self::s()->tinyIntDescriptions["Type"][$t]?></h2>
                    <table class="dp"><tr><th>Donor</th><th>E-mail</th><th>Date</th><th>Gross</th><th>CategoryId</th><th>Note</th><th>LifeTime</th></tr><?php
                    foreach($donationsByType as $donations){
                        $donation=new Donation($donations[key($donations)]);
                        ?><tr><td  rowspan="<?php print sizeof($donations)?>"><?php
                        if ($donors[$donation->DonorId]){
                            //print $donors[$donation->DonorId]->display_key()." ".
                            print $donors[$donation->DonorId]->name_check();
                        }else{ 
                            print $donation->DonorId;
                            //self::dd($donations);
                            //exit();
                        }
                    
                        ?></td>
                        <td rowspan="<?php print sizeof($donations)?>"><?php print $donors[$donation->DonorId]?$donors[$donation->DonorId]->display_email('Email'):""?></td><?php
                        $count=0;
                        foreach($donations as $r){                          
                            if ($count>0){
                                $donation=new Donation($r);
                                print "<tr>";
                            } 
                           ?><td><?php print $donation->Date?></td><td align=right><?php print $donation->show_field('Gross')?> <?php print $donation->Currency?></td><td><?php
                            if ($donation->CategoryId) print $donation->show_field("CategoryId");
                            else print $donation->Subject;
                            ?></td><td><?php print $donation->show_field("Note")?></td>  
                            <td <?php print $donorCount[$donation->DonorId]==1?" style='background-color:orange;'":""?>><?php  print "x".$donorCount[$donation->DonorId].($donorCount[$donation->DonorId]==1?" FIRST TIME!":"")."";?> </td>               
                            </tr><?php
                            $total+=$donation->Gross;
                            $count++;
                        }
                    }
                    ?><tfoot><tr><td colspan=3>Totals:</td><td align=right><?php print number_format($total,2)?></td><td></td><td></td><td></td></tr></tfoot></table><?php
                }

            }else{?>
                <form method="post"><button type="submit" name="Function" value="EmailDonationReceipts">Send E-mail Receipts</button>
                <table class="dp"><tr><th></th><th>Donation</th><th>Date</th><th>DonorId</th><th>Gross</th><th>CategoryId</th><th>Note</th><th>Type</th></tr><?php
                foreach($donations as $r){
                    $donation=new Donation($r);
                    ?><tr><td><?php
                        if ($r->ReceiptType){
                            print "Sent: ".$r->ReceiptType." ".$r->Address;
                        }else{
                            ?> <input type="checkbox" name="EmailDonationId[]" value="<?php print $donation->DonationId?>" checked/> <a target="donation" href="?page=donor-index&DonationId=<?php print $donation->DonationId?>">Custom Response</a><?php
                        }?></td><td><?php print $donation->display_key()?></td><td><?php print $donation->Date?></td><td <?php print $donorCount[$donation->DonorId]==1?" style='background-color:orange;'":""?>><?php
                        if ($donors[$donation->DonorId]){
                            print $donors[$donation->DonorId]->display_key()." ".$donors[$donation->DonorId]->name_check();
                        }else print $donation->DonorId;
                        print " (x".$donorCount[$donation->DonorId]
                        .($donorCount[$donation->DonorId]==1?" FIRST TIME!":"")
                        .")";
                        ?></td><td><?php print $donation->show_field('Gross')?> <?php print $donation->Currency?></td><td><?php
                        if ($donation->CategoryId) print $donation->show_field("CategoryId",false);
                        else print $donation->Subject;
                        ?></td>
                        <td><?php print $donation->show_field("Note")?></td>
                        <td><?php print $donation->show_field("Type")?></td>                
                    </tr><?php
                }
                ?></table><?php
            }

        }
    
        if (!$settings['summary']){
           // $all=self::get($where);
           // print self::show_results($all);
        }
        
    }

    static public function upload_dir(){
        return dn_plugin_base_dir()."/uploads/";
    }

    static public function csv_upload_check(){
        $timeNow=time();
        if(isset($_FILES['fileToUpload'])){
            $originalFile=basename($_FILES["fileToUpload"]["name"]);
           // $target_file = $tmpfname = tempnam(sys_get_temp_dir(), 'CSV');            
            //if (!file_exists(self::upload_dir()) {   mkdir(self::upload_dir(), 0777, true); }
            $target_file=self::upload_dir()."/".$originalFile;
            //dd($target_file);
            if (file_exists($target_file)){ 
                unlink($target_file);
            }
           
            if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
                self::display_notice("The file ". $originalFile. " has been uploaded.");               
                if ($_REQUEST['submit']=="Upload File"){
                    $result=self::csv_read_file_map($originalFile,$firstLineColumns=true,$timeNow);
                    return; 
                }elseif ($_REQUEST['submit']=="Upload NonPaypal"){
                    $result=self::csv_read_file_check($target_file,$firstLineColumns=true);                               
                }else{              
                    $result=self::csv_read_file($target_file,$firstLineColumns=true,$timeNow);                   
                }
                ### This next step will Insert records if there is no duplicate on ["Date","Gross","FromEmailAddress","TransactionID"];
                if ($stats=self::replace_into_list($result)){//inserted'=>sizeof($iSQL),'skipped'
                    echo "<div>Inserted ".$stats['inserted']." records. Skipped ".$stats['skipped']." repeats.</div>";
                    unlink($target_file); //don't keep it on the server...
                }
                global $suggest_donor_changes;

                 if ($suggest_donor_changes && sizeof($suggest_donor_changes)>0){
                     print "<h2>The following changes are suggested</h2><form method='post'>";
                     print "<table border='1'><tr><th>#</th><th>Name</th><th>Change</th></tr>";
                     foreach ($suggest_donor_changes as $donorId => $changes){
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
                     //self::dump($suggest_donor_changes);
                     //To do: timezone off. by -5
                     print "<hr>";
                     print "<div><a target='viewSummary' href='?page=donor-reports&UploadDate=".date("Y-m-d H:i:s",$timeNow)."'>View All</a> | <a target='viewSummary' href='?page=donor-reports&SummaryView=t&UploadDate=".date("Y-m-d H:i:s",$timeNow)."'>View Summary</a></div>";
                 }


                 if ($_POST['uploadSummary']=="true"){

                 }
            } else {                 
                self::display_error("Sorry, there was an error uploading your file: ".$originalFile."<br>Destination: ".$target_file);
            }           
        }
    }

    static public function csv_read_file_check($csvFile,$firstLineColumns=true){
        $donorMap=["Name1"=>"Name","Name2"=>"Name2","Note"=>"Note"];
        $donationMap=["Name1"=>"Name","Email"=>"FromEmailAddress","DepositDate"=>"DateDeposited","CheckDate"=>"Date","CheckNumber"=>"TransactionID","Name1"=>"Name","Gross"=>"Gross","ReceiptNeeded"=>"ReceiptId"];
        $row=0;
        $headerRow=array();
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
                   
                    $obj->donation_to_donor($donorFill,true);  //Will Set DonorId on Donation Table.    
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

    static public function process_map_file($post){
        //dd($post);
        $csv=self::csv_file_to_array($post["file"],$post["firstLineColumns"]);
       
        $recommended_bulk['Donor']=["Source","Country"];
        $recommended_bulk['Donation']=["Date","DateDeposited","Source","PaymentSource"];
        
        $selectDonation=Donation::s()->fillable; 
        $selectDonor=Donor::s()->fillable; 

        foreach($csv->data as $row => $r){
            $donor = new Donor();
            $donation = new Donation();
            foreach($r as $c=>$v){
                if (trim($v)=="") continue; //skip blanks    
                if ($field=$post['column'.$c]){                    
                    switch($field){
                        case 'Last, Name1 & Name2':
                            $andSplit=explode("&",$v);
                            $commaSplit=explode(",",$andSplit[0]);
                            if (sizeof($andSplit)>=1 && sizeof($andSplit)<=2 && sizeof($commaSplit)==2){
                                $donor->Name=trim($commaSplit[1])." ".trim($commaSplit[0]);
                                if (trim($andSplit[1])) $donor->Name2=trim($andSplit[1])." ".trim($commaSplit[0]);
                            }else{
                                $donor->Name=trim($v);
                            }
                            $donation->Name=trim($v);
                            //dd($donor);
                            //print "pattern $field set to $v on Index $c. <br>";
                        break;
                        case 'City, Region Postal Country':
                            $commaSplit=explode(",",$v);
                            $regionSplit=explode(" ",$commaSplit[1]);
                            $donor->City=trim($commaSplit[0]);
                            $donor->Region=trim($regionSplit[0]);
                            $donor->PostalCode=trim($regionSplit[1]);
                            if($regionSplit[2]) $donor->Country=trim($regionSplit[2]);
                            //print "pattern $field set to $v on Index $c. <br>";
                        break;
                        case "Date":
                        case "DateDeposited":
                            if ($v) $v=date("Y-m-d",strtotime($v));
                        break;
                        default:
                            if (in_array($field,$selectDonor)){
                                //print "field $field set to $v on Index $c row $row. <br>";
                                $donor->$field=trim($v);
                            }
                            if (in_array($field,$selectDonation)){
                                $donation->$field=trim($v);
                            }
                        break;
                    }                          

                    //dd($headerField,$field,$c,$selectDonor,$v,$post,$csv,$donor);
                
                }               
            }
            //dd($donor,$post,$csv);
            if ($donor->Name){
                $key=$donor->flat_key($_POST['donorKey']);
                $donors[$key]['donor']=$donor;
                $donors[$key]['donations'][$donation->flat_key()]=$donation;
            }else{
                $skippedLines[]=$r;
            }
        }
        ksort($donors);
        $stats=[];
        ### get donor keys for ALL donors -> if this gets big, might run into memory errors with this.
        $SQL="SELECT DonorId,MergedId,".(implode(",",$_POST['donorKey']))." FROM ".Donor::s()->get_table();
		$all=self::db()->get_results($SQL);
        foreach($all as $r){
            $donor=new Donor($r);
            $existingDonors[$donor->flat_key($_POST['donorKey'])]=$r->MergedId>0?$r->MergedId:$r->DonorId; //if the match has been merged, then return the merged to entry.
        }

        foreach($donors as $key=>$a){
            if ($existingDonors[$key]){
                $a['donor']->DonorId=$existingDonors[$key]; //donor already created
                $stats['donorFound']++;
            }else{
                $a['donor']->save();
                $stats['donorCreated']++;
            }          
            
            foreach($a['donations'] as $donation){
                ### eventually add a duplicate donation check...
                $donation->DonorId= $a['donor']->DonorId;
                $donation->save();
                $stats['donationsAdded']++;
            }
        }
        $notice="The following was the result of this export:<ul>";
        if ($stats['donorFound']) $notice.="<li>".$stats['donorFound']." Donors already existed.</li>";
        if ($stats['donorCreated']) $notice.="<li>".$stats['donorCreated']." Donors Created.</li>";
        if ($stats['donationsAdded']) $notice.="<li>".$stats['donationsAdded']." Donations Added.</li>";
        $notice.="</ul>";
        self::display_notice($notice);
        return true;

    }

    static public function csv_read_file_map($csvFile,$firstLineColumns=true,$timeNow=""){
        if (!$timeNow) $timeNow=time();
        $csv=self::csv_file_to_array($csvFile,$firstLineColumns);
        $selectDonation=Donation::s()->fillable; 
        $selectDonor=Donor::s()->fillable; 
        ?>
        <form method="post">
            <input type="hidden" name="file" value="<?php print $csvFile;?>"/>
            <input type="hidden" name="timenow" value="<?php print $timeNow?>"/>
            <?php if ($firstLineColumns){?> 
                <input type="hidden" name="firstLineColumns" value="true"/>
            <?php }?>           
            <button name="Function" value="ProcessMapFile">Process File</button>
            Key Fields -  
            Donor:
            <select name="donorKey[]" multiple>
                <?php 
                $flatKeys=Donor::s()->flat_key;
                foreach($flatKeys as $field){
                    ?><option name="<?php print $field?>" selected><?php print $field?></option>
                <?php 
                }
                foreach(Donor::s()->fillable as $field){
                    if (!in_array($field,$flatKeys)) print "<option>".$field."</option>";
                }                    
                ?>
            </select> 
            Donation: <select name="donationKey[]" multiple>
                <?php 
                $flatKeysD=Donation::s()->flat_key;
                foreach($flatKeysD as $field){
                    ?><option name="<?php print $field?>" selected><?php print $field?></option>
                <?php }
                foreach(Donation::s()->fillable as $field){
                    if (!in_array($field,$flatKeys)) print "<option>".$field."</option>";
                } 
                
                ?>
            </select>  <em>Key fields are used to detect duplicates</em>
        <table class="dp">
            <tr><?php foreach($csv->headers as $c =>$headerField) 
                    print "<th>".$headerField."</th>";
            ?></tr>
            <tr><?php foreach($csv->headers as $c => $headerField){
                $selected="";
                foreach(self::UPLOAD_AUTO_SELECT as $key=>$fieldsMapped){
                    if (strpos(strtolower($headerField),strtolower($key))>-1) $selected=$fieldsMapped;
                }               
                ?>
                <th><select name="column<?php print $c?>">
                    <option value="">--ignore--</option>
                    <?php foreach(self::UPLOAD_PATTERN as $pattern =>$field){                       
                        ?><option value="<?php print $pattern?>"<?php print strtolower($headerField)==strtolower($field) ||$selected==$field?" selected":"";?>><?php print $pattern?></option><?php
                    }?>
                    <option disabled>--Donor Fields--</option>
                    <?php foreach($selectDonor as $field){                          
                        ?><option value="<?php print $field?>"<?php print strtolower($headerField)==strtolower($field) ||$selected==$field?" selected":"";?>><?php print $field?></option><?php
                    }?>
                     <option disabled>--Donation Fields--</option>
                    <?php foreach($selectDonation as $field){
                        ?><option value="<?php print $field?>"<?php print strtolower($headerField)==strtolower($field) ||$selected==$field?" selected":"";?>><?php print $field?></option><?php
                    }?>
                </select><?php print $selected;?></th>
            <?php }?>
            </tr>
        <?php foreach($csv->data as $r){?>
            <tr><?php foreach($csv->headers as $c => $headerField){?>
                <td><?php print $r[$c]?></td>
                <?php } ?>
            </tr>        
        <?php } ?>
        </table>
        </form>
        <?php
        dd($csv);      
        
    }

    static public function csv_file_to_array($csvFile,$firstLineColumns=true,$settings=[]){      
        $headerRow=$return=$headers=[];
        if (($handle = fopen(self::upload_dir().$csvFile, "r")) !== FALSE) {
            $row=0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {        
                if ($firstLineColumns && $row==0){
                    $headerRow= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $data);
                }else{
                    $array=[];                   
                    for ($c=0; $c<sizeof($data); $c++) {
                        if ($headerRow) {
                            $fieldName=preg_replace("/[^a-zA-Z0-9 ]+/", "", $headerRow[$c]);//str_replace(" ","",$headerRow[$c]);   
                            if (!$fieldName) $fieldName=$c;
                        }else $fieldName=$c;                  
                        if (trim($data[$c])){ 
                            $array[$c]=$data[$c];
                            $headers[$fieldName]++;
                        }
                    } 
                    if (sizeof($array)>0){
                        $return[$row]=$array; 
                    }
                }
                $row++;
            }
        }else{
            self::display_error("Could not open ".self::upload_dir().$csvFile);
        }
        return (object)['headers'=>array_keys($headers),'data'=>$return];
    }

    static public function csv_read_file($csvFile,$firstLineColumns=true,$timeNow=""){//2019-2020-05-23.CSV
        if (!$timeNow) $timeNow=time();
        //self::create_table();
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
                    ###  helps handle a file from PPGF -> Paypal Giving file try to handle. Source: https://www.paypal.com/givingfund/activity
                    $fieldShift=["CurrencyCode"=>"Currency","DonorEmail"=>'FromEmailAddress',"ReferenceInformation"=>"Note","DonationDate"=>"Date","GrossAmount"=>"Gross","TotalFees"=>"Fee","NetAmount"=>"Net"];                  
            
                    if (($paypal['DonorFirstName'] || $paypal['DonorLastName']) && !$paypal['Name']){
                        $paypal['Name']=trim($paypal['DonorFirstName']." ".$paypal['DonorLastName']); 

                        $entry['DateDeposited']=date("Y-m-d",strtotime($paypal['PayoutDate']));  
                        
                        $entry['CategoryId']=DonationCategory::get_category_id($paypal['ProgramName']);
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
                                    $entry['CategoryId']=DonationCategory::get_category_id($paypal['ItemTitle']);
                                    
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
                    $entry['Source']='paypal';
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
                    }
                    $obj=new self($entry);
                    // self::dump($obj);
                    $obj->donation_to_donor();  //Will Set DonorId on Donation Table.      
                    //self::dd($obj);         
                    $q[]=$obj;
                }	
                $row++;
            }
            fclose($handle);
        }	
        return $q;
    }

    static public function request_handler(){
        if (!isset($_GET['f'])) $_GET['f']=null;
        if(isset($_FILES['fileToUpload'])){
            self::csv_upload_check();
            return true;
        }elseif($_POST['Function']=="ProcessMapFile"){
            self::process_map_file($_POST);
        }       

        elseif ($_GET['f']=="AddDonation"){           
            $donation=new Donation();
            if ($_GET['DonorId']){
               $donor=Donor::get_by_id($_GET['DonorId']);
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

            $donation->edit_simple_form();           
            return true;
        }elseif($_GET['f']=="ViewPaymentSourceYearSummary" && $_GET['Year']){
            self::summary_by_payment_source($_GET['PaymentSource'],$_GET['Year']);
            print "coming soon";
            return true;
            
        }elseif ($_POST['Function']=="Cancel" && $_POST['table']=="Donation"){
            $donor=Donor::get_by_id($_POST['DonorId']);
            $donor->view();
            return true;
        }elseif ($_REQUEST['DonationId']){	
            if ($_POST['Function']=="Delete" && $_POST['table']=="Donation"){
                $donation=new Donation($_POST);
                if ($donation->delete()){
                    self::display_notice("Donation #".$donation->show_field("DonationId")." for $".$donation->Gross." from ".$donation->Name." on ".$donation->Date." deleted");
                    //$donation->full_view();
                    return true;
                }
            }
            if ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
                $donation=new Donation($_POST);
                if ($donation->save()){
                    self::display_notice("Donation #".$donation->show_field("DonationId")." saved.");
                    $donation->full_view();
                    return true;
                }
            }
            $donation=Donation::get_by_id($_REQUEST['DonationId']);	
            $donation->full_view();
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donation"){
            $donation=new Donation($_POST);            
            if ($donation->save()){
                self::display_notice("Donation #".$donation->show_field("DonationId")." saved.");
                $donation->full_view();
            }
            return true;
        }elseif($_GET['UploadDate'] || $_GET['SummaryView']){                    
            if ($_POST['Function']=="EmailDonationReceipts" && sizeof($_POST['EmailDonationId'])>0){               
                foreach($_POST['EmailDonationId'] as $donationId){
                    $donation=Donation::get_by_id($donationId);
                    if ($donation->FromEmailAddress){                        
                        print $donation->email_receipt($donation->FromEmailAddress);
                    }else{
                        print "not sent to: ".$donationId." ".$donation->Name."<br>";
                    }                   
                }                
            }
            ?>
             <div>
                    <div><a href="?page=<?php print $_GET['page']?>">Return</a></div><?php
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
                    self::view_donations($where,
                        array(
                            'unsent'=>$_GET['unsent']=="t"?true:false,
                            'summary'=>$_GET['SummaryView']=="t"?true:false
                            )
                        );                    
             ?></div><?php
             exit();
            return true;
        }else{
            return false;
        }
    }

    public function full_view(){
        ?>
            <div>
                <div><a href="?page=<?php print $_GET['page']?>">Return</a></div>
                <h1>Donation #<?php print $this->DonationId?></h1><?php 
                if ($_REQUEST['edit']){
                    $this->edit_form();
                }else{
                    ?><div><a href="?page=donor-index&DonationId=<?php print $this->DonationId?>&edit=t">Edit Donation</a></div><?php
                    $this->view();
                    $this->receipt_form();                   
                }
            ?></div><?php
    }

    public function select_drop_down($field,$showKey=true,$allowBlank=false){
        ?><select name="<?php print $field?>"><?php
        if ($allowBlank){
            ?><option></option<?php
        }
        foreach($this->tinyIntDescriptions[$field] as $key=>$label){
            ?><option value="<?php print $key?>"<?php print $key==$this->$field?" selected":""?>><?php print ($showKey?$key." - ":"").$label?></option><?php
        }
        ?></select><?php
    }
    public function edit_simple_form(){  
        $hiddenFields=['DonationId','Fee','Net','ToEmailAddress','ReceiptID','AddressStatus']; //these fields more helpful when using paypal import, but are redudant/not necessary when manually entering a transaction
        ?>
        <form method="post" action="?page=donor-reports&DonationId=">
        <input type="hidden" name="table" value="Donation">
        <?php foreach ($hiddenFields as $field){?>
		    <input type="hidden" name="<?php print $field?>" value="<?php print $this->$field?>"/>
        <?php } ?>
        <table><tbody>
        <tr><td align="right">Total Amount</td><td><input required type="number" step=".01" name="Gross" value="<?php print $this->Gross?>"><?php $this->select_drop_down('Currency',false);?></td></tr> 
        <tr><td align="right">Check #/Transaction ID</td><td><input type="txt" name="TransactionID" value=""></td></tr>
        <tr><td align="right">Check/Sent Date</td><td><input type="date" name="Date" value="<?php print ($this->Date?$this->Date:date("Y-m-d"))?>"></td></tr>
        <tr><td align="right">Date Deposited</td><td><input type="date" name="DateDeposited" value="<?php print ($this->DateDeposited?$this->DateDeposited:date("Y-m-d"))?>"></td></tr>
        
        <tr><td align="right">DonorId</td><td><?php
        if ($this->DonorId){
            ?><input type="hidden" name="DonorId" value="<?php print $this->DonorId?>"> #<?php print $this->DonorId?><?php
        }else{
            ?><input type="text" name="DonorId" value="<?php print $this->DonorId?>"> Todo: Make a chooser or allow blank, and/or create after this step. <?php
        }
        ?></td></tr>
        <tr><td align="right">Name</td><td><input type="text" name="Name" value="<?php print $this->Name?>"></td></tr>
        <tr><td align="right">Email Address</td><td><input type="email" name="FromEmailAddress" value="<?php print $this->FromEmailAddress?>"></td></tr>
        <tr><td align="right">Phone Number</td><td><input type="tel" name="ContactPhoneNumber" value="<?php print $this->ContactPhoneNumber?>"></td></tr>

        <tr><td align="right">Payment Source</td><td> <?php $this->select_drop_down('PaymentSource');?></td></tr>
        <tr><td align="right">Type</td><td> <?php $this->select_drop_down('Type');?></td></tr>        
        <tr><td align="right">Status</td><td><?php $this->select_drop_down('Status');?></td></tr>

       <!-- <tr><td align="right">Address Status</td><td><?php $this->select_drop_down('AddressStatus');?></td></tr> -->
       <tr><td align="right">Category</td><td><select name="CategoryId"><?php
            $donationCategory=DonationCategory::get(array('(ParentId=0 OR ParentId IS NULL)'),'Category');
            foreach($donationCategory as $cat){
                ?><option value="<?php print $cat->CategoryId?>"<?php print $cat->CategoryId==$this->CategoryId?" selected":""?>><?php print $cat->Category?></option><?php
            }
       ?></select></td></tr>
       <tr><td align="right">Subject</td><td><input type="text" name="Subject" value="<?php print $this->Subject?>"></td></tr>
        <tr><td align="right">Note</td><td><textarea name="Note"><?php print $this->Note?></textarea></td></tr>
        <tr><td align="right">Tax Excempt</td><td><?php print $this->select_drop_down('NotTaxExcempt')?><div><em>Set to "Not Tax Exempt" if they have already been giving credit for the donation by donating through a donor advised fund.</div></td></tr>
        <tr></tr><tr><td colspan="2"><button type="submit" class="Primary" name="Function" value="Save">Save</button><button type="submit" name="Function" class="Secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->DonationId){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?php
        }
        ?>
    </td></tr>
		</tbody></table>
		</form>
        <?php
    }

    function receipt_email(){
        if ($this->emailBuilder) return;
        $this->emailBuilder=new stdClass();
        
        if ($this->NotTaxExcempt==1){                   
            $page = DonorTemplate::get_by_name('no-tax-thank-you');  
            if (!$page){ ### Make the template page if it doesn't exist.
                self::make_receipt_template_no_tax();
                $page = DonorTemplate::get_by_name('no-tax-thank-you');  
                self::display_notice("Page /no-tax-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
            }
        }else{
            $page = DonorTemplate::get_by_name('receipt-thank-you');  
            if (!$page){ ### Make the template page if it doesn't exist.
                self::make_receipt_template_thank_you();
                $page = DonorTemplate::get_by_name('receipt-thank-you');  
                self::display_notice("Page /receipt-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
            }
        }
         
        if (!$page){ ### Make the template page if it doesn't exist.
            self::make_receipt_template_thank_you();
            $page = DonorTemplate::get_by_name('receipt-thank-you');  
            self::display_notice("Page /receipt-thank-you created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $organization=get_option( 'donation_Organization');
        if (!$organization) $organization=get_bloginfo('name');
        if (!$this->Donor)  $this->Donor=Donor::get_by_id($this->DonorId);
        $address=$this->Donor->mailing_address();

        $subject=$page->post_title;
        $body=$page->post_content;
        $body=trim(str_replace("##Organization##",$organization,$body));
        $body=str_replace("##Name##",$this->Donor->Name.($this->Donor->Name2?" & ".$this->Donor->Name2:""),$body);
        //$body=str_replace("##Year##",$year,$body);
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

    static function make_receipt_template_no_tax(){
        $page = DonorTemplate::get_by_name('no-tax-thank-you');  
        if (!$page){
            $postarr['ID']=0;

            $tempLoc=dn_plugin_base_dir()."/resources/template_default_receipt_no_tax.html";          
            $postarr['post_content']=file_get_contents($tempLoc);            
            $postarr['post_title']='Thank You For Your ##Organization## Gift';
            $postarr['post_status']='private';
            $postarr['post_type']='donortemplate';
            $postarr['post_name']='no-tax-thank-you';
            return wp_insert_post($postarr);            
        }
    }
    static function make_receipt_template_thank_you(){
        $page = DonorTemplate::get_by_name('receipt-thank-you');  
        if (!$page){
            $postarr['ID']=0;
            $tempLoc=dn_plugin_base_dir()."/resources/template_default_receipt_thank_you.html";          
            $postarr['post_content']=file_get_contents($tempLoc);
            $postarr['post_title']='Thank You For Your ##Organization## Donation';
            $postarr['post_status']='private';
            $postarr['post_type']='donortemplate';
            $postarr['post_name']='receipt-thank-you';
            return wp_insert_post($postarr);            
        }    
    }

    public function email_receipt($email="",$customMessage=null,$subject=null){      
        $this->receipt_email();
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
        }else{
            self::display_error("Error sending e-mail to: ".$email.". Check your wordpress email sending settings.");
        }
        return $notice;
    }

    public function pdf_receipt($customMessage=null){
        if (!class_exists("TCPDF")){
            self::display_error("PDF Writing is not installed. You must run 'composer install' on the donor-press plugin directory to get this to funciton.");
            return false;
        }
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false); 
        $file=$this->receipt_file_info();        
        $pdf->AddPage();
        $this->receipt_email();
        $pdf->writeHTML($customMessage?$customMessage:$this->emailBuilder->body, true, false, true, false, '');        
        
        $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"DonationId","KeyId"=>$this->DonationId,"Type"=>"p","Address"=>$this->Donor->mailing_address(),"DateSent"=>date("Y-m-d H:i:s"),"Content"=>$customMessage));
        $dr->save();

        if ($pdf->Output($file, 'D')){
            return true;
        }else return false;
    }

    public function receipt_file_info(){
        return substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-DT'.$this->DonationId.'.pdf';
    }


    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
        $sql = "CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
                `DonationId` int(11) NOT NULL,
                `Date` datetime NOT NULL,
                `DateDeposited` date DEFAULT NULL,
                `DonorId` int(11) DEFAULT NULL,
                `Name` varchar(80) NOT NULL,
                `Type` tinyint(4) DEFAULT NULL,
                `TypeOther` varchar(30) DEFAULT NULL,
                `Status` tinyint(4) DEFAULT NULL,
                `Currency` varchar(3) DEFAULT NULL,
                `Gross` float(10,2) NOT NULL,
                `Fee` decimal(6,2) DEFAULT NULL,
                `Net` varchar(10) DEFAULT NULL,
                `FromEmailAddress` varchar(70) NOT NULL,
                `ToEmailAddress` varchar(26) DEFAULT NULL,
                `Source` varchar(20) NOT NULL,
                `SourceId` varchar(50) NOT NULL,
                `TransactionID` varchar(17) DEFAULT NULL,
                `AddressStatus` tinyint(4) DEFAULT NULL,
                `CategoryId` tinyint(4) DEFAULT NULL,
                `ReceiptID` varchar(16) DEFAULT NULL,
                `ContactPhoneNumber` varchar(20) DEFAULT NULL,
                `Subject` varchar(50) DEFAULT NULL,
                `Note` text,
                `PaymentSource` tinyint(4) DEFAULT NULL,
                `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `NotTaxExcempt` tinyint(4) DEFAULT '0' COMMENT '0=TaxExempt 1=Not Tax Excempt',
                QBOInvoiceId int(11) DEFAULT NULL
                )";               
        dbDelta( $sql );        
    }

    

    static public function summary_by_payment_source($paymentSource,$year){ 
        $SQL="Select DT.DonationId,D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, DT.`Date`,DT.DateDeposited,DT.Gross,DT.TransactionID,DT.Subject,DT.Note,DT.PaymentSource       
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE YEAR(Date)='".$year."' AND  Status>=0  AND PaymentSource='".$paymentSource."' Order BY DT.Date,DonationId";
        //AND Type>=0
        //print $SQL;
        $results = self::db()->get_results($SQL);
        //return;
        ?><table class="dp"><tr><th>DonationId</th><th>Name</th><th>Transaction Id</th><th>Amount</th><th>Date</th><th>Deposit Date</th></tr><?php
        foreach ($results as $r){?>
            <tr>
            <td><a target="donation" href="?page=donor-reports&DonationId=<?php print $r->DonationId?>"><?php print $r->DonationId?></a> | <a target="donation" href="?page=donor-reports&DonationId=<?php print $r->DonationId?>&edit=t">Edit</a></td>
                <td><?php print $r->Name?></td>
            <td><?php print $r->TransactionID?></td>
            <td align=right><?php print number_format($r->Gross,2)?></td>
            <td><?php print $r->Date?></td>
            <td><?php print $r->DateDeposited?></td>
            </tr><?php
        }
        ?></table><?php
    }

    public function receipt_form(){  
            
        $this->receipt_email();            
        if ($_POST['Function']=="SendDonationReceipt" && $_POST['Email']){
            print $this->email_receipt($_POST['Email'],stripslashes_deep($_POST['customMessage']),stripslashes_deep($_POST['EmailSubject']));
            
        }
        
        print "<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        $file=$this->receipt_file_info();
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","KeyType='DonationId'","KeyId='".$this->DonationId."'"));
        $bodyContent=$receipts[0]->Content?$receipts[0]->Content:$this->emailBuilder->body; //retrieve last saved custom message
        $bodyContent=$_POST['customMessage']?stripslashes_deep($_POST['customMessage']):$bodyContent; //Post value overrides this though.
        if (CustomVariables::get_option('QuickbooksClientId',true) && !$this->QBOInvoiceId){
            print '<a href="?page=donor-quickbooks&syncDonation='.$this->DonationId.'">Sync Donation to an Invoice on QuickBooks</a>';
        }
        //$receipts[0]->content
   
        print DonationReceipt::show_results($receipts,"You have not sent this donor a Receipt.");

        $emailToUse=($_POST['Email']?$_POST['Email']:$this->FromEmailAddress);
        if (!$emailToUse) $emailToUse=$this->Donor->Email;
        ?><form method="post">
            <h2>Send Receipt</h2>
            <input type="hidden" name="DonationId" value="<?php print $this->DonationId?>">
            <div>Send Receipt to: <input type="email" name="Email" value="<?php print $emailToUse?>">
                <button type="submit" name="Function" value="SendDonationReceipt">Send E-mail Receipt</button> 
                <button type="submit" name="Function" value="DonationReceiptPdf">Generate PDF</button>                  
            </div>
            <div><a target='pdf' href='post.php?post=<?php print $this->emailBuilder->pageID?>&action=edit'>Edit Template</a></div>
            <div style="font-size:18px;"><strong>Email Subject:</strong> <input style="font-size:18px; width:500px;" name="EmailSubject" value="<?php print $_POST['EmailSubject']?stripslashes_deep($_POST['EmailSubject']):$this->emailBuilder->subject?>"/>
            <?php wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false)); ?>
        </form>
        <?php    
    }
}