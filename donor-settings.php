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
$active_tab=Donor::show_tabs($tabs,$active_tab);

?>
<div id="pluginwrap">
	<?php
    if (DonationCategory::request_handler()) { print "</div>"; return;}	
    if (DonorType::request_handler()) { print "</div>"; return;}	
    if (DonorTemplate::request_handler()) { print "</div>"; return;}  
    if (CustomVariables::request_handler()) { print "</div>"; return;}    
    ?>
    <h1>Settings: <?php print $tabs[$active_tab]?></h1><?php
    switch($active_tab){  
        case "type":  DonorType::list(); break;     
        case "cat":  DonationCategory::list(); break;
        case "email": DonorTemplate::list(); break;
        case "bak":
            ?><form method="post">
                <button name="Function" value="BackupDonorPress">Backup Donor Press Tables/Settings</button>
                
                <div>
                <input type="file" name="fileToUpload" id="fileToUpload" accept=".json">     
                <button name="Function" value="RestoreDonorPress">Restore from File</button> <em>Caution - will remove current Donor Press Data</em>

                <hr>
                <h2>Nuke Site</h2>
                <button name="Function" value="NukeDonorPress">Clear Out Donor Press Files</button> - Useful for uninstalls or during testing.
            </form><?php
            $files = glob(Donor::upload_dir()."DonorPressBackup*.json");
            if (sizeof($files)>0){
                ?><h2>Backups</h2><?php
                foreach($files as $file){
                    print '<div><a href="'.plugins_url('donor-press/uploads/'.basename($file)).'">'.basename($file).'</a> '.filesize_formatted($file).' - '.date ("F d Y H:i", filemtime($file)).'</div>';                
                }
            }
            //dd($files);
            break;
        case "cv":  
        default:
            CustomVariables::form(); 
        break;
    }
    ?>		
</div>
<?php
function filesize_formatted($path){
    $size = filesize($path);
    $units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
}