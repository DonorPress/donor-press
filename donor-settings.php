<style>
@media print
{    
    #adminmenumain,#wpadminbar,.no-print, .no-print *
    {
        display: none !important;
    }
	body { background-color:white;}
	#wpcontent, #wpfooter{ margin-left:0px;}
	
}
</style>
<div id="pluginwrap">
	<?php
    global $wpdb;	
	if (DonationCategory::RequestHandler()) { print "</div>"; return;}	
	?>	
	<h1>Settings</h1>
    <?php    
    $SQL= "SELECT *,(Select COUNT(*) FROM ".Donation::getTableName()." Where CategoryId=C.CategoryId) as DonationTotal FROM ".DonationCategory::getTableName()." C";
    $results = $wpdb->get_results($SQL);
    //DonationCategory::dump($results);
    ?>
    <table border=1><tr><th>Id</th><th>Category</th><th>Description</th><th>ParentId</th><th>Total</th></tr><?php
     foreach ($results as $r){       
        ?><tr>
            <td><?php print $r->CategoryId?></td>
            <td><?php print $r->Category?></td>
            <td><?php print $r->Description?></td>
            <td><?php print $r->ParentId?></td>
            <td><?php print $r->DonationTotal?></td>
        </tr>
        <?php
     }
    ?></table>
	
</div>