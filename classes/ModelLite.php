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
			}
		}	
	}

	public function get_viewable_fields(){
		$fields=$this->fillable;
		$primaryKey=$this->primaryKey;
		
		### Add Primary Key to results
		if (is_array($primaryKey)){
			$fields=array_merge($primaryKey,$fields);
		}else{
			$fields=array_merge(array($primaryKey),$fields);
		}
		return $fields;
	}

	public function get_table(){
		$wpdb=self::db();       
        return $wpdb->prefix.strtolower(($this->table ?? class_basename($this)));
	}
	
	static public function get_table_name(){	
        return self::s()->get_table();
    }
	static public function get_fillable(){
		return self::s()->fillable;
	}

	static public function db(){ //concept for right now... if we want to avoid global $wpdb, run everything this...
		//$wpdadb = WPDataAccess\Connection\WPDADB::get_db_connection( 'your-local-db-name' ); 
		global $wpdb;
		return $wpdb;
	}

	public function save(){ //both insert and update routine. Creates new if no ReworkId passed in.
		$wpdb=self::db();  
		$wpdb->show_errors();
		$keyField=$this->primaryKey;
		foreach ($this->fillable as $field){
			if ($field!=$keyField){
				$data[$field]=$this->$field;
			}
		}		
		if (defined (static::UPDATED_AT)){
			$data[static::UPDATED_AT]= time();
		}

		if ($this->$keyField>0){
			$wpdb->update($this->get_table(),$data,array($keyField=>$this->$keyField));
		}else{
			if (defined (static::CREATED_AT) && !$data[static::CREATED_AT]){
				$data[static::CREATED_AT]= time();
			}		 	
			$result=$wpdb->insert($this->get_table(),$data);	
			
			$this->$keyField=$wpdb->insert_id;
			// self::dump($this->get_table());
			// self::dump($data);
			// self::dd(array($keyField=>$this->$keyField));
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
		?><table border=1><?
		$fields=$this->get_viewable_fields();
		foreach($fields as $f){			
			if ($this->$f){
				?><tr><td><?php print $f?></td><td><?php print $this->show_field($f)?></td></tr><?
			}
		}
		?></table>
		<?
	}

	static public function get_key($row){
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

	static public function replace_into_list($q){
		### Adds a check to avoid duplicate rows basd on 
		$wpdb=self::db();  
		### Cache all existing entries. If DB gets to big, this might use to much memory, and may want to do individual queries.
		$dupFieldsCheck=self::s()->duplicateCheck;		
        $result= $wpdb->get_results("Select ".implode(", ",$dupFieldsCheck).", Count(*) as C FROM ".self::s()->get_table()." Group By  ".implode(", ",$dupFieldsCheck));
        foreach ($result as $row){
			$existingEntries[self::get_key($row)]=$row->C;
		}
		//self::dump($existingEntries);

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
				//print self::get_key($row)."::".$SQL."<hr>";
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
		$wpdb=self::db();  
		$where[]=self::s()->primaryKey."='".$id."'";
		$SQL="SELECT * FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"");			
		return  new static((array)$wpdb->get_row($SQL));
	}
	
	public static function get($where=array(),$orderby="",$settings=false){
		$wpdb=self::db();  
		$SQL="SELECT * FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"").($orderby?" ORDER BY ".$orderby:"");
		//print $SQL;
		$all=$wpdb->get_results($SQL);
		foreach($all as $r){
			$obj=new static($r,$settings);
			if ($settings['key']){
				$primaryField=$obj->primaryKey;
				$return[$r->$primaryField]=$obj; //untested, but conceptial.
			}else{
				$return[]=$obj;
			}
		}
		
		return $return;
	}

	public function key_flat(){
		//return key value. If key is mutiple fields, then it seperates by pipes "|". 
		$keyField=$this->primaryKey;
		//dump($keyField);
		if (is_array($keyField)){
			$key="";
			foreach($keyField as $kf){
				$key.=($key?"|":"").$this->$kf;
			}
		}else $key=$this->$keyField;
		return $key;
	}

	public static function show_results($results,$noResultMessage="No Results Found."){		
		$fields=self::s()->get_viewable_fields();	
		if (!$results || sizeof($results)==0){
			return "<div><em>".$noResultMessage."</em></div>"; 
		}
		ob_start(); 
		?><table border=1><?
			$i=0;
			foreach($results as $r){
				if ($i==0){
					?><tr><?
					foreach ($fields as $field){?><th><?php print $field?></th><?php }
					?></tr><?
				}
				?><tr><?
				foreach ($fields as $field){?><td><?php print $r->show_field($field)?></td><?php }
				?></tr><?
				$i++;
			}
		?></table><?
		return ob_get_clean(); 
	}
	
	public function show_field($fieldName,$idShow=true){
		$v=$this->$fieldName;
		switch($fieldName){
			case "Gross":
			case "Fee":
			case "Net":
				return $v?number_format($v,2):"";
			break;
			case "DonationId":
				return '<a href="?page='.$_GET['page'].'&DonationId='.$v.'">'.$v.'</a>';
			break;
			case "MergedId":
				return '<a href="?page='.$_GET['page'].'&DonorId='.$v.'">'.$v.'</a>';
			case "DonorId":
				return '<a href="?page='.$_GET['page'].'&DonorId='.$v.'">'.$v.'</a> <a href="?page='.$_GET['page'].'&DonorId='.$v.'&f=AddDonation">+ Donation</a>';
			break;
			case "FromEmailAddress":
			case "ToEmailAddress":
			case "Email":
				return $this->display_email($fieldName); //'<a href="mailto:'.$v.'?Subject=">'.$v.'</a>';
			break;
			case "CategoryId":
				$label="";
				if ($this->table=='DonationCategory') {}
				else{
					global $cache_ModelLite_show_field;
					if (isset($cache_ModelLite_show_field[$fieldName][$v])){
						$label=$cache_ModelLite_show_field[$fieldName][$v];
					}elseif($v){
						$dCat=DonationCategory::get_by_id($v); //need to cache this..
						if ($dCat){
							$cache_ModelLite_show_field[$fieldName][$v]=$dCat->Category;
							$label=$dCat->Category;
						} 
					}
					
										
				}
				return ($idShow?'<a href="?page='.$_GET['page'].'&CategoryId='.$v.'">'.$v.'</a> - ':"").$label;
			break;
			default:
				if ($this->tinyIntDescriptions[$fieldName]){
					$label=self::s()->tinyIntDescriptions[$fieldName][$v];								
					if ($label){	
						return $v." - ".$label;
					}elseif ($fieldName=="Type" && $this->TypeOther){
							return ($idShow?$v." - ":"").$this->TypeOther;						
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
		return '<a href="?page='.$_GET['page'].'&'.$primaryKey.'='.$this->$primaryKey.'">'.$this->$primaryKey."</a> ";
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
	
		<table><?
		foreach($this->fillable as $field){
			$type="text";
            if (strpos($field,"Date")>-1){
                $type="date";
			}
			if ($field=="Date") $this->Date=substr($this->$field,0,10); //$type="datetime-local";
			?><tr><td align=right><?php print $field?></td><td><?
			if ($this->tinyIntDescriptions[$field]){
				?><select name="<?php print $field?>"><?
					foreach($this->tinyIntDescriptions[$field] as $key=>$label){
						?><option value="<?php print $key?>"<?php print $key==$this->$field?" selected":""?>><?php print $key." - ".$label?></option><?
					}
					if (!$this->tinyIntDescriptions[$field][$this->$field]){
						?><option value="<?php print $this->$field?>" selected><?php print $this->$field." - Not Set"?></option><?
					}
					?></select><?
			}else{
				?><input type="<?php print $type?>" name="<?php print $field?>" value="<?php print $this->$field?>"/><?
			}	
		
			
			?></td></tr><?
			}
		?><tr><tr><td colspan=2>
		<button type="submit" class="primary" name="Function" value="Save">Save</button>
		<button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->$primaryKey){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?
        }
        ?>
		</td></tr>
		</table>
		</form><?
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