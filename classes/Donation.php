<?php
require_once 'Donor.php';
require_once 'DonationCategory.php';
require_once 'DonorTemplate.php';
class Donation extends ModelLite
{
    protected $table = 'Donation';
	protected $primaryKey = 'DonationId';
	### Fields that can be passed //,"Time","TimeZone"
    public $fillable = ["Date","DateDeposited","DonorId","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","Source","SourceId","TransactionID","AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber","Subject","Note","PaymentSource","NotTaxDeductible","QBOInvoiceId"];	 

    public $flat_key = ["Date","Name","Gross","FromEmailAddress","TransactionID"];
    protected $duplicateCheck=["Date","Gross","FromEmailAddress","TransactionID"]; //check these fields before reinserting a matching entry.
   
    public $tinyIntDescriptions=[
        "Status"=>["9"=>"Completed","7"=>"Pending","0"=>"Unknown","-1"=>"Deleted","-2"=>"Denied"],
        "AddressStatus"=>[0=>"Not Set",-1=>"Non-Confirmed",1=>"Confirmed"],
        "PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"ACH/Bank Transfer","10"=>"Paypal"],
        "Type"=>[0=>"Other",1=>"Donation Payment",2=>"Website Payment",5=>"Subscription Payment",-2=>"General Currency Conversion",-1=>"General Withdrawal","-3"=>"Expense"],
        "Currency"=>["USD"=>"USD","CAD"=>"CAD","GBP"=>"GBP","EUR"=>"EUR","AUD"=>"AUD"],
        "NotTaxDeductible"=>["0"=>"Tax Deductible","1"=>"Not Tax Deductible (Donor Advised fund, etc)"]
    ];

    public $dateFields=[
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
        $donation->NotTaxDeductible =0;

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
        $limit=is_int($_GET['limit'])?$_GET['limit']:20;
        $wpdb=self::db();  
        $SQL="SELECT `CreatedAt`,MIN(`DateDeposited`) as DepositedMin, MAX(`DateDeposited`) as DepositedMax,COUNT(*) as C,Count(R.ReceiptId) as ReceiptSentCount
        FROM ".Donation::get_table_name()." D
        LEFT JOIN ".DonationReceipt::get_table_name()." R
        ON KeyType='DonationId' AND R.KeyId=D.DonationId WHERE 1
        Group BY `CreatedAt` Order BY `CreatedAt` DESC LIMIT ".$limit;
         $results = $wpdb->get_results($SQL);
         ?><h2>Upload Groups</h2>
         <form method="get" action="">
            <input type="hidden" name="page" value="<?php print $_GET['page']?>" />
            <input type="hidden" name="tab" value="<?php print $_GET['tab']?>" />
            Limit: <input type="number" name="limit" value="<?php print $limit?>"/>
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
                <form method="post">
                    <button type="submit" name="Function" value="EmailDonationReceipts">Send E-mail Receipts</button>
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

    static public function request_handler(){
        if (!isset($_GET['f'])) $_GET['f']=null;
        if(isset($_FILES['fileToUpload'])){
            DonationUpload::csv_upload_check();
            return true;
        }elseif($_POST['Function']=="ProcessMapFile"){
            DonationUpload::process_map_file($_POST);
        }elseif ($_GET['f']=="AddDonation"){           
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
        <tr><td align="right">Tax Deductible</td><td><?php print $this->select_drop_down('NotTaxDeductible')?><div><em>Set to "Not Tax Deductible" if they have already been giving credit for the donation by donating through a donor advised fund.</div></td></tr>
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
        
        if ($this->NotTaxDeductible==1){                   
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
        $body=str_replace("##Name##",$this->Donor->name_combine(),$body);
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
                `DonationId` int(11) NOT NULL AUTO_INCREMENT,
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
                `NotTaxDeductible` tinyint(4) DEFAULT '0' COMMENT '0=Tax Deductible 1=Not Tax Deductible',
                QBOInvoiceId int(11) DEFAULT NULL,
                PRIMARY KEY (`DonationId`)
                )";               
        dbDelta( $sql );        
    }

    
    public function receipt_form(){  
            
        $this->receipt_email();            
        if ($_POST['Function']=="SendDonationReceipt" && $_POST['Email']){
            print $this->email_receipt($_POST['Email'],stripslashes_deep($_POST['customMessage']),stripslashes_deep($_POST['EmailSubject']));
            
        }
        
        print "<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        $file=$this->receipt_file_info();
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","KeyType='DonationId'","KeyId='".$this->DonationId."'"));
        $lastReceiptKey=is_array($receipts)?sizeof($receipts)-1:0;
        $bodyContent=$receipts[$lastReceiptKey]->Content?$receipts[$lastReceiptKey]->Content:$this->emailBuilder->body; //retrieve last saved custom message
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