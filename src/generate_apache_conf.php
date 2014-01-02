#!/usr/bin/php -q
<?php

/**
  *
  * Generate Apache configuration for AlternC
  *
  * To force generation, /launch/generate_apache_conf.php force
  *
  * Return the number of vhost modified, return 0 if no action
  *
 **/

require_once("/usr/share/alternc/panel/class/config_nochk.php");
ini_set("display_errors", 1);


/*

FIXME : 
  - add security check
  - add hooks
  - make better retro compatibility

*/

// Check if we can modify Apache conf
if ( ! is_writable( ALTERNC_VHOST_FILE )) {
  die("Error: ".ALTERNC_VHOST_FILE." is not writable\n");
}

// Do we need to regenerate apache conf ?
$db->query("select count(*) as c from sub_domaines where web_action != 'OK';");
if (! $db->next_record()) $nb_todo = 0;
$nb_todo = $db->f('c');

// But, we may have forced it
if ( ! in_array('force', $argv) && $nb_todo < 1) {
  die('0');
}


// Generate apache conf
$conf = $dom->generate_apacheconf();

if (! $conf) {
  die("Error: generate empty configuration\n");
}

// Add some headers
$conf2 = "###BEGIN OF ALTERNC AUTO-GENERATED FILE - DO NOT EDIT MANUALLY###
# Generation: ".date('Y-m-d H:i:s')."
## LogFormat informations
Include \"/etc/alternc/apache_logformat.conf\"";

// Do we need to include manual configuration ?
if ( is_dir( ALTERNC_VHOST_MANUALCONF ) ) {
  $conf2.="\n## Manual VHOST\nInclude ".ALTERNC_VHOST_MANUALCONF."\n" ;
} else {
  $conf2.="\n## Manual VHOST directory missing (".ALTERNC_VHOST_MANUALCONF.")\n" ;
}

$conf2.="\n$conf\n\n###END OF ALTERNC AUTO-GENERATED FILE - DO NOT EDIT MANUALLY###\n";

// Write the conf !
if (! file_put_contents(ALTERNC_VHOST_FILE, $conf2) ) {
  die("Error: writing content\n");
}

echo $nb_todo;

