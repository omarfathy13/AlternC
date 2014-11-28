<?php
/*
  ----------------------------------------------------------------------
  AlternC - Web Hosting System
  Copyright (C) 2000-2012 by the AlternC Development Team.
  https://alternc.org/
  ----------------------------------------------------------------------
  LICENSE

  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License (GPL)
  as published by the Free Software Foundation; either version 2
  of the License, or (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  To read the license please visit http://www.gnu.org/copyleft/gpl.html
  ----------------------------------------------------------------------
  Purpose of file: Manage user quota
  ----------------------------------------------------------------------
*/

/**
 * Class for hosting quotas management
 *
 * This class manages services' quotas for each user of AlternC.
 * The available quotas for each service is stored in the system.quotas
 * mysql table. The used value is computed by the class using a
 * callback function <code>alternc_quota_check($uid)</code> that
 * may by exported by each service class.<br>
 * each class may also export a function <code>alternc_quota_names()</code>
 * that returns an array with the quotas names managed by this class.
 *
 */
class m_quota {

  var $disk=Array();  /* disk resource for which we will manage quotas */

  var $disk_quota_enable;
  var $quotas;
  var $clquota; // Which class manage which quota.


  /* ----------------------------------------------------------------- */
  /**
   * Constructor
   */
  function m_quota() {
    $this->disk_quota_enable = variable_get('disk_quota_enable', 1,'Are disk quota enabled for this server', array('desc'=>'Enabled','type'=>'boolean'));
    if ( $this->disk_quota_enable ) {
      $this->disk = Array( "web"=>"web" );
    }

  }

  private function dummy_for_translation() {
    _("quota_web");
  }

  function hook_menu() {
    $obj = array(
      'title'       => _("Show my quotas"),
      'ico'         => 'images/quota.png',
      'link'        => 'toggle',
      'pos'         => 110,
      'divclass'    => 'menu-quota',
      'links'       => array(),
     ) ;

    $q=$this->getquota();

    foreach ( array('web', 'bw_web') as $key ) {
      if ( ! isset($q[$key]["u"]) || empty($q[$key]["t"])) continue;
      $usage_percent = (int) ($q[$key]["u"] / $q[$key]["t"] * 100);
      $obj['links'][] = array( 'txt'=>_("quota_".$key) . " " . sprintf(_("%s%% of %s"),$usage_percent,format_size($q[$key]["t"]*1024)), 'url'=>($key == 'bw_web' ? 'stats_show_per_month.php' : 'quota_show.php') );
      $obj['links'][] = array( 'txt'=>'progressbar', 'total' => $q[$key]["t"], 'used' => $q[$key]["u"]);
    } 
  
     return $obj;
  }

  /* ----------------------------------------------------------------- */
  /** Check if a user can use a ressource.
   * @param string $ressource the ressource name (a named quota)
   * @Return TRUE if the user can create a ressource (= is there any quota left ?)
   * @return boolean
   */
  function cancreate($ressource="") {
    $t=$this->getquota($ressource);
    return $t["u"]<$t["t"];
  }


  /* ----------------------------------------------------------------- */
  /** List the quota-managed services in the server
   * @Return array the quota names and description (translated)
   */
  function qlist() {
    global $classes,$hooks;
    $qlist=array();
    reset($this->disk);
    while (list($key,$val)=each($this->disk)) {
      $qlist[$key]=_("quota_".$key); // those are specific disks quotas.
    }

    foreach($this->getquota() as $qq) {
      if (isset($qq['name'])) {
        $qlist[$qq['name']]=$qq['description'];
      }
    }
    return $qlist;
  }
  

  /**
    * Synchronise the quotas of the users with the quota of the
    * user's profile.
    * If the user have a greater quota than the profile, no change.
    * If the quota entry doesn't exist for the user, create it with
    * the defaults value.
    */
  function synchronise_user_profile() {
    global $db,$err;
    $err->log("quota","synchronise_user_profile");
    $q="insert into quotas select m.uid as uid, d.quota as name, d.value as total from membres m, defquotas d left join quotas q on q.name=d.quota  where m.type=d.type  ON DUPLICATE KEY UPDATE total = greatest(d.value, quotas.total);";
    if (!$db->query($q)) return false;
    return true;
  }

  /*
   * Create default quota in the profile
   * when a new quota appear
   *
   */
  function create_missing_quota_profile() {
    global $db,$quota,$err;
    $err->log("quota","create_missing_quota_profile");
    $qt=$quota->getquota('',true);
    $type=$quota->listtype();
    foreach($type as $t) {
      foreach($qt as $q=>$vv) {
      $db->query("INSERT IGNORE defquotas (value,quota,type) VALUES (0,'$q','$t');");
      }
    }
    return true;
  }

  /* ----------------------------------------------------------------- */
  /** Return a ressource usage (u) and total quota (t)
   * @param string $ressource ressource to get quota of
   * @Return array the quota used and total for this ressource (or for all ressource if unspecified)
   */
  function getquota($ressource="",$recheck=false) {
    global $db,$err,$cuid,$get_quota_cache,$hooks,$mem;
    $err->log("quota","getquota",$ressource);
    if ($recheck) { // rebuilding quota
      $get_quota_cache=null;
      $this->quotas=array();
    }
    if (! empty($get_quota_cache[$cuid]) ) {
      // This function is called many time each webpage, so I cache the result
      $this->quotas = $get_quota_cache[$cuid];
    } else {
	$res=$hooks->invoke("hook_quota_get");
	foreach($res as $r) {
          $this->quotas[$r['name']]=$r;
          $this->quotas[$r['name']]['u']=$r['used']; // retrocompatibilité
          $this->quotas[$r['name']]['t']=0; // Default quota = 0
	}
	reset($this->disk);

        if ($this->disk_quota_enable && !empty ($this->disk)) { // Check if there are some disk quota to check
          // Look if there are some cached value
          $disk_cached = $mem->session_tempo_params_get('quota_cache_disk');

          while (list($key,$val)=each($this->disk)) {
            $a=array(); 
            if ( 
              isset($disk_cached[$val]) 
              && !empty($disk_cached[$val]) 
              && $disk_cached[$val]['uid'] == $cuid
              && $disk_cached[$val]['timestamp'] > ( time() - (90) ) // Cache, en seconde
            ) {
              // If there is a cached value
              $a = $disk_cached[$val];
            } else {
              exec("/usr/lib/alternc/quota_get ".$cuid ,$ak);
              $a['u']=intval($ak[0]);
              $a['t']=@intval($ak[1]);
              $a['timestamp'] = time();
              $a['uid'] = $cuid;
              $disk_cached = $mem->session_tempo_params_set('quota_cache_disk', array($val=>$a));
            }
            $this->quotas[$val]=array("name"=>"$val", 'description'=>_("quota_".$val), "t"=>$a['t'],"u"=>$a['u']);
          }   
        }

        // Get the allowed quota from database.
        $db->query("select name, total from quotas where uid='$cuid';");
        while ( $db->next_record() ) {
          $this->quotas[$db->f('name')]['t']=$db->f('total');
        }

	$get_quota_cache[$cuid] = $this->quotas;
      }
      
    if ($ressource) {
      if (isset($this->quotas[$ressource]) ) {
	return $this->quotas[$ressource];
      } else {
	return 0;
      } 
    } else {
      return $this->quotas;
    }
  }
  
  
  /* ----------------------------------------------------------------- */
  /** Set the quota for a user (and for a ressource)
   * @param string $ressource ressource to set quota of
   * @param integer size of the quota (available or used)
   */
  function setquota($ressource,$size) {
    global $err,$db,$cuid;
    $err->log("quota","setquota",$ressource."/".$size);
    if (floatval($size)==0) $size="0";
    if (isset($this->disk[$ressource])) {
      // It's a disk resource, update it with shell command
      exec("sudo /usr/lib/alternc/quota_edit $cuid $size &> /dev/null &");
      // Now we check that the value has been written properly : 
      exec("sudo /usr/lib/alternc/quota_get $cuid &> /dev/null &",$a);
      if (!isset($a[1]) || $size!=$a[1]) {
	$err->raise("quota",_("Error writing the quota entry!"));
	return false;
      }
    }
    // We check that this ressource exists for this client :
    $db->query("SELECT * FROM quotas WHERE uid='$cuid' AND name='$ressource'");
    if ($db->num_rows()) {
      $db->query("UPDATE quotas SET total='$size' WHERE uid='$cuid' AND name='$ressource';");
    } else {
      $db->query("INSERT INTO quotas (uid,name,total) VALUES ('$cuid','$ressource','$size');");
    }
    return true;
  }


  /* ----------------------------------------------------------------- */
  /**
   * Erase all quota information about the user.
   */
  function delquotas() {
    global $db,$err,$cuid;
    $err->log("quota","delquota");
    $db->query("DELETE FROM quotas WHERE uid='$cuid';");
    return true;
  }


  /* ----------------------------------------------------------------- */
  /** Get the default quotas as an associative array
   * @return array the array of the default quotas
   */
  function getdefaults() {
    global $db;
    $c=array();

    $db->query("SELECT type,quota FROM defquotas WHERE type='default'");
    if(!$db->next_record())
      $this->addtype('default');

    $db->query("SELECT value,quota,type FROM defquotas ORDER BY type,quota");
    while($db->next_record()) {
      $type = $db->f("type");
      $c[$type][$db->f("quota")] = $db->f("value");
    }
    return $c;
  }


  /* ----------------------------------------------------------------- */
  /** Set the default quotas
   * @param array associative array of quota (key=>val)
   */
  function setdefaults($newq) {
    global $db;
    $qlist=$this->qlist();

    foreach($newq as $type => $quotas) {
      foreach($quotas as $qname => $value) {
	if(array_key_exists($qname, $qlist)) {
	  if(!$db->query("REPLACE INTO defquotas (value,quota,type) VALUES ($value,'$qname','$type');"))
	    return false;
	}
      }
    }
    return true;
  }
  

  /* ----------------------------------------------------------------- */
  /** Add an account type for quotas
   * @param string $type account type to be added
   * @return boolean true if all went ok
   */
  function addtype($type) {
    global $db,$err;
    $qlist=$this->qlist();
    if(empty($type)) return false;
    $type=strtolower($type);
    if (!preg_match("#^[a-z0-9]*$#",$type)) {
      $err->raise("quota", "Type can only contains characters a-z and 0-9");
      return false;
    }
    while (list($key,$val)=each($qlist)) {
      if(!$db->query("INSERT IGNORE INTO defquotas (quota,type) VALUES('$key', '$type');")
	 || $db->affected_rows() == 0)
	return false;
    }
    return true;
  }


  /* ----------------------------------------------------------------- */
  /** List for quotas
   * @return array
   */
  function listtype() {
    global $db;
    $db->query("SELECT distinct(type) FROM defquotas ORDER by type");
    $t=array();
    while ($db->next_record()) {
      $t[] = $db->f("type");
    }
    return $t;
  }


  /* ----------------------------------------------------------------- */
  /** Delete an account type for quotas
   * @param string $type account type to be deleted
   * @return boolean true if all went ok
   */
  function deltype($type) {
    global $db;
    
    if($db->query("UPDATE membres SET type='default' WHERE type='$type'") &&
       $db->query("DELETE FROM defquotas WHERE type='$type'")) {
      return true;
    } else {
      return false;
    }
  }

  
  /* ----------------------------------------------------------------- */
  /** Create default quotas entries for a new user.
   * The user we are talking about is in the global $cuid.
   */
  function addquotas() {
    global $db,$err,$cuid;
    $err->log("quota","addquota");
    $ql=$this->qlist();
    reset($ql);

    $db->query("SELECT type,quota FROM defquotas WHERE type='default'");
    if(!$db->next_record())
      $this->addtype('default');

    $db->query("SELECT type FROM membres WHERE uid='$cuid'");
    $db->next_record();
    $t = $db->f("type");
    
    foreach($ql as $res => $val) {
      $db->query("SELECT value FROM defquotas WHERE quota='$res' AND type='$t'");
      $q = $db->next_record() ? $db->f("value") : 0;
      $this->setquota($res, $q);
    }
    return true;
  }
  
  
  /* ----------------------------------------------------------------- */
  /** Return a quota value with its unit (when it is a space quota)
   * in MB, GB, TB ...
   * @param string $type The quota type
   * @param integer $value The quota value
   * @return string a quota value with its unit.
   */
  function display_val($type, $value) {
    switch ($type) {
    case 'bw_web':
      return format_size($value);
    case 'web':
      return format_size($value*1024);
    default:
      return $value;
    }
  }
  

  /* get size_xx function (filled by spoolsize.php) */
  function _get_sum_sql($sql) {
    global $db,$err,$cuid;
    $db->query($sql);
    if ($db->num_rows() == 0) {
      return -1;
    } else {
      $db->next_record();
      $r = $db->Record;
      return $r['sum'];
    }
  }
  
  function _get_count_sql($sql) {
    global $db,$err,$cuid;
    $db->query($sql);
    if ($db->num_rows() == 0) {
      return 0;
    } else {
      $db->next_record();
      $r = $db->Record;
      return $r['count'];
    }
  }

  function _get_size_and_record_sql($sql) {
    global $db,$err,$cuid;
    $db->query($sql);
    if ($db->num_rows() == 0) {
      return array();
    } else {
      $ret = array();
      while ($db->next_record()) {
        $ret[] = $db->Record;
      }
      return $ret;
    }
  }

  /* sum of websites sizes from all users */
  function get_size_web_sum_all() {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_web;");
  }

  /* sum of websites sizes from one user */
  function get_size_web_sum_user($u) {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_web WHERE uid='$u';");
  }

  /* sum of mailbox sizes from all domains */
  function get_size_mail_sum_all() {
    return $this->_get_sum_sql("SELECT SUM(bytes) AS sum FROM mailbox;");
  }

  /* sum of mailbox sizes for one domain */
  function get_size_mail_sum_domain($dom) {
    global $mail;
    return $mail->get_total_size_for_domain($dom);
  }

  /* count of mailbox sizes from all domains */
  function get_size_mail_count_all() {
    return $this->_get_count_sql("SELECT COUNT(*) AS count FROM mailbox;");
  }

  /* count of mailbox for one domain */
  function get_size_mail_count_domain($dom) {
    return $this->_get_count_sql("SELECT COUNT(*) AS count FROM dovecot_view WHERE user LIKE '%@{$dom}'");
  }

  /* get list of mailbox alias and size for one domain */
  function get_size_mail_details_domain($dom) {
    return $this->_get_size_and_record_sql("SELECT user as alias,quota_dovecot as size FROM dovecot_view WHERE user LIKE '%@{$dom}' ORDER BY alias;");
  }

  /* sum of mailman lists sizes from all domains */
  function get_size_mailman_sum_all() {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_mailman;");
  }

  /* sum of mailman lists sizes for one domain */
  function get_size_mailman_sum_domain($dom) {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_mailman WHERE list LIKE '%@{$dom}'");
  }

  /* sum of mailman lists for one user */
  function get_size_mailman_sum_user($u) {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_mailman WHERE uid = '{$u}'");
  }

  /* count of mailman lists sizes from all domains */
  function get_size_mailman_count_all() {
    return $this->_get_count_sql("SELECT COUNT(*) AS count FROM size_mailman;");
  }

  /* count of mailman lists for one user */
  function get_size_mailman_count_user($u) {
    return $this->_get_count_sql("SELECT COUNT(*) AS count FROM size_mailman WHERE uid = '{$u}'");
  }

  /* get list of mailman list and size for one user */
  function get_size_mailman_details_user($u) {
    return $this->_get_size_and_record_sql("SELECT s.size,CONCAT(m.list,'@',m.domain) as list FROM size_mailman s LEFT JOIN mailman m ON s.list=m.name WHERE s.uid='{$u}' ORDER BY s.list ASC");
  }

  /* sum of databases sizes from all users */
  function get_size_db_sum_all() {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_db;");
  }

  /* sum of databases sizes for one user */
  function get_size_db_sum_user($u) {
    return $this->_get_sum_sql("SELECT SUM(size) AS sum FROM size_db WHERE db = '{$u}' OR db LIKE '{$u}\_%'");
  }

  /* count of databases from all users */
  function get_size_db_count_all() {
    return $this->_get_count_sql("SELECT COUNT(*) AS count FROM size_db;");
  }

  /* count of databases for one user */
  function get_size_db_count_user($u) {
    return $this->_get_count_sql("SELECT COUNT(*) AS count FROM size_db WHERE db = '{$u}' OR db LIKE '{$u}\_%'");
  }

  /* get list of databases name and size for one user */
  function get_size_db_details_user($u) {
    return $this->_get_size_and_record_sql("SELECT db,size FROM size_db WHERE db='{$u}' OR db LIKE '{$u}\_%';");
  }

  /* Return appropriate value and unit of a size given in Bytes (e.g. 1024 Bytes -> return 1 KB) */
  function get_size_unit($size) {
    $units=array(1073741824=>_("GB"), 1048576=>_("MB"), 1024=>_("KB"), 0=>_("B"));
    foreach($units as $value=>$unit){
      if($size>=$value){
        $size=str_pad(round($size/($value ? $value : 1), 1), 5, ' ', STR_PAD_LEFT);
        return array('size'=>$size, 'unit'=>$unit);
      }
    }
  }

  // Affiche des barres de progression
  // color_type :
  //   0 = Pas de changement de couleur
  //   1 = Progression du vert vers le rouge en fonction du porcentage
  //   2 = Progression du rouge vers le vert en fonction du porcentage
  function quota_displaybar($usage, $color_type=1) {
    if ($color_type == 1) {
      $csscolor = " background-color:".PercentToColor($usage);
    } elseif ($color_type == 2) {
      $csscolor = " background-color:".PercentToColor(100-$usage);
    } else {
      $csscolor = "";
    }

    
    echo '<div class="progress-bar">';
    echo '<div class="barre" style="width:'.$usage.'%;'.$csscolor.'" ></div>';
    echo '<div class="txt">'.$usage.'%</div>';
    echo '</div>';
  }


  /* ==== Hook functions ==== */

  /* ----------------------------------------------------------------- */
  /** Hook function call when a user is deleted
   * AlternC's standard function called when a user is deleted
   * globals $cuid is the appropriate user
   */
  function hook_admin_del_member() {
    $this->delquotas();
  }


  /* ----------------------------------------------------------------- */
  /** Hook function called when a user is created
   * This function initialize the user's quotas.
   * globals $cuid is the appropriate user
   */
  function hook_admin_add_member() {
    global $err;
    $err->log("quota","hook_admin_add_member");
    $this->addquotas();
    $this->getquota('',true); // actualise quota
  }


  /* ----------------------------------------------------------------- */
  /** Exports all the quota related information for an account.
   * @access private
   * EXPERIMENTAL function ;) 
   */
  function alternc_export_conf() {
    global $db,$err;
    $err->log("quota","export");
    $str="  <quota>";

    $q=$this->getquota();
    foreach ($q as $k=>$v) {
      $str.=" <$k>\n";
      $str.="   <used>".($v["u"])."</used>\n";
      $str.="   <total>".($v["t"])."</total>\n";
      $str.=" </$k>\n";
    }
    $str.="</quota>\n";
    return $str;
  }


} /* Class m_quota */

