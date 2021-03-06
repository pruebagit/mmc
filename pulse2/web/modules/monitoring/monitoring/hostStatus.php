 <?php
/**
 * (c) 2004-2007 Linbox / Free&ALter Soft, http://linbox.com
 * (c) 2007-2008 Mandriva, http://www.mandriva.com
 *
 * $Id$
 *
 * This file is part of Mandriva Management Console (MMC).
 *
 * MMC is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * MMC is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with MMC; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

// load ZabbixApi
require("modules/monitoring/includes/ZabbixApiAbstract.class.php");
require("modules/monitoring/includes/ZabbixApi.class.php");
require("modules/monitoring/includes/functions.php");
require_once("modules/monitoring/includes/xmlrpc.php");

require("graph/navbar.inc.php");
require("localSidebar.php");
//require_once("modules/pulse2/includes/utilities.php");

if (isset($_GET['apiId'])) {
    $apiId = $_GET['apiId'];
}
else {
    try {
        // connect to Zabbix API
        $api = new ZabbixApi(getZabbixUri()."/api_jsonrpc.php", getZabbixUsername(), getZabbixPassword());
        $apiId = $api->getApiAuth();
    }
    catch(Exception $e) {
        // Exception in ZabbixApi catched
        print $e->getMessage();
        return;
    }
}

if (isset($_GET['hostid'])) {
	$hostid = $_GET['hostid'];
} else if (isset($_GET['cn'])) {
	$hostid = $_GET['cn'];
} else {
	new NotifyWidgetFailure(_T("No host id or cn found!!!", "monitoring"));
	return;
}

$p = new PageGenerator('');
$p->setSideMenu($sidemenu);
$p->display();

try {
	// connect to Zabbix API
	$api = new ZabbixApi();
	$api->setApiUrl(getZabbixUri()."/api_jsonrpc.php");
	$api->setApiAuth($apiId);


	$hostGet = $api->hostGet(array(
			'output' => 'extend',
			'filter' => array('host' => $hostid)
	));

	$host = $hostGet[0]->hostid;

} catch(Exception $e) {

	// Exception in ZabbixApi catched
	new NotifyWidgetFailure(nl2br($e->getMessage()));
	return;
}

print '<h2>' . _T("Alerts", 'monitoring') . '</h2>';

$params = array(
    'apiId' => $apiId,
    'hostid' => $host
);


$ajax = new AjaxFilterLocationFormid(urlStrRedirect("monitoring/monitoring/ajaxMonitoringAlert"), 'divAlert', "alert", $params, "Alert");
$ajax->setElements(array($hostGet[0]->name));
$ajax->setElementsVal(array($host));
$ajax->setRefresh(60000);
$ajax->display();
echo "<br/><br/>";
$ajax->displayDivToUpdate();


print "<br/><br/><br/>";
print '<h2>' . _T("Graphics", 'monitoring') . '</h2>';

$params = array(
    'apiId' => $apiId,
    'hostid' => $host
);


$ajax = new AjaxLocation(urlStrRedirect("monitoring/monitoring/ajaxGraph"), "divGraph", "hostid", $params);
$ajax->setElements(array($hostGet[0]->name));
$ajax->setElementsVal(array($host));
$ajax->display();
$ajax->displayDivToUpdate();

/* Compatibility problem with the list selector

print "<br/><br/><br/>";
print '<h2>' . _T("History", 'monitoring') . '</h2>';

$params = array(
    'apiId' => $apiId,
    'hostid' => $host
);

$ajax = new AjaxFilterLocationFormid(urlStrRedirect("monitoring/monitoring/ajaxHistory"), 'divHist', "history", $params, 'Hist');
$ajax->setElements(array($hostGet[0]->name));
$ajax->setElementsVal(array($host));
$ajax->display();
echo "<br/><br/>";
$ajax->displayDivToUpdate();
*/


?>
