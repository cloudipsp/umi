<?php

class fondyPayment extends payment {
	public function validate() {
		return true;
	}

	public static function getOrderId() {
		return (int) getRequest( 'shp_orderId' );
	}

	public function process( $template = null ) {

		$this->order->order();
		$cmsController = cmsController::getInstance();
		$language      = $this->object->language;
		if ( ! $language ) {
			$language = strtolower( $cmsController->getCurrentLang()->getPrefix() );
		}
		$protocol = getSelectedServerProtocol() . '://';
		$www      = $protocol . $cmsController->getCurrentDomain()->getHost();

		$response_url        = ( @$this->object->response_url ) ?  $this->object->response_url : $www . '/emarket/purchase/result/successful/';
		$server_callback_url = $www . '/emarket/gateway/' . $this->order->getId() . '/index.php';

		$lifetime = ( @$this->object->lifetime ) ? $this->object->lifetime : 36000;

		$currency = strtoupper( mainConfiguration::getInstance()->get( 'system', 'default-currency' ) );
		if ( $currency == 'RUR' ) {
			$currency = 'RUB';
		}

		$user_id    = $this->order->getValue( 'customer_id' );
		$userObject = umiObjectsCollection::getInstance()->getObject( $user_id );
		$sender_email = $userObject->getValue( 'email' ) ? $userObject->getValue( 'email' ) : $userObject->getValue( 'e-mail' );

		$data = array(
			'merchant_id'         => $this->object->merchant_id,
			'order_id'            => $this->order->id . '#' . time(),
			'currency'            => $currency,
			'order_desc'          => '#' . $this->order->id,
			'amount'              => round( $this->order->getActualPrice() * 100 ),
			'lang'            => $language,
			'response_url'        => $response_url,
			'server_callback_url' => $server_callback_url,
			'sender_email'        => $sender_email,
			'lifetime'        => $lifetime

		);

		$data['signature'] = fondycsl::getSignature( $data, $this->object->secret_key );
		$data['url'] = $this->get_chekout_url($data);

		$this->order->setPaymentStatus( 'initialized' );

		list( $templateString ) = def_module::loadTemplates( "emarket/payment/fondy/" . $template, "form_block" );

		return def_module::parseTemplate( $templateString, $data );
	}

	public function poll() {

		if ( empty( $_POST ) ) {
			$callback = json_decode( file_get_contents( "php://input" ) );
			if ( empty( $callback ) ) {
				die( 'post is empty!' );
			}
			$_POST = array();
			foreach ( $callback as $key => $val ) {
				$_POST[ $key ] = $val;
			}
		}

		$fondySettings = array(
			'merchant_id' => $this->object->merchant_id,
			'secret_key'  => $this->object->secret_key
		);
		if ( empty( $_POST['signature'] ) || ! fondycsl::isPaymentValid( $fondySettings, $_POST ) ) {
			die( "invalid signature" );
		}

		$buffer = outputBuffer::current();
		$buffer->clear();
		$buffer->contentType("text/plain");

		$status = $_POST['order_status'];

		switch ($status) {
			case 'processing': {
				$recipientAmount = (float) getRequest("recipientAmount");
				$checkAmount = (float) $this->order->getActualPrice();

				if (($recipientAmount - $checkAmount) < (float) 0.001) {
					$this->order->setPaymentStatus('validated');
					$buffer->push("OK");
				} else {
					$this->order->setPaymentStatus('declined');
					$buffer->push("failed");
				}

				break;
			}
			case 'approved'  : {
				$this->order->setPaymentStatus('accepted');
				$buffer->push("OK");
				break;
			}
			case 'declined'  : {
				$this->order->setPaymentStatus('declined');
				$buffer->push("declined");
				break;
			}
			case 'expired'  : {
				$this->order->setPaymentStatus('declined');
				$buffer->push("declined");
				break;
			}
		}

		$buffer->end();
	}

	protected function get_chekout_url( $params ) {
		if ( is_callable( 'curl_init' ) ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://api.fondy.eu/api/checkout/url/' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-type: application/json' ) );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( array( 'request' => $params ) ) );
			$result   = json_decode( curl_exec( $ch ) );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if ( $httpCode != 200 ) {
				$error = "Return code is {$httpCode} \n" . curl_error( $ch );
				throw new Exception( 'API request error: ' . $error );
			}
			if ( $result->response->response_status == 'failure' ) {
				throw new Exception( 'API request error: ' . $result->response->error_message );
			}
			$url = $result->response->checkout_url;
			return $url;
		} else {
			throw new Exception( 'Curl not enabled' );
		}
	}
}
class fondycsl {
	const RESPONCE_SUCCESS = 'success';
	const RESPONCE_FAIL = 'failure';
	const ORDER_SEPARATOR = '#';
	const SIGNATURE_SEPARATOR = '|';
	const ORDER_APPROVED = 'approved';
	const ORDER_DECLINED = 'declined';
	public static function getSignature( $data, $password, $encoded = true ) {
		$data = array_filter( $data, function ( $var ) {
			return $var !== '' && $var !== null;
		} );
		ksort( $data );
		$str = $password;
		foreach ( $data as $k => $v ) {
			$str .= self::SIGNATURE_SEPARATOR . $v;
		}
		if ( $encoded ) {
			return sha1( $str );
		} else {
			return $str;
		}
	}
	public static function isPaymentValid( $fondySettings, $response ) {
		if ( $fondySettings['merchant_id'] != $response['merchant_id'] ) {
			return false;
		}
		$responseSignature = $response['signature'];
		if ( isset( $response['response_signature_string'] ) ) {
			unset( $response['response_signature_string'] );
		}
		if ( isset( $response['signature'] ) ) {
			unset( $response['signature'] );
		}
		if ( fondycsl::getSignature( $response, $fondySettings['secret_key'] ) != $responseSignature ) {
			return false;
		}
		return true;
	}
}