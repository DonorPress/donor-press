<?php

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Core\ServiceContext;
use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
use QuickBooksOnline\API\Facades\Customer;

require_once 'CustomVariables.php';
// use QuickBooksOnline\API\Core\ServiceContext;
// use QuickBooksOnline\API\PlatformService\PlatformService;
// use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
// use QuickBooksOnline\API\Facades\Customer;


class QuickBooks extends ModelLite
{
	protected $dataService;
    protected $oAuth2LoginHelper;
    protected $accessTokenObj;
    const SESSION_PREFIX='quickBooks_';
    const SESSION_VARS=['code','state','realmId','accessToken','refreshToken','accessTokenExpiresAt','refreshTokenExpiresAt'];
    const SETTING_URL='https://www.developer.intuit.com/app/developer/dashboard';

    public function getOAuth2LoginHelper(){        
        if (!$this->dataService){
            $clientId=CustomVariables::get_option('QuickbooksClientId',true);
            $clientSecret=CustomVariables::get_option('QuickbooksSecret',true);
            if (!$clientId || !$clientSecret){
                $this->missing_api_error();
                return false;
            }
            $this->dataService = DataService::Configure(array(
                'auth_mode' => 'oauth2',
                'ClientID' => $clientId,
                'ClientSecret' =>  $clientSecret,
                'RedirectURI' => "http://localhost:8000/wp-admin/admin.php?redirect=donor_quickBooks_redirectUrl", //"http://pc007.ad.tilmor.com:8888/api/quickbooks/oauth2/callback",//https://www.tilmor.com/api/quickbooks/oauth2/callback
                'scope' => "com.intuit.quickbooks.accounting",
                'baseUrl' => "Development", ///Production
                'accessTokenKey' => $this->session(self::SESSION_PREFIX."accessToken"),
                'refreshTokenKey' => $this->session(self::SESSION_PREFIX."refreshToken"),
                'QBORealmID' => $this->session(self::SESSION_PREFIX."realmId")
            ));            
        }
        if (!$this->oAuth2LoginHelper){ 
            $this->oAuth2LoginHelper=$this->dataService->getOAuth2LoginHelper();
        }
        //$this->dataService->disableLog();   
        //$dataService->setLogLocation("/Your/Path/ForLog");     
        return $this->oAuth2LoginHelper;  
    }
    
    public function authenticate(){  
        $this->getOAuth2LoginHelper();
        ### if past accesss token expiration, clear out session, and start over
        if ($this->session(self::SESSION_PREFIX."accessToken") && $this->session(self::SESSION_PREFIX."accessTokenExpiresAt")<time()){
            $this->clearSession();  
        }
        ### refresh the token if expired
        if ($this->session(self::SESSION_PREFIX."refreshToken") && $this->session(self::SESSION_PREFIX."refreshTokenExpiresAt")<time()){
            $this->accessTokenObj=$this->oAuth2LoginHelper->refreshToken();
            $error = $this->oAuth2LoginHelper->getLastError();
            if($error){
                print '<div class="alert alert-danger" role="alert"><strong>Error Refreshing Token</strong> '.$error->getResponseBody()."</div>";
            }else{
                $this->dataService->updateOAuth2Token($this->accessTokenObj);
                $this->session([
                    self::SESSION_PREFIX."accessToken"=>$this->accessTokenObj->getAccessToken(),
                    self::SESSION_PREFIX."refreshToken"=>$this->accessTokenObj->getRefreshToken(),
                    self::SESSION_PREFIX."accessTokenExpiresAt"=>strtotime($this->accessTokenObj->getRefreshTokenExpiresAt()),
                    self::SESSION_PREFIX."refreshTokenExpiresAt"=>strtotime($this->accessTokenObj->getAccessTokenExpiresAt())
                ]);
            }
        }
        ### assume we have a good access token.
        if ($this->session(self::SESSION_PREFIX."accessToken")){
            return true;  
        }

        ### No Access Token, but we have a code and realmId returned 
        if ($this->session(self::SESSION_PREFIX."code") && $this->session(self::SESSION_PREFIX."realmId")){
            try {
                $this->accessTokenObj = $this->oAuth2LoginHelper->exchangeAuthorizationCodeForToken($this->session(self::SESSION_PREFIX."code"),$this->session(self::SESSION_PREFIX."realmId"));               
                $this->session([
                    self::SESSION_PREFIX."accessToken"=>$this->accessTokenObj->getAccessToken(),
                    self::SESSION_PREFIX."refreshToken"=>$this->accessTokenObj->getRefreshToken(),
                    self::SESSION_PREFIX."accessTokenExpiresAt"=>strtotime($this->accessTokenObj->getRefreshTokenExpiresAt()),
                    self::SESSION_PREFIX."refreshTokenExpiresAt"=>strtotime($this->accessTokenObj->getAccessTokenExpiresAt())
                ]); 
                return true;
            } catch (Exception $e) {                
                $this->clearSession();
                $this->accessTokenObj = null;
                self::dump($e);
            }
        }else{
            print "<a href='?redirect=donor_quickBooks_authorizeUrl'>Quickbooks API Login</a>";    
            return false; 
        }
    }
    
    public function display_line($field,$value){
        $notset=array();
        if ($value){
            if(is_object($value)||is_array($value)){
                foreach($value as $sf =>$sv){ 
                    $this->display_line(($field?$field."_":"").$sf,$sv); // $this->display_line(($field?$field.(is_object($sv)?"->".$sf:"[".$sf."]"):""),$sv);                    
                }
            }else{
                print "<tr><td><strong>".$field."</strong></td><td>".$value."</td></tr>";
            }
            
        }else{
            $notset[]=$field;
        }
        return $notset;
    }

    public function edit_line($field,$value){        
        if(is_object($value)||is_array($value)){
            foreach($value as $sf =>$sv){ 
                $this->edit_line(($field?$field."_":"").$sf,$sv); 
            }
        }else{
            print "<tr><td><strong>".$field."</strong></td><td>";
            if ($value=="false" || $value=="true"){
                print "<label><input type='radio' name='".$field."' value='true'".($value=="true"?" checked":"")."/> true</label> <label><input type='radio' name='".$field."' value='false'".($value=="false"?" checked":"")."/> false</label>";
            }else{
                print "<input name='".$field."' value='".$value."'>";
            }
           
            print "</td></tr>";
        }
    }


    public function find_changed_fields($field,$value, $changedFields=[]){
        if(is_object($value)||is_array($value)){
            foreach($value as $sf =>$sv){ 
                $changedFields=$this->find_changed_fields(($field?$field."_":"").$sf,$sv,$changedFields); 
            }
        }else{
            if ($_POST[$field]!=$value){
                $changedFields[$field]=$_POST[$field];
            }            
        }
        return $changedFields;
    }

    public function request_handler(){  
        if ($_GET['syncDonorId']){
            $donor=Donor::get_by_id($_GET['syncDonorId']);
            if (!$donor){
                print self::display_error("Donor #".$_GET['syncDonorId']." not found.");
            }
            if ($donor->MergedId>0){
                print self::display_error("Donor #".$_GET['syncDonorId']." has been merged to #".$donor->show_field('MergedId',$donor->MergedId,true,['donationlink'=>false]).". Please don't sync a donor that has been merged.");
            }
            if ($this->authenticate()){
                $customer=false;
                if ($_GET['forceNew'=='true']){ //skip lookups and jump to creation.

                }elseif ($_GET['QuickBooksId']){
                    $customer=$this->dataService->FindById("Customer", $_GET['QuickBooksId']);
                }elseif ($donor->QuickBooksId){
                    $customer=$this->dataService->FindById("Customer", $donor->QuickBooksId);
                }else{
                    if ($donor->Email){
                        $SQL="SELECT * FROM Customer Where PrimaryEmailAddr = '".$donor->Email."'";
                        $entities =$this->dataService->Query($SQL);
                        
                    }
                    if (!$entities || sizeof($entities)==0){
                        $names=[];
                        if ($donor->Name) $names[]=$donor->Name;
                        if ($donor->Name2) $names[]=$donor->Name2;
                        if (sizeof($names)>0) {
                            $SQL="SELECT * FROM Customer Where DisplayName IN ('".implode("','",$names)."')";                   
                            $entities =$this->dataService->Query($SQL);     
                        }                   
                    }
                    if (isset($entities) && sizeof($entities)==1) $customer=$entities[0];
                    if (isset($entities) && sizeof($entities)>1){
                        print "<div class=\"notice notice-success\">Attempting to Sync Donor #".$donor->show_field('DonorId').", however multiple Quick Book Matches were found. Please select the closest match:<ul>";
                        foreach($entities as $customer){
                            print '<li><a href="?page='.$_GET['page'].'&syncDonorId='.$_GET['syncDonorId'].'&QuickBooksId='.$customer->Id.'">#'.$customer->Id.' '.$customer->DisplayName.'</a> '.$customer->PrimaryEmailAddr->Address.'</li>';
                        }
                        print '<li><a href="?page='.$_GET['page'].'&syncDonorId='.$_GET['syncDonorId'].'&forceNew=true">Force New Entry</a></li>';
                        print "</ul><div>";
                        return false;
                    }
                }
                if ($customer){
                    print self::display_notice('Match Found. #'.$customer->Id.' '.$customer->DisplayName.'</a> '.$customer->PrimaryEmailAddr->Address);
                    print '<div><a href="?page='.$_GET['page'].'&syncDonorId='.$_GET['syncDonorId'].'&forceNew=true">Force New Entry</a> - careful when doing this. You want to avoid creating duplicate entries.</div>';
                    print "Add Sync Logic Here";
                }else{                    
                    //create new entry here.
                    $customerToCreate=$this->donor_to_customer($donor);
                    $resultObj = $this->dataService->Add($customerToCreate);
                    $error =$this->dataService->getLastError();
                    if($error) self::display_error($error->getResponseBody());
                    else{
                        if ($resultObj->Id){
                            $donor->QuickBooksId=$resultObj->Id;
                            $donor->save();
                            self::display_notice("Quick Books Id #".$donor->show_field('QuickBooksId')." created and linked to Donor #".$donor->show_field('DonorId'));
                        }
                    }
                }
            }
            return true;
        }
        
        if ($_POST['Function']=='SaveQuickBooks' && $_POST['quickbooks_table'] && $_POST['quickbooks_id']){
            ### Get Current entry
            //dump($_POST);           

            if ($this->authenticate()){
                //$entity=$this->dataService->FindById($_GET['table'], $_GET['Id']);
                $entity=$this->dataService->FindById($_POST['quickbooks_table'], $_POST['quickbooks_id']);
                $this->dataService->throwExceptionOnError(true);
                $error =$this->dataService->getLastError();
                if($error) self::display_error($error->getResponseBody());
                else{
                    $changedFields=$this->find_changed_fields('',$entity);
                    dump($changedFields);
                }
            }
        }
    }

    public function donor_to_customer($donor){
        ### takes a Donor Object and puts it into a QuickBooks Customer Object
        $name=explode(" ",$donor->Name);        
        $array=[           
            'FullyQualifiedName'=>$donor->Name,
            'CompanyName'=>$donor->Name,
            'DisplayName'=>$donor->Name,
            'OtherContactInfo'=>$donor->Name2,            
            'PrimaryEmailAddr'=>['Address'=>$donor->Email,'Default'=>$donor->EmailStatus==1?1:0],
            'PrimaryPhone'=>['FreeFormNumber'=>$donor->phone()],
            'BillAddr'=>['Line1'=>$donor->Address1,'Line2'=>$donor->Address2,'City'=>$donor->City,'CountrySubDivisionCode'=>$donor->Region,'PostalCode'=>$donor->PostalCode,'PostalCode'=>$donor->PostalCode,'Country'=>$donor->Country,'CountryCode'=>$donor->Country],
            'Notes'=>'donor|'.$donor->DonorId."|".$donor->Source."|".$donor->SourceId,
            'BusinessNumber'=> $donor->DonorId      
        ];
        if (sizeof($name)==2){
            $array['GivenName']=$name[0];    $array['FamilyName']=$name[1]; 
        }

        if (sizeof($name)==3){
            $array['GivenName']=$name[0];    $array['MiddleName']=$name[2]; $array['FamilyName']=$name[2]; 
        }
        return Customer::create($array);
    }

    public function customer_to_donor($customer){
        $array=[
            "Source"=>'QuickBooks',
            "SourceId"=>$customer->Id,
            "Name"=>$customer->DisplayName,
            "Name2"=>$customer->Organization?$customer->Organization:$customer->OtherContactInfo,
            "Email"=>$customer->PrimaryEmailAddr->Address,
            "Phone"=>$customer->PrimaryPhone->FreeFormNumber,
            "Address1"=>$customer->BillAddr->Line1,
            "Address2"=>$customer->BillAddr->Line2,
            "City"=>$customer->BillAddr->City,
            "Region"=>$customer->BillAddr->CountrySubDivisionCode,
            "PostalCode"=>$customer->BillAddr->PostalCode,
            "Country"=>$customer->BillAddr->CountryCode,
            "QuickBooksId"=>$customer->Id         
        ];
        return new Donor($array);
    }

    public function show(){       
        if ($this->authenticate()){
            self::display_notice("<strong>You are authenticated!</strong><div>Token expires: ".date("Y-m-d H:i:s",$this->session(self::SESSION_PREFIX."accessTokenExpiresAt")).". Refresh Expires at ".date("Y-m-d H:i:s",$this->session(self::SESSION_PREFIX."refreshTokenExpiresAt"))." in ".($this->session(self::SESSION_PREFIX."refreshTokenExpiresAt")-time())." seconds</div>");
            $tables=['Customer'=>'DisplayName','Invoice'=>'Balance','Vendor'=>'DisplayName','Employee'=>'DisplayName','Item'=>'Name','Account'=>'Name','Bill'=>'VendorRef','BillPayment'=>'VendorRef','CompanyInfo'=>'CompanyName','CreditMemo'=>'TotalAmt'
            ,'Deposit'=>'CashBack.Memo','JournalEntry'=>'PrivateNote','SalesReceipt'=>'DocNumber']; //,'Department',,'Budget'
            if ($_GET['table']){      
                if ($_GET['Id']){
                    $entity=$this->dataService->FindById($_GET['table'], $_GET['Id']);
                    $this->dataService->throwExceptionOnError(true);
                    $error =$this->dataService->getLastError();
                    if($error){
                        self::display_error($error->getResponseBody());
                    }else{
                        print "<div><a href='?page=donor-quickbooks&table=".$_GET['table']."'>Back to ".$_GET['table']." list</a></div>
                        <h3>".$_GET['table']." #".$_GET['Id']."</h3>";
                        if ($_GET['edit']){
                            print "                        
                            <form method='post' action='?page=donor-quickbooks&table=".$_GET['table']."&Id=".$_GET['Id']."'>
                            <input type='hidden' name='quickbooks_table' value='".$_GET['table']."'/>
                            <input type='hidden' name='quickbooks_id' value='".$_GET['Id']."'/>
                            <table class=\"dp\">";
                            $this->edit_line('',$entity); 
                            print "</table>
                            <button name='Function' value='SaveQuickBooks'>Save</button><button>Cancel</button>
                            </form>";

                        }else{
                            print "
                            <div><a href='?page=donor-quickbooks&table=".$_GET['table']."&Id=".$_GET['Id']."&edit=t'>edit</a></div>
                            <table class=\"dp\">";
                            $notset=$this->display_line('',$entity);                        
                            print "</table>";
                        }                        
                       
                        if ($notset && sizeof($notset)>0){
                            print "<div><strong>Fields Not Set: </strong>".implode(", ",$notset)."</div>";
                        }                        
                    }
                }else{
                    $entities =$this->dataService->Query("SELECT * FROM ".$_GET['table']);
                    $error =$this->dataService->getLastError();
                    $this->dataService->throwExceptionOnError(true);
                    if($error){
                       self::display_error($error->getResponseBody());
                    }else{
                        print "<div><a href='?page=donor-quickbooks'>Back to Quickbook list</a></div>
                        <h3>".$_GET['table']." List</h3>";
                        ?><table class="dp">
                        <?php
                        foreach ($entities as $entity){
                            $field=$tables[$_GET['table']];
                            ?><tr><td><a href="?page=donor-quickbooks&table=<?php print $_GET['table']?>&Id=<?php print $entity->Id?>"><?php print $entity->Id?></a></td><td><?php print $entity->$field?></td></tr><?php
                        }?>
                        </table>
                        <?php    
                    }                           
                }
            }else{ ?>
                <h3>View</h3>
                 <?php foreach ($tables as $tbl=>$key){ ?>
                    <div><a href="?page=donor-quickbooks&table=<?php print $tbl?>"><?php print $tbl?></a></div>
                 <?php 
                 }  
                         
            }
            return;


            $cust=$this->dataService->FindById("Customer", 58);
            $error = $this->dataService->getLastError();
            dd($cust,$error);

            $cust=$this->dataService->FindById("Customer", 58);
            $customerToUpdate= Customer::update($cust,[              
                // "Source" =>"donor-press",
                "ContactName"=>"Tara Steiner",
                "ClientEntityId"=>"999"             
              ]);

            $resultObj = $this->dataService->Update( $customerToUpdate);



            // $customerToCreate = Customer::create([
            //     "FullyQualifiedName" => "Denver Steiner",
            //     "CompanyName" => "Denver Steiner",
            //     "DisplayName" => "Denver Steiner",
            //     "PrintOnCheckName" => "Denver Steiner" ,
            //     "Source" =>"donor-press"             
            //   ]);

            //   $resultObj = $this->dataService->Add( $customerToCreate);
              $error = $this->dataService->getLastError();
              if ($error) {
                  echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
                  echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
                  echo "The Response message is: " . $error->getResponseBody() . "\n";
              }else {
                  dd($resultObj);
                  // echo "Created Id={$resultObj->Id}. Reconstructed response body:\n\n";
                  // $xmlBody = XmlObjectSerializer::getPostXmlFromArbitraryEntity($resultingObj, $urlResource);
                  // echo $xmlBody . "\n";
              }
        



            $cust=$this->dataService->FindById("Customer", 1);
            $statement = "SELECT * FROM INVOICE";
            $numberOfCustomers = $this->dataService->Query($statement);
            $error =$this->dataService->getLastError();
            dd(  $this->dataService->getCompanyInfo(),$numberOfCustomers[1], $cust,$cust->BillAddr,$error);


            $entities =$this->dataService->Query("SELECT * FROM Customer");
            $error =$this->dataService->getLastError();
            $this->dataService->throwExceptionOnError(true);
            if($error){
              self::display_error($error->getResponseBody());
            }else{
                dump($entities);
            }
            return;



            $invoiceToCreate = Invoice::create([
                "DocNumber" => "101",
                "Line" => [
                  [
                    "Description" => "Sewing Service for Alex",
                    "Amount" => 150.00,
                    "DetailType" => "SalesItemLineDetail",
                    "SalesItemLineDetail" => [
                      "ItemRef" => [
                        "value" => 1,
                        "name" => "Services"
                      ]
                    ]
                  ]
                ],
                "CustomerRef" => [
                    "value" => "1",
                    "name" => "Alex"
                ]
              ]);
            $this->dataService->disableLog();           
            $resultObj = $this->dataService->Add($invoiceToCreate);
            $error = $this->dataService->getLastError();
            if ($error) {
                echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
                echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
                echo "The Response message is: " . $error->getResponseBody() . "\n";
            }else {
                dd($resultObj);
                // echo "Created Id={$resultObj->Id}. Reconstructed response body:\n\n";
                // $xmlBody = XmlObjectSerializer::getPostXmlFromArbitraryEntity($resultingObj, $urlResource);
                // echo $xmlBody . "\n";
            }

            
            

            
           return;
           // dd($this->dataService);
            $customerObj = new Customer();
            $customerObj->Name = "Name" . rand();
            $customerObj->CompanyName = "CompanyName" . rand();
            $customerObj->GivenName = "GivenName" . rand();
            $customerObj->DisplayName = "DisplayName" . rand();
            $resultingCustomerObj = $this->dataService->Add($customerObj);
            dd($customerObj,$resultingCustomerObj);
            return;
           
        }
	}

    public function oauthcallback(){
        ///api/quickbooks/oauth2/callback?code=AB11670607450L4ne1zzbuPBxTHOz00f8oPv9ZKA0B2qVIBw34&state=QANVI&realmId=4620816365259820260
        foreach($_REQUEST as $key=>$val){
            $this->session([self::SESSION_PREFIX.$key=>$val]);               
        }          
        return header("Location: ?redirect=donor_quickBooks_authorizeRedirect");      
    }

    public function authorizeUrl(){ //external redirect to QuickBooks Authroization URL      
        return header("Location: ".$this->getOAuth2LoginHelper()->getAuthorizationCodeURL()); 
    }

    public function authorizeRedirect(){ //Captures variables returned, stores them as session variables, and then redirects to main page
        $this->requestToSession();  
        return header("Location: ?page=donor-quickbooks");
    }

    public function requestToSession(){ 
        foreach(self::SESSION_VARS as $key){
            if ($_GET[$key]){
                $this->session([self::SESSION_PREFIX.$key=>$_GET[$key]]);  
            }   
        }       
    }

    public function clearSession(){
        foreach(self::SESSION_VARS as $key){
            unset($_SESSION[self::SESSION_PREFIX.$key]);
        }       
    }

    public function session($var){
        if (is_array($var)){
            foreach ($var as $f=>$v){
                $_SESSION[$f]=$v;
            }
            return true;            
        }
    
        return $_SESSION[$var];
    }

    public function check_redirects($redirect){ 
        //reads a url like: ?redirect=donor_quickBooks_xxx       
       $prefix="donor_".self::SESSION_PREFIX;       
       if (strpos($redirect, $prefix)>-1){ 
            switch(substr($redirect,strlen($prefix))){
                case "authorizeUrl": $this->authorizeUrl(); break; //step 1 redirect to external url for oath
                case "redirectUrl" : $this->oauthcallback(); break;        //step 3 after returning from external url -> read returned variables.
                case "authorizeRedirect" : $this->authorizeRedirect(); break; 
            }
       }      
    }

    public function missing_api_error(){
        self::display_error("Quickbook API Client/Password not setup. Create a <a target='quickbooktoken' href='".QuickBooks::SETTING_URL."'>Client/Password on QuickBooks Developer</a> first, and then <a href='?page=donor-settings'>paste them in the settings</a>.");
    }
}