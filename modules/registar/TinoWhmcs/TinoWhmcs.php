<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once 'libs/core.php';

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\Registrarmodule\ApiClient;
use WHMCS\Database\Capsule;

    function TinoWhmcs_getConfigArray() {
        $configarray = array(
            "Username" => array(
                "Type" => "text",
                "Size" => "50",
                "Description" => "Enter your username here"
            ),
            "Password" => array(
                "Type" => "password",
                "Size" => "50",
                "Description" => "Enter your password here"
            ),
            "TestMode" => array(
                "Type" => "tickbox",
                "Options" => "1, 2",
                "Description" => "Test or Live"
            ),
        );
        return $configarray;
    }

    function TinoWhmcs_RegisterDomain($params = []) {

        try {
            $core = new CoreTNWhmcsReseller($params);

            $result = $core->Register();


          // if(!$result['success']){
          //     return ["error" => explode('|', $result)];
          // }


            if ($result['error']) {
                return ["error" => $result['error'] ? implode(' | ', $result['error']) : "Wrong response from the server while registering domai1."];
            } else {
                return true;
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_TransferDomain($params = []) {

        try {

            $core = new CoreTNWhmcsReseller($params);
            $result = $core->Transfer();

            if ($result['order_id'] == '' || !$result['success']) {
                return ["error" => $result['error'] ? implode(' | ', $result['error']) : "Wrong response from the server while transfering domain."];
            } else {
                return [];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_RenewDomain($params = []) {
        try {

            $core = new CoreTNWhmcsReseller($params);

            $result = $core->Renew();

            if ($result['order_num'] == '' || $result['error']) {
                return ["error" =>  $result['error'] ? implode(' | ', $result['error']) :  "Wrong response from the server while transfering domain."];
            } else {
                return array('success' => 'Domain Renewal Success');
            }
            // return ["error" => "This action is not available for this Domain Registrant"];
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_GetEPPCode($params = []) {
        try {

            $core = new CoreTNWhmcsReseller($params);
            $result = $core->getEppCode();

            if (empty($result) || !$result['success']) {
                return ["error" =>  $result['error'] ? implode(' | ', $result['error']) :  "Wrong response from the server while obtaining domain information."];
            } else {
                return $result['epp_code'];
            }


        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_Sync($params = []) {
        try {
            $core = new CoreTNWhmcsReseller($params);
            $result = $core->synchInfo();
            if (empty($result) || !$result['success']) {
                return ["error" => $result['error'] ?: "Wrong response from the server while obtaining domain information."];
            } else {
                return [
                    "active" => ($result['status'] == 'Active' ? true : false),
                    "expirydate" => $result["expires"],
                ];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_GetRegistrarLock($params = []) {
        try {
           ;
            $core = new CoreTNWhmcsReseller($params);
            $result = $core->getRegistrarLock();
            if (!$result['success']) {
                    return ["error" => $result['error'] ?: "Wrong response from the server while obtaining registrar lock status."];
                } else {
                    return ($result['registrar_lock'] ? 'locked' : 'unlocked');
                }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_SaveRegistrarLock($params = []) {
        try {

            $core = new CoreTNWhmcsReseller($params);
            $result = $core->updateRegistrarLock();
        if (!$result['success']) {
                return ["error" => $result['error'] ?: "Wrong response from the server while updating registrar lock."];
            } else {
                return [];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_GetContactDetails($params = []) {
        try {

            $core = new CoreTNWhmcsReseller($params);

            $result = $core->getContactInfo();

        if (empty($result) || !$result['success']) {
                return ["error" =>  $result['error'] ? implode(' | ', $result['error']) :  "Wrong response from the server while obtaining domain contact information."];
            } else {
                return $result['items'];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_SaveContactDetails($params = []) {
        try {
            $core = new CoreTNWhmcsReseller($params);
            $result = $core->updateContactInfo();
        if (!$result['success']) {
                return ["error" =>  $result['error'] ? implode(' | ', $result['error']) :  "Wrong response from the server while updating domain contact information."];
            } else {
                return [];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_GetDNS($params = []) {
        try {

            $core = new CoreTNWhmcsReseller($params);
            $result = $core->getDNSManagement();
        if (empty($result) || !$result['success']) {
                return ["error" => $result['error'] ?: "Wrong response from the server while obtaing DNS records."];
            } else {
                return $result['items'];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_SaveDNS($params = []) {
        try {

            $core = new CoreTNWhmcsReseller($params);
            return ["error" => "This action is not available for this Domain Registrant"];
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_GetNameservers($params = []) {
        try {
            $core = new CoreTNWhmcsReseller($params);
            $result = $core->getNameServers();

            if (!$result || $result['error']) {
                return ["error" => $result['error'] ? implode(' | ', $result['error']) : "Wrong response from the server while obtaining domain name servers."];
            } else {
                return $result['items'];
            }
        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
    function TinoWhmcs_SaveNameservers($params = []) {
        try {
            $core = new CoreTNWhmcsReseller($params);
            $result = $core->updateNameServers();
            if (empty($result) || !$result['success']) {
                return ["error" => $result['error'] ? implode(' | ', $result['error']) : "Wrong response from the server while obtaining domain name servers."];
            } else {
                return $result['items'];
            }

        } catch (\Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }
