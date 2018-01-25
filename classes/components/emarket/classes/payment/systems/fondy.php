<?php
class fondyPayment extends payment {
	public function validate() { return true; }

	public static function getOrderId() {
		return (int) getRequest('shp_orderId');
	}

	public function process($template = null) {
		$this->order->order();
		$cmsController = cmsController::getInstance();
		$strDescription = "";
		foreach($this->order->getItems() as $objItem){
			$strDescription .= $objItem->getName();
			if($objItem->getAmount() > 1)
				$strDescription .= "*".$objItem->getAmount();
			$strDescription .= "; ";
		}

		$strLanguage = strtolower( $cmsController->getCurrentLang()->getPrefix() );			
		$strProtocol = !empty( $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
		$www = $strProtocol . $cmsController->getCurrentDomain()->getHost();

		$strSuccessUrl = ( @$this->object->success_url ) ? $this->_http( $this->object->success_url ) : $www . '/emarket/purchase/result/successful/' ;
		$strFailUrl = ( @$this->object->failure_url ) ? $this->_http( $this->object->failure_url ) : $www . '/emarket/purchase/result/failed/';
		$strCallbackUrl = $www . '/emarket/gateway/' . $this->order->getId() . '/index.php';
		$strCheckUrl = ( @$this->object->check_url ) ? $this->object->check_url : $strCallbackUrl;
		$strResultUrl = ( @$this->object->result_url ) ? $this->object->result_url : $strCallbackUrl;

		$bDemoMode = ( @$this->object->demo_mode ) ? 1 : 0;
		$nLifeTime = ( @$this->object->lifetime ) ? $this->object->lifetime*60 : 0;

		$currency = strtoupper( mainConfiguration::getInstance()->get('system', 'default-currency') );
		if ($currency == 'RUR') $currency = 'RUB';

		$arrFields = array(
			'merchant_id'	=> $this->object->merchant_id,
			'order_id'		=> $this->order->id,
			'currency'		=> $currency,
			'amount'			=> number_format($this->order->getActualPrice(), 2, '.', ''),
			'lifetime'		=> $nLifeTime,
			'testing_mode'	=> $bDemoMode,
			'description'	=> $strDescription,
			'user_ip'		=> $_SERVER['REMOTE_ADDR'],
			'language'		=> $strLanguage,
			'check_url'		=> $strCheckUrl,
			'result_url'		=> $strResultUrl,
			'request_method'	=> 'POST',
			'success_url'	=> $strSuccessUrl,
			'failure_url'	=> $strFailUrl,
			'salt'			=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация salt и подписи сообщения.
			'cms_payment_module'=> 'UMICMS',
		);

		if(!empty($this->object->payment_system) && !$bDemoMode)
			$arrFields['payment_system'] = $this->object->payment_system;

		$user_id = $this->order->getValue('customer_id');
		$userObject = umiObjectsCollection::getInstance()->getObject( $user_id );
		
		$strMaybePhone = $userObject->getValue('phone') ? $userObject->getValue('phone') : $userObject->getValue('delivery_phone');
		$strMaybeEmail = $userObject->getValue('email') ? $userObject->getValue('email') : $userObject->getValue('e-mail');
				
		preg_match_all("/\d/", $strMaybePhone, $array);
		$strPhone = implode('',@$array[0]);
		$arrFields['user_phone'] = $strPhone;
		
		$arrFields['user_email'] = $strMaybeEmail;
		$arrFields['user_contact_email'] = $strMaybeEmail;

		$arrFields['sig'] = Signature::make('init_payment.php', $arrFields, $this->object->secret_key);

		$response = file_get_contents('https://www.fondy.ru/init_payment.php?' . http_build_query($arrFields));
		$responseElement = new SimpleXMLElement($response);

		$checkResponse = Signature::checkXML('init_payment.php', $responseElement, $this->object->secret_key);

	   	if ($checkResponse && (string)$responseElement->status == 'ok') {

			$bCreateOfdCheck = ( @$this->object->create_ofd_check ) ? 1 : 0;

   			if ($bCreateOfdCheck == 1) {

       			$paymentId = (string)$responseElement->payment_id;

				$VATstr = $this->getVatNameFromTaxDescription( @$this->object->ofd_vat_type );

       	        $ofdReceiptItems = array();
       			foreach($this->order->getItems() as $objItem) {
       	            $ofdReceiptItem = new OfdReceiptItem();
       	            $ofdReceiptItem->label = $objItem->getName();
       	            $ofdReceiptItem->amount = round($objItem->getItemPrice() * $objItem->getAmount(), 2);
       	            $ofdReceiptItem->price = round($objItem->getItemPrice(), 2);
       	            $ofdReceiptItem->quantity = $objItem->getAmount();
       	            $ofdReceiptItem->vat = $VATstr;
       	            $ofdReceiptItems[] = $ofdReceiptItem;
           		}

				$umiObjectsCollection = umiObjectsCollection::getInstance();
				$delivery = $umiObjectsCollection->getObject($this->order->getValue("delivery_id"));

				if ($delivery) {
					$shipping_name = $delivery->getName();
					$shipping = $this->order->getDeliveryPrice();
					if ($shipping > 0) {
	       				$ofdReceiptItem = new OfdReceiptItem();
	       				$ofdReceiptItem->label = trim($shipping_name);
	       				$ofdReceiptItem->amount = round($shipping, 2);
    	   				$ofdReceiptItem->price = round($shipping, 2);
	       				$ofdReceiptItem->quantity = 1;
	       				$ofdReceiptItem->vat = '18'; // fixed
	       				$ofdReceiptItems[] = $ofdReceiptItem;
					}
				} 

       			$ofdReceiptRequest = new OfdReceiptRequest($this->object->merchant_id, $paymentId);
       			$ofdReceiptRequest->items = $ofdReceiptItems;
       			$ofdReceiptRequest->sign($this->object->secret_key);

       			$responseOfd = file_get_contents('https://www.fondy.ru/receipt.php?' . http_build_query($ofdReceiptRequest->requestArray()));
       			$responseElementOfd = new SimpleXMLElement($responseOfd);

       			if ((string)$responseElementOfd->status != 'ok')
					throw new Exception('fondy create OFD check error. ' . $responseElementOfd->error_description);

       		}

    	} else {

			throw new Exception('fondy init payment error. ' . $responseElement->error_description);

    	}

		$arrFields['sig'] = Signature::make('payment.php', $arrFields, $this->object->secret_key);
		$arrFields['url'] = (string)$responseElement->redirect_url;

		$this->order->setPaymentStatus('initialized');
		
		list($templateString) = def_module::loadTemplates("emarket/payment/fondy/".$template, "form_block");
		return def_module::parseTemplate($templateString, $arrFields);
	}

	public function getVatNameFromTaxDescription($taxId) {
		$taxGuideId = umiObjectTypesCollection::getInstance()
				->getTypeIdByGUID('tax-rate-guide');
		$taxList = umiObjectsCollection::getInstance()
				->getGuidedItems($taxGuideId);

		if ($taxList[$taxId]) {
			if (strpos ($taxList[$taxId], '18/118') !== false) return '118';
			if (strpos ($taxList[$taxId], '10/100') !== false) return '110';
			if (strpos ($taxList[$taxId], '18') !== false) return '18';
			if (strpos ($taxList[$taxId], '10') !== false) return '10';
		}

		return '0';

		/*
			[40] => Без НДС 
			[44] => НДС по расчетной ставке 10/110 
			[45] => НДС по расчетной ставке 18/118 
			[41] => НДС по ставке 0% 
			[42] => НДС по ставке 10% 
			[43] => НДС по ставке 18% )
		*/
	}

	public function poll() {
		$arrRequest = array();
		if(!empty($_POST)) 
			$arrRequest = $_POST;
		else {
			foreach($_GET as $strName => $strValue)
				if(preg_match('/^/', $strName))
					$arrRequest[$strName] = $strValue;
		}


		$thisScriptName = Signature::getOurScriptName();
		if (empty($arrRequest['sig']) || !Signature::check($arrRequest['sig'], $thisScriptName, $arrRequest, $this->object->secret_key))
			die("Wrong signature");
		
		$buffer = outputBuffer::current();
		$buffer->clear();
		$buffer->contentType("text/xml");
			
		$arrResponse = array();
		$strOrderStatus = $this->order->getCodeByStatus($this->order->getPaymentStatus());
		$strPaymentStatus = $this->order->getCodeByStatus($this->order->getOrderStatus());

		if(!isset($arrRequest['result'])){
			$bCheckResult = 1;
			if(!isset($this->order) || !in_array($strPaymentStatus, array('payment','waiting','accepted')) || !in_array($strOrderStatus, array('initialized','accepted'))){
				$bCheckResult = 0;
				$error_desc = "Товар не доступен. Либо заказа нет, либо его статус ".$strOrderStatus.', а статус оплаты '.$strPaymentStatus;
			}
			elseif(number_format($this->order->getActualPrice(), 2, '.', '') != number_format($arrRequest['amount'], 2, '.', '')){
				$bCheckResult = 0;
				$error_desc = "Неверная сумма";
			}
			
			$arrResponse['salt']              = $arrRequest['salt']; // в ответе необходимо указывать тот же salt, что и в запросе
			$arrResponse['status']            = $bCheckResult ? 'ok' : 'error';
			$arrResponse['error_description'] = $bCheckResult ?  ""  : $error_desc;
			$arrResponse['sig']				 = Signature::make($thisScriptName, $arrResponse, $this->object->secret_key);
			
			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('salt', $arrResponse['salt']);
			$objResponse->addChild('status', $arrResponse['status']);
			$objResponse->addChild('error_description', $arrResponse['error_description']);
			$objResponse->addChild('sig', $arrResponse['sig']);
			
		}
		else{
			if(!isset($this->order) || !in_array($strPaymentStatus, array('payment','waiting','accepted')) || !in_array($strOrderStatus, array('initialized','accepted'))){
				$strResponseDescription = "Товар не доступен. Либо заказа нет, либо его статус ".$strOrderStatus.', а статус оплаты '.$strPaymentStatus;
				if($arrRequest['can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';
			}
			elseif(number_format($this->order->getActualPrice(), 2, '.', '') != number_format($arrRequest['amount'], 2, '.', '')){
				$strResponseDescription = "Неверная сумма";
				if($arrRequest['can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';
			}
			else {
				$strResponseStatus = 'ok';
				$strResponseDescription = "Оплата принята";
				if ($arrRequest['result'] == 1) {
					$this->order->setPaymentStatus("accepted");
//					$this->order->setOrderStatus("accepted");
					$this->order->payment_document_num = $arrRequest['payment_id'];
				}
				else{
					$this->order->setPaymentStatus("rejected");
//					$this->order->setOrderStatus("rejected");
				}
			}
			
			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('salt', $arrRequest['salt']); // в ответе необходимо указывать тот же salt, что и в запросе
			$objResponse->addChild('status', $strResponseStatus);
			$objResponse->addChild('description', $strResponseDescription);
			$objResponse->addChild('sig', Signature::makeXML($thisScriptName, $objResponse, $this->object->secret_key));
		}
		
		$buffer->push($objResponse->asXML());
		$buffer->end();
	}
};
	

class Signature {

	/**
	 * Get script name from URL (for use as parameter in self::make, self::check, etc.)
	 *
	 * @param string $url
	 * @return string
	 */
	public static function getScriptNameFromUrl ( $url )
	{
		$path = parse_url($url, PHP_URL_PATH);
		$len  = strlen($path);
		if ( $len == 0  ||  '/' == $path{$len-1} ) {
			return "";
		}
		return basename($path);
	}
	
	/**
	 * Get name of currently executed script (need to check signature of incoming message using self::check)
	 *
	 * @return string
	 */
	public static function getOurScriptName ()
	{
		return self::getScriptNameFromUrl( $_SERVER['PHP_SELF'] );
	}

	/**
	 * Creates a signature
	 *
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function make ( $strScriptName, $arrParams, $strSecretKey )
	{
		return md5( self::makeSigStr($strScriptName, $arrParams, $strSecretKey) );
	}

	/**
	 * Verifies the signature
	 *
	 * @param string $signature
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return bool
	 */
	public static function check ( $signature, $strScriptName, $arrParams, $strSecretKey )
	{
		return (string)$signature === self::make($strScriptName, $arrParams, $strSecretKey);
	}


	/**
	 * Returns a string, a hash of which coincide with the result of the make() method.
	 * WARNING: This method can be used only for debugging purposes!
	 *
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return string
	 */
	static function debug_only_SigStr ( $strScriptName, $arrParams, $strSecretKey ) {
		return self::makeSigStr($strScriptName, $arrParams, $strSecretKey);
	}


	private static function makeSigStr ( $strScriptName, $arrParams, $strSecretKey ) {
		unset($arrParams['sig']);
		ksort($arrParams);
		return $strScriptName .';' . self::arJoin($arrParams) . ';' . $strSecretKey;
	}

	private static function arJoin ($in) {
		return rtrim(self::arJoinProcess($in, ''), ';');
	}

	private static function arJoinProcess ($in, $str) {
		if (is_array($in)) {
			ksort($in);
			$s = '';
			foreach($in as $v) {
				$s .= self::arJoinProcess($v, $str);
			}
			return $s;
		} else {
			return $str . $in . ';';
		}
	}
	
	private static function makeFlatParamsArray ( $arrParams, $parent_name = '' )
	{
		$arrFlatParams = array();
		$i = 0;
		foreach ( $arrParams as $key => $val ) {
			
			$i++;
			if ( 'sig' == $key )
				continue;
				
			/**
			 * Имя делаем вида tag001subtag001
			 * Чтобы можно было потом нормально отсортировать и вложенные узлы не запутались при сортировке
			 */
			$name = $parent_name . $key . sprintf('%03d', $i);

			if (is_array($val) ) {
				$arrFlatParams = array_merge($arrFlatParams, self::makeFlatParamsArray($val, $name));
				continue;
			}

			$arrFlatParams += array($name => (string)$val);
		}

		return $arrFlatParams;
	}

	/********************** singing XML ***********************/

	/**
	 * make the signature for XML
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function makeXML ( $strScriptName, $xml, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::make($strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Verifies the signature of XML
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return bool
	 */
	public static function checkXML ( $strScriptName, $xml, $strSecretKey )
	{
		if ( ! $xml instanceof SimpleXMLElement ) {
			$xml = new SimpleXMLElement($xml);
		}
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::check((string)$xml->sig, $strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Returns a string, a hash of which coincide with the result of the makeXML() method.
	 * WARNING: This method can be used only for debugging purposes!
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function debug_only_SigStrXML ( $strScriptName, $xml, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::makeSigStr($strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Returns flat array of XML params
	 *
	 * @param (string|SimpleXMLElement) $xml
	 * @return array
	 */
	private static function makeFlatParamsXML ( $xml, $parent_name = '' )
	{
		if ( ! $xml instanceof SimpleXMLElement ) {
			$xml = new SimpleXMLElement($xml);
		}

		$arrParams = array();
		$i = 0;
		foreach ( $xml->children() as $tag ) {
			
			$i++;
			if ( 'sig' == $tag->getName() )
				continue;
				
			/**
			 * Имя делаем вида tag001subtag001
			 * Чтобы можно было потом нормально отсортировать и вложенные узлы не запутались при сортировке
			 */
			$name = $parent_name . $tag->getName().sprintf('%03d', $i);

			if ( $tag->children()->count() > 0 ) {
				$arrParams = array_merge($arrParams, self::makeFlatParamsXML($tag, $name));
				continue;
			}

			$arrParams += array($name => (string)$tag);
		}

		return $arrParams;
	}
}

class OfdReceiptRequest
{
	const SCRIPT_NAME = 'receipt.php';

	public $merchantId;
	public $operationType = 'payment';
	public $paymentId;
	public $items = array();

	private $params = array();

	public function __construct($merchantId, $paymentId)
	{
		$this->merchantId = $merchantId;
		$this->paymentId = $paymentId;
	}

	public function sign($secretKey)
	{
		$params = $this->toArray();
		$params['salt'] = 'salt';
		$params['sig'] = Signature::make(self::SCRIPT_NAME, $params, $secretKey);
		$this->params = $params;
	}

	public function toArray()
	{
		$result = array();

		$result['merchant_id'] = $this->merchantId;
		$result['operation_type'] = $this->operationType;
		$result['payment_id'] = $this->paymentId;

		foreach ($this->items as $item) {
			$result['items'][] = $item->toArray();
		}

		return $result;
	}

	public function requestArray()
	{
		return $this->params;
	}

	public function makeXml()
	{
		//var_dump($this->params);
		$xmlElement = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><request></request>');

		foreach ($this->params as $paramName => $paramValue) {
			if ($paramName == 'items') {
				//$itemsElement = $xmlElement->addChild($paramName);
				foreach ($paramValue as $itemParams) {
					$itemElement = $xmlElement->addChild($paramName);
					foreach ($itemParams as $itemParamName => $itemParamValue) {
						$itemElement->addChild($itemParamName, $itemParamValue);
					}
				}
				continue;
			}

			$xmlElement->addChild($paramName, $paramValue);
		}

		return $xmlElement->asXML();
	}
}

class OfdReceiptItem
{
	public $label;
	public $amount;
	public $price;
	public $quantity;
	public $vat;

	public function toArray()
	{
		return array(
			'label' => extension_loaded('mbstring') ? mb_substr($this->label, 0, 128) : substr($this->label, 0, 128),
			#'amount' => $this->amount,
			'price' => $this->price,
			'quantity' => $this->quantity,
			'vat' => $this->vat,
		);
	}
}
?>