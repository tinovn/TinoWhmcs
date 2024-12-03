<?php

class CoreTNWhmcsReseller
{

    /**
     * @var string
     */
    protected $version = '2.2022-05-23';

    /**
     * @var string
     */
    private $host = [
      'live' => 'https://api.tino.vn',
      'test' => 'https://ote.tino.org/api',
    ];

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var string
     */
    private $encrypt_key = '205f42277c5f5d6c2153217e7b386569';


    public $configuration = array(
        'Username' => array(
            'value' => '',
            'type' => 'input',
            'default' => false
        ),
        'Password' => array(
            'value' => '',
            'type' => 'password',
            'default' => false
        ),
    );

    public function __construct($params = [])
    {

        $this->options = $params;
        $this->configuration['Username']['value'] = $params['Username'];
        $this->configuration['Password']['value'] = $params['Password'];
        $this->configuration['TestMode']['value'] = $params['TestMode'];

    }

    private function api($url, $method = 'GET', $params = null)
    {

        if (
            empty($this->configuration['Username']['value']) ||
            empty($this->configuration['Password']['value'])
        ) {
            throw new Exception('Please check module configuration');
            return false;
        }

        $live = $this->configuration['TestMode']['value'] != 1;

        $endpoint = !$live ? $this->host['test'] : $this->host['live'];

        $fullUrl = $endpoint . $url;
        $header = array(
            "cache-control: no-cache"
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_USERPWD, $this->configuration['Username']['value'] . ":" . $this->configuration['Password']['value']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method != 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $details = json_decode($response,true);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // $msg = null;
        // if (!empty($err)) {
        //     $msg = $err;
        // } elseif (empty($details)) {
        //     $msg = 'Uncaught error. Please try again or contact support';
        // } elseif (!empty($details['error']) && $details['success'] !== true) {
        //     if (is_array($details['error'])) {
        //         $msg = $details['error'][0];
        //     } else {
        //         $msg = $details['error'];
        //     }
        // } elseif (array_key_exists('success', $details)) {
        //     if ($details['success'] !== true) {
        //         $msg = 'Uncaught error. Please try again or contact support';
        //     }
        // }
        // if ($msg !== null) {
        //     // throw new Exception([$msg]);
        //     return $msg;
        // }
        return $response;

    }

    /**
     * default type: registrant
     *
     * @param string $type
     * @param array $cdata
     * @return array
     */
    public function makeContact($type = '')
    {
      if($type == 'tech'|| $type == 'billing'){
        $type = 'admin';
      }
        $cdata = $this->options;

        $gender = $cdata['additionalfields']['owner_gender'] == 'Nam' ? 'Male' : 'Female';

        if($cdata['additionalfields']['owner_name']) {
          $fullname =$cdata['additionalfields']['owner_name'];
          $fullname_array = explode(' ', $fullname, 2);
          $cdata[$type . 'firstname'] =   $fullname_array[1];
          $cdata[$type . 'lastname'] =   $fullname_array[0];
        }
          $contact_type = 'ind';
          if($cdata['additionalfields']['owner_type'] == 1 && $type == '') {
            $contact_type = 'org';
          }

        $contact = array(
            "type" =>   $contact_type,
            "firstname" => $cdata[$type . 'firstname'],
            "lastname" => $cdata[$type . 'lastname'],
            "address1" => $cdata[$type . 'address1'],
            "city" => $cdata[$type . 'city'],
            "postcode" => $cdata[$type . 'postcode'],
            "state" => $cdata[$type . 'state'],
            "country" => $cdata[$type . 'country'],
            "email" => $cdata[$type . 'email'],
            "phonenumber" => $cdata[$type . 'phonenumber'],
            "ward" => $cdata['additionalfields']['owner_city'],
            "birthday" => $cdata['additionalfields']['owner_birthday'],
            "nationalid" => $cdata['additionalfields']['owner_person_id'],
            "gender" => $gender
        );
        if( $contact_type == 'org' &&  $type == ''){
          $contact["companyname"] = $cdata['additionalfields']['congty_ten'];
          $contact["taxid"] = $cdata['additionalfields']['congty_mst'];
        }


        return $contact;
    }

    /**
     * @return mixed
     */
    public function Register()
    {
        try {
            $i = 1;
            $j = 1;
            while (isset($this->options["ns{$i}"])) {
                if ($this->options["ns{$i}"] != '') {
                    $ns[] = $this->options["ns{$i}"];
                    $j++;
                }
                $i++;
            }
            $url = "/domain/order";
            $params['action'] = 'register';
            $params['name'] = $this->options['sld'] . '.' . $this->options['tld'];
            $params['years'] = $this->options['regperiod'];
            $params['nameservers'] = (!empty($ns) ? $ns : null);
            $params['registrant'] = $this->makeContact();
            $params['admin'] = $this->makeContact('admin');
            $params['tech'] = $this->makeContact('tech');
            $params['billing'] = $this->makeContact('billing');

            $result = $this->api($url, 'POST', $params);

            return json_decode($result, true);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     * @return mixed
     */
    public function Transfer()
    {

        // try {
            $i = 1;
            $j = 1;
            while (isset($this->options["ns{$i}"])) {
                if ($this->options["ns{$i}"] != '') {
                    $ns[] = $this->options["ns{$i}"];
                    $j++;
                }
                $i++;
            }
            $url = "/domain/order";
            $params['action'] = 'transfer';
            $params['name'] = $this->options['sld'] . '.' . $this->options['tld'];
            $params['years'] = $this->options['regperiod'];
            $params['epp'] = $this->options['eppcode'];
            $params['nameservers'] = (!empty($ns) ? $ns : null);
            $params['registrant'] = $this->makeContact();
            $params['admin'] = $params['tech'] = $params['billing'] = $this->makeContact('admin');
            $result = $this->api($url, 'POST', $params);

            return json_decode($result, true);

        // } catch (Exception $e) {
        //     return ['success' => false, 'error' => $e->getMessage()];
        // }
    }

    /**
     *
     * TODO
     *
     * @return mixed
     */
    public function Renew()
    {

        $domainName = $this->options['sld'] . '.' . $this->options['tld'];
        $domain = $this->getDomainByName();
        if(!$domain){
            return ['error' => ['Domain not found']];
        }

        if (in_array($domain['status'], ['Active', 'Expired'])) {
          $url = '/domain/' . $domain['id'] . '/renew';
          $postfields = [
            'id' => $domain['id'],
            'years' => $this->options['regperiod'],
            'pay_method' => 1,
          ];
          $result = $this->api($url, 'POST', $postfields);

          if (empty($result))
               return false;

          if ($result == 'insufficient_credit') {
            return ['error' => $result];
          }
          return json_decode($result, true);

        } else {
          return [
            'error' => 'Domain status is not available for renew. Please contact admin'
          ];
        }



    }

    /**
     *
     *
     * @return mixed
     */
    public function getNameServers()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $url = "/domain/" . $domain['id'] . "/ns";
            $result = $this->api($url);
            $result = json_decode($result, true);
            if($result['error'])
              return $result;

            $return['success'] = $result['success'];
            $i = 1;
            foreach ($result['nameservers'] as $key => $value) {
                $return['items']['ns' . $i] = $value;
                $i++;
            }
            return $return;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function updateNameServers()
    {
        try {
            $domain = $this->getDomainByName();

            if(!$domain){
                return ['error' => ['Domain not found']];
            }


            $i = 1;
            while (isset($this->options["ns{$i}"])) {
                $ns = $this->options["ns{$i}"];
                $nss[] = $ns;
                $i++;
            }
            $url = "/domain/" . $domain['id'] . "/ns";
            $params['nameservers'] = $nss;
            $result = $this->api($url, 'PUT', $params);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }



    /**
     * helper
     *
     * @param $type
     * @param $params
     * @return mixed
     */
    private function getContact($type, $params)
    {
        // $values["Type"] = $params["contact_info"][$type]['type'];
        if($params["contact_info"][$type]['name']){
          $values["Full Name"] = $params["contact_info"][$type]['name'];
        } elseif($params["contact_info"][$type]['companyname']) {
          $values['Full Name'] = $params["contact_info"][$type]['companyname'];
        } else {
          $values["Full Name"] = $params["contact_info"][$type]['lastname'] . ' ' . $params["contact_info"][$type]['firstname'];
        }

        $values["Address"] = $params["contact_info"][$type]['address1'];
        $values["Ward"] = $params["contact_info"][$type]['ward'];
        $values["City"] = $params["contact_info"][$type]['city'];
        $values["State"] = $params["contact_info"][$type]['state'];
        $values["Postcode"] = $params["contact_info"][$type]['postcode'];
        $values["Country"] = $params["contact_info"][$type]['country'];
        $values["Phone"] = $params["contact_info"][$type]['phonenumber'];
        $values["Fax"] = $params["contact_info"][$type]['phonenumber'];
        $values["Email"] = $params["contact_info"][$type]['email'];
        $values["Nationalid"] = $params["contact_info"][$type]['nationalid'];
        $values["Birthday"] = $params["contact_info"][$type]['birthday'];
        $values["Gender"] = $params["contact_info"][$type]['gender'];
        return $values;
    }

    /**
     * helper
     *
     * @param $type
     * @return array
     */
    private function prepareContact($type)
    {
        $values = [];
        $values['name'] = $this->options["contactdetails"][$type]["Full Name"];
        $values['firstname'] = $this->options["contactdetails"][$type]["First Name"];
        $values['lastname'] = $this->options["contactdetails"][$type]["Last Name"];
        $values['companyname'] = $this->options["contactdetails"][$type]["Company Name"];
        $values['address1'] = $this->options["contactdetails"][$type]["Address"];
        $values['address2'] = $this->options["contactdetails"][$type]["Address 2"];
        $values['city'] = $this->options["contactdetails"][$type]["City"];
        $values['state'] = $this->options["contactdetails"][$type]["State"];
        $values['postcode'] = $this->options["contactdetails"][$type]["Postcode"];
        $values['country'] = $this->options["contactdetails"][$type]["Country"];
        $values['phonenumber'] = $this->options["contactdetails"][$type]["Phone"];
        $values['email'] = $this->options["contactdetails"][$type]["Email"];
        $values['nationalid'] = $this->options["contactdetails"][$type]["Nationalid"];
        $values['gender'] = $this->options["contactdetails"][$type]["Gender"];
        return $values;
    }

    /**
     *
     * @return mixed
     */
    public function getContactInfo()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $url = "/domain/" . $domain['id'] . "/contact";
            $result = $this->api($url);
            $result = json_decode($result, true);

            $return['success'] = $result['success'];
            $return['items'] = [];
            $return['items']['Admin'] = $this->getContact('admin', $result);
            $return['items']['Registrant'] = $this->getContact('registrant', $result);
            $return['items']['Billing'] = $this->getContact('billing', $result);
            $return['items']['Technical'] = $this->getContact('tech', $result);
            return $return;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function updateContactInfo()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $params['contact_info']['admin'] = $this->prepareContact('Admin');
            $params['contact_info']['billing'] = $this->prepareContact('Billing');
            $params['contact_info']['tech'] = $this->prepareContact('Technical');
            $params['contact_info']['registrant'] = $this->prepareContact('Registrant');
            $url = "/domain/" . $domain['id']. "/contact";
            $result = $this->api($url, 'PUT', $params);

            $result = json_decode($result, true);
            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function getRegistrarLock()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $url = "/domain/" . $domain['id'] . "/reglock";
            $result = $this->api($url);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    /**
     *
     *
     * @return mixed
     */
    public function updateRegistrarLock()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $params['switch'] = ($this->options['lockenabled'] == 'locked' ? true : false);
            $url = "/domain/" . $domain['id'] . "/reglock";
            $result = $this->api($url, 'PUT', $params);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function getDNSManagement()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $url = "/domain/" . $domain['id'] . "/dns";
            $result = $this->api($url);
            $result = json_decode($result, true);
            $array = array();
            $array['success'] = $result['success'];
            $return['items'] = [];
            foreach ($result['records'] as $record) {
                $host['id'] = $record['id'];
                $host['hostname'] = $record['name'];
                $host['address'] = $record['content'];
                $host['type'] = $record['type'];
                $host['priority'] = $record['priority'];
                $array['items'][] = $host;
            }
            return $array;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * TODO
     *
     * @return mixed
     */
    public function updateDNSManagement()
    {
//         if (!$domain_id = $this->getDomainByName($this->options['sld'] . '.' . $this->options['tld'])) {
//             $this->addError('There was an error');
//             return false;
//         }
//
//         $getDNS = $this->getDNSManagement();
//
// //        foreach ($getDNS['hosts'] as $delrecord) {
// //            $url = "/domain/".$domain_id."/dns/".$delrecord['id'];
// //            $result = $this->api($url,'DELETE');
// //            if (!$result) {
// //                return false;
// //            }
// //        }
//
//         $i = 1;
//         $change = [];
//         while (isset($this->options["HostName{$i}"])) {
//             $hostname = $this->options["HostName{$i}"];
//             $recordtype = $this->options["RecordType{$i}"];
//             $address = $this->options["Address{$i}"];
//             $priority = isset($this->options["Priority{$i}"]) ? $this->options["Priority{$i}"] : 10;
//
//             $params['name'] = $hostname;
//             $params['priority'] = $priority;
//             $params['content'] = $address;
//             $params['type'] = $recordtype;
//
//             $url = "/domain/" . $domain_id . "/dns";
//             $result = $this->api($url, 'POST', $params);
//             if (!$result) {
//                 return false;
//             }
//
//             if ($getDNS['hosts'][$i]['hostname'] != urldecode($hostname) || $getDNS['hosts'][$i]['recordtype'] != urldecode($recordtype) || $getDNS['hosts'][$i]['address'] != urldecode($address))
//                 $change[] = array('from' => $getDNS['hosts'][$i]['hostname'] . ' (' . $getDNS['hosts'][$i]['recordtype'] . ') ' . $getDNS['hosts'][$i]['address'], 'to' => urldecode($hostname) . ' (' . urldecode($recordtype) . ') ' . urldecode($address));
//             $i++;
//         }
//         if (($this->options["newHostName"] != '') && ($this->options['newAddress'] != '')) {
//             $params['name'] = $this->options["newHostName"];
//             $params['priority'] = 10;
//             $params['content'] = $this->options['newAddress'];
//             $params['type'] = $this->options['newRecordType'];
//
//             $url = "/domain/" . $domain_id . "/dns";
//             $result = $this->api($url, 'POST', $params);
//             if (!$result) {
//                 return false;
//             }
//             $change[] = array('name' => 'new', 'from' => $this->options['newHostName'] . ' (' . $this->options['newRecordType'] . ') ' . $this->options['newAddress']);
//         }
//         if (empty($change))
//             return false;
//         $this->addInfo('DNS Management updated successfully');
//         $this->logAction(array('action' => 'Update DNS Management',
//                 'result' => true,
//                 'change' => $change,
//                 'error' => false
//             )
//         );
//         return true;
    }

    /**
     *
     *
     * @return mixed
     */
    public function registerNameServer()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $params['nameserver'] = $this->options['nameserver'];
            $params['ip'] = $this->options['ipaddress'];
            $params['action'] = 'registerNameServer';
            $url = "/domain/" . $domain['id'] . "/reg";
            $result = $this->api($url, 'PUT', $params);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function modifyNameServer()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $params['nameserver'] = $this->options['nameserver'];
            $params['oldip'] = $this->options['currentipaddress'];
            $params['newip'] = $this->options['newipaddress'];
            $params['action'] = 'modifyNameServer';
            $url = "/domain/" . $domain['id'] . "/reg";
            $result = $this->api($url, 'PUT', $params);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function deleteNameServer()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $params['nameserver'] = $this->options['nameserver'];
            $params['action'] = 'deleteNameServer';
            $url = "/domain/" . $domain['id'] . "/reg";
            $result = $this->api($url, 'PUT', $params);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     *
     * @return mixed
     */
    public function getEppCode()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $url = "/domain/" . $domain['id'] . "/epp";
            $result = $this->api($url);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     * @return mixed
     */
    public function synchInfo()
    {
        try {
            $domain = $this->getDomainByName();
            $url = "/domain/" . $domain['id'];
            $result = $this->api($url);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *
     * @return mixed
     */
    public function updateIDProtection()
    {
        try {
            $domain = $this->getDomainByName();
            if(!$domain){
                return ['error' => ['Domain not found']];
            }
            $params['switch'] = $this->options["protectenable"];
            $url = "/domain/" . $domain['id'] . "/idprotection";
            $result = $this->api($url, 'PUT', $params);
            $result = json_decode($result, true);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    /**
     *
     * @param $name
     * @return bool|int
     */
    private function getDomainByName()
    {
        try {
            $name = $this->options['sld'] . '.' . $this->options['tld'];
            $url = "/domain";
            $result = $this->api($url);

            $result = json_decode($result, true);


            if($result['domains']){
              foreach ($result['domains'] as $domain) {
                  if ($domain['name'] === $name)
                      return $domain;
              }
            }


            throw new Exception('There was an error while obtaining domain ID. Domain not found. Please try again or contact support.');
        } catch (Exception $e) {
            return false;
        }
    }
    private function isExpired(){
      return true;
      // try {
      //     $name = $this->options['sld'] . '.' . $this->options['tld'];
      //     $url = "/domain";
      //     $result = $this->api($url);
      //     $result = json_decode($result, true);
      //     foreach ($result['domains'] as $domain) {
      //         if ($domain['name'] == $name)
      //             return $domain['id'];
      //     }
      //     throw new Exception('There was an error while obtaining domain ID. Domain not found. Please try again or contact support.');
      // } catch (Exception $e) {
      //     return false;
      // }

    }
}
