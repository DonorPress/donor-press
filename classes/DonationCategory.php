<?php
### Manages the Category of a Donation
/* Expand to manage all Drop down field options....
DonorCategory
CategoryId
Table
Field
Id
Description
ParentId
CreatedAt
UpdatedAt




Fields Donation
Category
Status
AddressStatus
PaymentSource
Type
Currency


*/
require_once("Donation.php");
class DonationCategory extends ModelLite
{
    protected $table = 'DonationCategory';
	protected $primaryKey = 'CategoryId';
	### Fields that can be passed 
    protected $fillable = ["Category","Description","ParentId"];	 
  	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';    

    static public function requestHandler(){
        if ($_GET['CategoryId']){	
            if ($_POST['Function']=="Save" && $_POST['table']=="DonationCategory"){
                $donationCategory=new self($_POST);
                if ($donationCategory->save()){
                    self::DisplayNotice("Donation Category#".$donationCategory->showField("CategoryId")." saved.");
                }
            }
            $donationCategory=self::getById($_REQUEST['CategoryId']);	
            ?>
            <div id="pluginwrap">
                <div><a href="?page=<?php print $_GET['page']?>">Return</a></div>
                <h1>Category #<?php print $donationCategory->CategoryId?></h1><?	
                if ($_REQUEST['edit']){
                    $donationCategory->editForm();
                }else{
                    ?><div><a href="?page=<?php print $_GET['page']?>&CategoryId=<?php print $donationCategory->CategoryId?>&edit=t">Edit Category</a></div><?
                    $donationCategory->view();
                }
            ?></div><?
            return true;
        }else{
            return false;
        }
    }

    static public function consolidateCategories(){
        global $wpdb;
        $results = $wpdb->get_results("SELECT `CategoryId`, `Category`,ParentId FROM `wp_donationcategory`");
        foreach ($results as $r){
            if ($cache[$r->Category]){
                $uSQL="UPDATE `wp_donation` SET `CategoryId`='".$cache[$r->Category]."' WHERE `CategoryId`='".$r->CategoryId."'";
                print $uSQL.";<br>";
                $dSQL="DELETE FROM `wp_donationcategory` WHERE `CategoryId`='".$r->CategoryId."'";
                print $dSQL.";<br>";
            }else{
                $cache[$r->Category]=$r->CategoryId;
            }   
        }
    }

    public function view(){ //single entry->View fields
		//print "varview";
		$this->varView();
		//do stats on entries with this category.. maybe even reports based on dates.
	}

    static public function getCategoryId($text){
        $text=trim($text);
        global $cache_DonationCategory_getCategoryId;
        if ($cache_DonationCategory_getCategoryId[$text]){
            return $cache_DonationCategory_getCategoryId[$text];
        }
        $result=self::get(array("Category LIKE '".addslashes($text)."'"));
        if ($result && sizeof($result)>0){
            if ($result[0]->ParentId>0) {
                $cache_DonationCategory_getCategoryId[$text]=$result[0]->ParentId;                
            }else{  
                $cache_DonationCategory_getCategoryId[$text]=$result[0]->CategoryId;
            }
            return $cache_DonationCategory_getCategoryId[$text];
        }
        ### Otherwise, create the entry.
        $cat=new self(array('Category'=>$text));
        $cat->save();
        $cache_DonationCategory_getCategoryId[$text]=$cat->CategoryId;
        return  $cache_DonationCategory_getCategoryId[$text];
    }

    static public function createTable(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php'); 
    	global $wpdb;
        //$charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS  `".self::getTableName()."` (
                `CategoryId` int(11) NOT NULL AUTO_INCREMENT,
                `Category` varchar(50) NOT NULL,
                `Description` varchar(250) DEFAULT NULL,
                `ParentId` int(11) DEFAULT NULL,
                `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`CategoryId`),
                KEY `Category` (`Category`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8;";          
        dbDelta( $sql );
    }
}