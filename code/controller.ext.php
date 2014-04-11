<?php

/**
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@zpanelcp.com
 * @copyright (c) 2008-2011 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
class module_controller {

    static $complete;
    static $error;
    static $writeerror;
    static $nosub;
    static $alreadyexists;
    static $badname;
    static $blank;
    static $ok;
    static $renewerror;
    static $renewok;

    /**
     * The 'worker' methods.
     */
    static function ListDomains($uid = 0) {
        global $zdbh;
        if ($uid == 0) {
            $sql = "SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
            $numrows = $zdbh->prepare($sql);
        } else {
            $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':uid', $uid);
        }
        //$numrows = $zdbh->query($sql);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            if ($uid == 0) {
                $sql = $zdbh->prepare($sql);
            }else{
                $sql = $zdbh->prepare($sql);
                $sql->bindParam(':uid', $uid);
            }
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
		if(!isset($rowdomains['vh_expiry_ts'])){
                    $rowdomains['vh_expiry_ts'] = 0;
                }
                if(!isset($rowdomains['vh_invoice_created_yn'])){
                    $rowdomains['vh_invoice_created_yn'] = 0;
                }
                          
                array_push($res, array(
                    'uid' => $rowdomains['vh_acc_fk'],
                    'name' => $rowdomains['vh_name_vc'],
                    'directory' => $rowdomains['vh_directory_vc'],
                    'active' => $rowdomains['vh_active_in'],
                    'id' => $rowdomains['vh_id_pk'],
                    'expiry' => $rowdomains['vh_expiry_ts'],
                    'invoice_created_yn' => $rowdomains['vh_invoice_created_yn'],
                ));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListDomainDirs($uid) {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $res = array();
        $handle = @opendir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html");
        $chkdir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/";
        if (!$handle) {
            # Log an error as the folder cannot be opened...
        } else {
            while ($file = @readdir($handle)) {
                if ($file != "." && $file != ".." && $file != "_errorpages") {
                    if (is_dir($chkdir . $file)) {
                        array_push($res, array('domains' => $file));
                    }
                }
            }
            closedir($handle);
        }
        return $res;
    }

    static function ExecuteDeleteDomain($id) {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDeleteDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id");
        $sql->bindParam(':id', $id);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteDomain');
        return $retval;
    }

    static function ExecuteAddDomain($uid, $domain, $destination, $autohome) {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddDomain');
        $currentuser = ctrl_users::GetUserDetail($uid);
        $domain = strtolower(str_replace(' ', '', $domain));
        if (!fs_director::CheckForEmptyValue(self::CheckCreateForErrors($domain))) {
            //** New Home Directory **//
            if ($autohome == 1) {
                $destination = "/" . str_replace(".", "_", $domain);
                $vhost_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/" . $destination . "/";
                fs_director::CreateDirectory($vhost_path);
                fs_director::SetFileSystemPermissions($vhost_path, 0777);
                //** Existing Home Directory **//
            } else {
                $destination = "/" . $destination;
                $vhost_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/public_html/" . $destination . "/";
            }
            // Error documents:- Error pages are added automatically if they are found in the _errorpages directory
            // and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
            fs_director::CreateDirectory($vhost_path . "/_errorpages/");
            $errorpages = ctrl_options::GetSystemOption('static_dir') . "/errorpages/";
            if (is_dir($errorpages)) {
                if ($handle = @opendir($errorpages)) {
                    while (($file = @readdir($handle)) !== false) {
                        if ($file != "." && $file != "..") {
                            $page = explode(".", $file);
                            if (!fs_director::CheckForEmptyValue(self::CheckErrorDocument($page[0]))) {
                                fs_filehandler::CopyFile($errorpages . $file, $vhost_path . '/_errorpages/' . $file);
                            }
                        }
                    }
                    closedir($handle);
                }
            }
            // Lets copy the default welcome page across...
            if ((!file_exists($vhost_path . "/index.html")) && (!file_exists($vhost_path . "/index.php")) && (!file_exists($vhost_path . "/index.htm"))) {
                fs_filehandler::CopyFileSafe(ctrl_options::GetSystemOption('static_dir') . "pages/welcome.html", $vhost_path . "/index.html");
            }
            // If all has gone well we need to now create the domain in the database...
            $sql = $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk,
														 vh_name_vc,
														 vh_directory_vc,
														 vh_type_in,
														 vh_created_ts) VALUES (
														 :userid,
														 :domain,
														 :destination,
														 1,
														 :time)"); //CLEANER FUNCTION ON $domain and $homedirectory_to_use (Think I got it?)
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->bindParam(':userid', $currentuser['userid'] );
            $sql->bindParam(':domain', $domain);
            $sql->bindParam(':destination', $destination);
            $sql->execute();
            // Only run if the Server platform is Windows.
            if (sys_versions::ShowOSPlatformVersion() == 'Windows') {
                if (ctrl_options::GetSystemOption('disable_hostsen') == 'false') {
                    // Lets add the hostname to the HOSTS file so that the server can view the domain immediately...
                    @exec("C:/zpanel/bin/zpss/setroute.exe " . $domain . "");
                    @exec("C:/zpanel/bin/zpss/setroute.exe www." . $domain . "");
                }
            }
            self::SetWriteApacheConfigTrue();
            $retval = TRUE;
            runtime_hook::Execute('OnAfterAddDomain');
            return $retval;
        }
    }

    static function CheckCreateForErrors($domain) {
        global $zdbh;
        // Check for spaces and remove if found...
        $domain = strtolower(str_replace(' ', '', $domain));
        // Check to make sure the domain is not blank before we go any further...
        if ($domain == '') {
            self::$blank = TRUE;
            return FALSE;
        }
        // Check for invalid characters in the domain...
        if (!self::IsValidDomainName($domain)) {
            self::$badname = TRUE;
            return FALSE;
        }
        // Check to make sure the domain is in the correct format before we go any further...
        $wwwclean = stristr($domain, 'www.');
        if ($wwwclean == true) {
            self::$error = TRUE;
            return FALSE;
        }
        // Check to see if the domain already exists in ZPanel somewhere and redirect if it does....
        $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:domain AND vh_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domain', $domain);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() > 0) {
                self::$alreadyexists = TRUE;
                return FALSE;
            }
        }
        // Check to make sure user not adding a subdomain and blocks stealing of subdomains....
        // Get shared domain list
        $SharedDomains = array();
        $a = explode(',', ctrl_options::GetSystemOption('shared_domains'));
        foreach ($a as $b) {
            $SharedDomains[] = $b;
        }
        if (substr_count($domain, ".") > 1) {
            $part = explode('.', $domain);
            foreach ($part as $check) {
                if (!in_array($check, $SharedDomains)) {
                    if (strlen($check) > 3) {
                        $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc LIKE :check AND vh_type_in !=2 AND vh_deleted_ts IS NULL");
                        $checkSql = '%'.$check.'%';
                        $sql->bindParam(':check', $checkSql);
                        $sql->execute();
                        while ($rowcheckdomains = $sql->fetch()) {
                            $subpart = explode('.', $rowcheckdomains['vh_name_vc']);
                            foreach ($subpart as $subcheck) {
                                if (strlen($subcheck) > 3) {
                                    if ($subcheck == $check) {
                                        if (substr($domain, -7) == substr($rowcheckdomains['vh_name_vc'], -7)) {
                                            self::$nosub = TRUE;
                                            return FALSE;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return TRUE;
    }

    static function CheckErrorDocument($error) {
        $errordocs = array(100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207,
            300, 301, 302, 303, 304, 305, 306, 307, 400, 401, 402,
            403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413,
            414, 415, 416, 417, 418, 419, 420, 421, 422, 423, 424,
            425, 426, 500, 501, 502, 503, 504, 505, 506, 507, 508,
            509, 510);
        return in_array($error, $errordocs);
    }

    static function IsValidDomainName($a) {
        if (stristr($a, '.')) {
            $part = explode(".", $a);
            foreach ($part as $check) {
                if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $check) || preg_match('/-$/', $check)) {
                    return false;
                }
            }
        } else {
            return false;
        }
        return true;
    }

    static function IsValidEmail($email) {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }

    static function SetWriteApacheConfigTrue() {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx='true'
								WHERE so_name_vc='apache_changed'");
        $sql->execute();
    }

    static function IsvalidIP($ip) {
        return preg_match("^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}^", $ip) == 1;
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function getDomainList() {
        global $zdbh;

        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $domains = self::ListDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domains)) {
            foreach ($domains as $row) {
		$renew_yn = 0;
                if($row['expiry'] > 0){
                    $expiry = date("Y-m-d", ($row['expiry']));
                    if($expiry < date("Y-m-d")){
                        $row['active'] = '-1';
                    } else {
                        $datetime = date("Y-m-d");
                        $date_diff = strtotime($expiry) - strtotime($datetime);
                        $expiration_days = ($date_diff/86400);

                        if($expiration_days <= 7){
                            $renew_yn = 1;
                        }
                    }
                } else {
                    $expiry = 'Never';
                }
            
                $status = self::getDomainStatusHTML($row['active'], $row['id'],$renew_yn);
                
                if(($row['active'] == '-1' || $row['active'] == 1) && $row['invoice_created_yn']){
                    //invoice has been generated, display a payment link instead
                    //select invoice reference
                    $invoice = $zdbh->prepare("SELECT invoice_reference FROM zpanel_xbilling.x_invoices 
                                                   INNER JOIN zpanel_xbilling.x_invoices_orders ON 
                                                   zpanel_xbilling.x_invoices_orders.invoice_id=zpanel_xbilling.x_invoices.invoice_id
                                                   INNER JOIN zpanel_xbilling.x_orders ON 
                                                   zpanel_xbilling.x_orders.order_id=zpanel_xbilling.x_invoices_orders.order_id
                                                   WHERE zpanel_xbilling.x_orders.order_vh_fk=:id AND invoice_status='0' 
                                                   AND order_deleted_ts IS NULL 
                                                   ORDER BY zpanel_xbilling.x_invoices.invoice_id DESC;");
                    $invoice->bindParam(':id', $row['id']);
                    $invoice->execute();
                    $invoice_info = $invoice->fetch();
                    if(is_array($invoice_info)){                               
                        //get panel url
                 		$sql = "SELECT setting_value FROM zpanel_xbilling.x_settings 
                 		            WHERE setting_name='website_billing_url' AND reseller_ac_id_fk=:rid";
                        $bindArray = array(':rid'=>$currentuser['resellerid']);
                        $zdbh->bindQuery($sql, $bindArray);
                        $panel_info = $zdbh->returnRow(); 
                        
                        if(is_array($panel_info)){
                            if($row['active'] == '-1'){
                                $status = "<td><font color=\"red\">" . ui_language::translate("Expired") . "</font></td>";
                            } elseif($row['active'] == 1){
                                $status = "<td><font color=\"green\">" . ui_language::translate("Live") . "</font></td>";                            
                            }
                            $status .= "<td><button class=\"btn btn-warning\" type=\"button\" onclick=\"window.open('".$panel_info['setting_value']."/view_invoice.php?invoice=".$invoice_info['invoice_reference']."');\" >". ui_language::translate("Pay Invoice") ."</button></td>";
                        }
                    }
                }
                
                $res[] = array('name' => $row['name'],
                               'directory' => $row['directory'],
                               'active' => $row['active'],
                               'status' => $status,
                               'expiry' => $expiry,
                               'id' => $row['id']);
            }
            return $res;
        } else {
            return false;
        }
    }

    static function getCreateDomain() {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['domainquota'] < 0) or //-1 = unlimited
               ($currentuser['domainquota'] > ctrl_users::GetQuotaUsages('domains', $currentuser['userid']));
    }

    static function getDomainDirsList() {
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListDomainDirs($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domaindirectories)) {
            return $domaindirectories;
        } else {
            return false;
        }
    }

    static function doCreateDomain() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddDomain($currentuser['userid'], $formvars['inDomain'], $formvars['inDestination'], $formvars['inAutoHome'])) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    static function doDeleteDomain() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteDomain($formvars['inDelete'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    static function doConfirmDeleteDomain() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&id=" . $row['id'] . "&domain=" . $row['name'] . "");
                exit;
            }
        }
        return false;
    }

    static function getisDeleteDomain() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    static function getCurrentID() {
        global $controller;
        $id = $controller->GetControllerRequest('URL', 'id');
        $formvars = $controller->GetAllControllerRequests('FORM');

        if(!$id && isset($formvars['id'])){
            $id = $formvars['id'];
        }

        return ($id) ? $id : '';
    }

    static function getCurrentDomain() {
        global $controller, $zdbh;
        $domain = $controller->GetControllerRequest('URL', 'domain');
        $formvars = $controller->GetAllControllerRequests('FORM');

        if(!$domain && isset($formvars['id'])){
                    $sql = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk=:id");

                    //$sql->bindParam(':uid', $currentuser['userid']);
                    $sql->bindParam(':id', $formvars['id']);
                    $sql->execute();

                    if($domain_info = $sql->fetch()){
                        $domain = $domain_info['vh_name_vc'];
                    }
        }

        return ($domain) ? $domain : '';
    }
    
    
    static function doConfirmRenewDomain(){
        global $controller;
        
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $urlvars = $controller->GetAllControllerRequests('URL');
        
        if(isset($formvars['id'])){
            //runtime_csfr::Protect();
        }
        if(isset($formvars['id']) && (isset($urlvars['action']) && ($urlvars['action'] =='ConfirmRenewDomain' || ($urlvars['action'] =='RenewDomain' && self::$renewerror)))){
            return true;
        }
        return false;
    }
    
    static function getisRenewDomain(){
        return self::doConfirmRenewDomain();
    }
    
    static function getDomainPackageID($id){
        global $controller,$zdbh;
        
        $currentuser = ctrl_users::GetUserDetail();

        //first we try to fetch package information from a previous order if it exists, 
        //if not we use this user account's package id
            $sql = $zdbh->prepare("SELECT zpx_package_id FROM zpanel_xbilling.x_packages_periods 
                                    INNER JOIN zpanel_xbilling.x_orders ON 
                                    x_orders.package_period_id_fk=x_packages_periods.package_period_id 
                                    WHERE x_orders.order_vh_fk=:id AND ac_id_fk=:uid 
                                    AND order_deleted_ts IS NULL");
            $sql->bindParam(':id', $id);
            $sql->bindParam(':uid', $currentuser['userid']);
            $sql->execute();

            $package_info = $sql->fetch();            
            if(is_array($package_info)){
                $package_id = $package_info['zpx_package_id'];
            } else {
                //package couldn't be retrieved, use this user's package id instead
                $package_id = $currentuser['packageid'];
            }
        return $package_id;
    }
    
    static function getServicePeriods(){
        global $controller,$zdbh;
        
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');

        if(isset($formvars['id'])){
            $package_id = self::getDomainPackageID($formvars['id']);
            if(isset($package_id) && $package_id > 0){
                /*$periods = $zdbh->prepare("SELECT * FROM zpanel_xbilling.x_periods 
                                                WHERE reseller_ac_id_fk=:rid 
                                                AND period_deleted_ts IS NULL 
                                                ORDER BY period_duration ASC;");*/
               $periods = $zdbh->prepare("SELECT * FROM zpanel_xbilling.x_packages_periods 
                                            LEFT JOIN zpanel_xbilling.x_periods ON 
                                           zpanel_xbilling.x_periods.period_id=zpanel_xbilling.x_packages_periods.period_id 
                                           WHERE zpanel_xbilling.x_packages_periods.zpx_package_id=:package_id 
                                           AND package_amount>0 AND period_deleted_ts IS NULL
                                           ORDER BY zpanel_xbilling.x_periods.period_duration ASC;");
               $periods->bindParam(':package_id', $package_id);
                
                //$periods->bindParam(':rid', $currentuser['resellerid']);
               $periods->execute();
               $res = array();
                if (!fs_director::CheckForEmptyValue($periods)){
               		//get currency
               		$sql = "SELECT setting_value FROM zpanel_xbilling.x_settings 
                                WHERE setting_name='currency' AND reseller_ac_id_fk=:rid";
                    $bindArray = array(':rid'=>$currentuser['resellerid']);
                    $zdbh->bindQuery($sql, $bindArray);
                    $setting_info = $zdbh->returnRow();
                    
                    $currency = $setting_info['setting_value'];
                    
                    while ($row = $periods->fetch()) {
                       $duration = $row['period_duration'].' month';
                       if($row['period_duration'] > 1){
                        $duration .= 's';
                       }
                       
                       $label = $duration.' @ '.$row['package_amount'].' '.$currency;
                       array_push($res, array('label' => $label,
                                              'id' => $row['period_id']));  
                    }
                    return $res;
                } else {
                    return false;
                }            
                
                
            }

        }
    }
    
    static function doRenewDomain(){
        global $controller,$zdbh;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        
        if(isset($formvars['period_id']) && isset($formvars['id'])){
            if(fs_director::CheckForEmptyValue(self::ExecuteRenewDomain())){
              self::$renewerror = true;
              return false;  
            }
            
            self::$renewok = true;
            return true;            
        } else {
            self::$renewerror = true;
            return false;
        }
        //print_r($formvars);
        //exit;
        
    
    }

    static function ExecuteRenewDomain(){
        global $controller,$zdbh;

        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');

        
        if((isset($formvars['period_id']) && isset($formvars['id'])) && ((int) $formvars['period_id'] > 0 && (int) $formvars['id'] > 0) ){
            $datetime = date("Y-m-d H:i");
            $order_invoice_status = 0;
            $order_complete_dated = '0000-00-00 00:00';
            $domain_enabled_yn = 0;
            
            $package_id = self::getDomainPackageID($formvars['id']);

            //fetch order amount
            if($formvars['period_id'] > 0){
                $numrows = $zdbh->prepare("SELECT * FROM zpanel_xbilling.x_packages_periods 
                                            WHERE zpx_package_id=:pkg_id AND period_id=:pid;");
                $numrows->bindParam(':pkg_id', $package_id);
                $numrows->bindParam(':pid', $formvars['period_id']);
                $numrows->execute();
                $package_period = $numrows->fetch();
            } elseif($formvars['period_id'] == '-1') {
                $package_period['package_period_id'] = '-1';
                $package_period['package_amount'] = 0;
            }

            

            //fetch package name

            $numrows = $zdbh->prepare("SELECT pk_name_vc FROM zpanel_core.x_packages 
                                        INNER JOIN zpanel_xbilling.x_packages ON 
                                        zpanel_xbilling.x_packages.zpx_package_id=zpanel_core.x_packages.pk_id_pk
                                        WHERE zpanel_xbilling.x_packages.reseller_ac_id_fk=:rid 
                                        AND zpanel_xbilling.x_packages.zpx_package_id=:pkg_id;");

            $numrows->bindParam(':rid', $currentuser['resellerid']);
            $numrows->bindParam(':pkg_id', $package_id);
            $numrows->execute();
            $package = $numrows->fetch();
            
            $order_desc = $package['pk_name_vc'];

            //fetch period duration
            if($formvars['period_id'] > 0){
                $numrows = $zdbh->prepare("SELECT period_duration FROM zpanel_xbilling.x_periods 
                                            WHERE reseller_ac_id_fk=:uid AND period_id=:pid");
                $numrows->bindParam(':uid', $currentuser['resellerid']);

                //$numrows->bindParam(':pkg_id', $data['package_id']);

                $numrows->bindParam(':pid', $formvars['period_id']);

                $numrows->execute();
                $period = $numrows->fetch();

            } elseif($formvars['period_id'] == '-1') {
                $period['period_duration'] = 0;

            }
            

            if(isset($period['period_duration'])){
                if($period['period_duration'] > 0){

                    $order_desc .= ' Hosting Package for '.$period['period_duration'].' Month';
                    if($period['period_duration'] > 1){
                        $order_desc .= 's';

                    }
                } elseif($period['period_duration'] == 0){
                    $order_desc .= ' - Free Hosting';

                    $order_invoice_status = 1;
                    $order_complete_dated = date("Y-m-d H:i");

                    $domain_enabled_yn = 1;

                }
                $order_desc .= ' (Renewal)';

            }

          //fetch previous order(voucher) information
          $voucher_id = 0;
          $numrows = $zdbh->prepare("SELECT zpanel_xbilling.x_vouchers.* FROM zpanel_xbilling.x_orders INNER JOIN zpanel_xbilling.x_invoices_orders 
                                      ON zpanel_xbilling.x_invoices_orders.order_id=zpanel_xbilling.x_orders.order_id 
                                      INNER JOIN zpanel_xbilling.x_invoices 
                                      ON zpanel_xbilling.x_invoices.invoice_id=zpanel_xbilling.x_invoices_orders.invoice_id
                                      INNER JOIN zpanel_xbilling.x_vouchers 
                                      ON zpanel_xbilling.x_vouchers.voucher_id=zpanel_xbilling.x_invoices.invoice_voucher_id
                                      WHERE zpanel_xbilling.x_orders.order_vh_fk=:domain_id 
                                      AND zpanel_xbilling.x_orders.order_deleted_ts IS NULL 
                                      AND zpanel_xbilling.x_invoices.invoice_voucher_id > 0 
                                      AND zpanel_xbilling.x_vouchers.active_yn = 1
                                      ORDER BY zpanel_xbilling.x_orders.order_id DESC LIMIT 1;");
          $numrows->bindParam(':domain_id', $formvars['id']);
          $numrows->execute();
          $prv_order_info = $numrows->fetch();
          //die(var_dump($prv_voucher_info));

          if(isset($prv_order_info['voucher_code']) && $prv_order_info['voucher_code'] != ''){
            //is this a recurring discount voucher?
            if($prv_order_info['discount_type'] == 2){
                $voucher_id = $prv_order_info['voucher_id'];
            }
          }

          //die(var_dump($voucher_id));
            
            $sql = $zdbh->prepare("INSERT INTO zpanel_xbilling.x_orders (ac_id_fk,

                                    reseller_ac_id_fk,order_vh_fk,order_dated,order_amount, order_desc,
									package_period_id_fk,order_status,order_complete_dated

									) VALUES (:userID,:rid,:domain_id,:date,:amount,:desc,:pkg_pid,
									'".$order_invoice_status."', '".$order_complete_dated."')");
            $sql->bindParam(':userID', $currentuser['userid']);
            $sql->bindParam(':rid', $currentuser['resellerid']);
            $sql->bindParam(':date', $datetime);
            $sql->bindParam(':amount', $package_period['package_amount']);
            $sql->bindParam(':desc', $order_desc);
            $sql->bindParam(':pkg_pid', $package_period['package_period_id']);
            $sql->bindParam(':domain_id', $formvars['id']);
            $sql->execute();
            

            //fetch newly created order id
            $numrows = $zdbh->prepare("SELECT order_id FROM zpanel_xbilling.x_orders 
                                        WHERE reseller_ac_id_fk=:rid AND ac_id_fk=:uid 
                                        AND order_dated=:datetime");
            $numrows->bindParam(':uid', $currentuser['userid']);
            $numrows->bindParam(':rid', $currentuser['resellerid']);
            $numrows->bindParam(':datetime', $datetime);
            $numrows->execute();
            $order = $numrows->fetch();

            if(isset($order['order_id'])){
                $order_id = $order['order_id'];
                $invoice_reference = self::randomString(7);                


                $sql = $zdbh->prepare("INSERT INTO zpanel_xbilling.x_invoices (

                                        reseller_ac_id_fk,invoice_dated,
                                        invoice_total_amount, invoice_reference,
                                        invoice_status,ac_id_fk,invoice_voucher_id

									    ) VALUES (:rid,:date,:amount,
									    :ref, '".$order_invoice_status."',:uid,:vid)");

                $sql->bindParam(':uid', $currentuser['userid']);
                $sql->bindParam(':rid', $currentuser['resellerid']);
                $sql->bindParam(':date', $datetime);
                $sql->bindParam(':amount', $package_period['package_amount']);
                $sql->bindParam(':ref', $invoice_reference);
                $sql->bindParam(':vid', $voucher_id);
                $sql->execute();



                //fetch newly created invoice id
                $numrows = $zdbh->prepare("SELECT invoice_id FROM zpanel_xbilling.x_invoices 
                                            WHERE reseller_ac_id_fk=:rid AND ac_id_fk=:uid 
                                            AND invoice_reference=:ref");

                $numrows->bindParam(':uid', $currentuser['userid']);
                $numrows->bindParam(':rid', $currentuser['resellerid']);
                $numrows->bindParam(':ref', $invoice_reference);
                $numrows->execute();

                $invoice = $numrows->fetch();               

                if(isset($invoice['invoice_id'])){
                    //create invoice and order relationship                    
                    $sql = $zdbh->prepare("INSERT INTO zpanel_xbilling.x_invoices_orders (
                                            invoice_id,order_id
                                            ) VALUES ('".$invoice['invoice_id']."',
                                            '".$order['order_id']."')");
                    $sql->execute();
                }                                
            }
            
            if($domain_enabled_yn == 1){
                if(isset($period['period_duration'])){
                    //if domain has expired, start counting from today's date
                    $payment_date = $datetime;
                    // else start counting from current expiration date
                    $sql = $zdbh->prepare("SELECT vh_expiry_ts FROM x_vhosts 
                                                WHERE vh_acc_fk=:uid AND vh_id_pk=:id");

                    $sql->bindParam(':uid', $currentuser['userid']);
                    $sql->bindParam(':id', $formvars['resellerid']);
                    $sql->execute();
                    $domain_info = $numrows->fetch();

                    $current_expiration_date = $domain_info['vh_expiry_ts'];
                    if($current_expiration_date > 0){
                        $expiry = date("Y-m-d", $current_expiration_date);
                        if($expiry > date("Y-m-d")){
                            $payment_date = $expiry;
                        }
                    } else {
                        $payment_date = 0;
                    }
                    
                    if($payment_date > 0){
                        $new_expiry_date = strtotime($payment_date."+".$period['period_duration']." months");
                    }
                    
                    $sql = $zdbh->prepare("UPDATE zpanel_core.x_vhosts 
                                             SET vh_expiry_ts=:date,vh_enabled_in='1' 
                                             WHERE vh_id_pk=:domain_id");
                    $sql->bindParam(':date', $new_expiry_date);
                    $sql->bindParam(':domain_id', $formvars['id']);
                    $sql->execute();                                    
                 }
            }
            
            //update invoice created
              $sql = $zdbh->prepare("UPDATE zpanel_core.x_vhosts SET vh_invoice_created_yn='1' 
                                        WHERE vh_id_pk=:domain_id");
              $sql->bindParam(':domain_id', $formvars['id']);
              $sql->execute();                                    
            
            
            self::$renewok = true;
            return true;            
        } else {
            self::$renewerror = true;
            return false;
        }
    }  
    
    static function randomString($length = 10){      
        $chars = 'BCDFGHJKLMNPQRSTVWXYZAEIU23456789';
        $str = '';
        for ($i=0; $i<$length; $i++){
            $str .= ($i%2) ? $chars[mt_rand(19, 25)] : $chars[mt_rand(0, 18)];
        }

        return $str;
    }    

    static function getCSFR_Tag() {
        return runtime_csfr::Token();
    }

    static function getModuleName() {
        $module_name = ui_module::GetModuleName();
        return $module_name;
    }

    static function getModuleIcon() {
        global $controller;
        return '/modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/icon.png';
    }

    static function getModuleDesc() {
        return ui_language::translate(ui_module::GetModuleDescription());
    }

    static function getDomainUsagepChart() {
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['domainquota'];
        if ($maximum < 0) { //-1 = unlimited
           return '<img src="'. ui_tpl_assetfolderpath::Template().'images/unlimited.png" alt="'.ui_language::translate('Unlimited').'"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('domains', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return  '<img src="etc/lib/pChart2/zpanel/z3DPie.php?score=' . $free . '::' . $used
                  . '&labels=Free: ' . $free . '::Used: ' . $used
                  . '&legendfont=verdana&legendfontsize=8&imagesize=240::190&chartsize=120::90&radius=100&legendsize=150::160"'
                  . ' alt="'.ui_language::translate('Pie chart').'"/>';
        }
    }

    static function getDomainStatusHTML($int, $id, $renew_yn=0) {
        global $controller;
        if ($int == 1) {
            /*return '<td><font color="green">' . ui_language::translate('Live') . '</font></td>'
                 . '<td></td>';*/
            $html = "<td><font color=\"green\">" . ui_language::translate("Live") . "</font></td><td>";
            if($renew_yn == 1){
                $html .= "<button class=\"btn btn-warning\" type=\"button\" onclick=\"renew_domain('".$id."');\" >". ui_language::translate("Renew") ."</button>";
            }
            $html .= "</td>";
            return $html;
        } elseif ($int == '-1') {
            return "<td><font color=\"red\">" . ui_language::translate("Expired") . "</font></td><td><button class=\"btn btn-warning\" type=\"button\" onclick=\"renew_domain('".$id."');\" >". ui_language::translate("Renew") ."</button></td>";                 
        } else {
            return '<td><font color="orange">' . ui_language::translate('Pending') . '</font></td>'
                 . '<td><a href="#" class="help_small" id="help_small_' . $id . '_a"'
                 . 'title="' . ui_language::translate('Your domain will become active at the next scheduled update.  This can take up to one hour.') . '">'
                 . '<img src="/modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/help_small.png" border="0" /></a>';
        }
    }

    static function getResult() {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain can not be empty. Please enter a valid Domain Name and try again."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain name is not valid. Please enter a valid Domain Name: i.e. 'domain.com'"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("The domain already appears to exist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$nosub)) {
            return ui_sysmessage::shout(ui_language::translate("You cannot add a Sub-Domain here. Please use the Subdomain manager to add Sub-Domains."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("Please remove 'www'. The 'www' will automatically work with all Domains / Subdomains."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$writeerror)) {
            return ui_sysmessage::shout(ui_language::translate("There was a problem writting to the virtual host container file. Please contact your administrator and report this error. Your domain will not function until this error is corrected."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your domain web hosting has been saved successfully."), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}

?>
