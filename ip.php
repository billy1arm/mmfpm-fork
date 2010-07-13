<?php
/*
 * Project Name: global Module for MiniManager for Mangos Server
 * Date: 17.10.2007 version (0.0.4)
 * Author: Den Wailhorn
 */
require_once("header.php");
valid_login($action_permission['read']);

/*--------------------------------------------------*/
 global $lang_global, $lang_spells, $output;

$output .= "<center><fieldset class=\"full_frame\">
<legend>".$lang_spells['search_ip']."</legend><br />
<table border=\"0\" class=\"hidden\"><tr>
<td>
<form method=\"post\">
<input type=\"text\" name=\"ip\" size=\"35\" value=\"$ip\"></td><td>";
 makebutton($lang_global['search'], "javascript:do_submit()",120);
$output .= "
</form></td>
</tr><tr><td colspan=\"2\" align=\"left\">".$lang_spells['whois']."<a target=\"_new\" href=\"http://nic.ru/whois/?query=$ip\">ru-center whois</a>
<br><br>
<pre>";
if ($ip!="")
{
$sock = fsockopen ("whois.ripe.net",43);
{
fputs ($sock, $ip."\r\n");
while (!feof($sock))
{
$output .= (str_replace(": ",":&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;",fgets ($sock,128)));
}
}
fclose ($sock);
}
$output .="</pre>
</td>
</tr>
</table></form></fieldset><br /><br /></center>";
/*--------------------------------------------------*/

require_once("footer.php");
?>
