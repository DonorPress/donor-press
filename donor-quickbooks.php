<?php
require_once 'classes/QuickBooks.php';
if (Donor::request_handler())  { print "</div>"; return;}

$clientId=CustomVariables::get_option('QuickbooksClientId');
$clientSecret=CustomVariables::get_option('QuickbooksSecret');
$qb=new Quickbooks();
?>
<div id="pluginwrap">
    <h2>Quickbooks Sync</h2><?php  
    if (!$clientId || !$clientSecret){
        $qb->missing_api_error();
    }else{ 
        $qb->request_handler();   
        //dump($clientId,$clientSecret)  
        $qb->show();
    }
?>
</div>