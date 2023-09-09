<?php
require_once 'Donor.php';
require_once 'DonationCategory.php';
require_once 'DonorTemplate.php';
class Donation extends ModelLite
{
    protected $table = 'donation';
	protected $primaryKey = 'DonationId';
	### Fields that can be passed //,"Time","TimeZone"
    public $fillable = ["Date","DateDeposited","DonorId","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","Source","SourceId","TransactionID","AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber","Subject","Note","PaymentSource","TransactionType","QBOInvoiceId","QBOPaymentId"];	 

    public $flat_key = ["Date","Name","Gross","FromEmailAddress","TransactionID"];
    protected $duplicateCheck=["Date","Gross","FromEmailAddress","TransactionID"]; //check these fields before reinserting a matching entry.
   
    public $tinyIntDescriptions=[
        "Status"=>["9"=>"Completed","7"=>"Pending","0"=>"Unknown","-1"=>"Deleted","-2"=>"Denied"],
        "AddressStatus"=>[0=>"Not Set",-1=>"Non-Confirmed",1=>"Confirmed"],
        "PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"ACH/Bank Transfer","10"=>"Paypal","11"=>"Givebutter"],
        "Type"=>[0=>"Other",1=>"Donation Payment",2=>"Website Payment",5=>"Subscription Payment",-2=>"General Currency Conversion",-1=>"General Withdrawal","-3"=>"Expense"],
        "Currency"=>["USD"=>"USD","CAD"=>"CAD","GBP"=>"GBP","EUR"=>"EUR","AUD"=>"AUD"],
        "TransactionType"=>["0"=>"Tax Deductible","1"=>"Not Tax Deductible (Donor Advised fund, etc)","3"=>"IRA Qualified Charitable Distribution (QCD)","100"=>"Service (Not Tax Deductible)","-1"=>"Expense"]
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
        'PaymentSource'=>0,
        'TransactionType'=>0,
        'QBOInvoiceId'=>0,
        'QBOPaymentId'=>0
	];

    protected $fieldLimits = [ //SELECT concat("'",column_name,"'=>",character_maximum_length ,",") as grid FROM information_schema.columns where table_name = 'wp_donation' and table_schema='wordpress' and data_type='varchar'
        'Name'=>80,
        'TypeOther'=>30,
        'Currency'=>3,
        'FromEmailAddress'=>70,
        'ToEmailAddress'=>26,
        'Source'=>20,
        'SourceId'=>50,
        'TransactionID'=>17,
        'ReceiptID'=>16,
        'ContactPhoneNumber'=>20,
        'Subject'=>50
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
        $donation->TransactionType =0;

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
         $linkBase="?page=".$_GET['page']."&tab=".$_GET['tab']."&limit=".$limit."&dateField=CreatedAt&SummaryView=f";
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
            <button type="submit" name="ActionView" value="t">View action List</button>
            <button type="submit" name="SummaryView" value="t">View Summary</button>
        </form>
        <div> 
            <a href="<?php print $linkBase."&df=".date("Y-m-d")?>">Today</a> | 
            <a href="<?php print $linkBase."&df=".date("Y-m-d",strtotime("-7 days"))?>">Last 7 Days</a> | 
            <a href="<?php print $linkBase."&df=".date("Y-m-d",strtotime("-30 days"))?>">Last 30 Days</a>
        </div>

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
        print "<div><strong>Criteria:</strong> ".implode(", ",$where)."</div>";
        $SQL="Select D.*,R.Type as ReceiptType,R.Address,R.DateSent,R.ReceiptId
          FROM ".Donation::get_table_name()." D
        LEFT JOIN ".DonationReceipt::get_table_name()." R ON KeyType='DonationId' AND R.KeyId=D.DonationId AND R.ReceiptId=(Select MAX(ReceiptId) FROM ".DonationReceipt::get_table_name()." WHERE KeyType='DonationId' AND KeyId=D.DonationId)        
        WHERE ".implode(" AND ", $where)." Order BY D.Date DESC,  D.DonationId DESC;";
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
                    <table class="dp"><tr><th>Donor Id</th><th>Donor</th><th>E-mail</th><th>Date</th><th>Gross</th><th>CategoryId</th><th>Note</th><th>LifeTime</th></tr>
                    <?php
                    foreach($donationsByType as $donations){
                        $donation=new Donation($donations[key($donations)]);
                        ?><tr>
                            <td  rowspan="<?php print sizeof($donations)?>"><?php                        
                                print $donation->show_field('DonorId');
                            ?></td>                            
                            <td rowspan="<?php print sizeof($donations)?>"><?php
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
                           ?><td><?php print $donation->show_field('Date')?></td><td align=right><?php print $donation->show_field('Gross')?> <?php print $donation->Currency?></td><td><?php
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

            }else{
                $qbAction=[];
                $items=[];
                if(Quickbooks::is_setup()){
                    $qb=new QuickBooks();
                    $items=$qb->item_list("",false);
                    if ($items){
                        $qbInvoiceItem=[];
                        //Check Items on Invoice against QB??
                        $qbInvoices=[];
                        foreach($donations as $r){
                            $qbInvoices[]=$r->QBOInvoiceId;
                        }
                        
                        if (sizeof($qbInvoices)>0){
                            $qbInvoiceResult=$qb->get_all_entity('Invoice',"Id IN ('".implode("','",$qbInvoices)."')");
                            foreach($qbInvoiceResult as $qbInv){
                                if($qbInv->Line[0] && $qbInv->Line[0]->SalesItemLineDetail->ItemRef){
                                    $qbInvoiceItem[$qbInv->Id]=$qbInv->Line[0]->SalesItemLineDetail->ItemRef;
                                }
                                //print "checked: ".$qbInv->Id." ->".$qbInvoiceItem[$qbInv->Id]."<br>";
                            }
                            //Line_0_SalesItemLineDetail_ItemRef
                        }
                    }
                }
                
                ?>
                <form method="post">
                    <button type="submit" name="Function" value="EmailDonationReceipts">Send E-mail Receipts</button>
                    <button type="submit" name="Function" value="PdfDonationReceipts" disabled>Generate Pdf Receipts</button>
                    |
                    <button type="submit" name="Function" value="PdfLabelDonationReceipts">Generate Labels</button>
                    Labels Start At: <strong>Column:</strong> (1-3) &#8594; <input name="col" type="number" value="1"  min="1" max="3" /> &#8595; <strong>Row:</strong> (1-10)<input name="row" type="number" value="1" min="1" max="10"   />

                <table class="dp"><tr><th></th><th>Donation</th><th>Date</th><th>DonorId</th><th>Gross</th><th>CategoryId</th><th>Note</th><th>Type</th><th>Transaction Type</th><?php if (Quickbooks::is_setup()){ print "<th>Quickbooks Link</th>"; } ?></tr><?php
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
                        <td><?php print $donation->show_field("TransactionType")?></td> 
                        <?php 
                            if (Quickbooks::is_setup()){
                                print "<td>";
                                if ($donation->QBOInvoiceId){
                                    if ($qbInvoiceItem[$donation->QBOInvoiceId]){
                                        print "<strong>QB Item:</strong> ".$items[$qbInvoiceItem[$donation->QBOInvoiceId]]->FullyQualifiedName.' (#'.$qbInvoiceItem[$donation->QBOInvoiceId].')';
                                    }
                                }else{
                                    $QBItemId=$qb->default_item_id($donation,$donors[$donation->DonorId]);
                                    if ($QBItemId){
                                        print "<strong>QB Item:</strong> ";
                                    }else{
                                        $QBItemId=null;                                   
                                    }
                                    if ($items && sizeof($items)>0){?>                        
                                        <select name="QBItemId_<?php print $donation->DonationId?>">
                                        <option value="">-not set-</option><?php                    
                                        foreach($items as $item){
                                            print '<option value="'.$item->Id.'"'.($item->Id==$QBItemId?" selected":"").'>'.$item->FullyQualifiedName.' (#'.$item->Id.')</option>';
                                        }
                                        ?></select> 
                                        <?php 
                                        
                                    }
                                    if ($QBItemId){
                                        print " ".QuickBooks::qbLink('Item',$QBItemId)." ";
                                    }
                                }               
                                print "<div>";
                                $return=Quickbooks::donation_process_check($donation,$donors[$donation->DonorId]);
                                if (isset($return['newCustomerFromDonor'])){ 
                                    foreach($return['newCustomerFromDonor'] as $donorId){
                                        $qbAction['newCustomerFromDonor'][]=$donorId;
                                    }
                                }

                                if (isset($return['newInvoicesFromDonation'])){ 
                                    foreach($return['newInvoicesFromDonation'] as $donationId){
                                        $qbAction['newInvoicesFromDonation'][]=$donationId;
                                    }
                                }
                                print "</div>";
           
                                print "</td>";
                        }?>             
                    </tr><?php
                }
                ?></table>
                <?php
                if (sizeof($qbAction)>0 && $qb){
                    if ($qb->authenticate()){
                        if ($qbAction['newCustomerFromDonor']){
                            print sizeof($qbAction['newCustomerFromDonor'])." Customers Need created in QB. <input type=\"hidden\" name=\"DonorsToCreateInQB\" value=\"".implode("|",$qbAction['newCustomerFromDonor'])."\"/> <button name='Function' value='QBDonorToCustomerCheck'>Create Customer in QB</button> ";
                        }

                        if ($qbAction['newInvoicesFromDonation']){
                            print sizeof($qbAction['newInvoicesFromDonation'])." Invoices Need created in QB. <input type=\"hidden\" name=\"DonationsToCreateInQB\" value=\"".implode("|",$qbAction['newInvoicesFromDonation'])."\"/> <button name='Function' value='QBDonationToInvoice'>Create Invoice & Payments in QB</button>";
                        }
                    }
                }
                ?>
                </form>
                <?php
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
               $donor=Donor::find($_GET['DonorId']);
               $donorText=" for Donor #".$donor->DonorId." ".$donor->Name;
               ### copy settings from the last donation...
               $lastDonation=Donation::first(['DonorId ='.$donor->DonorId],"DonationId DESC",
               ['select'=>'DonorId,Name,FromEmailAddress,CategoryId,PaymentSource,TransactionType']);
               if ($lastDonation) $donation=$lastDonation;               
            }
            print "<h2>Add donation".$donorText."</h2>";
            $donation->DonorId=$donor->DonorId;
            ### Donor Settings override whatever is autopopulated from previous donation
            if ($donor->Name) $donation->Name=$donor->Name;
            if ($donor->Email) $donation->FromEmailAddress=$donor->Email;
            if ($donor->Phone) $donation->ContactPhoneNumber=$donor->Phone;
            ### Defaults set IF prevous donation not pulled.
            $donation->PaymentSource=$donation->PaymentSource?$donation->PaymentSource:1;
            $donation->Type=$donation->Type?$donation->Type:1;

            $donation->Status=9;

            $donation->edit_simple_form();           
            return true;
        }elseif ($_POST['Function']=="Cancel" && $_POST['table']=="donation"){
            $donor=Donor::find($_POST['DonorId']);
            $donor->view();
            return true;
        }elseif ($_REQUEST['DonationId']){	
            if ($_POST['Function']=="Delete" && $_POST['table']=="donation"){
                $donation=new Donation($_POST);
                if ($donation->delete()){
                    self::display_notice("Donation #".$donation->show_field("DonationId")." for $".$donation->Gross." from ".$donation->Name." on ".$donation->Date." deleted");
                    //$donation->full_view();
                    return true;
                }
            }
            if ($_POST['Function']=="Save" && $_POST['table']=="donation"){
                $donation=new Donation($_POST);
                if ($donation->save()){
                    self::display_notice("Donation #".$donation->show_field("DonationId")." saved.");
                    $donation->full_view();
                    return true;
                }
            }
            if ($_POST['syncDonationToInvoiceQB']=="true"){
                $qb=new QuickBooks();
                $invoice_id=$qb->donation_to_invoice_check($_REQUEST['DonationId'],$_REQUEST['ItemId']); //ItemId
                if ($invoice_id) self::display_notice("Quickbooks Invoice: ".QuickBooks::qbLink('Invoice',$invoice_id) ." added to Donation #".$donation->show_field("DonationId")." saved.");
                
            }

            $donation=Donation::find($_REQUEST['DonationId']);	
            $donation->full_view();
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="donation"){
            $donation=new Donation($_POST);            
            if ($donation->save()){
                self::display_notice("Donation #".$donation->show_field("DonationId")." saved.");
                $donation->full_view();
            }
            return true;
        }elseif ($_POST['Function']=="QBDonorToCustomerCheck" && $_POST['DonorsToCreateInQB']){
            $qb=new QuickBooks();
            $qb->DonorToCustomer(explode("|",$_POST['DonorsToCreateInQB']));  
            return true;         
            
        }elseif($_POST['Function']=="QBDonationToInvoice" && $_POST['DonationsToCreateInQB']){
            $donationIds=explode("|",$_POST['DonationsToCreateInQB']);            
            $donations=self::get(["DonationId IN ('".implode("','",$donationIds)."')"]);            
            foreach($donations as $donation){
                $donation->QBItemId=$_POST['QBItemId_'.$donation->DonationId];
                $donation->send_to_QB(['silent'=>true]);
            }
            self::display_notice(sizeof($donations)." invoices/payments created in QuickBooks.");  
            return true;     
        }elseif($_GET['UploadDate'] || $_GET['SummaryView'] || $_GET['ActionView']){    
            if ($_POST['Function']=="LinkMatchQBtoDonorId"){
                $qb=new QuickBooks();
                $qb->process_customer_match($_POST['match'],$_POST['rmatch']);                    
            }
            
            if ($_POST['Function']=="EmailDonationReceipts" && sizeof($_POST['EmailDonationId'])>0){               
                foreach($_POST['EmailDonationId'] as $donationId){
                    $donation=Donation::find($donationId);
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
                        $where[]="DATE(`$dateField`)>='".$_GET['df']."'";
                    }
                    if ($_GET['dt']){
                        $where[]="DATE(`$dateField`)<='".$_GET['dt']."'";
                    } 
                    //dd($where);                   
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

    public function full_view(){?>
        <div>
            <form method="get">
                <input type="hidden" name="page" value="donor-index"/>
                <div><a href="?page=<?php print $_GET['page']?>">Home</a> |
                <a href="?page=donor-index&DonorId=<?php print $this->DonorId?>">View Donor</a> | Donor Search: <input id="donorSearch" name="dsearch" value=""> <button>Go</button></div>
            </form>
            <h1>Donation #<?php print $this->DonationId?></h1><?php
            if ($_REQUEST['edit']){
                if ($_REQUEST['raw']) $this->edit_form();
                else{ $this->edit_simple_form(); }
            }else{
                ?><div><a href="?page=donor-index&DonationId=<?php print $this->DonationId?>&edit=t">Edit Donation</a></div><?php
                $this->view();
                $this->receipt_form();
            }?>
            
        </div><?php
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
        $hiddenFields=['DonationId','ToEmailAddress','ReceiptID','AddressStatus']; //these fields more helpful when using paypal import, but are redudant/not necessary when manually entering a transaction
        //?page=donor-index&DonationId=4458&edit=t
        if ($this->DonationId){
            ?><div><a href="?page=donor-index&DonationId=<?php print $this->DonationId?>&edit=t&raw=t">Edit Raw</a></div><?php
        }?>
        
        <form method="post" action="?page=donor-reports&DonationId=<?php print $this->DonationId?>" style="border: 1px solid #999; padding:20px; width:90%;">
        <input type="hidden" name="table" value="donation">
        <?php foreach ($hiddenFields as $field){?>
		    <input type="hidden" name="<?php print $field?>" value="<?php print $this->$field?>"/>
        <?php } ?>
        <script>
            function calculateNet(){
                var net= document.getElementById('donation_gross').value-document.getElementById('donation_fee').value;
                document.getElementById('donation_net').value=net;
                document.getElementById('donation_net_show').innerHTML=net;
            }
        </script> 
        <table><tbody>
        <tr>
            <td align="right">Total Amount</td>
            <td><input id="donation_gross" style="text-align:right;" onchange="calculateNet();" required type="number" step=".01" name="Gross" value="<?php print $this->Gross?>"> <?php $this->select_drop_down('Currency',false);?></td></tr>
        <tr>
            <td align="right">Fee</td>
            <td><input id="donation_fee" style="text-align:right;"  onchange="calculateNet();" type="number" step=".01" name="Fee" value="<?php print $this->Fee?$this->Fee:0?>"> <strong>Net:</strong> <input id="donation_net" type="hidden" name="Net" value="<?php print $this->Net?$this->Net:0?>"/>$<span id="donation_net_show"><?php print number_format($this->Net?$this->Net:0,2)?></span></td></tr> 
        <tr><td align="right">Check #/Transaction ID</td><td><input type="txt" name="TransactionID" value="<?php print $this->TransactionID?>"></td></tr>
        <tr><td align="right">Check/Sent Date</td><td><input type="date" name="Date" value="<?php print ($this->Date?date("Y-m-d",strtotime($this->Date)):date("Y-m-d"))?>"></td></tr>
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
       <tr><td align="right">Category</td><td><?php
        print DonationCategory::select(['Name'=>"CategoryId",'selected'=>$this->CategoryId])?></td></tr>
       <tr><td align="right">Subject</td><td><input type="text" name="Subject" value="<?php print $this->Subject?>"></td></tr>
        <tr><td align="right">Note</td><td><textarea name="Note"><?php print $this->Note?></textarea></td></tr>
        <tr><td align="right">Transaction Type</td><td><?php print $this->select_drop_down('TransactionType')?><div><em>Set to "Not Tax Deductible" if they have already been giving credit for the donation by donating through a donor advised fund, or if this is a payment for a service.</div></td></tr>
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

        if ($this->CategoryId){
            $page=DonationCategory::find($this->CategoryId)->getTemplate();
        }
        if (!$page){
            if ($this->TransactionType==1){                   
                $page = DonorTemplate::get_by_name('no-tax-thank-you');  
                if (!$page){ ### Make the template page if it doesn't exist.
                    self::make_receipt_template_no_tax();
                    $page = DonorTemplate::get_by_name('no-tax-thank-you');  
                    self::display_notice("Email Template /no-tax-thank-you created. <a target='edit' href='?page=donor-settings&tab=email&DonorTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
                }
            }elseif ($this->TransactionType==3){                   
                $page = DonorTemplate::get_by_name('ira-qcd');  
                if (!$page){ ### Make the template page if it doesn't exist.
                    self::make_receipt_template_ira_qcd();
                    $page = DonorTemplate::get_by_name('ira-qcd');  
                    self::display_notice("Email Template /ira-qcd created. <a target='edit' href='?page=donor-settings&tab=email&DonorTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
                }
            }else{
                $page = DonorTemplate::get_by_name('receipt-thank-you');  
                if (!$page){ ### Make the template page if it doesn't exist.
                    self::make_receipt_template_thank_you();
                    $page = DonorTemplate::get_by_name('receipt-thank-you');  
                    self::display_notice("Email Template /receipt-thank-you created. <a target='edit' href='?page=donor-settings&tab=email&DonorTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
                }
            }
        }
            
        if (!$page){ ### Make the template page if it doesn't exist.
            self::make_receipt_template_thank_you();
            $page = DonorTemplate::get_by_name('receipt-thank-you');  
            self::display_notice("Page /receipt-thank-you created. <a target='edit' href='?page=donor-settings&tab=email&DonorTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
       
        if (!$this->Donor)  $this->Donor=Donor::find($this->DonorId);
        $address=$this->Donor->mailing_address();
        $subject=$page->post_title;
        $body=$page->post_content;

        $body=str_replace("##Name##",$this->Donor->name_combine(),$body);
        $body=str_replace("##Gross##","$".number_format($this->Gross,2),$body);
        if (!$address){ //remove P
            $body=str_replace("<p>##Address##</p>",$address,$body);
        }
        $body=str_replace("##Address##",$address,$body);
        $body=str_replace("##Date##",date("F j, Y",strtotime($this->Date)),$body);
        $body=str_replace("##Year##",date("Y",strtotime($this->Date)),$body);

        ### replace custom variables.
        foreach(CustomVariables::variables as $var){
            if (substr($var,0,strlen("Quickbooks"))=="Quickbooks") continue;
            if (substr($var,0,strlen("Paypal"))=="Paypal") continue;
            $body=str_replace("##".$var."##", get_option( 'donation_'.$var),$body);
            $subject=str_replace("##".$var."##",get_option( 'donation_'.$var),$subject);                   
        }   

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body;    
        $this->emailBuilder->fontsize=$page->post_excerpt_fontsize;
        $this->emailBuilder->margin=$page->post_excerpt_margin; 
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
            $postarr['post_excerpt']='{"fontsize":"12","margin":".25"}';
            return wp_insert_post($postarr);            
        }
    }

    static function make_receipt_template_ira_qcd(){
        $page = DonorTemplate::get_by_name('ira-qcd');  
        if (!$page){
            $postarr['ID']=0;

            $tempLoc=dn_plugin_base_dir()."/resources/template_default_receipt_ira_qcd.html";          
            $postarr['post_content']=file_get_contents($tempLoc);            
            $postarr['post_title']='Thank You For IRA Qualified Charitable Distribution To ##Organization##';
            $postarr['post_status']='private';
            $postarr['post_type']='donortemplate';
            $postarr['post_name']='ira-qcd';
            $postarr['post_excerpt']='{"fontsize":"12","margin":".25"}';
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
            $postarr['post_excerpt']='{"fontsize":"12","margin":".25"}';
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
            $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"DonationId","KeyId"=>$this->DonationId,"Type"=>"e","Address"=>$email,"DateSent"=>date("Y-m-d H:i:s"),
            "Subject"=>$subject,"Content"=>$customMessage));
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
        ob_clean();
        $this->receipt_email();        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $margin=($this->emailBuilder->margin?$this->emailBuilder->margin:.25)*72;
        $pdf->SetMargins($margin,$margin,$margin);
        $pdf->SetFont('helvetica', '', $this->emailBuilder->fontsize?$this->emailBuilder->fontsize:12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false); 
        $file=$this->receipt_file_info();        
        $pdf->AddPage();
        $pdf->writeHTML($customMessage?$customMessage:$this->emailBuilder->body, true, false, true, false, '');        
        
        $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"DonationId","KeyId"=>$this->DonationId,"Type"=>"p","Address"=>$this->Donor->mailing_address(),"DateSent"=>date("Y-m-d H:i:s"),"Subject"=>$this->emailBuilder->subject,"Content"=>$customMessage));
        $dr->save();
        if ($pdf->Output($file, 'D')){
            return true;
        }else return false;
    }

    public function receipt_file_info(){
        return substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-DT'.$this->DonationId.'.pdf';
    }

    public function save($time=""){
        if ($this->CategoryId && (!$this->TransactionType || $this->TransactionType==0)){ //this is slightly problematic if we want it to be "0", but it is overwritten by the category on save. Could cause some perceived buggy behavior.      
            $this->TransactionType=DonationCategory::get_default_transaction_type($this->CategoryId);          
        }
        parent::save($time);

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
                `Gross` DECIMAL(10,2) NOT NULL,
                `Fee` decimal(6,2) DEFAULT NULL,
                `Net` DECIMAL(10,2) DEFAULT NULL,
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
                `TransactionType` tinyint(4) DEFAULT '0' COMMENT '0=Tax Deductible 1=Not Tax Deductible, 3=IRA QCD 100=Service, -1=Expense',
                QBOInvoiceId int(11) DEFAULT NULL,
                QBOPaymentId int(11) DEFAULT NULL,
                PRIMARY KEY (`DonationId`)
                )";               
        dbDelta( $sql );        
    }

    
    public function receipt_form(){  
        $donor=Donor::find($this->DonorId); 
        $this->receipt_email();            
        if ($_POST['Function']=="SendDonationReceipt" && $_POST['Email']){
            print $this->email_receipt($_POST['Email'],stripslashes_deep($_POST['customMessage']),stripslashes_deep($_POST['EmailSubject']));
            
        }
        $file=$this->receipt_file_info();
               
        print "<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","KeyType='DonationId'","KeyId='".$this->DonationId."'"));
        $lastReceiptKey=is_array($receipts)?sizeof($receipts)-1:0;
        $bodyContent=$receipts[$lastReceiptKey]->Content?$receipts[$lastReceiptKey]->Content:$this->emailBuilder->body; //retrieve last saved custom message
        $bodyContent=$_POST['customMessage']?stripslashes_deep($_POST['customMessage']):$bodyContent; //Post value overrides this though.
        
        $subject=$_POST['EmailSubject']?stripslashes_deep($_POST['EmailSubject']):$this->emailBuilder->subject;

        //dump($receipts[$lastReceiptKey]);

        if ($_GET['resetLetter']=="t"){
            $subject=$this->emailBuilder->subject;
            $bodyContent=$this->emailBuilder->body;
        }


        if (Quickbooks::is_setup() && $this->QBOInvoiceId>=0){
            if ($donor->QuickBooksId>0){
                ?><form method="post">
                    <input type="hidden" name="syncDonationToInvoiceQB" value="t"/>
                <input type="hidden" name="DonationId" value="<?php print $this->DonationId?>"/>
                <?php              

                if ($this->QBOInvoiceId==0){
                    $qb=new QuickBooks();
                    $items=$qb->item_list("",false);
                    $QBItemId=$qb->default_item_id($this,$donor);
                    if ($items && sizeof($items)>0){?>                    
                        <strong>Item:</strong> <select name="ItemId">
                             <option value="">-not set-</option><?php                    
                                foreach($items as $item){
                                    print '<option value="'.$item->Id.'"'.($item->Id==$QBItemId?" selected":"").'>'.$item->FullyQualifiedName.' (#'.$item->Id.')</option>';
                                }
                        ?></select>                    
                        <?php
                        if ($QBItemId){
                            print " ".QuickBooks::qbLink('Item',$QBItemId)." ";
                        }
                    }
                    
                   // print '<a href="?page=donor-quickbooks&syncDonation='.$this->DonationId.'">Sync Donation to an Invoice on QuickBooks</a>';
                    ?>
                    <button type="submit" style="background-color:lightgreen;">Create Invoice & Payment In QB</button> | <a style="background-color:orange;" target="QB" a href="?page=donor-quickbooks&ignoreSyncDonation=<?php print $donation->DonationId?>">Ignore/Don't Sync to QB</a>
                    </form>
                    <?php
                }elseif(!$this->QBOPaymentId){
                    print "Invoice #".$this->show_field("QBOInvoiceId")." synced, but Payment has NOT been synced.";
                    print '<a href="?page=donor-quickbooks&syncDonationPaid='.$this->DonationId.'">Sync Payment to QuickBooks</a>';
                }else{
                    print "<div>Invoice #".$this->show_field("QBOInvoiceId")." & Payment: ".$this->show_field("QBOPaymentId")." synced to Quickbooks.</div>";
                }
            }elseif($donor->QuickBooksId==0){
                print '<a href="?page=donor-quickbooks&syncDonorId='.$this->DonorId.'">Create Donor in QB</a> (before sending Invoice)';
            }
        }
        //$receipts[0]->content
   
        print DonationReceipt::show_results($receipts,"You have not sent this donor a Receipt.");

        $emailToUse=($_POST['Email']?$_POST['Email']:$this->FromEmailAddress);
        if (!$emailToUse) $emailToUse=$this->Donor->Email;
        ?><form method="post" action="?page=<?php print $_GET['page']?>&DonationId=<?php print $this->DonationId?>">
            <h2>Send Receipt</h2>
            <input type="hidden" name="DonationId" value="<?php print $this->DonationId?>">
            <div>Send Receipt to: <input type="email" name="Email" value="<?php print $emailToUse?>">
                <button type="submit" name="Function" value="SendDonationReceipt">Send E-mail Receipt</button> 
                <button type="submit" name="Function" value="DonationReceiptPdf">Generate PDF</button>                  
            </div>
            <div><a target='pdf' href='?page=donor-settings&tab=email&DonorTemplateId=<?php print $this->emailBuilder->pageID?>&edit=t'>Edit Template</a> | <a href="?page=donor-reports&DonationId=<?php print $this->DonationId?>&resetLetter=t">Reset Letter</a></div>
            <div style="font-size:18px;"><strong>Email Subject:</strong> <input style="font-size:18px; width:500px;" name="EmailSubject" value="<?php print $subject;?>"/>
            <?php wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false)); ?>
        </form>
        <?php    
    }

    static function label_by_id($donationIds,$col_start=1,$row_start=1,$limit=100000){
        if (sizeof($donationIds)<$limit) $limit=sizeof($donationIds);
        if (!class_exists("TCPDF")){
            self::display_error("PDF Writing is not installed. You must run 'composer install' on the donor-press plugin directory to get this to funciton.");
            return false;
        }      
        $SQL="Select DT.DonationId,DR.*
        FROM ".Donation::get_table_name()." DT
        INNER JOIN ".Donor::get_table_name()." DR ON DT.DonorId=DR.DonorId 
        WHERE DT.DonationId IN (".implode(",",$donationIds).")";      
        
        $donations = self::db()->get_results($SQL);
        
        foreach ($donations as $r){
            $donationList[$r->DonationId]=new Donor($r);
           
        }
        $a=[];

        $defaultCountry=CustomVariables::get_option("DefaultCountry");       
        foreach($donationIds as $id){
            if ($donationList[$id]){
                $address=$donationList[$id]->mailing_address("\n",true,['DefaultCountry'=>$defaultCountry]);
                if(!$address) $address=$donationList[$id]->name_combine();
                if ($address) $a[]=$address;
            }
        }

        $dpi=72;	
        $pad=10;
        $margin['x']=13.5;// 3/16th x
        $margin['y']=.5*$dpi;
        ob_clean();
        $pdf = new TCPDF('P', 'pt', 'LETTER', true, 'UTF-8', false); 
        $pdf->SetFont('helvetica', '', 12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false); 	

        $pdf->AddPage();
        $pdf->SetCellPadding($pad);
        $pdf->SetAutoPageBreak(true);
        $pdf->SetMargins($margin['x'],$margin['y'],$margin['x']);
        $pdf->SetCreator('Donor-Press Plugin');
        $pdf->SetAuthor('Donor-Press');
        $pdf->SetTitle($year.'Year End Labels');	 
        $starti=($col_start>0?($col_start-1)%3:0)+($row_start>0?3*floor($row_start-1):0);
        $border=0; $j=0;
        for ($i=$starti;$i<sizeof($a)+$starti;$i++){
            $col=$i%3;
            $row=floor($i/3)%10;
            if ($i%30==0 && $j!=0){ $pdf->AddPage();}	
            $pdf->MultiCell(2.625*$dpi,1*$dpi,$a[$j],$border,"L",0,0,$margin['x']+$col*2.75*$dpi,$margin['y']+$row*1*$dpi,true);
            $j++;		
        }	
        $pdf->Output("DonorPressDonationLabels.pdf", 'D');

    }

    public function send_to_QB($settings=[]){
        $qb=new QuickBooks();
        return $qb->donation_to_invoice_process($this);
    }
}