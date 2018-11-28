<?php

namespace App\Components;

/*
 * eGHL payment
 * @author: XuanXin Zhen <xuanxin.zhen@gmail.com>
 */

class GHLPaymentGateway
{
    private $URL = null;
    protected $TransactionType = null;
    protected $PymtMethod = null;
    protected $ServiceID = null;
    protected $PaymentID = null;
    protected $OrderNumber = null;
    protected $PaymentDesc = null;
    protected $MerchantReturnURL = null;
    protected $MerchantCallBackURL = null;
    protected $Amount = null;
    protected $CurrencyCode = null;
    protected $HashValue = null;
    protected $CustIP = null;
    protected $CustName = null;
    protected $CustEmail = null;
    protected $CustPhone = null;
    protected $PageTimeout = null;
    protected $MerchantTermsURL = null;

    protected $TxnID = null;
    protected $TxnStatus = null;
    protected $AuthCode = null;
    protected $Param6 = null;
    protected $Param7 = null;
    protected $HashValue2 = null;

    // Define all hash value components specifiying if they are mandatory or not
    private $hash_components = array(    //Param => is_mandatory
        'ServiceID' => true,
        'PaymentID' => true,
        'MerchantReturnURL' => true,
        'MerchantCallBackURL' => false,
        'Amount' => true,
        'CurrencyCode' => true,
        'CustIP' => true,
        'PageTimeout' => false
    );

    // 计算 HashValue2 的参数
    private $response_hash_components = array(
        'TxnID' => true,
        'ServiceID' => true,
        'PaymentID' => true,
        'TxnStatus' => true,
        'Amount' => true,
        'CurrencyCode' => true,
        'AuthCode' => false,
        'OrderNumber' => true,
        'Param6' => false,
        'Param7' => false,
    );

    // Define all post params specifiying if they are mandatory or not
    private $post_vars = array(    //Param => is_mandatory
        'TransactionType' => true,
        'PymtMethod' => true,
        'ServiceID' => true,
        'PaymentID' => true,
        'OrderNumber' => true,
        'PaymentDesc' => true,
        'MerchantReturnURL' => true,
        'MerchantCallBackURL' => false,
        'Amount' => true,
        'CurrencyCode' => true,
        'HashValue' => true,
        'CustIP' => true,
        'CustName' => true,
        'CustEmail' => true,
        'CustPhone' => true,
        'PageTimeout' => true,
        'MerchantTermsURL' => false
    );

    // Will contain the HTTP Query format of post params
    private $post_args = null;

    public function __construct($URL = null)
    {
        if (is_null($URL)) {
            echo "Payment URL is not provided</br>";
        } else {
            $this->URL = $URL;
        }
    }

    // Method to get the value of protected/private variable of this class
    public function get($attr)
    {
        return $this->$attr;
    }

    // Method to set the value of protected/private variable of this class
    public function set($attr, $value)
    {
        if (in_array($attr, array('Amount'))) {
            $this->$attr = static::getFormattedCurrency($value);
        }

        $this->$attr = $value;
    }

    //Calling this function will automatically populate all post variables via $_REQUEST
    public function getValuesFromRequest()
    {
        $exempted_attr = array('URL', 'hash_components', 'post_args');
        $args = get_object_vars($this);
        foreach ($args as $ind => $val) {
            if (!in_array($ind, $exempted_attr)) {
                if (isset($_REQUEST[$ind])) {
                    $this->$ind = $_REQUEST[$ind];
                }
            }
        }
    }

    public function getResponseValuesFromRequest()
    {
        $exempted_attr = array('URL', 'hash_components', 'response_hash_components', 'post_args');
        $args = get_object_vars($this);
        foreach ($args as $ind => $val) {
            if (!in_array($ind, $exempted_attr)) {
                if (isset($_REQUEST[$ind])) {
                    $this->$ind = $_REQUEST[$ind];
                }
            }
        }
    }

    public function getResponseValuesFromParams($params)
    {
        $exempted_attr = array('URL', 'hash_components', 'response_hash_components', 'post_args');
        $args = get_object_vars($this);
        foreach ($args as $ind => $val) {
            if (!in_array($ind, $exempted_attr)) {
                if (isset($params[$ind])) {
                    $this->$ind = $params[$ind];
                }
            }
        }
    }

    public function getFormattedCurrency($amount)
    {
        return number_format($amount, 2, '.', '');
    }

    /* 	calculate hashing (HashValue)
        must pass the merchant password as argument to this function
    */
    public function calcHash($mPassword = null)
    {
        if (is_null($mPassword)) {
            return false;
        }
        $hash_str = $mPassword;
        if ($this->checkHashComponents()) {
            foreach ($this->hash_components as $component => $is_mandatory) {
                $hash_str .= $this->$component;
            }
            $this->HashValue = hash('sha256', $hash_str);
            return $this->HashValue;
        } else {
            return false;
        }
    }

    public function calcResponseHash($mPassword = null)
    {
        if (is_null($mPassword)) {
            return false;
        }

        $hashStr2 = $hashStr = $mPassword;
        if ($this->checkResponseHashComponents()) {
            foreach ($this->response_hash_components as $component => $is_mandatory) {
                if (!in_array($component, array('OrderNumber', 'Param6', 'Param7'))) {
                    $hashStr .= $this->$component;
                }
                $hashStr2 .= $this->$component;
            }
            $this->HashValue = hash('sha256', $hashStr);
            $this->HashValue2 = hash('sha256', $hashStr2);
            return $this->HashValue2; // 返回 HashValue2 作为验证
        } else {
            return false;
        }
    }

    public function verifyRequest($mPassword)
    {
        $this->getResponseValuesFromRequest();
        $originHashValue = $this->get('HashValue2'); // 也可以使用 HashValue
        $hashValue = $this->calcResponseHash($mPassword);
        //\Log::info("origin: ${originHashValue}, calc: ${hashValue}");

        return $hashValue === $originHashValue;
    }

    public function verifyRequestFromParams($mPassword, $params)
    {
        $this->getResponseValuesFromParams($params);
        $originHashValue = $this->get('HashValue2'); // 也可以使用 HashValue
        $hashValue = $this->calcResponseHash($mPassword);
        //\Log::info("origin: ${originHashValue}, calc: ${hashValue}");

        return $hashValue === $originHashValue;
    }

    public function getUrl()
    {
        if (is_null($this->URL)) {
            return false;
        }

        return $this->URL;
    }

    //forming payment request
    public function getFormHTML($hashpass = null)
    {
        $hash = $this->calcHash($hashpass);

        if ($hash === false) {
            echo 'HashValue cannot be calculated';
        } elseif ($this->checkPostVars()) {
            $html = '<!DOCTYPE html>
							<html lang="en">
							<head>
								  <meta charset="UTF-8">
								  <title>Document</title>
							</head>
							<body>

								  <form name="frmPayment" method="post" action="' . $this->URL . '">
										<input type="hidden" name="TransactionType" value="' . $this->TransactionType . '">
										<input type="hidden" name="PymtMethod" value="' . $this->PymtMethod . '">
										<input type="hidden" name="ServiceID" value="' . $this->ServiceID . '">
										<input type="hidden" name="PaymentID" value="' . $this->PaymentID . '">
										<input type="hidden" name="OrderNumber" value="' . $this->OrderNumber . '">
										<input type="hidden" name="PaymentDesc" value="' . $this->PaymentDesc . '">
										<input type="hidden" name="MerchantReturnURL" value="' . $this->MerchantReturnURL . '">
										<input type="hidden" name="MerchantCallBackURL" value="' . $this->MerchantCallBackURL . '">
										<input type="hidden" name="Amount" value="' . $this->Amount . '">
										<input type="hidden" name="CurrencyCode" value="' . $this->CurrencyCode . '">
										<input type="hidden" name="CustIP" value="' . $this->CustIP . '">
										<input type="hidden" name="CustName" value="' . $this->CustName . '">
										<input type="hidden" name="CustEmail" value="' . $this->CustEmail . '">
										<input type="hidden" name="CustPhone" value="' . $this->CustPhone . '">
										<input type="hidden" name="HashValue" value="' . $this->HashValue . '">
										<input type="hidden" name="MerchantTermsURL" value="' . $this->MerchantTermsURL . '">
										<input type="hidden" name="PageTimeout" value="' . $this->PageTimeout . '">
										<input type="submit" value="submit">
								  </form>
							</body>
							</html>';
            return $html;
        } else {
            exit;
        }
    }

    //to form HTTP Query format i.e. name value params seperated by &
    public function buildPostVarStr()
    {
        $exempted_attr = array('URL', 'hash_components', 'post_args');
        $args = get_object_vars($this);
        foreach ($args as $ind => $val) {
            if (in_array($ind, $exempted_attr)) {
                unset($args[$ind]);
            }
        }
        $this->post_args = http_build_query($args);
        return $this->post_args;
    }

    //to validate the mandatory hash components are present
    private function checkHashComponents()
    {
        foreach ($this->hash_components as $component => $is_mandatory) {
            if (is_null($this->$component) && $is_mandatory) {
                echo 'A mandatory hash component "' . $component . '" is missing...<br/>';
                return false;
            }
        }
        return true;
    }

    private function checkResponseHashComponents()
    {
        foreach ($this->response_hash_components as $component => $is_mandatory) {
            if (is_null($this->$component) && $is_mandatory) {
                echo 'A mandatory response hash component "' . $component . '" is missing...<br/>';
                return false;
            }
        }
        return true;
    }

    //to validate the mandatory post params are present
    private function checkPostVars()
    {
        foreach ($this->post_vars as $component => $is_mandatory) {
            if (is_null($this->$component) && $is_mandatory) {
                echo 'A mandatory Post param "' . $component . '" is missing...<br/>';
                return false;
            }
        }
        return true;
    }
}
