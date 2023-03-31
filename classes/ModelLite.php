<?php
/*****
Model Lite Class to make saves/inserts/updates/forms a tad easier to develop

Requires the following constants as seen in this example:	
	protected $table = 'Donor';
	protected $primaryKey = 'DonorId';
	### Fields that can be passed 
    protected $fillable = ["Name","Name2","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];	    
	### Default Values
	protected $attributes = [        
        'Country' => 'USA',
	];
	public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt'; 
*****/

class ModelLite{
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
	
	public function __construct($attributes = []){
        $this->fill((array)$attributes);
	}
	
	public function fill(array $attributes){
		//self::dump($attributes);
		$fields=$this->get_viewable_fields();		
		
		if (is_array($attributes)){ ### flip array to object
			$attributes=(object)$attributes;
		}

		if (is_array($fields)){
			foreach ($fields as $field){
				//I'm not positive why we have to strip slashes... but it fixes the issue.
				$this->$field=stripslashes(isset($attributes->$field)?$attributes->$field:$this->attributes[$field]);

				if (isset($this->fieldLimits)&&isset($this->fieldLimits[$field])){ //trim strings that are to long.
					$this->$field=substr($this->$field,0,$this->fieldLimits[$field]);
					//if (strlen($this->$field)>$this->fieldLimits[$field]){
						//print $field." -> Was: '".$this->$field."' | Trimmed to: '". substr($this->$field,0,$this->fieldLimits[$field])."'<br>";
						
					//}				

				}
			}
		}	
	}

	public function get_viewable_fields(){
		$fields=$this->fillable?$this->fillable:[];
		$primaryKey=$this->primaryKey;
		
		### Add Primary Key to results
		if (is_array($primaryKey)){
			$fields=array_merge($primaryKey,$fields);
		}else{
			$fields=array_merge(array($primaryKey),$fields);
		}
		return $fields;
	}

	public function get_table($type="full"){
		$wpdb=self::db(); 
		$base=strtolower(($this->table ?? class_basename($this)));
		if($type=="base"){
			return $base;
		}      
        return $wpdb->prefix.$base;
	}

	static public function get_base_table(){
		return self::s()->get_table('base');
	}
	
	static public function get_table_name($type="full"){	
        return self::s()->get_table($type);
    }
	static public function get_fillable(){
		return self::s()->fillable;
	}

	static public function db(){ //concept for right now... if we want to avoid global $wpdb, run everything this...
		//$wpdadb = WPDataAccess\Connection\WPDADB::get_db_connection( 'your-local-db-name' ); 
		global $wpdb;
		return $wpdb;
	}

	public function save($time=""){ //both insert and update routine. Creates new if no ReworkId passed in.
		if (!$time) $time=date("Y-m-d H:i:s"); //time();
		$wpdb=self::db();  
		$wpdb->show_errors();
		$keyField=$this->primaryKey;
		foreach ($this->fillable as $field){
			if (isset($this->fieldLimits)&&isset($this->fieldLimits[$field])){ //trim strings that are to long.
				$data[$field]=substr($this->$field,0,$this->fieldLimits[$field]);
			}else{
				$data[$field]=$this->$field;
			}			
		}		
		if (static::UPDATED_AT && !$data[static::UPDATED_AT]){
			$data[static::UPDATED_AT]= $time;
		}

		if ($this->$keyField>0){
			if (!$wpdb->update($this->get_table(),$data,array($keyField=>$this->$keyField))){
				print $wpdb->print_error();
				dump($data,"WHERE ".$keyField."=".$this->$keyField);
				
			}
		}else{
			if (static::CREATED_AT && !$data[static::CREATED_AT]){
				$data[static::CREATED_AT]= $time;
			}
			//dump($data);		 	
			if (!$wpdb->insert($this->get_table(),$data)){				
				print $wpdb->print_error();
				dump($data);
			}			
			$this->$keyField=$wpdb->insert_id;
			$insert=true;
		}
		return $this;

	}

	public function delete(){
		$wpdb=self::db();  
		$keyField=$this->primaryKey;
		if ($this->$keyField){
			 $wpdb->delete($this->get_table(),array($keyField=>$this->$keyField));
			 return true;
		}
		return false;
	}

	public function view(){ //single entry->View fields
		//print "varview";
		$this->var_view();
		//print_r($this);
	}

	public function var_view(){
		?><table class="dp"><?php
		$fields=$this->get_viewable_fields();
		foreach($fields as $f){			
			if ($this->$f){
				?><tr><td><?php print $f?></td><td><?php print $this->show_field($f)?></td></tr><?php
			}
		}
		?></table>
		<?php
	}

	static public function get_key($row){ //maybe discontiue this and replace with flat_key();
		$key=[];
		foreach(self::s()->duplicateCheck as $field){
			$value=$row->$field;
			switch($field){
				case "Gross":
					$value*=1;
				break;
				default:
					$value=strtoupper($value);
				break;
			}
			$key[]=$value;
		}
		return implode("|",$key);
	}

	public function flat_key($keys=[]){ //takes key fields and flattens them for the sake of comparison
        if (sizeof($keys)==0) $keys=$this->flat_key;
		$return="";
        foreach($keys as $key){
           $return.=($return?"|":"").preg_replace("/[^a-zA-Z0-9 ]+/", "",strtolower($this->$key));
        }
        return $return;        
    }

	static public function replace_into_list($q){
		### Adds a check to avoid duplicate rows basd on 
		$wpdb=self::db();  
		### Cache all existing entries. If DB gets to big, this might use to much memory, and may want to do individual queries.
		$dupFieldsCheck=self::s()->duplicateCheck;		
        $result= $wpdb->get_results("Select ".implode(", ",$dupFieldsCheck).", Count(*) as C FROM ".self::s()->get_table()." Group By  ".implode(", ",$dupFieldsCheck));
        foreach ($result as $row){
			$existingEntries[self::get_key($row)]=$row->C;
		}

        if (sizeof($q)>0){		
			$iSQL=[];
			$skipped=0;
            foreach ($q as $row){
				if ($existingEntries[self::get_key($row)]){ //skip entry already exists
					$skipped++;
				}else{
					$items=[];
					foreach ($row->fillable as $f){				
						$items[]="'".($row->$f?addslashes($row->$f):"")."'";
					}
					$iSQL[]="(".implode(", ",$items).")";
				}
            }
			if (sizeof($iSQL)>0){
				$SQL="INSERT INTO ".self::s()->get_table()." (`".implode("`,`",$row->fillable)."`) VALUES ".implode(", ",$iSQL);
				$result= $wpdb->query($SQL);
			}
			return array('inserted'=>sizeof($iSQL),'skipped'=>$skipped,'insertResult'=>$result);
        }
    }

	static public function s(){
		// A way to retreive protectic variables from a static call.
		// example: self::s()->get_table()
        $instance = new static;
        return $instance;
	}
	
	static public function dump($obj){
		print "<pre>"; var_dump($obj); print "</pre>";
	}

	public static function dd($obj){
		self::dump($obj);
		exit();
	}

	public static function get_by_id($id,$settings=false){		
		$where[]=self::s()->primaryKey."='".$id."'";
		$SQL="SELECT * FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"");			
		return  new static((array) self::db()->get_row($SQL));
	}

	public static function first($where=array(),$orderby,$settings=[]){
		$settings['limit']=1;
		$first=self::get($where,$orderby,$settings);
		if (is_array($first) && sizeof($first)>0) return $first[key($first)];
		else return false;
	}

	public static function find($id){
		return self::get_by_id($id);			
	}
	
	public static function get($where=array(),$orderby="",$settings=false){
		if ($where && !is_array($where)) $where=[$where];	
		$SQL="SELECT ".($settings['select']?$settings['select']:"*")." FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"").($orderby?" ORDER BY ".$orderby:"").($settings['limit']?" LIMIT ".$settings['limit']:"");
		$all=self::db()->get_results($SQL);
		foreach($all as $r){
			$obj=new static($r,$settings);
			if ($settings['key']){
				$primaryField=$obj->primaryKey;
				$return[$r->$primaryField]=$obj;
			}else{
				$return[]=$obj;
			}
		}
		
		return $return;
	}

	public function key_flat(){
		//return key value. If key is mutiple fields, then it seperates by pipes "|". 
		$keyField=$this->primaryKey;
		if (is_array($keyField)){
			$key="";
			foreach($keyField as $kf){
				$key.=($key?"|":"").$this->$kf;
			}
		}else $key=$this->$keyField;
		return $key;
	}

	public static function show_results($results,$noResultMessage="<div class=\"notice notice-warning\">No Results Found</div>",$fieldList=[]){		
		$fields=$fieldList?$fieldList:self::s()->get_viewable_fields();	
		if (!$results || sizeof($results)==0){
			return "<div><em>".$noResultMessage."</em></div>"; 
		}
		ob_start(); 
		?>
		<script>
			function toggleDisplay(id){
				var x = document.getElementById(id);
				if (x.style.display === "none") {
					x.style.display = "block";
				} else {
					x.style.display = "none";
				}
			}
		</script>	
		<table class="dp"><?php
			$i=0;
			foreach($results as $r){
				if ($i==0){
					?><tr><?php
					foreach ($fields as $field){?><th><?php print $field?></th><?php }
					?></tr><?php
				}
				?><tr><?php
				foreach ($fields as $field){
					?><td><?php print $r->show_field($field)?></td><?php 
				
				}
				?></tr><?php
				$i++;
			}
		?></table><?php
		return ob_get_clean(); 
	}

	public function phone_format($phone){
        return preg_replace('~.*(\d{3})[^\d]{0,7}(\d{3})[^\d]{0,7}(\d{4}).*~', '($1) $2-$3', $phone);
    }
	
	public function show_field($fieldName,$settings=[]){
		$v=$this->$fieldName;
		switch($fieldName){
			case "Address":
				if (get_class($this)=='Donor') return $this->mailing_address(", ",false);
				else return $this->Address;
				break;
			case "Phone":
				return $this->phone_format($v);
			break;
			case "Gross":
			case "Fee":
			case "Net":
				return $v?number_format($v,2):"";
			break;
			case "Content":
				return ($v?"<div><a href='#' onclick=\"toggleDisplay('message_".$this->ReceiptId."');return false;\">Show/Hide</a></div><div style='display:none;' id='message_".$this->ReceiptId."'>".$v."</div>":"<div><em>Standard Message</em></div>");
				break;
			case "QBOPaymentId":
				if ($v==-1) return "<span style='color:red;'>Ignored</span>";
				if ($v==0) return "<span>Not Synced to QB</span>";
				return '<a '.($settings['target']?'target="'.$settings['target'].'"':"").'href="?page=donor-quickbooks&table=Payment&Id='.$v.'">'.$v.'</a> '.QuickBooks::qbLink('Payment',$v,'QB');
				break;	
			case "QBOInvoiceId":
				if ($v==-1) return "<span style='color:red;'>Ignored</span>";
				if ($v==0) return "<span>Not Synced to QB</span>";
				return '<a '.($settings['target']?'target="'.$settings['target'].'"':"").'href="?page=donor-quickbooks&table=Invoice&Id='.$v.'">'.$v.'</a> '.QuickBooks::qbLink('Invoice',$v,'QB');
			case "QuickBooksId":
				if ($v==-1) return "<span style='color:red;'>Ignored</span>";
				if ($v==0) return "<span>Not Synced to QB</span>";
				return '<a '.($settings['target']?'target="'.$settings['target'].'"':"").'href="?page=donor-quickbooks&table=Customer&Id='.$v.'">'.$v.'</a> '.QuickBooks::qbLink('Customer',$v,'QB');
			case "DonationId":
				return '<a '.($settings['target']?'target="'.$settings['target'].'"':"").'href="?page=donor-index&DonationId='.$v.'">'.$v.'</a>';
			break;
			case "MergedId":
				return '<a '.($settings['target']?'target="'.$settings['target'].'"':"").'href="?page=donor-index&DonorId='.$v.'">'.$v.'</a>';
			case "DonorId":
				return '<a '.($settings['target']?'target="'.$settings['target'].'"':"").'href="?page=donor-index&DonorId='.$v.'">'.$v.'</a>'.($settings['donationlink']?' <a href="?page=donor-index&DonorId='.$v.'&f=AddDonation">+ Donation</a>':"");
			break;
			case "Date":
					return str_replace(" 00:00:00","",$v);
				break;
			case "FromEmailAddress":
			case "ToEmailAddress":
			case "Email":
				return $this->display_email($fieldName); //'<a href="mailto:'.$v.'?Subject=">'.$v.'</a>';
			break;
			case "CategoryId":
				$label="";
				if ($this->table=='DonationCategory') {
					$settings['idShow']=true;
				}else{
					global $cache_ModelLite_show_field;
					if (isset($cache_ModelLite_show_field[$fieldName][$v])){
						$label=$cache_ModelLite_show_field[$fieldName][$v];
					}elseif($v){
						$dCat=DonationCategory::find($v); //need to cache this..
						if ($dCat){
							$cache_ModelLite_show_field[$fieldName][$v]=$dCat->Category;
							$label=$dCat->Category;
						} 
					}
					
										
				}
				return '<a href="?page='.$_GET['page'].'&tab=cat&CategoryId='.$v.'">'.$v.'</a> - '.$label;
				//return ($settings['idShow']?'<a href="?page='.$_GET['page'].'&tab=cat&CategoryId='.$v.'">'.$v.'</a> - ':"").$label;
			break;
			// case "TransactionType":
			// 	if (!$v) $v=0;
			// 	return Donation::s()->tinyIntDescriptions['TransactionType'][$v];
			// 	break;
			default:
				if ($this->tinyIntDescriptions[$fieldName]){
					$label=self::s()->tinyIntDescriptions[$fieldName][$v];								
					if ($label){	
						return $v." - ".$label;
					}elseif ($fieldName=="Type" && $this->TypeOther){
							return ($settings['idShow']?$v." - ":"").$this->TypeOther;						
					}
				}
				return $v;				
			break;
		}
	}

	static public function get_tiny_description($fieldName,$v){
		return self::s()->tinyIntDescriptions[$fieldName][$v];
	}

	public function display_key(){
		$primaryKey=$this->primaryKey;
		return '<a href="?page=donor-index&'.$primaryKey.'='.$this->$primaryKey.'">'.$this->$primaryKey."</a> ";
	}

	public function display_email($fieldName='Email'){
		$return="";
		if (trim($this->$fieldName)){
			$emails=explode(";",$this->$fieldName);
			$count=0;
			foreach($emails as $email){
				$email=trim($email);
				if ($count>0) $return.="; ";
				if ($this->EmailStatus<0 || !filter_var($email, FILTER_VALIDATE_EMAIL)){
					$return.= '<span style="text-decoration:line-through; color:red;">'.$email.'</span>';
				}else{
					$return.= '<a href="mailto:'.$email.'">'.$email.'</a>';
				}
				$count++;
			}	
			return $return;		
		}		
	}
	
	public function edit_form(){
		$primaryKey=$this->primaryKey;
		?><form method="post" action="?page=<?php print $_GET['page']?>&<?php print $primaryKey?>=<?php print $this->$primaryKey?>">
		<input type="hidden" name="table" value="<?php print $this->table?>"/>
		<input type="hidden" name="<?php print $primaryKey?>" value="<?php print $this->$primaryKey?>"/>
	
		<table><?php
		foreach($this->fillable as $field){
			$type="text";
			if (isset($this->fieldLimits)&&isset($this->fieldLimits[$field])){ //trim strings that are to long.
				$maxlength=$this->fieldLimits[$field];
			}else{
				$maxlength=false;
			}
            if (strpos($field,"Date")>-1){
                $type="date";
			}
			if ($field=="Date") $this->Date=substr($this->$field,0,10); //$type="datetime-local";
			?><tr><td align=right><?php print $field?></td><td><?php

			$select=false;
			if ($this->tinyIntDescriptions[$field]) $select=$this->tinyIntDescriptions[$field];
			switch ($field){
				case "TypeId":
					$select=DonorType::list_array();
					break;
			}

			if ($select){
				?><select name="<?php print $field?>"><?php
					foreach($select as $key=>$label){
						?><option value="<?php print $key?>"<?php print $key==$this->$field?" selected":""?>><?php print $key." - ".$label?></option><?php
					}
					if (!$select[$this->$field]){
						?><option value="<?php print $this->$field?>" selected><?php print $this->$field." - Not Set"?></option><?php
					}
					?></select><?php
			}else{
				?><input type="<?php print $type?>" name="<?php print $field?>" value="<?php print $this->$field?>"<?php
				if ($maxlength) print ' maxlength="'.$maxlength.'"';?>/><?php
			}	
		
			
			?></td></tr><?php
			}
		?><tr><tr><td colspan=2>
		<button type="submit" class="primary" name="Function" value="Save">Save</button>
		<button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->$primaryKey){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?php
        }
        ?>
		</td></tr>
		</table>
		</form><?php
	}

	static public function display_notice($html){
		print "<div class=\"notice notice-success is-dismissible\">".$html."</div>";
	}

	static public function display_warning($html){
		print "<div class=\"notice notice-warning is-dismissible\">".$html."</div>";
	}

	static public function display_error($html){
		print "<div class=\"notice notice-error is-dismissible\">".$html."</div>";
	}

	static public function show_tabs($tabs,$active_tab){
		$active_tab=$_REQUEST['tab']?$_REQUEST['tab']:key($tabs);
		?>
		<div class="dp-tab-links">
			<?php foreach ($tabs as $tab=>$label){
				print '<a href="?page='.$_GET['page'].'&tab='.$tab.'" class="tab'.($active_tab==$tab?" active":"").'">'.$label.'</a>';			
			}?>
		</div>
		<?php
		return $active_tab;
		
	}

	static public function upload_dir(){
		$dir=dn_plugin_base_dir()."/uploads/";
		if (!is_dir($dir)){
			mkdir($dir, 0777, true);
		}
        return $dir;
    }

	// public static function compare($old, $new){
	// 	if (sizeof($old)==0){
	// 		return false;
	// 	}
	// 	foreach (static::fields as $field){
	// 		if ($old->$field!=$new->$field){
	// 			$was[$field]=$old->$field;
	// 		}
	// 	}
	// 	return $was;
	// }

}
?>
