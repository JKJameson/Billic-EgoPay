<?php
class EgoPay {
	public $settings = array(
		'description' => 'Accept payments via EgoPay.',
	);
	function payment_button($params) {
		global $billic, $db;
		$html = '';
		if (get_config('egopay_store_id') == '') {
			return $html;
		}
		if ($billic->user['verified'] == 0 && get_config('egopay_require_verification') == 1) {
			return 'verify';
		} else {
			$html.= '<form action="https://www.egopay.com/payments/pay/form" method="post">' . PHP_EOL;
			$html.= '<input type="hidden" value="' . get_config('egopay_store_id') . '" name="store_id" />' . PHP_EOL;
			$html.= '<input type="hidden" value="' . $params['charge'] . '" name="amount" />' . PHP_EOL;
			$html.= '<input type="hidden" value="' . get_config('billic_currency_code') . '" name="currency" />' . PHP_EOL;
			$html.= '<input type="hidden" value="Invoice #' . $params['invoice']['id'] . '" name="description" />' . PHP_EOL;
			$html.= '<input type="hidden" value="' . $params['invoice']['id'] . '" name="cf_1" />' . PHP_EOL;
			$html.= '<input type="submit" class="btn btn-default" value="EgoPay" />' . PHP_EOL;
			$html.= '</form>' . PHP_EOL;
		}
		return $html;
	}
	function payment_callback() {
		global $billic, $db;
		try {
			$oEgopay = new EgoPaySciCallback(array(
				'store_id' => get_config('egopay_store_id') ,
				'store_password' => get_config('egopay_store_pass') ,
			));
			$aResponse = $oEgopay->getResponse($_POST);
			$billic->module('Invoices');
			return $billic->modules['Invoices']->addpayment(array(
				'gateway' => 'EgoPay',
				'invoiceid' => $aResponse['cf_1'],
				'amount' => $aResponse['fAmount'],
				'currency' => $aResponse['sCurrency'],
				'transactionid' => $aResponse['sId'],
			));
		}
		catch(EgoPayException $e) {
			return 'EgoPay Error:' . $e->getMessage();
		}
	}
	function settings($array) {
		global $billic, $db;
		if (empty($_POST['update'])) {
			echo '<form method="POST"><input type="hidden" name="billic_ajax_module" value="EgoPay"><table class="table table-striped">';
			echo '<tr><th>Setting</th><th>Value</th></tr>';
			echo '<tr><td>Require Verification</td><td><input type="checkbox" name="egopay_require_verification" value="1"' . (get_config('egopay_require_verification') == 1 ? ' checked' : '') . '></td></tr>';
			echo '<tr><td>EgoPay Store ID</td><td><input type="text" class="form-control" name="egopay_store_id" value="' . safe(get_config('egopay_store_id')) . '"></td></tr>';
			echo '<tr><td>EgoPay Store Pass</td><td><input type="text" class="form-control" name="egopay_store_pass" value="' . safe(get_config('egopay_store_pass')) . '"></td></tr>';
			echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="update" value="Update &raquo;"></td></tr>';
			echo '</table></form>';
		} else {
			if (empty($billic->errors)) {
				set_config('egopay_require_verification', $_POST['egopay_require_verification']);
				set_config('egopay_store_id', $_POST['egopay_store_id']);
				set_config('egopay_store_pass', $_POST['egopay_store_pass']);
				$billic->status = 'updated';
			}
		}
	}
}
if (!class_exists('EgoPaySci')) {
	/**
	 * EgoPay Sci Class
	 * @author EgoPay
	 * @copyright 2013
	 */
	class EgoPaySci {
		/**
		 * EgoPay SCI url
		 */
		const EGOPAY_PAYMENT_URL = "https://www.egopay.com/payments/pay";
		/**
		 * EgoPay Store ID
		 */
		protected $_storeId;
		/**
		 * EgoPay Store password
		 */
		protected $_storePassword;
		/**
		 * Set these urls if you don't want use the ones you have in the website
		 * User gets redirected after success payment
		 */
		protected $_successUrl;
		/**
		 * User gets redirected then he goes back without paying
		 */
		protected $_failUrl;
		/**
		 * Constructor
		 * @param mixed $aParams - parameters that initiate API object
		 * The available parameters are:
		 * Required:
		 *   store_id - id of the store
		 *   store_password - unique generated password for the store
		 * Optional:
		 *   success_url - success callback url
		 *   fail_url - failed callback url
		 */
		public function __construct($aParams) {
			//Required parameters
			$aRequired = array(
				'store_id',
				'store_password'
			);
			$aOptional = array(
				'success_url',
				'fail_url'
			);
			foreach ($aRequired as $required) if (!array_key_exists($required, $aParams) || !$aParams[$required]) throw new EgoPayException("This param is required - '$required'");
			$aBoth = array_merge($aRequired, $aOptional);
			foreach ($aBoth as $key) if (array_key_exists($key, $aParams) && $aParams[$key]) {
				$name = preg_replace('/(?<=_)([a-z]{1})/ie', 'strtoupper(\'$1\')', $key);
				$name = str_replace('_', '', $name);
				$name = '_' . $name;
				$this->{$name} = $aParams[$key];
			}
		}
		/**
		 * Creates confirmation url
		 * @param type $aData - data that will be sent
		 * @return string - confirmation url
		 */
		public function getConfirmationUrl($aData) {
			$sHash = $this->createHash($aData);
			return self::EGOPAY_PAYMENT_URL . '/?hash=' . urlencode($sHash);
		}
		public function sendRequest($aData) {
			$sUrl = $this->getConfirmationUrl($aData);
			header('Location: ' . $sUrl);
		}
		/**
		 * Creates encoded data hash
		 * @param type $aData
		 * @return type
		 */
		public function createHash($aData) {
			$aRequired = array(
				'amount',
				'currency'
			);
			foreach ($aRequired as $required) if (!array_key_exists($required, $aData) || !$aData[$required]) throw new EgoPayException("This param is required - '$required'");
			if (!empty($this->_successUrl)) $aData['success_url'] = $this->_successUrl;
			if (!empty($this->_failUrl)) $aData['fail_url'] = $this->_failUrl;
			$sData = serialize($aData);
			$sResult = $this->_storeId . $this->encode($sData);
			return $sResult;
		}
		/**
		 * Required for encoding
		 * @param type $string
		 * @return type
		 */
		protected function safe_b64encode($string) {
			$data = base64_encode($string);
			$data = str_replace(array(
				'+',
				'/',
				'='
			) , array(
				'-',
				'_',
				''
			) , $data);
			return $data;
		}
		/**
		 * Encodes given value
		 * @param type $value
		 * @return type
		 */
		protected function encode($data) {
			if (!$data) {
				return false;
			}
			$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
			$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
			$crypttext = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $this->_storePassword, $data, MCRYPT_MODE_ECB, $iv);
			return trim($this->safe_b64encode($crypttext));
		}
	}
	class EgoPaySciCallback {
		/**
		 * Current SCI version
		 */
		const VERSION = '1.1';
		/**
		 * SCI payment URL
		 */
		const EGOPAY_REQUEST_URL = "https://www.egopay.com/payments/request";
		/**
		 * EgoPay Store ID
		 */
		protected $_storeId;
		/**
		 * EgoPay Store password
		 */
		protected $_storePassword;
		/**
		 * After specified amount of seconds, the request is treated as expired
		 */
		protected $_timeOut = 15;
		/**
		 * Constructor
		 * @param mixed $aParams - parameters that initiate API object
		 * The available parameters are:
		 * Required:
		 *   store_id - id of the store
		 *   store_password - unique generated password for the store
		 */
		public function __construct($aParams) {
			//Required parameters
			$aRequired = array(
				'store_id',
				'store_password'
			);
			foreach ($aRequired as $required) if (!array_key_exists($required, $aParams) || !$aParams[$required]) throw new EgoPayException("This param is required - '$required'");
			foreach ($aRequired as $key) if (array_key_exists($key, $aParams) && $aParams[$key]) {
				$name = preg_replace('/(?<=_)([a-z]{1})/ie', 'strtoupper(\'$1\')', $key);
				$name = str_replace('_', '', $name);
				$name = '_' . $name;
				$this->{$name} = $aParams[$key];
			}
		}
		/**
		 * Sends response to the EgoPay server with data that was sent from EgoPay
		 * server
		 * @param type $aParams
		 * @return string response
		 */
		public function getResponse($aParams) {
			if (!function_exists('curl_init')) {
				die("Curl library not installed.");
				return false;
			}
			if (!isset($aParams['product_id'])) throw new EgoPayException("This param is required - 'product_id'");
			$aPost = array(
				'product_id' => $aParams['product_id'],
				'store_id' => $this->_storeId,
				'security_password' => $this->_storePassword,
				'v' => self::VERSION
			);
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, self::EGOPAY_REQUEST_URL);
			curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_5_6; en-us) AppleWebKit/525.27.1 (KHTML, like Gecko) Version/3.2.1 Safari/525.27.1");
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($aPost));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeOut);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			$response = curl_exec($ch);
			if (!curl_errno($ch)) { #No errors
				$response_info = curl_getinfo($ch);
				if ($response_info['http_code'] != 200) {
					throw new EgoPayException('Invalid request to EgoPay. Response code: ' . $response_info['http_code']);
				}
			} else {
				if ($response == 'INVALID') throw new EgoPayException('Invalid request to EgoPay');
			}
			curl_close($ch);
			$aResponse = array();
			parse_str($response, $aResponse);
			return $aResponse;
		}
	}
	/**
	 * EgoPay Api Exception class
	 */
	class EgoPayException extends Exception {
	}
}
