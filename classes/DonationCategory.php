<?php
### Manages the Category of a Donation

require_once 'Donation.php';
class DonationCategory extends ModelLite
{
    protected $table = 'donation_category';
	protected $primaryKey = 'CategoryId';
	### Fields that can be passed 
    protected $fillable = ["Category","Description","ParentId","TemplateId"];	 
  	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';  
    //NOte: TemplateId links to table posts -> ID, but uses post_type='donortemplate' 

    static public function request_handler(){
        $wpdb=self::db();  
        if ($_POST['CategoryId'] && $_POST['Function']=="Delete" && $_POST['table']=="donation_category"){
            $donationCategory=new self($_POST);
            if ($donationCategory->delete()){
                self::display_notice("Donation Category '".$donationCategory->Category."' deleted."); 
            }
        }elseif ($_POST['CategoryId'] && $_POST['Function']=="DonationCategoryMergeTo" && $_POST['table']=="donation_category"){
            $donationCategory=new self($_POST);
            $mergeTo=self::get($_POST['MergeTo']);
            //self::dump($mergeTo);
            if (!$mergeTo->CategoryId){
                self::display_error("Could not find Merge to Donation Category: ".$_POST['MergeTo']);
                return;
            }
            $old=new self($_POST);
            $old->donation_count();
            
            $wpdb->update(Donation::get_table_name(),array("CategoryId"=>$_POST['MergeTo']),array('CategoryId'=> $old->CategoryId));
            self::display_notice($old->donation_count." donation record counts changed from Category '". $old->Category."' to ".$mergeTo->show_field("CategoryId")." - ".$mergeTo->Category); 

            if ($donationCategory->delete()){                
                self::display_notice("Donation Category '".$donationCategory->Category."' deleted."); 
            }        
        }elseif ($_GET['CategoryId']){	
            if ($_POST['Function']=="Save" && $_POST['table']=="donation_category"){
                $donationCategory=new self($_POST);
                if ($donationCategory->save()){
                    self::display_notice("Donation Category#".$donationCategory->show_field("CategoryId")." - ".$donationCategory->Cateogry." saved.");
                }
            }
            $donationCategory=self::get_by_id($_REQUEST['CategoryId']);	
            ?>
            <div id="pluginwrap">
                <div><a href="?page=<?php print $_GET['page']?>">Return</a></div>
                <h1>Category #<?php print $donationCategory->CategoryId?></h1><?php 
                if ($_REQUEST['edit']){
                    $donationCategory->edit_form();
                }else{
                    ?><div><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&CategoryId=<?php print $donationCategory->CategoryId?>&edit=t">Edit Category</a></div><?php
                    $donationCategory->view(); 
                }
            ?></div><?php
            return true;
        }else{
            return false;
        }
    }

    function edit_form(){
        $wpdb=self::db();         
        $primaryKey=$this->primaryKey;
		?><form method="post" action="?page=<?php print $_GET['page'].($_GET['tab']?'&tab='.$_GET['tab']:"")."&".$primaryKey."=".$this->$primaryKey?>">
		<input type="hidden" name="table" value="<?php print $this->table?>"/>
		<input type="hidden" name="<?php print $primaryKey?>" value="<?php print $this->$primaryKey?>"/>
        <table>
            <tr><td align="right">Category Title</td><td><input style="width: 300px" type="text" name="Category" value="<?php print $this->Category?>"></td></tr>
            <tr><td align="right">Description</td><td><textarea rows=3 cols=40 name="Description"><?php print $this->Description?></textarea></td></tr>
            <tr><td align="right">Parent Category</td><td><select name="ParentId"><option value="0">[--None--]</option><?php
             $results = $wpdb->get_results("SELECT `CategoryId`, `Category`,ParentId FROM ".self::get_table_name()." WHERE (ParentId=0 OR ParentId IS NULL) AND CategoryId<>'".$this->CategoryId."' Order BY Category");
             foreach($results as $r){
                ?><option value="<?php print $r->CategoryId?>"<?php print ($r->CategoryId==$this->CategoryId?" selected":"")?>><?php
                print $r->Category." (".$r->CategoryId.")";?></option><?php
             }?></select></td></tr>
            <tr><td>Response Template</td><td><select name="TemplateId"><option value="">default</option><?php
            $list=DonorTemplate::get(array("post_type='donortemplate'","post_parent=0"),"post_name,post_title");
            foreach($list as $t){
                print '<option value="'.$t->ID.'"'.($t->ID==$this->TemplateId?" selected":"").'>'.$t->post_name.'</option>';
            }
            ?></select></td></tr>
            <tr><td colspan="2">
                <button type="submit" class="primary" name="Function" value="Save">Save</button>
                <button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate="">Cancel</button>
                <?php if ($this->$primaryKey && $this->donation_count()==0){ ?>
                <button type="submit" name="Function" value="Delete">Delete</button>
                <?php }?>
                </td></tr>
		    </table>
            <?php 
            if ($this->donation_count()>0){                
                ?><div>Donations using this Category: <?php $this->donation_count()?></div>
                Merge To: <select name="MergeTo"><option value="0">[--None--]</option><?php
             $results = $wpdb->get_results("SELECT `CategoryId`, `Category`,ParentId FROM ".self::get_table_name()." WHERE (ParentId=0 OR ParentId IS NULL) AND CategoryId<>'".$this->CategoryId."' Order BY Category");
             foreach($results as $r){
                ?><option value="<?php print $r->CategoryId?>"<?php print ($r->CategoryId==$this->CategoryId?" selected":"")?>><?php
                print $r->Category." (".$r->CategoryId.")";?></option><?php
             }?></select> <button type="submit" name="Function" value="DonationCategoryMergeTo">Merge</button>
            <?php }?>           
		</form><?php

    }
    public function donation_count(){
        $wpdb=self::db();   
        if (!$this->donation_count){ 
            $results = $wpdb->get_results("Select COUNT(*) as C FROM ".Donation::get_table_name()." Where CategoryId=".$this->CategoryId);
            $this->donation_count=$results[0]->C?$results[0]->C:0;
        }
        return $this->donation_count;
    }
    

    static public function consolidate_categories(){
        $wpdb=self::db();  
        $cache=[];
        $results = $wpdb->get_results("SELECT `CategoryId`, `Category`,ParentId FROM ".self::get_table_name());
        foreach ($results as $r){
            if ($cache[$r->Category]){
                $uSQL="UPDATE".Donation::get_table_name()." SET `CategoryId`='".$cache[$r->Category]."' WHERE `CategoryId`='".$r->CategoryId."'";
                print $uSQL.";<br>";
                $dSQL="DELETE FROM ".DonationCategory::get_table_name()." WHERE `CategoryId`='".$r->CategoryId."'";
                print $dSQL.";<br>";
            }else{
                $cache[$r->Category]=$r->CategoryId;
            }   
        }
    }

    public function view(){ //single entry->View fields
		//print "varview";
		$this->var_view();
		//do stats on entries with this category.. maybe even reports based on dates.
	}

    static public function list(){
        $wpdb=self::db();          
        $SQL= "SELECT *,(Select COUNT(*) FROM ".Donation::get_table_name()." Where CategoryId=C.CategoryId) as donation_count FROM ".self::get_table_name()." C Order BY Category";       
        $results = $wpdb->get_results($SQL);
        foreach ($results as $r){ 
            $parent[$r->ParentId?$r->ParentId:0][]=$r;
        }
        ?>
        <h2>Donation Categories</h2>
        <table border="1"><tr><th>Id</th><th>Category</th><th>Description</th><th>ParentId</th><th>Total</th></tr><?php
         self::show_children(0,$parent,0);
         foreach ($results as $r){       
           
         }
        ?></table>	
        <?php
    }

    static function show_children($parentId,$parent,$level=0){
        if (!$parent[$parentId]) return;
        foreach ($parent[$parentId] as $r){
            ?><tr>
                <td style="padding-left:<?php print $level*20?>px"><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&CategoryId=<?php print $r->CategoryId?>&edit=t"><?php print $r->CategoryId?></a></td>
                <td><?php print $r->Category?></td>
                <td><?php print $r->Description?></td>
                <td><?php print $r->ParentId?></td>
                <td><?php print $r->donation_count?></td>
            </tr>
            <?php
            self::show_children($r->CategoryId,$parent,$level+1);
        }
    }


    static public function get_category_id($text){
        $text=trim($text);
        global $cache_DonationCategory_get_category_id;
        if ($cache_DonationCategory_get_category_id[$text]){
            return $cache_DonationCategory_get_category_id[$text];
        }
        $result=self::get(array("Category LIKE '".addslashes($text)."'"));
        if ($result && sizeof($result)>0){
            if ($result[0]->ParentId>0) {
                $cache_DonationCategory_get_category_id[$text]=$result[0]->ParentId;                
            }else{  
                $cache_DonationCategory_get_category_id[$text]=$result[0]->CategoryId;
            }
            return $cache_DonationCategory_get_category_id[$text];
        }
        ### Otherwise, create the entry.
        $cat=new self(array('Category'=>$text));
        $cat->save();
        $cache_DonationCategory_get_category_id[$text]=$cat->CategoryId;
        return  $cache_DonationCategory_get_category_id[$text];
    }

    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php'); 
    	$wpdb=self::db();  
        //$charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS  `".self::get_table_name()."` (
            `CategoryId` int(11) NOT NULL AUTO_INCREMENT,
            `Category` varchar(50) NOT NULL,
            `Description` varchar(250) DEFAULT NULL,
            `ParentId` int(11) DEFAULT NULL,
            `TemplateId` int(11) DEFAULT NULL,
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`CategoryId`),
            KEY `Category` (`Category`)
          )";          
        dbDelta( $sql );
    }
}