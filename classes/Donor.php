<?php

require_once 'ModelLite.php';
require_once 'Donation.php';
require_once 'DonationReceipt.php';

class Donor extends ModelLite {
    protected $table = 'donor';
	protected $primaryKey = 'DonorId';
	### Fields that can be passed 
    public $fillable = ["Name","Name2","Email","EmailStatus","Phone","Address1","Address2","City","Region","PostalCode","Country","TypeId","TaxReporting","MergedId","Source","SourceId","QuickBooksId"];	    
	
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

    protected $fieldLimits = [//SELECT concat("'",column_name,"'=>",character_maximum_length ,",") as grid FROM information_schema.columns where table_name = 'wp_donor' and table_schema='wordpress' and data_type='varchar'
        'Source'=>20,
        'SourceId'=>50,
        'Name'=>80,
        'Name2'=>80,
        'Email'=>80,
        'Phone'=>20,
        'Address1'=>80,
        'Address2'=>80,
        'City'=>50,
        'Region'=>20,
        'PostalCode'=>20,
        'Country'=>2,
    ];
	//public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
    /*
    ALTER TABLE `wordpress`.`dwp_donor` 
ADD COLUMN `TypeId` INT NULL DEFAULT NULL AFTER `Country`;
*/
   
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
            `TypeId` int(11) NOT NULL DEFAULT '0',
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
            <a href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $this->DonorId?>&edit=t">Edit Donor</a> | 
            <a href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $this->DonorId?>&f=AddDonation">Add Donation</a>
        </div>
        <?php
        $this->var_view();
        $this->quick_books_link();        
        $this->merge_form();
        ?>
        <h2>Donation Summary</h2>
        <div>Year End Receipt: <a href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")?>"><?php print date("Y")?></a> | <a href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")-1?>"><?php print date("Y")-1?></a> | <a href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")-2?>"><?php print date("Y")-2?></a></div>
        <?php
         $totals=['Count'=>0,'Total'=>0];
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
		print Donation::show_results($results,"",["DonationId","Date","DateDeposited","Name","Type","Gross","FromEmailAddress","CategoryId","Subject","Note","PaymentSource","TransactionID","TransactionType"]);		
    }

    function quick_books_link(){
        if (CustomVariables::get_option('QuickbooksClientId',true)){
            if (!$this->QuickBooksId){                
                print "<div><a href='?page=donor-quickbooks&syncDonorId=".$this->DonorId."'>Sync Donor to Quickbooks</a></div>";
            }
        }
    }

    static public function request_handler(){      
        if (self::input('table','post')=='donor' && self::input('DonorId','post') && self::input('Function','post')=="Delete"){
            //check if any donations connected to this account or merged ids..
            $donations=Donation::get(array('DonorId='.self::input('DonorId','post')));
            if (sizeof($donations)>0){
                self::display_error("Can't delete Donor #".self::input('DonorId','post').". There are ".sizeof($donations)." donation(s) attached to this.");           
                return false;
            }
            $donors=Donation::get(array('MergedId='.self::input('DonorId','post')));
            if (sizeof($donors)>0){
                self::display_error("Can't delete Donor #".self::input('DonorId','post').". There are ".sizeof($donors)." donors merged to this entry.");                           
                return false;
            }else{
                $dSQL="DELETE FROM ".Donor::get_table_name()." WHERE `DonorId`='".self::input('DonorId','post')."'";
                self::db()->query($dSQL);
                self::display_notice("Deleted Donor #".self::input('DonorId','post').".");                           
                return true;
            }


        }elseif (self::input('Function','post')=='MergeDonor' && self::input('DonorId','post')){
            //self::dump($_POST);            
            $data=array();
            foreach(self::s()->fillable as $field){
                if ($_POST[$field] && $field!='DonorId'){
                    $data[$field]=$_POST[$field];
                }
            }
            if (sizeof($data)>0){
                ### Update Master Entry with Fields from merged details
                self::db()->update(self::s()->get_table(),$data,array('DonorId'=>self::input('DonorId','post')));
            }
            $mergeUpdate['MergedId']=self::input('DonorId','post');
            foreach(self::input('donorIds','post') as $oldId){
                if ($oldId!=self::input('DonorId','post')){
                    ### Set MergedId on Old Donor entry
                    self::db()->update(self::s()->get_table(),$mergeUpdate,array('DonorId'=>$oldId));
                    ### Update all donations on old donor to new donor
                    $uSQL="UPDATE ".Donation::s()->get_table()." SET DonorId='".self::input('DonorId','post')."' WHERE DonorId='".$oldId."'";  
                    self::db()->query($uSQL);
                    self::display_notice("Donor #<a href='?page=".self::input('page','get')."&DonorId=".$oldId."'>".$oldId."</a> merged to #<a href='?page=".self::input('page','get')."&DonorId=".self::input('DonorId','post')."'>".self::input('DonorId','post')."</a>");
                }
            }  
            $_GET['DonorId']=self::input('DonorId','post'); 

        }
        if (self::input('f','get')=="AddDonor"){
            $donor=new Donor;
            if (self::input('dsearch','request') && !$donor->DonorId && !$donor->Name){
                $donor->Name=self::input('dsearch','request');
            }          
            print "<h2>Add Donor</h2>";            
            $donor->edit_form();           
            return true;
        }elseif(self::input('f','get')=="summary_list" && self::input('dt','get') && self::input('df','get')){            
            self::summary_list(array("Date BETWEEN '".self::input('df','get')." 00:00:00' AND '".self::input('dt','get')." 23:59:59'"),self::input('Year','get'));
            return true;
        }elseif(self::input('Function','post')=='MergeConfirm'){ 
            $donorA=Donor::find(self::input('MergeFrom','post'));
            $donorB=Donor::find(self::input('MergedId','post'));
            if (!$donorB->DonorId){
                 self::display_error("Donor ".self::input('MergedId','post')." not found.");
                 return false;
            }else{
                $donorB->merge_form_compare($donorA);
            }
            return true;
        }elseif(self::input('Function','get')=='MergeConfirm'){ 
            $donorA=Donor::find(self::input('MergeFrom','get'));
            $donorB=Donor::find(self::input('MergedId','get'));
            if (!$donorB->DonorId){
                 self::display_error("Donor ".self::input('MergedId','get')." not found.");
                 return false;
            }else{
                $donorB->merge_form_compare($donorA);
            }
            return true;
        }elseif (self::input('DonorId','get')){
            if (self::input('f','get')=="YearReceipt"){
                $donor=Donor::find(self::input('DonorId','request'));
                $donor->year_receipt_form(self::input('Year','get'));
                return true;
            }
            
            if (self::input('Function','post')=="Save" && self::input('table','post')=="donor"){
                $donor=new Donor($_POST);
                if ($donor->save()){			
                    self::display_notice("Donor #".$donor->show_field("DonorId")." saved.");
                }
                
            }
            $donor=Donor::find(self::input('DonorId','request'));	
            ?>
            <div>
               <?php 
                $donor->donor_header();
                if (self::input('edit','request')){
                    $donor->edit_form();
                }else{             
                    $donor->view();                    
                }
            ?></div><?php            
            return true;
        }elseif (self::input('Function','post')=="Save" && self::input('table','post')=="donor"){
            $donor=new Donor($_POST);
            if ($donor->save()){			
                self::display_notice("Donor #".$donor->show_field("DonorId")." saved.");
                $donor->view();
            }
            return true;
            
        }elseif(self::input('Function','post')=='MakeDonorChanges'){            
            self::db()->show_errors();
            foreach(self::input('changes','post') as $line){
                $v=explode("||",$line); //$donorId."||".$field."||".$value
                $changes[$v[0]][$v[1]]=$v[2];
            }
            if (sizeof($changes)>0){
                foreach($changes as $donorId=>$change){
                    $ulinks[]='<a target="lookup" href="?page='.self::input('page','get').'&DonorId='.$donorId.'">'.$donorId.'</a>';
                    self::db()->update(self::get_table_name(),$change,array("DonorId"=>$donorId));

                }
                self::display_notice(sizeof($changes)." Entries Updated: ".implode(" | ",$ulinks));
            }

        }else{
            return false;
        }
    }
    function donor_header(){?>
        <form method="get">
        <input type="hidden" name="page" value="<?php print self::input('page','get')?>"/>
        <div><a href="?page=<?php print self::input('page','get')?>">Home</a> 
        <?php if (self::input('edit','request')){?> | <a href="?page=donor-index&DonorId=<?php print $this->DonorId?>">View Donor</a> <?php }?>
         | Donor Search: <input id="donorSearch" name="dsearch" value=""> <button>Go</button></div>        
    </form>                
    <h1>Donor Profile #<?php print self::input('DonorId','request')?> <?php print $this->Name?></h1>
    <?php
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

    function mailing_address($seperator="<br>",$include_name=true,$settings=[]){
        $address="";
        if ($this->Address1) $address.=$this->Address1.$seperator;
        if ($this->Address2) $address.=$this->Address2.$seperator;
        if ($this->City || $this->Region) $address.=$this->City." ".$this->Region." ".$this->PostalCode;
      
        if (isset($settings['DefaultCountry']) && $settings['DefaultCountry']==$this->Country){}
        elseif($address) $address.=" ".$this->Country;   
        if (($address&&$include_name) || isset($settings['NameOnlyOkay'])){
            $nameLine=$this->name_combine();
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
    
    static function year_list($settings=[]){       
        if (!$settings['orderBy']) $settings['orderBy']="D.Name, D.Name2, YEAR(DT.Date)";
        $total=0;
        if (!$settings['where']) $settings['where']=[];
        $settings['where'][]="Status>=0";
        $settings['where'][]="Type>=1";        
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,YEAR(DT.Date) as Year, COUNT(*) as donation_count, SUM(Gross)  as Total 
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE ".implode(" AND ",$settings['where'])
        ." Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,YEAR(DT.Date) "
        .(sizeof($settings['having'])>0?" HAVING ".implode(" AND ",$settings['having']):"")
        ." Order BY ".$settings['orderBy'];
        //print "<pre>".$SQL."</pre>";
        $results = self::db()->get_results($SQL);
        $q=[];
        foreach($results as $r){
            $q['yearList'][$r->DonorId][$r->Year]+=$r->Total;
            if (!$q['donors'][$r->DonorId]) $q['donors'][$r->DonorId]=new Donor($r);
            $q['year'][$r->Year]+=$r->Total;
        }
        if ($q['year']) ksort($q['year']);
        ?><table class="dp">
            <thead>
                <tr><th>Donor</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th>
            <?php
            foreach($q['year'] as $y=>$total){
                print "<th>".$y."</th>";
            }
            ?></tr>
            </thead>
            <tbody>
            <?php
            foreach($q['donors'] as $id=>$years){
                $donor=$q['donors'][$id];
                ### code filter if amount is given, if amount given in any year then show this row.
                if ($settings['amount']){
                    $pass=false;
                    foreach($q['yearList'][$id] as $amount){
                        if ($amount>=$settings['amount']){
                            $pass=true;
                            break;
                        }
                    }
                }else{
                    $pass=true;
                }
                if ($pass){ 
                    $donorList[]=$donor->DonorId;                
                    ?>  
                    <tr>
                        <td><?php print $donor->show_field('DonorId')?></td>
                        <td><?php print $donor->name_combine()?></td>
                        <td><?php print $donor->display_email()?></td>    
                        <td><?php print $donor->phone()?></td> 
                        <td><?php print $donor->mailing_address(', ',false)?></td> 
                        <?php
                        foreach($q['year'] as $y=>$total){
                            $q['total'][$y]+=$total;
                            print "<td style='text-align:right;'>".($q['yearList'][$id][$y]?number_format($q['yearList'][$id][$y],2):"")."</td>";
                        }
                        ?></tr>        
                    </tr><?php
                }
           
            }?>
            </tbody>
            <tfoot><tr><td></td><td></td><td></td><td></td><td>Totals:</td><?php
            foreach($q['year'] as $y=>$total){
                print "<td style='text-align:right;'>".($q['total'][$y]?number_format($q['total'][$y],2):"")."</td>";
            }
            ?></tr></tfoot>
        </table>
        <?php 
        if (sizeof($donorList)>0) print "<div>Donor Ids: ".implode(",",$donorList) ."</div>";   
    }

    static function summary_list($where=[],$year=null,$settings=[]){
        if (!$settings['orderBy']) $settings['orderBy']="SUM(Gross) DESC,COUNT(*) DESC";
        $total=0;
        $where[]="Status>=0";
        $where[]="Type>=1";        
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`, COUNT(*) as donation_count, SUM(Gross)  as Total , MIN(DT.Date) as DateEarliest, MAX(DT.Date) as DateLatest 
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE ".implode(" AND ",$where)
        ." Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country` "
        .(sizeof($settings['having'])>0?" HAVING ".implode(" AND ",$settings['having']):"")
        ." Order BY ".$settings['orderBy'];

        $results = self::db()->get_results($SQL);
        ?><div><a href="?page=<?php print self::input('page','get')?>">Return</a></div><form method=post><input type="hidden" name="Year" value="<?php print $year?>"/>
        <table class="dp">
            <thead>
                <tr><th>Donor</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Count</th><th>Amount</th><th>First Donation</th><th>Last Donation</th></tr>
            </thead>
            <tbody><?php
        foreach ($results as $r){
            $donor=new self($r);
            ?>
            <tr>
                <td><a target="donor" href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a></td><td><?php print $donor->name_check()?></td>
                <td><?php print $donor->display_email()?></td>    
                <td><?php print $donor->phone()?></td> 
                <td><?php print $donor->mailing_address(', ',false)?></td>          
                <td><?php print $r->donation_count?></td>
                <td><?php print $r->Total?></td>
                <td><?php print $r->DateEarliest?></td>
                <td><?php print $r->DateLatest?></td>
            </tr><?php
            $total+=$r->Total;
        }?>
        </tbody>
        <tfoot>
        <tr><td></td><td></td><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td></tr>
        </tfoot>
        </table>
                  
        <?php
        return;
    }

    static function summary_list_year($year){       
        ### Find what receipt haven't been sent yet
        $SQL="SELECT `DonorId`, `Type`, `Address`, `DateSent`,`Subject` FROM ".DonationReceipt::get_table_name()." WHERE `KeyType`='YearEnd' AND `KeyId`='".$year."'";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $receipts[$r->DonorId][]=new DonationReceipt($r);
        }
        ## Find NOT Tax Deductible entries
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City,Region,PostalCode,Country, COUNT(*) as donation_count, SUM(Gross) as Total FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 AND DT.TransactionType=1 Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City Order BY COUNT(*) DESC, SUM(Gross) DESC";  
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $NotTaxDeductible[$r->DonorId]=$r;
        }

        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,Address2,City,Region,PostalCode,Country, COUNT(*) as donation_count, SUM(Gross) as Total FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 AND DT.TransactionType=0 Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City,Region,Country Order BY COUNT(*) DESC, SUM(Gross) DESC";
        $results = self::db()->get_results($SQL);
        ?><form method=post><input type="hidden" name="Year" value="<?php print $year?>"/>
        <table class="dp"><tr><th>Donor</th><th>Name</th><th>Email</th><th>Mailing</th><th>Count</th><th>Amount</th><th>Preview</th><th><input type="checkbox" checked onClick="toggleChecked(this,'emails[]');")/>
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
            <tr><td><a target="donor" href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a> 
            <a target="donor" href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $r->DonorId?>&edit=t">edit</a></td>
            <td><?php print $donor->name_check()?></td>
            <td><?php print $donor->display_email()?></td> 
            <td><?php print $donor->mailing_address("<br>",false)?></td>             
            <td><?php print $r->donation_count?></td>
            <td align=right><?php print number_format($r->Total,2)?></td><td><a target="donor" href="?page=<?php print self::input('page','get')?>&DonorId=<?php print $r->DonorId?>&f=YearReceipt&Year=<?php print $year?>">Receipt</a></td>
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
                 if($NotTaxDeductible[$r->DonorId]){
                    print "Count: ".$NotTaxDeductible[$r->DonorId]->donation_count." Total: ".number_format($NotTaxDeductible[$r->DonorId]->Total,2);
                    $donorTotal+=$NotTaxDeductible[$r->DonorId]->Total;
                 }?>
             </td>
             <td align=right><?php print number_format($donorTotal,2);?></td>
            </tr><?php
            $total+=$donorTotal;
        }?><tr><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td><td></td></table>
        Limit: <Input type="number" name="limit" value="<?php print self::input('limit')?>" style="width:50px;"/>
        <button type="submit" name="Function" value="SendYearEndEmail">Send Year End E-mails</button>
        <button type="submit" name="Function" value="SendYearEndPdf">Send Year End Pdf</button> <label><input type="checkbox" name="blankBack" value="t"> Print Blank Back</label>
        <label><input type="checkbox" name="preview" value="t"> Preview Only - Don't mark .pdf as sent</label>
        <div>
            <button name="Function" value="ExportDonorList">Export Donor List</button>
            <button name="Function" value="PrintYearEndLabels">Print Labels</button>
            Labels Start At: <strong>Column:</strong> (1-3) &#8594; <input name="col" type="number" value="1"  min="1" max="3" /> &#8595; <strong>Row:</strong> (1-10)<input name="row" type="number" value="1" min="1" max="10"   />
        <em>Designed for 1"x2.625" address label sheets -30 Labels total on 8.5"x11" Paper. When printing, make sure there is NO printer scaling.</div>
        </form>
        <?php
        return;
    }

    function receipt_table_generate($donations){
        if (sizeof($donations)==0) return "";
        $total=0;
        $ReceiptTable='<table border="1" cellpadding="4"><tr><th width="115">Date</th><th width="330">Reference</th><th width="100">Amount</th></tr>';
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
            self::display_notice("Page /donor-receiptyear created. <a target='edit' href='?page=donor-settings&tab=email&DonorTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $total=0;
        $taxDeductible=[];
        $NotTaxDeductible=[];
        $donations=Donation::get(array("DonorId=".$this->DonorId,"YEAR(Date)='".$year."'"),'Date');
        foreach($donations as $r){
            if ($r->TransactionType==0){
                $taxDeductible[]=$r;
                $total+=$r->Gross;                
            }elseif($r->TransactionType==1){
                $NotTaxDeductible[]=$r;
                $nteTotal+=$r->Gross;
            }
        }
        $ReceiptTable="";
        if (sizeof($taxDeductible)>0){
            $ReceiptTable.=$this->receipt_table_generate($taxDeductible);
        }

        if (sizeof($NotTaxDeductible)>0){
            $plural=sizeof($NotTaxDeductible)==1?"":"s";
            
            $ReceiptTable.="<p>Additionally the following gift".$plural."/grant".$plural." totaling <strong>$".number_format($nteTotal,2)."</strong> ".($plural=="s"?"were":"was")." given for which you may have already received a tax deduction. Consult a tax professional on whether these gifts can be claimed:</p>";
            
            $ReceiptTable.=$this->receipt_table_generate($NotTaxDeductible);
        }
        if ($ReceiptTable=="") $ReceiptTable="<div><em>No Donations found in ".$year."</div>";
        
        $organization=get_option( 'donation_Organization');
        if (!$organization) $organization=get_bloginfo('name');
        $subject=$page->post_title;
        $body=$page->post_content;

        ### replace custom variables.
        foreach(CustomVariables::variables as $var){
            if (substr($var,0,strlen("Quickbooks"))=="Quickbooks") continue;
            if (substr($var,0,strlen("Paypal"))=="Paypal") continue;
            $body=str_replace("##".$var."##", get_option( 'donation_'.$var),$body);
            $subject=str_replace("##".$var."##",get_option( 'donation_'.$var),$subject);                   
        }

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
        $this->emailBuilder->fontsize=$page->post_excerpt_fontsize;
        $this->emailBuilder->margin=$page->post_excerpt_margin; 
        
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
            self::display_error("The Following Variables need manually changed:<ul><li>##".implode("##</li><li>##",array_keys($variableNotFilledOut))."##</li></ul> Please <a target='pdf' href='?page=donor-settings&tab=email&DonorTemplateId=".$this->emailBuilder->pageID."&edit=t'>correct template</a>.");
        }
    }

    function year_receipt_form($year){       
        $this->year_receipt_email($year);  
        $form="";      
        if (self::input('Function','post')=="SendYearReceipt" && self::input('Email','post')){
            $html=self::input('customMessage','post')?stripslashes_deep(self::input('customMessage','post')) :$this->emailBuilder->body;
            //
            if (wp_mail(self::input('Email','post'), $this->emailBuilder->subject,$html,array('Content-Type: text/html; charset=UTF-8'))){ 
                $form.="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".self::input('Email','post')."</div>";
                $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>self::input('Email','post'),"Subject"=>$this->emailBuilder->subject,"Content"=>$html,"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();
                self::display_notice($year." Year End Receipt Sent to: ".self::input('Email','post'));
            }
        }        
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","`KeyType`='YearEnd'","`KeyId`='".$year."'"));
        $lastReceiptKey=is_array($receipts)?sizeof($receipts)-1:0;        
        
        
        $homeLinks="<a href='?page=".self::input('page','get')."'>Home</a> | <a href='?page=".self::input('page','get')."&DonorId=".$this->DonorId."'>Return to Donor Overview</a>";

        if (self::input('reset','request')){
            $bodyContent=$this->emailBuilder->body;
        }else{
            $bodyContent=$receipts[$lastReceiptKey]->Content?$receipts[$lastReceiptKey]->Content:$this->emailBuilder->body;
            if ($receipts &&$bodyContent!=$this->emailBuilder->body){
                $homeLinks.= "| <a href='?page=donor-index&DonorId=".self::input('DonorId','request')."&f=YearReceipt&Year=".self::input('Year')."&reset=t'>Update/Reset Letter with latest information</a>";
            }
        }


        print "<div class='no-print'>".$homeLinks."</div>";
        print '<form method="post">';
        print "<h2>".$this->emailBuilder->subject."</h2>";             
       

        wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false));

        
        ### Form View
        print '<div class="no-print"><hr>Send Receipt to: <input type="email" name="Email" value="'.(self::input('Email','post')?self::input('Email','post'):$this->Email).'"/><button type="submit" name="Function" value="SendYearReceipt">Send E-mail</button>
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
            self::display_error("PDF Writing is not installed. You must run 'composer install' on the donor-press plugin directory to get this to funciton or install <a href='https://donorpress.com/wp-admin/plugin-install.php?s=DoublewP%2520TCPDF%2520Wrapper&tab=search&type=term'>DoublewP TCPDF Wrapper</a> ");
            return false;
        }
        $this->year_receipt_email($year);
        ob_clean();
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $margin=($this->emailBuilder->margin?$this->emailBuilder->margin:.25)*72;
        $pdf->SetMargins($margin,$margin,$margin);
        $html="<h2>".$this->emailBuilder->subject."</h2>".$customMessage?$customMessage:$this->emailBuilder->body;
        $pdf->SetFont('helvetica', '', $this->emailBuilder->fontsize?$this->emailBuilder->fontsize:10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $f=$this->receipt_file_info($year);
        $file=$f['file'];
        $path=$f['path'];

        $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"m","Address"=>$this->mailing_address(),"Subject"=>$this->emailBuilder->subject,"Content"=>$html,"DateSent"=>date("Y-m-d H:i:s")));
		$dr->save();  
        if ($pdf->Output($path, 'D')){
            return true;
        }else{
            return false;
        }
    }

    static function YearEndReceiptMultiple($year,$donorIdPost,$limit,$blankBlack=false,$logReceipt=true){
        if (!$limit) $limit=1000;
        if (sizeof($donorIdPost)<$limit) $limit=sizeof($donorIdPost);
        for($i=0;$i<$limit;$i++){
            $donorIds[]=$donorIdPost[$i];
        }
        if (sizeof($donorIds)>0){
            if (!class_exists("TCPDF")){
                Donation::display_error("PDF Writing is not installed. You must run 'composer install' on the donor-press plugin directory to get this to funciton.");
                return false;
            }
            $donorList=Donor::get(array("DonorId IN ('".implode("','",$donorIds)."')"));
            ob_clean();
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);            
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false); 				
            foreach ($donorList as $donor){
                $donor->year_receipt_email($year);
                $pdf->AddPage();
                $margin=($this->emailBuilder->margin?$donor->emailBuilder->margin:.25)*72;
                $pdf->SetMargins($margin,$margin,$margin);
                $pdf->SetFont('helvetica', '', $donor->emailBuilder->fontsize?$this->emailBuilder->fontsize:12);                
                $pdf->writeHTML("<h2>".$donor->emailBuilder->subject."</h2>".$donor->emailBuilder->body, true, false, true, false, '');
                if ($blankBlack && $pdf->PageNo()%2==1){ //add page number check
                    $pdf->AddPage();
                }
                if ($logReceipt){
                    $dr=new DonationReceipt(array("DonorId"=>$donor->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"m","Address"=>$donor->mailing_address(),"Subject"=>$donor->emailBuilder->subject,"Content"=>"<h2>".$donor->emailBuilder->subject."</h2>".$donor->emailBuilder->body,"DateSent"=>date("Y-m-d H:i:s")));                            
                    $dr->save();
                }
            }                    
            $pdf->Output('YearEndReceipts'.$year.'.pdf', 'D');
            return true;
        }
    }

    static function YearEndLabels($year,$donorIdPost,$col_start=1,$row_start=1,$limit=100000){
        if (sizeof($donorIdPost)<$limit) $limit=sizeof($donorIdPost);
        if (!class_exists("TCPDF")){
            self::display_error("PDF Writing is not installed. You must run 'composer install' on the donor-press plugin directory to get this to funciton.");
            return false;
        }       

        $donors=Donor::get(["DonorId IN ('".implode("','",$donorIdPost)."')"],"",['key'=>true]);        
        $a=[];
        $defaultCountry=CustomVariables::get_option("DefaultCountry");       
        foreach($donorIdPost as $id){
            if ($donors[$id]){
                $address=$donors[$id]->mailing_address("\n",true,['DefaultCountry'=>$defaultCountry]);
                if(!$address) $address=$donors[$id]->name_combine();
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
         /// $pdf_tmp->AddPage();
        // $pdf_tmp->SetCellPadding($pad);
        $pdf->SetCellPadding($pad);
        $pdf->SetAutoPageBreak(true);
        $pdf->SetMargins($margin['x'],$margin['y'],$margin['x']);
        // set document information
        $pdf->SetCreator('Donor-Press Plugin');
        $pdf->SetAuthor('Donor-Press');
        $pdf->SetTitle($year.'Year End Labels');	
        //$pdf->setCellHeightRatio(1.1);       
        $starti=($col_start>0?($col_start-1)%3:0)+($row_start>0?3*floor($row_start-1):0);
        $border=0; $j=0;
        for ($i=$starti;$i<sizeof($a)+$starti;$i++){
            $col=$i%3;
            $row=floor($i/3)%10;
            if ($i%30==0 && $j!=0){ $pdf->AddPage();}
            //$h=shrinkletters(2.625*$dpi,$dpi,$a[$j],12); //size cell			
            $pdf->MultiCell(2.625*$dpi,1*$dpi,$a[$j],$border,"L",0,0,$margin['x']+$col*2.75*$dpi,$margin['y']+$row*1*$dpi,true);
            $j++;		
        }	
        $pdf->Output("DonorPressYearEndLabels".$year.".pdf", 'D');

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
            $tempLoc=dn_plugin_base_dir()."/resources/template_default_receipt_year.html";   
            $t=new DonorTemplate();          
            $t->post_content=file_get_contents($tempLoc);            
            $t->post_title='##Organization## ##Year## Year End Receipts';
            $t->post_name='donor-receiptyear';
            $t->post_excerpt='{"fontsize":"10","margin":".2"}';
            $t->save();
            return $t;
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

    static function get_mail_list($where=[]){
        $SQL="Select D.DonorId, D.Name, D.Name2, D.Email,`Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,COUNT(*) as donation_count, SUM(Gross) as Total,DATE(MIN(DT.`Date`)) as FirstDonation, DATE(MAX(DT.`Date`)) as LastDonation
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE ".(sizeof($where)>0?implode(" AND ",$where):" 1 ")."    
        Group BY D.DonorId, D.Name, D.Name2,`Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country` Order BY D.Name";   
        $results = self::db()->get_results($SQL);        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Donors.csv');
        $fp = fopen('php://memory', 'r+');
        fputcsv($fp, array_keys((array)$results[0]));//write first line with field names
        foreach ($results as $r){
            fputcsv($fp, (array)$r);
        }
        rewind($fp);
         $csv_line = stream_get_contents($fp);
         print $csv_line;
         exit();
    }

    static function merge_suggestions(){ //similar to find duplicates to merge... probably can consolidate
        $matchField=['Name','Name2','Email','Phone','Address1'];
        $SQL="Select D.DonorId, D.Name, D.Name2,D.Email,D.Phone,D.Address1,MergedId, COUNT(DT.DonationId) as donation_count
        FROM ".Donor::get_table_name()." D LEFT JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId
        Group BY D.DonorId, D.Name, D.Name2,D.Email,D.Phone,D.Address1,MergedId Order BY  D.DonorId";
        $results = self::db()->get_results($SQL); 
       // dd($results);
        $show=false;
        $merge=[];
        $cache=[];
        foreach ($results as $r){
            $donors[$r->DonorId]=$r;
            if ($r->MergedId>0){
                if ($r->donation_count>0){ 
                    $merge[$r->DonorId]=$r->MergedId;                   
                }
            }elseif(!$r->MergedId){
                foreach($matchField as $field){
                    if (trim($r->$field)){
                        $val=preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($r->$field));
                        if (!$cache[$val] || !in_array($r->DonorId,$cache[$val])) $cache[$val][]=$r->DonorId;
                        if ($cache[$val] && sizeof($cache[$val])>1) $show=true;
                        if ($field=="Name"){
                            $nameParts=explode(" ",strtolower(trim(str_replace(" &","",$r->Name))));
                            if (sizeof($nameParts)>2){                                
                                $val=preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($nameParts[0].$nameParts[sizeof($nameParts)-1]));
                                if (!$cache[$val] || !in_array($r->DonorId,$cache[$val])) $cache[$val][]=$r->DonorId;
                                $val=preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($nameParts[1].$nameParts[sizeof($nameParts)-1]));
                                if (!$cache[$val] || !in_array($r->DonorId,$cache[$val])) $cache[$val][]=$r->DonorId;
                            }
                            
                        }
                    }
                }              
            }            
        }
        //dd( $cache);
        if (sizeof($merge)>0) $show=true;
        if ($show){
            ?><h2>Entries Found to Merge</h2>
            <table border=1><th>Donor</th><th>Merge To</th></tr><?php
            foreach($merge as $from=>$to){
                print '<tr><td>';              
                print '<div><a target="donor" href="?page=donor-index&DonorId='.$donors[$from]->DonorId.'">'.$donors[$from]->DonorId.'</a> '.$donors[$from]->Name.' (merged id: '.$donors[$from]->MergedId.') <a target="donor" href="?page=donor-index&Function=MergeConfirm&MergeFrom='.$donors[$from]->DonorId.'&MergedId='.$donors[$to]->DonorId.'">Merge To -></a></div>';   
                          
                print '</td><td><a target="donor" href="?page=donor-index&DonorId='.$donors[$to]->DonorId.'">'.$donors[$to]->DonorId.'</a> '.$donors[$to]->Name."</td></tr>";
            }
            foreach($cache as $key=>$a){
                if (sizeof($a)>1){                  
                    print '<tr><td>';
                    for($i=1;$i<sizeof($a);$i++){
                        print '<div><a target="donor" href="?page=donor-index&DonorId='.$donors[$a[$i]]->DonorId.'">'.$donors[$a[$i]]->DonorId.'</a> '.$donors[$a[$i]]->Name.($donors[$a[$i]]->Name2?" & ".$donors[$a[$i]]->Name2:"").' <a target="donor" href="?page=donor-index&Function=MergeConfirm&MergeFrom='.$donors[$a[$i]]->DonorId.'&MergedId='.$donors[$a[0]]->DonorId.'">Merge To -></a></div>';   
                    }                 
                    print '</td><td><a target="donor" href="?page=donor-index&DonorId='.$donors[$a[0]]->DonorId.'">'.$donors[$a[0]]->DonorId.'</a> '.$donors[$a[0]]->Name.($donors[$a[0]]->Name2?" & ".$donors[$a[0]]->Name2:"")."</td></tr>";
                }
            }
        }
    }

   

}