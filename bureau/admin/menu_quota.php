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
*/
?>
<div class="menu-box menu-quota">
<a href="quota_show.php">
  <div class="menu-title">
    <img src="images/quota.png" alt="<?php __("Show my quotas"); ?>" />&nbsp;<?php __("Show my quotas"); ?>
    <img src="images/menu_right.png" alt="" style="float:right;" class="menu-right"/>
  </div>
</a>
  <?php
  $q=$quota->getquota();

  $qlist=$quota->qlist();
  reset($qlist);
  $col=1;


  if (!is_array($q) || empty($q) ) {
    // "No quotas for this account, or quotas currently unavailable
    return;
  }

  $first=true;
  while (list($key,$val)=each($qlist)) {
    $col=3-$col;

    // FIXME: rewrite it
    if (($key == 'bw_web' || $key == 'web') && (isset($q[$key]["t"]) && $q[$key]["t"] > 0)) {
  /*
      // Quite useless, so i commented it out
      if ($first) {
        echo '<dt id="#quotas">' . _("Quotas") . '</dt>';
        $first=false;
      }
  */

      if ($key == 'web') {
        $q[$key]["u"] = $q[$key]["u"] * 1024;
        $q[$key]["t"] = $q[$key]["t"] * 1024;
      }

      $usage_percent = (int) ($q[$key]["u"] / $q[$key]["t"] * 100);
      $usage_color = ($q[$key]["u"] > $q[$key]["t"] ? '#800' : '#080');
      $usage_color = ((85 < $usage_percent && $usage_percent <= 100) ? '#ff8800' : $usage_color); // yellow

      $url = ($key == 'bw_web' ? 'stats_show_per_month.php' : 'quota_show.php');

      echo '<ul><li>';
      echo '<div><a href="' . $url . '">' . /* _($val) */  _("quota_".$key) . " " . sprintf(_("%s%% of %s"),$usage_percent,format_size($q[$key]["t"])) . '</a></div>';
      echo "</li>\n";
      echo '<li>';
      echo '<div class="progress-bar">';
      echo '<div style="width: ' . ($usage_percent > 100 ? 100 : $usage_percent) . '%; background: ' . $usage_color . ';">&nbsp;</div>';
      echo '</div>';
      echo '</li></ul>';
    }
  }
  ?>
  <div style="height: 5px;">&nbsp;</div>
</div>