<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   
use DonorPress\QuickBooks;
$qb=new QuickBooks();
?>
<div id="pluginwrap">
    <h2>Quickbooks Sync</h2><?php  
    if (Quickbooks::is_setup()){
        if ($qb->request_handler()){ }     
        else $qb->show();
    }else{ 
        $qb->missing_api_error();
    }
?>
</div>