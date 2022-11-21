<?php
require_once 'classes/Paypal.php';
?>
<div id="pluginwrap">
    <h2>Paypal Test</h2><?php
    $paypal = new Paypal();
    $results=$paypal->get_transactions_date_range(date("Y-m-d",strtotime("-33 days")));
    print "<pre>"; print_r($results); print "</pre>";

?>
</div>