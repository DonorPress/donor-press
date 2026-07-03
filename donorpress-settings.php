<?php
use DonorPress\Donor;
use DonorPress\DonorType;
use DonorPress\DonationCategory;
use DonorPress\DonorTemplate;
use DonorPress\CustomVariables; 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   
?>
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
<?php
$tabs=['cv'=>'Site Variables','email'=>'Email Templates','cat'=>'Donation Categories','type'=>'Donor Types','bak'=>'Backup/Restore'];
$active_tab=Donor::show_tabs($tabs);

?>
<div id="pluginwrap">
	<?php
    if (DonationCategory::request_handler()) { print "</div>"; return;}	
    if (DonorType::request_handler()) { print "</div>"; return;}	
    if (DonorTemplate::request_handler()) { print "</div>"; return;}  
    if (CustomVariables::request_handler()) { print "</div>"; return;}    
    ?>
    <h1>Settings: <?php print esc_html($tabs[$active_tab])?></h1><?php
    switch($active_tab){  
        case "type":  DonorType::list(); break;     
        case "cat":  DonationCategory::list(); break;
        case "email": DonorTemplate::list(); break;
        case "bak":
            ?><form method="post" enctype="multipart/form-data">
                <h2>Backup</h2>
                <div>
                    <button name="Function" value="BackupDonorPress">Backup Donor Press Tables/Settings</button>
                </div>                                
                <hr>
                <h2>Restore</h2>                
                <div>
                <input type="file" name="fileToUpload" accept=".json">
                <button name="Function" value="RestoreDonorPress">Restore from File</button>
                Server Upload Limit: <?php print esc_html(ini_get("upload_max_filesize"));?>  <em>Caution - will remove current Donor Press Data</em>
                </div>
                <hr>
                <h2>Nuke Site</h2>
                <button name="Function" value="NukeDonorPress">Clear Out Donor Press Files</button> - Useful for uninstalls or during testing.
                <h2>Load Test Data</h2>
                <button name="Function" value="LoadTestData">Load Test Records</button>  Records: <input type="number" name="records" value="20"/> - Useful for testing the plugin. This will add new donor and donations records (but not remove existing data).
            </form><?php
            break;
        case "emailclean":
            if($_POST['Function']=="CleanEmailList"){
                $currentList=explode("\n",str_replace("\r","",$_POST['currentList']));
                $newEmailList=explode("\n",str_replace("\r","",$_POST['newEmailList']));
                $includeDonor=$_POST['includeDonor'];
                $currentEmails=[];
                foreach($currentList as $line){
                    $parts=explode("\t",$line);
                    $email=trim($parts[0]);                   
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue; // Skip invalid email addresses
                    }
                    $name=trim(preg_replace('/[^a-zA-Z0-9 ]/', '', $parts[1])); //remove bad characters from name but allow them in email
                    if (!$name) $name=strtolower($email); //replace any non ascii characters 
                    if(count($parts)>=1){
                        $currentEmails[strtolower($email)]=$name;
                    }
                }

                $addedEmailList=[];
                $count['donor_added']=0;
                $count['list_added']=0;
                if($includeDonor){
                    foreach(Donor::get() as $donor){
                        if(!isset($currentEmails[strtolower($donor->Email)]) && filter_var($donor->Email, FILTER_VALIDATE_EMAIL)){
                           $addedEmailList[strtolower($donor->Email)]=$donor->Name;
                           $count['donor_added']++;
                        }
                    }
                }

               
                foreach($newEmailList as $line){
                    $parts=explode("\t",$line);
                    if(count($parts)>1){
                        $email=trim($parts[0]);
                        $name=trim(preg_replace('/[^a-zA-Z0-9 ]/', '', $parts[1])); //remove bad characters from name but allow them in email
                        if (!$name) $name=strtolower($email); //replace any non ascii characters 
                        if (!isset($currentEmails[strtolower($email)]) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $addedEmailList[strtolower($email)]=$name;
                            $count['list_added']++;
                        }
                    }
                }
                print "<h2>Cleaned List</h2>";
                ?><strong>Original List Size:</strong> <?php print count($currentEmails)?> <br>
                
                <strong>Added from Donor List:</strong> <?php print $count['donor_added']?> <br>
                <strong>Added from New List:</strong> <?php print $count['list_added']?> <br>
                <strong>New List Size:</strong> <?php print count($addedEmailList)?> <br>
                <?php
                print "<textarea rows='10' cols='50'>";
               foreach($addedEmailList as $email=>$name){
                   print $email."\t".($name!=$email?$name:"")."\n";
               }
                print "</textarea>";

            }
            ?><form method="post">
                <h2>Clean Email List From External Lists</h2>
                <div>
                <strong>Current List</strong>  Email[tab]Name format
                <textarea name="currentList" rows="10" cols="50" placeholder="Enter one email address per line"><?php print $_POST['currentList'] ?></textarea><br>
                <strong>New List</strong> Email[tab]Name format
                <textarea name="newEmailList" rows="10" cols="50" placeholder="Enter one email address per line"><?php print $_POST['newEmailList'] ?></textarea><br>
                <label><input type="checkbox" name="includeDonor" value="1" <?php if($_POST['includeDonor']=="1") print "checked"; ?>/>Include Donor List</label><br>
                <button name="Function" value="CleanEmailList">Return New List</button>

                </div>
            </form><?php
            break;
        case "cv":
        default:
            CustomVariables::form(); 
        break;
    }
    ?>		
</div>