<?php
/*
 $Id: ftp_list.php,v 1.5 2003/06/10 13:16:11 root Exp $
 ----------------------------------------------------------------------
 AlternC - Web Hosting System
 Copyright (C) 2002 by the AlternC Development Team.
 http://alternc.org/
 ----------------------------------------------------------------------
 Based on:
 Valentin Lacambre's web hosting softwares: http://altern.org/
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
 Original Author of file: Benjamin Sonntag
 Purpose of file: List ftp accounts of the user.
 ----------------------------------------------------------------------
*/
require_once("../class/config.php");

$noftp=false;
if (!$r=$ftp->get_list($domain)) {
	$noftp=true;
	$error=$err->errstr();
}

include("head.php");
?>
</head>
<body id="ftp-account-list">
<h3><?php __("FTP accounts list"); ?></h3>
<?php
	if ($noftp) {
?>
	<p class="error"><?php echo $error ?></p>
	<a href="ftp_add.php"><?php __("Create a new ftp account") ?></a><br />
	<?php $mem->show_help("ftp_list_no"); ?>
	</body></html>
<?php
		exit();
	}

if ($error) {
?>
<p class="error"><?php echo $error ?></p>
<?php } ?>
<?php if ($quota->cancreate("ftp")) { ?>
<p class="add">
<a href="ftp_add.php"><?php __("Create a new ftp account"); ?></a>
</p>
<div id="help">
<?php  	}
$mem->show_help("ftp_list");
?></div>
<div class="delete"><input type="submit" name="submit" class="inb" value="<?php __("Delete checked accounts"); ?>" /></div>
<form method="post" action="ftp_del.php">

<?php
reset($r);
$col=1;
while (list($key,$val)=each($r))
	{
	$col=3-$col;
?>
    <dl class="ftp-account">
      <dt><input type="checkbox" class="inc" id="del_<?php echo
      $val["id"]; ?>" name="del_<?php echo $val["id"]; ?>" value="<?php
      echo $val["id"]; ?>" /><?php __("Username"); ?>:<strong> <label
      for="del_<?php echo $val["id"]; ?>"><?php echo $val["login"]
	  ?></label></strong></dt>
        <dd><span><?php __("Folder"); ?>: <code>/<?php echo $val["dir"] ?></code></span</dd>
	<dd class="edit"><a href="ftp_edit.php?id=<?php echo $val["id"] ?>"><?php __("Edit"); ?></a></dd>
	<dd><span></span></dd>
    </dl>

<?php
	}
?>
<div class="delete"><input type="submit" name="submit" class="inb" value="<?php __("Delete checked accounts"); ?>" /></div>
</form>
</body>
</html>
