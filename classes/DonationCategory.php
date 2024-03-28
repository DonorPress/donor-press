<?php
namespace DonorPress;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly      
### Manages the Category of a Donation

class DonationCategory extends ModelLite
{
    protected $table = 'donation_category';
	protected $primaryKey = 'CategoryId';
	### Fields that can be passed 
    protected $fillable = ["Category","Description","ParentId","TemplateId","TransactionType","QBItemId"];	 
  	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';  
    //NOte: TemplateId links to table posts -> ID, but uses post_type='donorpress' 

    static public function request_handler(){
        $wpdb=self::db();  
        if (self::input('CategoryId','post') && self::input('Function','post')=="Delete" && self::input('table','post')=="donation_category"){
            $donationCategory=new self(self::input_model('post'));
            if ($donationCategory->delete()){
                self::display_notice("Donation Category '".$donationCategory->Category."' deleted."); 
            }
        }elseif (self::input('CategoryId','post') && self::input('Function','post')=="DonationCategoryMergeTo" && self::input('table','post')=="donation_category"){
            $donationCategory=new self(self::input_model('post'));
            $mergeTo=self::find(self::input('MergeTo','post'));
            //self::dump($mergeTo);
            if (!$mergeTo->CategoryId){
                self::display_error("Could not find Merge to Donation Category: ".self::input('MergeTo','post'));
                return;
            }
            $old=new self(self::input_model('post'));
            $old->donation_count();
            
            $wpdb->update(Donation::get_table_name(),array("CategoryId"=>self::input('MergeTo','post')),array('CategoryId'=> $old->CategoryId));
            self::display_notice($old->donation_count." donation record counts changed from Category '". $old->Category."' to ".$mergeTo->show_field("CategoryId")." - ".$mergeTo->Category); 

            if ($donationCategory->delete()){                
                self::display_notice("Donation Category '".$donationCategory->Category."' deleted."); 
            }        
        }elseif (self::input('CategoryId','request')&&self::input('tab','get')=="cat"){	            

            if (self::input('CategoryId','post')=='new'){
                unset($_POST['CategoryId']);
            }

            if (self::input('ChangeTypeTo','get')&& self::input('ChangeTypeFrom','get')){
                $uSQL="UPDATE ".Donation::get_table_name()." SET TransactionType='".(self::input('ChangeTypeTo','get')=='ZERO'?0:self::input('ChangeTypeTo','get'))."' WHERE CategoryId='".self::input('CategoryId','get')."' AND (TransactionType".(self::input('ChangeTypeFrom','get')=='ZERO'?"=0 OR TransactionType IS NULL":"='".self::input('ChangeTypeFrom','get')."'").")";
                $wpdb->get_results($uSQL);
                print self::display_notice("TransactionType Changed from ".self::input('ChangeTypeFrom','get')." to ".self::input('ChangeTypeTo','get')." for Category ".self::input('CategoryId','get'));
            }

            if (self::input('Function','post')=="Save" && self::input('table','post')=="donation_category"){              
                $donationCategory=new self(self::input_model('post'));
                if ($donationCategory->save()){
                    self::display_notice("Donation Category#".$donationCategory->show_field("CategoryId")." - ".$donationCategory->Cateogry." saved.");
                    $_REQUEST['CategoryId']=$donationCategory->CategoryId;
                }
            }
            if (self::input('CategoryId','request')=="new"){
                $donationCategory=new self();
            }else{
                $donationCategory=self::find(self::input('CategoryId','request'));	
            }          

            ?>
            <div id="pluginwrap">
                <div><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get'))?>">Return</a></div>
                <h1>Category <?php print self::input('CategoryId','get')=="new"?"NEW":"#".$donationCategory->CategoryId?></h1><?php 
                if (self::input('edit','request')){
                    $donationCategory->edit_form();
                }else{
                    ?><div><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&CategoryId='.$donationCategory->CategoryId)?>&edit=t">Edit Category</a></div><?php
                    $donationCategory->view(); 
                    if ($donationCategory->TransactionType){
                        $SQL="SELECT TransactionType,COUNT(*) as C  FROM ".Donation::get_table_name()." WHERE CategoryId='".$donationCategory->CategoryId."' AND (TransactionType<>'".$donationCategory->TransactionType."' OR TransactionType IS NULL) Group BY TransactionType";
                        //print $SQL;
                        $results = $wpdb->get_results($SQL);
                        if (sizeof($results)>0){
                            ?><h4>Donations Exist with Alternative Transaction Types</h4>
                            <table class="dp"><tr><th>Transaction Type</th><th>Count</th><th></th></tr>
                            <?php
                            foreach($results as $r){
                                print "<tr><td>".$r->TransactionType." ".Donation::s()->tinyIntDescriptions["TransactionType"][$r->TransactionType]."</td><td><a target='lookup' href='?page=donorpress-reports&tab=donations&CategoryId=".$donationCategory->CategoryId."&TransactionType=".($r->TransactionType?$r->TransactionType:"ZERO")."&Function=DonationList'>".$r->C."</td><td><a href='?page=".self::input('page','get')."&tab=".self::input('tab','get')."&CategoryId=".self::input('CategoryId','get')."&ChangeTypeTo=".($donationCategory->TransactionType?$donationCategory->TransactionType:"ZERO")."&ChangeTypeFrom=".($r->TransactionType?$r->TransactionType:"ZERO")."'>Change All To: ".$donationCategory->TransactionType." (".Donation::s()->tinyIntDescriptions["TransactionType"][$donationCategory->TransactionType].")</a></td></tr>";
                            }?>
                            </table>
                            <?php 
                        }else{
                            //print "Query came up empty: ".$SQL;
                        }                       
                    }else{
                        //print "Type not found";
                    }
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
		?><form method="post" action="<?php print esc_url('?page='.self::input('page','get').(self::input('tab','get')?'&tab='.self::input('tab','get'):"")."&".$primaryKey."=".$this->$primaryKey)?>">
		<input type="hidden" name="table" value="<?php print esc_attr($this->table)?>"/>
		<input type="hidden" name="<?php print esc_attr($primaryKey)?>" value="<?php print esc_attr($this->$primaryKey?$this->$primaryKey:"new")?>"/>
        <table>
            <tr><td align="right">Category Title</td><td><input style="width: 300px" type="text" name="Category" value="<?php print esc_attr($this->Category)?>"></td></tr>
            <tr><td align="right">Description</td><td><textarea rows=3 cols=40 name="Description"><?php print esc_textarea($this->Description)?></textarea></td></tr>
            <tr><td align="right">Parent Category</td><td><select name="ParentId"><option value="0">[--None--]</option><?php
             $results = $wpdb->get_results("SELECT `CategoryId`, `Category`,ParentId FROM ".self::get_table_name()." WHERE (ParentId=0 OR ParentId IS NULL) AND CategoryId<>'".$this->CategoryId."' Order BY Category");
             foreach($results as $r){
                ?><option value="<?php print esc_attr($r->CategoryId)?>"<?php print ($r->CategoryId==$this->ParentId?" selected":"")?>><?php
                print $r->Category." (".$r->CategoryId.")";?></option><?php
             }?></select></td></tr>
             <tr><td align="right">Default Transaction Type</td><td>
             <select name="TransactionType">
                <option value="">--None--</option>
                <?php
                foreach(Donation::s()->tinyIntDescriptions["TransactionType"] as $key=>$label){
                    ?><option value="<?php print esc_attr($key==0?"ZERO":$key)?>"<?php print ($key==0?"ZERO":$key)==self::input('Type','get')?" selected":""?>><?php print $key." - ".$label?></option><?php
                }?>
            </select>

             </td></tr>
            <tr><td align="right">Response Template</td><td><select name="TemplateId"><option value="">default</option><?php
            $list=DonorTemplate::get(array("post_type='donorpress'","post_parent=0"),"post_name,post_title");
            foreach($list as $t){
                print '<option value="'.$t->ID.'"'.($t->ID==$this->TemplateId?" selected":"").'>'.$t->post_name.'</option>';
            }
            ?></select></td></tr>
            <?php            
            if (Quickbooks::is_setup()){?>
                <tr>
                    <td align="right">Default QuickBook Item:</td>
                    <td><?php
                    $qb=new QuickBooks();
                    if ($items=$qb->item_list("",false)){
                        if (sizeof($items)>0){?>                        
                        <select name="QBItemId"><option value="">-not set-</option><?php                    
                        foreach($items as $item){
                            print '<option value="'.$item->Id.'"'.($item->Id==$this->QBItemId?" selected":"").'>'.$item->FullyQualifiedName.'</option>';
                        }
                        ?></select> 
                        <?php 
                        }
                    }else{?>
                    <div>No Items Found in Quickbooks. Please create a non-stock item in Quickbooks first.</div>
                    <input type="hidden" name="QBItemId" value="<?php print esc_attr($this->QBItemId)?>"/>
                    <?php } ?>
                    <em>When syncing to Quickbooks, this is how the default item on an invoice is logged. Items on Quickbooks determine which sales account gets used.</em></td>
                </tr>
                <?php
            }else{
                ?><input type="hidden" name="QBItemId" value="<?php print esc_attr($this->QBItemId)?>"/><?php
            }
          
            ?>
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
                Merge To: <?php
                print self::select(["Name"=>"MergeTo","IncludeNone"=>true,"DisableId"=>$this->CategoryId]);
                ?>                
                <button type="submit" name="Function" value="DonationCategoryMergeTo">Merge</button>
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
            if (isset($cache[$r->Category])){
                $uSQL="UPDATE".Donation::get_table_name()." SET `CategoryId`='".$cache[$r->Category]."' WHERE `CategoryId`='".$r->CategoryId."'";
                print $uSQL.";<br>";
                $dSQL="DELETE FROM ".self::get_table_name()." WHERE `CategoryId`='".$r->CategoryId."'";
                print $dSQL.";<br>";
            }else{
                $cache[$r->Category]=$r->CategoryId;
            }   
        }
    }

    public function getTemplate(){
        if ($this->TemplateId>0){
            return DonorTemplate::find($this->TemplateId);
        }
        if ($this->ParentId>0){
            return self::find($this->ParentId)->getTemplate(); //recursive go up to parent to find default thank you
        }
        return false;
    }

    public function view(){ //single entry->View fields
		//print "varview";
		$this->var_view();
		//do stats on entries with this category.. maybe even reports based on dates.
	}

    static public function list(){                
        $SQL= "SELECT *,(Select COUNT(*) FROM ".Donation::get_table_name()." Where CategoryId=C.CategoryId) as donation_count FROM ".self::get_table_name()." C Order BY Category";       
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){ 
            $parent[$r->ParentId?$r->ParentId:0][]=$r;
        }
        ?>
        <h2>Donation Categories</h2>
        <a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get'))?>&CategoryId=new&edit=t">Add Category</a>
        <table border="1"><tr><th>Id</th><th>Category</th><th>Description</th><th>Transaction Type</th><th>ParentId</th>
        <?php if (Quickbooks::is_setup()) print  "<th>QuickBooks Item Id</th>";?>
        <th>Total Donations</th><th></th></tr><?php
         self::show_children(0,$parent,0);         
        ?></table>	
        <?php
    }

    static function show_children($parentId,$parent,$level=0){
        if (!$parent[$parentId]) return;
        foreach ($parent[$parentId] as $r){
            ?><tr>
                <td style="padding-left:<?php print esc_html($level*20)?>px"><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&CategoryId='.$r->CategoryId)?>"><?php print esc_html($r->CategoryId)?></a></td>
                <td><?php print esc_html($r->Category)?></td>
                <td><?php print esc_html($r->Description)?></td>
                <td><?php print $r->TransactionType." ".Donation::s()->tinyIntDescriptions["TransactionType"][$r->TransactionType]?></td>
                <td><?php print esc_html($r->ParentId)?></td>
                <?php if (Quickbooks::is_setup()) print  "<td>".($r->QBItemId>0?Quickbooks::qbLink('Item',$r->QBItemId):"")."</td>";?>

                <td><a target='lookup' href='<?php print esc_url('?page=donorpress-reports&tab=donations&CategoryId='.$r->CategoryId);?>&Function=DonationList'><?php print esc_html($r->donation_count)?></a></td>
                <td><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&CategoryId='.$r->CategoryId.'&edit=t')?>">edit</a></td>
            </tr>
            <?php
            self::show_children($r->CategoryId,$parent,$level+1);
        }
    }

    static function show_options($parentId,$parent,$level=0,$settings=[]){
        if (!isset($parent[$parentId])) return;
        if (!is_array($settings['selected'])) $settings['selected']=[$settings['selected']];
        $return="";
        foreach ($parent[$parentId] as $r){
            $return.='<option value="'.$r->CategoryId.'"'
            .(isset($settings['DisableId']) && $settings['DisableId']==$r->CategoryId?" disabled":"")
            .(in_array($r->CategoryId,$settings['selected'])?' selected':"").'>';
            for($i=0;$i<$level;$i++){
                $return.="--";
            }
            $return.=$r->Category.(isset($r->donation_count)?" (x".$r->donation_count.")":"")."</option>";
            $return.=self::show_options($r->CategoryId,$parent,$level+1,$settings);
        }
        return $return;
    }

    static public function select($settings=[]){
        $return='<select name="'.($settings['Name']?$settings['Name']:"CategoryId").'"';
        if (isset($settings["Multiple"])) $return.=" multiple";       
        $return.=">";
        if (isset($settings['IncludeNone'])){
           $return.='<option value="0">[--None--]</option>';
        }else{
            $return.='<option></option>';
        }       
       
        $SQL= "SELECT *".(isset($settings['Count'])?",(Select COUNT(*) FROM ".Donation::get_table_name()." Where CategoryId=C.CategoryId) as donation_count":"")." FROM ".self::get_table_name()." C Order BY Category";       
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){ 
            $parent[$r->ParentId?$r->ParentId:0][]=$r;
        }
        if (!isset($settings['selected'])) $settings['selected']=self::input($settings['Name']?$settings['Name']:"CategoryId");
        else $selected=$settings['selected'];

        $return.=self::show_options(0,$parent,0,$settings);
        $return.="</select>";
        return $return;
    }

    public function getQuickBooksId(){
        if($this->QBItemId) return $this->QBItemId;
        if ($this->ParentId){
            $parent=self::find($this->ParentId);
            return $parent->getQuickBooksId(); //recursive all the way to top.
        }
        return false;
    }

    static public function get_default_transaction_type($category_id){
        $cat=self::find($category_id);
        if ($cat->TransactionType) return $cat->TransactionType;
        if ($cat->ParentId){
            $parent=self::find($cat->ParentId);
            return $parent->TransactionType;
        }
    }


    static public function get_category_id($text){
        $text=substr(trim($text),0,50); //correct entries over 50 characters
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
            `TransactionType` int(11) DEFAULT NULL,
            `QBItemId` int(11) DEFAULT NULL,
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`CategoryId`),
            KEY `Category` (`Category`)
          )";          
        dbDelta( $sql );
    }
}