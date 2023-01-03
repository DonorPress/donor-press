<?php
require_once 'classes/QuickBooks.php';
$clientId=CustomVariables::get_option('QuickbooksClientId');
$clientSecret=CustomVariables::get_option('QuickbooksSecret');
$qb=new Quickbooks();
?>
<div id="pluginwrap">
    <h2>Quickbooks Sync</h2><?php  
    if (!$clientId || !$clientSecret){
        $qb->missing_api_error();
    }else{ 
        if ($qb->request_handler()){ print "</div>"; return;}     
        $qb->show();
    }
?>
</div>