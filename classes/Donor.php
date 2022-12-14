<?php

require_once 'ModelLite.php';
require_once 'Donation.php';
require_once 'DonationReceipt.php';

class Donor extends ModelLite {
    protected $table = 'Donor';
	protected $primaryKey = 'DonorId';
	### Fields that can be passed 
    public $fillable = ["Source","SourceId","Name","Name2","Email","EmailStatus","Phone","Address1","Address2","City","Region","PostalCode","Country","TaxReporting","MergedId","QuickBooksId"];	    
	
    public $flat_key = ["Name","Name2","Email","Phone","City","Region"];
    ### Default Values
	protected $attributes = [        
        'Country' => 'US',
        'TaxReporting'=> 0,
        'EmailStatus'=>1
    ];
    
    protected $tinyIntDescriptions=[
        "EmailStatus"=>["-1"=>"Returned","0"=>"Not Set","1"=>"Valid"],        
    ];
	//public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
   
    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
          $sql="CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
            `DonorId` int(11) NOT NULL AUTO_INCREMENT,
            `Source` varchar(20) NOT NULL,
            `SourceId` varchar(50) NOT NULL,
            `Name` varchar(80) NOT NULL,
            `Name2` varchar(80)  NULL,
            `Email` varchar(80)  NULL,
            `EmailStatus` tinyint(4) NOT NULL DEFAULT '1' COMMENT '-1=Bounced 1=Active',
            `Phone` varchar(20)  NULL,
            `Address1` varchar(80)  NULL,
            `Address2` varchar(80)  NULL,
            `City` varchar(50)  NULL,
            `Region` varchar(20)  NULL,
            `PostalCode` varchar(20)  NULL,
            `Country` varchar(2)  NULL,
            `MergedId` int(11) NOT NULL DEFAULT '0',
            `QuickBooksId` int(11) DEFAULT NULL,
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `TaxReporting` tinyint(4) DEFAULT '0' COMMENT '0 - Standard -1 Not Required -2 Opt Out',
            PRIMARY KEY (`DonorId`)
            )";
          dbDelta( $sql );
    }

    static public function from_paypal_api_detail($detail){
        $payer=$detail->payer_info;
        $shipInfo=$detail->shipping_info;

        $type=Donation::transaction_event_code_to_type($detail->transaction_info->transaction_event_code);

        $d = new self();
        $d->Source='paypal';
        $d->SourceId=$payer->account_id;
        $d->Email=$payer->email_address; 
       
        //$d->EmailStatus=1; //if already set elswhere... should not be etting this
        $d->Name=$payer->payer_name->alternate_full_name;
        $address=$payer->address?$payer->address->address:$shipInfo->address; //address not always provided, but if it is, look first if it is on the payer object, otherwise look at shipping_info   
        if ($address){
            $d->Address1=$address->line1;
            if ($address->line2) $d->Address2=$address->line2;
            $d->City=$address->city;
            $d->Region=$address->state;
            $d->Country=$address->country_code;
            $d->PostalCode=$address->postal_code;
        }elseif($payer->country_code){
            $d->Country=$payer->country_code;  //entries without addresses usually at least have country codes.
        }
        //deposit scenerio detected
        if ($type<0 && !$d->Email){
            $d->Email=Donation::get_deposit_email();            
        }
        if (!$d->Name && $detail->transaction_info->bank_reference_id){
            $d->Name="Bank ".$detail->transaction_info->bank_reference_id;
            if (!$d->SourceId) $d->SourceId=$detail->transaction_info->bank_reference_id;
        }

        if (!$d->SourceId) $d->SourceId=$detail->transaction_info->bank_reference_id;
        return $d;        
    }

    public function merge_form(){
        ?><form method="post">
        <input type="hidden" name="MergeFrom" value="<?php print $this->DonorId?>"/> 
        Merge To Id: <input type="number" name="MergedId" value="">
        <button method="submit" name="Function" value="MergeConfirm">Merge</button>
        Enter the ID of the Donor you want to merge to. You will have the option to review this merge. Once merged, all donations will be relinked to the new profile.</form><?php
    }

    static public function donor_update_suggestion($current,$new,$timeProcessed=null){   
        $suggest_donor_changes=[];
        foreach ($new as $donorN){
            if ($donorN->DonorId && $current[$donorN->DonorId]){
                foreach(self::s()->fillable as $field){
                    switch($field){
                        case "Name":
                        case "Name2":
                        case "Address1":
                        case "Address2":
                        case "City":                           
                            $value=ucwords(strtolower($donorN->$field));
                            break;
                        case "Region":
                        case "Country":
                        case "PostalCode":
                            $value=strtoupper($donorN->$field);
                            break;
                        case "Email":
                            $value=strtolower($donorN->$field);
                            break;
                        default:
                            $value=$donorN->$field;
                        break;
                    }
                    $value=trim($value);
                    if (isset($donorN->$field) && $value!="" && $value!=$current[$donorN->DonorId]->$field){
                        $suggest_donor_changes[$donorN->DonorId][$field]['c']=$current[$donorN->DonorId]->$field;
                        $suggest_donor_changes[$donorN->DonorId][$field]['n'][$value]++;
                    }
                }
                //If there is any changes for this donor, then set name so it can be read
                if ($suggest_donor_changes[$donorN->DonorId]){
                    $suggest_donor_changes[$donorN->DonorId]['Name']['c']=$current[$donorN->DonorId]->Name;
                } 
            }
        }
        //self::dump($suggest_donor_changes);
        self::donor_update_suggestion_form($suggest_donor_changes,$timeProcessed);          
    }

    static public function donor_update_suggestion_form($suggest_donor_changes,$timeProcessed=null){
        if (sizeof($suggest_donor_changes)==0) return;

        print "<h2>The following changes are suggested</h2><form method='post'>";
        print "<table border='1'><tr><th>#</th><th>Name</th><th>Change</th></tr>";
        foreach ($suggest_donor_changes as $donorId => $changes){
            print "<tr><td><a target='lookup' href='?page=donor-index&DonorId=".$donorId."'>".$donorId."</td><td>".$changes['Name']['c']."</td><td>";
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
        print "<hr>";
        print "<div><a target='viewSummary' href='?page=donor-reports&UploadDate=".date("Y-m-d H:i:s",$timeProcessed)."'>View All</a> | <a target='viewSummary' href='?page=donor-reports&SummaryView=t&UploadDate=".date("Y-m-d H:i:s",$timeProcessed)."'>View Summary</a></div>";
   
    }

    public function merge_form_compare($oldDonor){
        if ($this->DonorId==$oldDonor->DonorId){
            self::display_error("Can't Merge entry to itself.");           
            return;
        }
        $where= array("DonorId IN (".$this->DonorId.",".$oldDonor->DonorId.")");
        $SQL="SELECT DonorId, Count(*) as C,SUM(`Gross`) as Total, MIN(Date) as DateEarliest, MAX(Date) as DateLatest FROM ".Donation::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY DonorId";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $stats[$r->DonorId]=$r;
        }
        //self::dump($stats);
        foreach($oldDonor as $field=>$value){
            if ($value!=$this->$field){
                $changes[$field]=$value;
            }
        }
        ?><form method='post'>
        <input type='hidden' name='donorIds[]' value='<?php print $this->DonorId?>'>
        <input type='hidden' name='donorIds[]' value='<?php print $oldDonor->DonorId?>'>
        <h2>The following changes are suggested</h2>
        <form method='post'>
        <table border='1'><tr><th>Field</th><th>Donor A</th><th>Donor B</th></tr><?php
        foreach($changes as $field=>$value){
            if ($field=="MergedId") continue; //don't allow mergeing of merge IF it is the original donor
            ?><tr><td><?php print $field?></td>
            <td><input type="radio" name="<?php print $field?>" value="<?php print $value?>"<?php print !$this->$field?" checked":""?>><?php print $value?></td>
            <td><input type="radio" name="<?php print $field?>" value="<?php print $this->$field?>"<?php print $this->$field?" checked":""?>><?php print $this->$field?></td>
            </tr><?php                                    
        }
        ?><tr><td>Donation Details Will Merge</td><td><?php
        $thisStat=$stats[$oldDonor->DonorId];
        print $thisStat->C." Donations $".number_format($thisStat->Total,2);
        print " ".substr($thisStat->DateEarliest,0,10).($thisStat->DateEarliest!=$thisStat->DateLatest?" to ".substr($thisStat->DateLatest,0,10):"");
        ?></td>
        <td><?php
        $thisStat=$stats[$this->DonorId];
        print $thisStat->C." Donations $".number_format($thisStat->Total,2);
        print " ".substr($thisStat->DateEarliest,0,10).($thisStat->DateEarliest!=$thisStat->DateLatest?" to ".substr($thisStat->DateLatest,0,10):"");
        ?>
        </td></tr>
        </table>
        <button type='submit' name='Function' value='MergeDonor'>Merge Donors</button>
        </form><?php

    }

    public function view(){ ?>
        <div>
            <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&edit=t">Edit Donor</a> | 
            <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=AddDonation">Add Donation</a>
        </div>
        <?php
        $this->var_view();
        $this->quick_books_link();        
        $this->merge_form();
        ?>
        <h2>Donation Summary</h2>
        <div>Year End Receipt: <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")?>"><?php print date("Y")?></a> | <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")-1?>"><?php print date("Y")-1?></a> | <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")-2?>"><?php print date("Y")-2?></a></div>
        <?php
         $totals=[];
        $SQL="SELECT  `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".Donation::get_table_name()." 
        WHERE DonorId='".$this->DonorId."'  Group BY `Type`";
        $results = self::db()->get_results($SQL);
        ?><table class="dp"><tr><th>Type</th><th>Count</th><th>Amount</th></tr><?php
        foreach ($results as $r){?>
            <tr><td><?php print $r->Type?></td><td><?php print $r->Count?></td><td align=right><?php print number_format($r->Total,2)?></td></tr><?php
            $totals['Count']+=$r->Count;
            $totals['Total']+=$r->Total;
        }        
        if (sizeof($results)>1){?>
        <tfoot style="font-weight:bold;"><tr><td>Totals:</td><td><?php print $totals['Count']?></td><td align=right><?php print number_format($totals['Total'],2)?></td></tr></tfoot>
        <?php } ?></table>
        <h2>Donation List</h2>
		<?php
		$results=Donation::get(array("DonorId='".$this->DonorId."'"),"Date DESC");
		print Donation::show_results($results,"",["DonationId","Date","DateDeposited","Name","Type","Gross","FromEmailAddress","CategoryId","Subject","Note","PaymentSource","TransactionID"]);		
    }

    function quick_books_link(){
        if (CustomVariables::get_option('QuickbooksClientId',true)){
            if (!$this->QuickBooksId){                
                print "<div><a href='?page=donor-quickbooks&syncDonorId=".$this->DonorId."'>Sync Donor to Quickbooks</a></div>";
            }
        }
    }

    static public function request_handler(){      
        if ($_POST['table']=='Donor' && $_POST['DonorId'] && $_POST['Function']=="Delete"){
            //check if any donations connected to this account or merged ids..
            $donations=Donation::get(array('DonorId='.$_POST['DonorId']));
            if (sizeof($donations)>0){
                self::display_error("Can't delete Donor #".$_POST['DonorId'].". There are ".sizeof($donations)." donation(s) attached to this.");           
                return false;
            }
            $donors=Donation::get(array('MergedId='.$_POST['DonorId']));
            if (sizeof($donors)>0){
                self::display_error("Can't delete Donor #".$_POST['DonorId'].". There are ".sizeof($donors)." donors merged to this entry.");                           
                return false;
            }else{
                $dSQL="DELETE FROM ".Donor::get_table_name()." WHERE `DonorId`='".$_POST['DonorId']."'";
                self::db()->query($dSQL);
                self::display_notice("Deleted Donor #".$_POST['DonorId'].".");                           
                return true;
            }


        }elseif ($_POST['Function']=='MergeDonor' && $_POST['DonorId']){
            //self::dump($_POST);            
            $data=array();
            foreach(self::s()->fillable as $field){
                if ($_POST[$field] && $field!='DonorId'){
                    $data[$field]=$_POST[$field];
                }
            }
            if (sizeof($data)>0){
                ### Update Master Entry with Fields from merged details
                self::db()->update(self::s()->get_table(),$data,array('DonorId'=>$_POST['DonorId']));
            }
            $mergeUpdate['MergedId']=$_POST['DonorId'];
            foreach($_POST['donorIds'] as $oldId){
                if ($oldId!=$_POST['DonorId']){
                    ### Set MergedId on Old Donor entry
                    self::db()->update(self::s()->get_table(),$mergeUpdate,array('DonorId'=>$oldId));
                    ### Update all donations on old donor to new donor
                    $uSQL="UPDATE ".Donation::s()->get_table()." SET DonorId='".$_POST['DonorId']."' WHERE DonorId='".$oldId."'";  
                    self::db()->query($uSQL);
                    self::display_notice("Donor #<a href='?page=".$_GET['page']."&DonorId=".$oldId."'>".$oldId."</a> merged to #<a href='?page=".$_GET['page']."&DonorId=".$_POST['DonorId']."'>".$_POST['DonorId']."</a>");
                }
            }  
            $_GET['DonorId']=$_POST['DonorId']; 

        }
        if ($_GET['f']=="AddDonor"){
            $donor=new Donor;
            if ($_REQUEST['dsearch'] && !$donor->DonorId && !$donor->Name){
                $donor->Name=$_REQUEST['dsearch'];
            }          
            print "<h2>Add Donor</h2>";            
            $donor->edit_form();           
            return true;
        }elseif($_GET['f']=="summary_list" && $_GET['dt'] && $_GET['df']){            
            self::summary_list(array("Date BETWEEN '".$_GET['df']." 00:00:00' AND '".$_GET['dt']." 23:59:59'"),$_GET['Year']);
            return true;
        }elseif($_POST['Function']=='MergeConfirm'){ 
            $donorA=Donor::get_by_id($_POST['MergeFrom']);
            $donorB=Donor::get_by_id($_POST['MergedId']);
            if (!$donorB->DonorId){
                 self::display_error("Donor ".$_POST['MergedId']." not found.");
                 return false;
            }else{
                $donorB->merge_form_compare($donorA);
            }
            return true;
        }elseif($_GET['Function']=='MergeConfirm'){ 
            $donorA=Donor::get_by_id($_GET['MergeFrom']);
            $donorB=Donor::get_by_id($_GET['MergedId']);
            if (!$donorB->DonorId){
                 self::display_error("Donor ".$_GET['MergedId']." not found.");
                 return false;
            }else{
                $donorB->merge_form_compare($donorA);
            }
            return true;
        }elseif ($_GET['DonorId']){
            if ($_GET['f']=="YearReceipt"){
                $donor=Donor::get_by_id($_REQUEST['DonorId']);
                $donor->year_receipt_form($_GET['Year']);
                return true;
            }
            
            if ($_POST['Function']=="Save" && $_POST['table']=="Donor"){
                $donor=new Donor($_POST);
                if ($donor->save()){			
                    self::display_notice("Donor #".$donor->show_field("DonorId")." saved.");
                }
                
            }
            $donor=Donor::get_by_id($_REQUEST['DonorId']);	
            ?>
            <div>
                <div><a href="?page=<?php print $_GET['page']?>">Return to Donor Lookup</a></div>
                <h1>Donor Profile #<?php print $_REQUEST['DonorId']?> <?php print $donor->Name?></h1><?php 
                if ($_REQUEST['edit']){
                    $donor->edit_form();
                }else{             
                    $donor->view();                    
                }
            ?></div><?php            
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donor"){
            $donor=new Donor($_POST);
            if ($donor->save()){			
                self::display_notice("Donor #".$donor->show_field("DonorId")." saved.");
                $donor->view();
            }
            return true;
            
        }elseif($_POST['Function']=='MakeDonorChanges'){            
            self::db()->show_errors();
            foreach($_POST['changes'] as $line){
                $v=explode("||",$line); //$donorId."||".$field."||".$value
                $changes[$v[0]][$v[1]]=$v[2];
            }
            if (sizeof($changes)>0){
                foreach($changes as $donorId=>$change){
                    $ulinks[]='<a target="lookup" href="?page='.$_GET['page'].'&DonorId='.$donorId.'">'.$donorId.'</a>';
                    self::db()->update(self::get_table_name(),$change,array("DonorId"=>$donorId));

                }
                self::display_notice(sizeof($changes)." Entries Updated: ".implode(" | ",$ulinks));
            }

        }else{
            return false;
        }
    }

    function name_combine(){
        if (trim($this->Name2)){
            $name1=explode(" ",trim($this->Name));
            $name2=explode(" ",trim($this->Name2));
            if (end($name1)==end($name2)){ //if they share a last name, combine it.
                $return=[];
                for($i=0;$i<sizeof($name1)-1;$i++){
                    $return[]=$name1[$i];
                }
                $return[]="&";
                for($i=0;$i<sizeof($name2);$i++){
                    $return[]=$name2[$i];
                }
                return implode(" ",$return);
            }else{
                return $this->Name." & ".$this->Name2;
            }
        }else{
            return $this->Name;
        }
    }

    function mailing_address($seperator="<br>",$include_name=true){
        $address="";
        if ($this->Address1) $address.=$this->Address1.$seperator;
        if ($this->Address2) $address.=$this->Address2.$seperator;
        if ($this->City || $this->Region) $address.=$this->City." ".$this->Region." ".$this->PostalCode." ".$this->Country;
        $nameLine=$this->name_combine();
        if ($address&&$include_name){
            $address=$nameLine.(trim($address)?$seperator.$address:"");
        }
        return trim($address);
    }

    function name_check(){        
        return self::name_check_individual($this->Name).($this->Name2?" & ".self::name_check_individual($this->Name2):"");
    }

    static function name_check_individual($name){
        $names=explode(" ",$name);
        $alert=false;
        foreach ($names as $n){
            if (ucfirst($n)!=$n){
                $alert=true;
            }
        }       
        if ($alert) return "<span style='background-color:yellow;'>".$name."</span>";
        else return $name;
    }
   
    public function phone(){
        return $this->phone_format($this->Phone);
    }
    
    static function summary_list($where=[],$year=null){
        $total=0;
        $where[]="Status>=0";
        $where[]="Type>=1";        
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`, COUNT(*) as donation_count, SUM(Gross)  as Total , MIN(DT.Date) as DateEarliest, MAX(DT.Date) as DateLatest FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE ".implode(" AND ",$where)." Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country` Order BY  SUM(Gross) DESC,COUNT(*) DESC";
        $results = self::db()->get_results($SQL);
        ?><div><a href="?page=<?php print $_GET['page']?>">Return</a></div><form method=post><input type="hidden" name="Year" value="<?php print $year?>"/>
        <table class="dp"><tr><th>Donor</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Count</th><th>Amount</th><th>First Donation</th><th>Last Donation</th></tr><?php
        foreach ($results as $r){
            $donor=new self($r);
            ?>
            <tr>
                <td><a target="Donor" href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a></td><td><?php print $donor->name_check()?></td>
                <td><?php print $donor->display_email()?></td>    
                <td><?php print $donor->phone()?></td> 
                <td><?php print $donor->mailing_address(', ',false)?></td>          
                <td><?php print $r->donation_count?></td>
                <td><?php print $r->Total?></td>
                <td><?php print $r->DateEarliest?></td>
                <td><?php print $r->DateLatest?></td>
            </tr><?php
            $total+=$r->Total;
        }?><tr><td></td><td></td><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td></tr></table>
                  
        <?php
        return;
    }

    static function summary_list_year($year){       
        ### Find what receipt haven't been sent yet
        $SQL="SELECT `DonorId`, `Type`, `Address`, `DateSent` FROM ".DonationReceipt::get_table_name()." WHERE `KeyType`='YearEnd' AND `KeyId`='".$year."'";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $receipts[$r->DonorId][]=new DonationReceipt($r);
        }
        ## Find NOT Tax Deductible entries
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, COUNT(*) as donation_count, SUM(Gross) as Total FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 AND DT.NotTaxDeductible>0 Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City Order BY COUNT(*) DESC, SUM(Gross) DESC";  
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $notTaxDeductible[$r->DonorId]=$r;
        }

        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, COUNT(*) as donation_count, SUM(Gross) as Total FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 AND DT.NotTaxDeductible=0 Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City Order BY COUNT(*) DESC, SUM(Gross) DESC";
        $results = self::db()->get_results($SQL);
        ?><form method=post><input type="hidden" name="Year" value="<?php print $year?>"/>
        <table class="dp"><tr><th>Donor</th><th>Name</th><th>Email</th><th>Count</th><th>Amount</th><th>Preview</th><th><input type="checkbox" checked onClick="toggleChecked(this,'emails[]');")/>
        <script>
            function toggleChecked(source,name){                
                checkboxes = document.getElementsByName(name);
                for(var i=0, n=checkboxes.length;i<n;i++) {
                    checkboxes[i].checked = source.checked;
                }                    
            }
        </script> E-mail</th><th><input type="checkbox" checked onClick="toggleChecked(this,'pdf[]');")/> PDF</th><th>Sent</th><th>Not Tax Deductible</th><th>Donor Total</th></tr><?php
        $total=0;
        foreach ($results as $r){
            $donor=new self($r);
            $donorTotal=$r->Total;
            ?>
            <tr><td><a target="Donor" href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a></td>
            <td><?php print $donor->name_check()?></td>
            <td><?php print $donor->display_email()?></td>            
            <td><?php print $r->donation_count?></td>
            <td align=right><?php print number_format($r->Total,2)?></td><td><a target="Donor" href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>&f=YearReceipt&Year=<?php print $year?>">Receipt</a></td>
            <td><?php
             if (filter_var($r->Email, FILTER_VALIDATE_EMAIL) && $r->EmailStatus>=0) {
                ?><input name="emails[]" type="checkbox" value="<?php print $r->DonorId?>" <?php print ($receipts[$r->DonorId] ?"":" checked")?>/><?php
             }
            ?></td>
            <td><?php
             //if ($r->Address1 && $r->City) {
                ?><input name="pdf[]" type="checkbox" value="<?php print $r->DonorId?>" <?php print ($receipts[$r->DonorId]?"":" checked")?>/><?php
             //}
            ?></td><td><?php
            //self::dump($receipts[$r->DonorId]);
            print DonationReceipt::displayReceipts($receipts[$r->DonorId]);
            ?></td>
            <td><?php 
                 if($notTaxDeductible[$r->DonorId]){
                    print "Count: ".$notTaxDeductible[$r->DonorId]->donation_count." Total: ".number_format($notTaxDeductible[$r->DonorId]->Total,2);
                    $donorTotal+=$notTaxDeductible[$r->DonorId]->Total;
                 }?>
             </td>
             <td align=right><?php print number_format($donorTotal,2);?></td>
            </tr><?php
            $total+=$donorTotal;
        }?><tr><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td><td></td></table>
        Limit: <Input type="number" name="limit" value="<?php print $_REQUEST['limit']?>" style="width:50px;"/>
        <button type="submit" name="Function" value="SendYearEndEmail">Send Year End E-mails</button>
        <button type="submit" name="Function" value="SendYearEndPdf">Send Year End Pdf</button> <label><input type="checkbox" name="blankBack" value="t"> Print Blank Back</label>
        </form>
        <?php
        return;
    }

    function receipt_table_generate($donations){
        if (sizeof($donations)==0) return "";
        $total=0;
        $ReceiptTable='<table border="1" cellpadding="4"><tr><th width="115">Date</th><th width="330">Subject</th><th width="100">Amount</th></tr>';
        foreach($donations as $r){
            $lastCurrency=$r->Currency;
            $total+=$r->Gross; 
            $ReceiptTable.="<tr><td>".date("F j, Y",strtotime($r->Date))."</td><td>";
            switch($r->PaymentSource){
                case 1:
                    $ReceiptTable.="Check".(is_numeric($r->TransactionID)?" #".$r->TransactionID:"");$ReceiptTable.=$r->Subject?" ".$r->Subject:"";
                    break;
                case "5":
                    $ReceiptTable.="Paypal".($r->Subject?": ".$r->Subject:"");
                    break;
                case "6": 
                    $ReceiptTable.="ACH/Wire".($r->Subject?": ".$r->Subject:($r->TransactionID?" #".$r->TransactionID:""));
                    break;
                default:  $ReceiptTable.= $r->Subject;
                break;                  
            } 
            if (!$r->Subject && $r->CategoryId) $ReceiptTable.=" ".$r->show_field("CategoryId",['showId'=>false]) ;
                        
            $ReceiptTable.="</td><td align=\"right\">".trim(number_format($r->Gross,2)." ".$r->Currency).'</td></tr>';            
        }
        $ReceiptTable.="<tr><td colspan=\"2\"><strong>Total:</strong></td><td align=\"right\"><strong>".trim(number_format($total,2)." ".$lastCurrency)."</strong></td></tr></table>";
        return $ReceiptTable;
    }

    function year_receipt_email($year){
        $this->emailBuilder=new stdClass();
        $page = DonorTemplate::get_by_name('donor-receiptyear');  
        //$this->dump($page);
        if (!$page){ ### Make the template page if it doesn't exist.
            self::make_receipt_year_template();
            $page = DonorTemplate::get_by_name('donor-receiptyear');  
            self::display_notice("Page /donor-receiptyear created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $total=0;
        $donations=Donation::get(array("DonorId=".$this->DonorId,"YEAR(Date)='".$year."'"),'Date');
        foreach($donations as $r){
            if ($r->NotTaxDeductible==0){
                $taxDeductible[]=$r;
                $total+=$r->Gross;                
            }else{
                $notTaxDeductible[]=$r;
                $nteTotal+=$r->Gross;
            }
        }
        $ReceiptTable="";
        if (sizeof($taxDeductible)>0){
            $ReceiptTable.=$this->receipt_table_generate($taxDeductible);
        }

        if (sizeof($notTaxDeductible)>0){
            $plural=sizeof($notTaxDeductible)==1?"":"s";
            if ($ReceiptTable){
                $ReceiptTable.="<p>Additionally the following gift".$plural."/grant".$plural." totaling <strong>$".number_format($nteTotal,2)."</strong> ".($plural=="s"?"were":"was")." given for which you may have already received a tax deduction. Consult a tax professional on whether these gifts can be claimed:</p>";
            }
            $ReceiptTable.=$this->receipt_table_generate($notTaxDeductible);
        }
        if ($ReceiptTable=="") $ReceiptTable="<div><em>No Donations found in ".$year."</div>";
        
        $organization=get_option( 'donation_Organization');
        if (!$organization) $organization=get_bloginfo('name');
        $subject=$page->post_title;
        $body=$page->post_content;
        
        ### custom variables
        $body=trim(str_replace("##Organization##",$organization,$body));
        $body=trim(str_replace("##FederalId##", get_option( 'donation_FederalId' ),$body)); 
        $body=trim(str_replace("##ContactName##", get_option( 'donation_ContactName' ),$body)); 
        $body=trim(str_replace("##ContactTitle##", get_option( 'donation_ContactTitle' ),$body)); 
        $body=trim(str_replace("##ContactEmail##", get_option( 'donation_ContactEmail' ),$body)); 
        ### generated variables
        $body=str_replace("##Name##",$this->name_combine(),$body);
        $body=str_replace("##Year##",$year,$body);
        $body=str_replace("##DonationTotal##","$".number_format($total,2),$body);
        $body=str_replace("<p>##ReceiptTable##</p>",$ReceiptTable,$body);
        $body=str_replace("##ReceiptTable##",$ReceiptTable,$body);
        $address=$this->mailing_address();
        if (!$address){ //remove P
            $body=str_replace("<p>##Address##</p>",$address,$body);
        }
        $body=str_replace("##Address##",$address,$body);

        $body=str_replace("##Date##",date("F j, Y"),$body);

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        $subject=trim(str_replace("##Year##",$year,$subject));
        $subject=trim(str_replace("##Organization##",$organization,$subject));
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body; 
        
        $variableNotFilledOut=array();
        $pageHashes=explode("##",$body); 
        $c=0;
        foreach($pageHashes as $r){
            if ($c%2==1){
                if (strlen($r)<16){
                    $variableNotFilledOut[$r]=1;
                }
            }
            $c++;
        }
        if (sizeof($variableNotFilledOut)>0){
            self::display_error("The Following Variables need manually changed:<ul><li>##".implode("##</li><li>##",array_keys($variableNotFilledOut))."##</li></ul> Please <a target='pdf' href='post.php?post=".$this->emailBuilder->pageID."&action=edit'>correct template</a>.");
        }
    }

    function year_receipt_form($year){       
        $this->year_receipt_email($year);  
        $form="";      
        if ($_POST['Function']=="SendYearReceipt" && $_POST['Email']){
            $html=$_POST['customMessage']?stripslashes_deep($_POST['customMessage']) :$this->emailBuilder->body;
            //
            if (wp_mail($_POST['Email'], $this->emailBuilder->subject,$html,array('Content-Type: text/html; charset=UTF-8'))){ 
                $form.="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".$_POST['Email']."</div>";
                $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>$_POST['Email'],"Content"=>$html,"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();
                self::display_notice($year." Year End Receipt Sent to: ".$_POST['Email']);
            }
        }        
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","`KeyType`='YearEnd'","`KeyId`='".$year."'"));
        $lastReceiptKey=is_array($receipts)?sizeof($receipts)-1:0;
        $bodyContent=$receipts[$lastReceiptKey]->Content?$receipts[$lastReceiptKey]->Content:$this->emailBuilder->body;

        $homeLinks="<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        print $homeLinks;
        print '<form method="post">';
        print "<h2>".$this->emailBuilder->subject."</h2>";             
       

        wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false));

        
        ### Form View
        print '<div class="no-print"><hr>Send Receipt to: <input type="email" name="Email" value="'.($_POST['Email']?$_POST['Email']:$this->Email).'"/><button type="submit" name="Function" value="SendYearReceipt">Send E-mail</button>
        <button type="submit" name="Function" value="YearEndReceiptPdf">Generate PDF</button>';
       
        print DonationReceipt::show_results($receipts);
        print '</form>';
        if ($this->emailBuilder->pageID){
            print "<div><a target='pdf' href='?page=donor-settings&tab=email&DonorTemplateId=".$this->emailBuilder->pageID."&edit=t'>Edit Template</a></div>";      
        }

        return true;
    }

    function year_receipt_pdf($year,$customMessage=null){
        if (!class_exists("TCPDF")){
            self::display_error("PDF Writing is not installed. You must run 'composer install' on the donor-press plugin directory to get this to funciton.");
            return false;
        }
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->year_receipt_email($year);
        $html="<h2>".$this->emailBuilder->subject."</h2>".$customMessage?$customMessage:$this->emailBuilder->body;
        $pdf->SetFont('helvetica', '', 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $f=$this->receipt_file_info($year);
        $file=$f['file'];
        $path=$f['path'];

        $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"m","Address"=>$this->mailing_address(),"Content"=>$html,"DateSent"=>date("Y-m-d H:i:s")));
		$dr->save();  
        if ($pdf->Output($path, 'D')){
            return true;
        }else{
            return false;
        }
    }
    function receipt_file_info($year){
        $file=substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-'.$year.'.pdf';
        $link=dn_plugin_base_dir()."/resources/".$file; //Not acceptable on live server... May need to scramble code name on file so it isn't guessale.
        return array('path'=>$link,'file'=>$file,'link'=>$link);
    }

    static function autocomplete($query){       
        $searchText = strtoupper($query);
        $where= array("(UPPER(Name) LIKE '%".$searchText."%' 
        OR UPPER(Name2)  LIKE '%".$searchText."%'
        OR UPPER(Email) LIKE '%".$searchText."%'
        OR UPPER(Phone) LIKE '%".$searchText."%')"
        ,"(MergedId =0 OR MergedId IS NULL)");
        //,Name2
		$SQL="SELECT DonorId,Name, Name2, Email, Phone FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"").($orderby?" ORDER BY ".$orderby:"")." LIMIT 10";

		$all=self::db()->get_results($SQL);
        print json_encode($all);
        exit();
        //wp_die(); 

		// foreach($all as $r){
        //     $return[]=$r->Name;
        // }
        // print json_encode($return);
        // wp_die(); 
      
    }

    static function make_receipt_year_template(){
        $page = DonorTemplate::get_by_name('donor-receiptyear');  
        if (!$page){
            $postarr['ID']=0;
            $tempLoc=dn_plugin_base_dir()."/resources/template_default_receipt_year.html";          
            $postarr['post_content']=file_get_contents($tempLoc);
            $postarr['post_title']='##Organization## ##Year## Year End Receipts';
            $postarr['post_status']='private';
            $postarr['post_type']='donortemplate';
            $postarr['post_name']='donor-receiptyear';           
            return wp_insert_post($postarr);            
        }
    }

    static function find_duplicates_to_merge(){
        ### function to track down duplicates by email. Helpful for cleaningup DB if a script goes awry.
        $SQL="SELECT DN.* FROM ".Donor::get_table_name()." DN LEFT JOIN ".Donation::get_table_name()." DT ON DN.`DonorId`=DT.DonorID Where DonationId IS NULL and MergedId=0;";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $stats['e'][strtolower($r->Email)]=$r;
            $stats['d'][$r->DonorId]=$r;
        }
        $SQL="Select * From ".Donor::get_table_name()." WHERE Email IN ('".implode("','",array_keys($stats['e']))."') AND DonorId NOT IN (".implode(',',array_keys($stats['d'])).")";
        //print $SQL;
        $results = self::db()->get_results($SQL);       
        ?><h3>Merger list</h3><table><?php
        
        foreach ($results as $r){
            $match= $stats['e'][strtolower($r->Email)];
            if ($match->Address1 && !$r->Address1){            
                ?><tr>
                    <td><a target='match' href="?page=donor-index&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a> - <?php print $r->Name?></td><td><?php print $r->Address1?></td>
                    <td><a target='match' href="?page=donor-index&DonorId=<?php print $match->DonorId?>"><?php print $match->DonorId?></a> -<?php print $match->Name?></td><td><?php print $match->Address1?></td>
                    <td><a target='match' href='?page=donor-index&Function=MergeConfirm&MergeFrom=<?php print $match->DonorId?>&MergedId=<?php print $r->DonorId?>'><-Merge</a></td></tr><?php
            }
            $current[$r->DonorId]=$r;
            $match->DonorId=$r->DonorId;
            $match->email=strtolower($match->email);
            $new[$r->DonorId]=$match;

        }
        ?></table>
        <?php        
        self::donor_update_suggestion($current,$new);
    }

    static function get_email_list(){
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,COUNT(*) as donation_count, SUM(Gross) as Total,DATE(MIN(DT.`Date`)) as FirstDonation, DATE(MAX(DT.`Date`)) as LastDonation
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE D.Email<>'' AND D.EmailStatus=1 AND D.MergedId=0        
        Group BY D.DonorId, D.Name, D.Name2,`Email` Order BY D.Name";
        $results = self::db()->get_results($SQL);
        $fp = fopen(dn_plugin_base_dir()."/resources/email_list.csv", 'w');
        fputcsv($fp, array_keys((array)$results[0]));//write first line with field names
        foreach ($results as $r){
            fputcsv($fp, (array)$r);
        }
        fclose($fp);
    }

    static function get_mail_list(){
        $SQL="Select D.DonorId, D.Name, D.Name2,`Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,COUNT(*) as donation_count, SUM(Gross) as Total,DATE(MIN(DT.`Date`)) as FirstDonation, DATE(MAX(DT.`Date`)) as LastDonation
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE D.Address1<>''      
        Group BY D.DonorId, D.Name, D.Name2,`Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country` Order BY D.Name";
        print "<pre>".$SQL."</pre>";
        $results = self::db()->get_results($SQL);
        $fp = fopen(dn_plugin_base_dir()."/resources/address_list.csv", 'w');
        fputcsv($fp, array_keys((array)$results[0]));//write first line with field names
        foreach ($results as $r){
            fputcsv($fp, (array)$r);
        }
        fclose($fp);
    }
}