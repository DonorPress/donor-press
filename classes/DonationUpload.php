<?php
require_once 'Donor.php';
require_once 'DonationCategory.php';
require_once 'DonorTemplate.php';
class DonationUpload extends ModelLite
{
    protected $paypal = ["Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","From Email Address","To Email Address","Transaction ID","Address Status","Item Title","Item ID","Option 1 Name","Option 1 Value","Option 2 Name","Option 2 Value","Reference Txn ID","Invoice Number","Custom Number","Quantity","Receipt ID","Balance","Contact Phone Number","Subject","Note","Payment Source"];
    protected $paypalPPGF = ["Payout Date","Donation Date","Donor First Name","Donor Last Name","Donor Email","Program Name","Reference Information","Currency Code","Gross Amount","Total Fees","Net Amount","Transaction ID"];
    protected $csvHeaders = ["DepositDate","CheckDate","CheckNumber","Name1","Name2","Gross","Account","ReceiptNeeded","Note","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];

    const UPLOAD_PATTERN = [
        'Last, Name1 & Name2'=>'Name|Name2',
        'City, Region Postal Country'=>'City|Region|PostalCode|Country',
        'Category'=>'CategoryId'
    ];

    const UPLOAD_AUTO_SELECT = ['name'=>'Name','check'=>'TransactionID','date'=>'Date','deposit'=>'DateDeposited','amount'=>'Gross','total'=>'Gross','note'=>'Note','comment'=>'Note','address'=>'Address1','address1'=>'Address1','address2'=>'Address2','e-mail'=>'Email','Phone'=>'Phone'];  

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
                                       
                    $obj=new Donation($donationFill);             
                   
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

    }
    
    
    static public function process_map_file($post){
        //dd($post);
        $csv=self::csv_file_to_array($post["file"],$post["firstLineColumns"]);
        //dd($csv,$post);
        $recommended_bulk['Donor']=["Source","Country"];
        $recommended_bulk['Donation']=["Date","DateDeposited","Source","PaymentSource"];
        
        $selectDonation=Donation::s()->fillable; 
        $selectDonor=Donor::s()->fillable;
        ##save .map file so if this file is reuploaded/reopened, it reads previous settings. 
        if ($post['file']){
            file_put_contents(self::upload_dir().$post['file'].".map",json_encode($post));
        }  
        //dd($post);     

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
                            $regionSplit=explode(" ",trim($commaSplit[1]));
                            $donor->City=trim($commaSplit[0]);
                            $donor->Region=trim($regionSplit[0]);
                            $donor->PostalCode=trim($regionSplit[1]);
                            if($regionSplit[2]) $donor->Country=trim($regionSplit[2]);
                            //print "pattern $field set to $v on Index $c. <br>";
                        break;
                        case "Category":
                            $donation->CategoryId==DonationCategory::get_category_id($v);
                            break;
                        case "Date":
                        case "DateDeposited":                            
                            if (trim($v)) $donation->$field=date("Y-m-d",strtotime($v));
                            //print "field $field set to $v on Index $c row $row. <br>";
                        break;
                        case "Gross":
                        case "Fee":
                        case "Net":
                            $donation->$field=trim(str_replace(",","",$v));
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
            if (!$donation->Fee) $donation->Fee=0;
            if (!$donation->Net) $donation->Net=$donation->Gross + $donation->Fee;
            //dd($donor,$post,$r);
            if ($donor->Name){
                $key=$donor->flat_key($_POST['donorKey']);
                $donors[$key]['donor']=$donor;
                $donors[$key]['donations'][$donation->flat_key()]=$donation;
            }else{
                $skippedLines[]=$r;
            }
        }
        if ($donors) ksort($donors);
        //dump($post,$donors);

        //dd($donors[key($donors)]);
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
                $a['donor']->save($post['timenow']);
                //confirm this is creating an Id...
                $stats['donorCreated']++;
            }          
            if ($a['donor']->DonorId){
                foreach($a['donations'] as $donation){
                    ### eventually add a duplicate donation check...
                    $donation->DonorId= $a['donor']->DonorId;
                    $donation->save($post['timenow']);
                    $stats['donationsAdded']++;
                }
            }else{
                self::error("Donor ID not created/not found for ".$donor->Name);
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
        $previous=[];
        if (file_exists(self::upload_dir().$csvFile.".map")){
            $previous=json_decode(file_get_contents(self::upload_dir().$csvFile.".map"));
            self::display_notice("Using Previously Uploaded Presets");           
        }
        

        if (!$timeNow) $timeNow=time();
        $csv=self::csv_file_to_array($csvFile,$firstLineColumns);
        $selectDonation=Donation::s()->fillable; 
        $selectDonor=Donor::s()->fillable; 

        $flatKeys=isset($previous->donorKey)?$previous->donorKey:Donor::s()->flat_key;
        $flatKeysD=isset($previous->donationKey)?$previous->donationKey:Donation::s()->flat_key;
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
                $column= "column".$c;             
                ?>
                <th><select name="<?php print $column?>">
                    <option value="">--ignore--</option>
                    <?php foreach(self::UPLOAD_PATTERN as $field => $mapped){ 
                        $select=false;
                        if (isset($previous->$column)){ //pull previous value
                            if ($previous->$column==$field)  $select=true;
                        }elseif (strtolower($headerField)==strtolower($field) ||$selected==$field){       
                            $select=true;
                        }                        
                        ?><option value="<?php print $field?>"<?php print $select?" selected":"";?>><?php print $field?></option><?php
                    }?>
                    <option disabled>--Donor Fields--</option>
                    <?php foreach($selectDonor as $field){ 
                        $select=false;
                        if (isset($previous->$column)){ //pull previous value
                            if ($previous->$column==$field)  $select=true;
                        }elseif (strtolower($headerField)==strtolower($field) ||$selected==$field){       
                            $select=true;
                        }                           
                        ?><option value="<?php print $field?>"<?php print $select?" selected":"";?>><?php print $field?></option><?php
                    }?>
                     <option disabled>--Donation Fields--</option>
                    <?php foreach($selectDonation as $field){
                         $select=false;
                        if (isset($previous->$column)){ //pull previous value
                            if ($previous->$column==$field)  $select=true;
                        }elseif (strtolower($headerField)==strtolower($field) ||$selected==$field){       
                            $select=true;
                        }   
                        ?><option value="<?php print $field?>"<?php print $select?" selected":"";?>><?php print $field?></option><?php
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
        $dbHeaders=Donation::s()->fillable; 
        $dbHeaders[]="ItemTitle";        
        $headerRow=DonationUpload::s()->paypal; 
        $tinyInt=Donation::s()->tinyIntDescriptions; 
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
                    
                    if ($entry['TypeOther'] && in_array($entry['TypeOther'],array("Payment Refund","Payment Reversal"))){
                        $entry['Name']=''; //Paypal
                        $from= $entry['ToEmailAddress'];//=> donations@masmariposas.org
                        $entry['ToEmailAddress']=$entry['FromEmailAddress'];
                        $entry['FromEmailAddress']=$from;
                        unset($from);
                    }                 

                    $obj=new Donation($entry);
                    $obj->donation_to_donor();  //Will Set DonorId on Donation Table.          
                    $q[]=$obj;
                }	
                $row++;
            }
            fclose($handle);
        }	
        return $q;
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
                if ($stats=Donation::replace_into_list($result)){//inserted'=>sizeof($iSQL),'skipped'
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
            } else {                 
                self::display_error("Sorry, there was an error uploading your file: ".$originalFile."<br>Destination: ".$target_file);
            }           
        }
    }


}