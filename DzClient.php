<?php
/**
 *	DozorroClient is a sample PHP client for Dozorro API
 *
 *	Requires: php-curl, php-libsodium, sha3_256
 *
 *	Example usage:
 * 	$client = new DozorroClient($api_url);
 *	$client->set_param('limit', 200);
 *	$items = $client->get_feed();
 *  if ($items) {
 *		foreach ($items as $i) {	
 *			$object = $client->get_object($i->id);
 *		}
 *	}
 *
 *	@package  Dozorro
 *	@author   Volodymyr Flonts <flyonst@gmail.com>
 *	@version  0.1
 *  @license  https://www.apache.org/licenses/LICENSE-2.0
 *	@access   public
 *	@see      http://dozorro-api-sandbox.ed.org.ua/
**/

class DozorroClient {
	private $api_url			= '';

	private $params 			= array();

	private $curl_options = array(
		CURLOPT_HEADER		   	=> false,
		CURLOPT_RETURNTRANSFER 	=> true,
		CURLOPT_FOLLOWLOCATION 	=> true,
		CURLOPT_CONNECTTIMEOUT 	=> 30,
		CURLOPT_TIMEOUT        	=> 30,
		CURLOPT_MAXREDIRS      	=> 10,
	    CURLOPT_SSL_VERIFYHOST 	=> 0,
	    CURLOPT_SSL_VERIFYPEER 	=> 0
	);

	/*
	 *	Throw Exception on http errors
	 */
	public $throw_on_error 		= true;

	/*
	 *	Create DozorroClient object
	 *
	 *	@param string $api_url
	 *	@param array $curl_options
	 */
	function __construct($api_url, $curl_options = null) {
		$this->api_url = $api_url;
		if (substr($this->api_url, -1) != '/')
			$this->api_url .= '/';
		if ($curl_options)
			$this->curl_options = array_merge($this->curl_options, $curl_options);
		$this->ch = curl_init();
		curl_setopt_array($this->ch, $this->curl_options);
	}

	function __destruct() {
		curl_close($this->ch);
		$this->ch = null;
	}

	/*
	 *	Update GET params for get_feed query
	 *
	 *	@param string $name
	 *	@param string $value
	 */
	public function set_param($name, $value) {
		$this->params[$name] = $value;
	}

	private function throw_error($msg=false) {
		if ($msg === false)
			$msg = curl_error($this->ch);
		if ($this->throw_on_error)
			throw new Exception($msg);
        trigger_error($msg, E_USER_ERROR);
	}

	private function curl_get($tail, $params=false) {
		$url = $this->api_url . $tail;
		if ($params)
			$url .= '?'.http_build_query($params);
		curl_setopt($this->ch, CURLOPT_URL, $url);
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "GET");
		$resp = curl_exec($this->ch);
		if (curl_errno($this->ch)) {
			$this->throw_error();
			return false;
		}
		$info = curl_getinfo($this->ch);
		if ($info['http_code'] >= 300) {
			$this->throw_error("HTTP status ".$info['http_code']);
			return false;
		}
		if ($resp && substr($resp, 0, 2) == '{"')
			return json_decode($resp);
		return $resp;
	}

	private function curl_put($tail, $data) {
		$opt = JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
		if (is_array($data))
			$data = json_encode($data, $opt);
		$url = $this->api_url . $tail;
		curl_setopt($this->ch, CURLOPT_URL, $url);
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "PUT");
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: '.strlen($data)));
		$resp = curl_exec($this->ch);
		if (curl_errno($this->ch)) {
			$this->throw_error();
			return false;
		}
		$info = curl_getinfo($this->ch);
		if ($info['http_code'] >= 300) {
			$this->throw_error("PUT status ".$info['http_code']);
			return false;
		}
		if ($resp && substr($resp, 0, 2) == '{"')
			return json_decode($resp);
		return $resp;
	}

	/*
	 *	Get Dozorro databsae objects feed
	 *
	 *	@return array
	 *	@throws Exception
	 */
	public function get_feed() {
		$data = $this->curl_get('feed', $this->params);
		if ($data === false)
			return $data;
		// update offset for next query
		if (property_exists($data, 'next_page')) 
			$this->params['offset'] = $data->next_page->offset;
		return $data->data;
	}

	/*
	 *	Get one or multiple objects by their ID
	 *
	 *	@param mixed $object_id (string id or array of ids)
	 *	@return object
	 *	@throws Exception
	 */
	public function get_object($object_id) {
		if (is_array($object_id))
			$object_id = implode(',', $object_id);
		return $this->curl_get('object/'.$object_id);
	}

	/*
	 *	Put signed form data to central database
	 *
	 *	@param string $tender
	 *	@param string $hashid
	 *	@param array $data
	 *	@throws Exception
	 */
	public function put_form($tender, $hashid, $data) {
		$tail = sprintf("tender/%s/form/%s", $tender, $hashid);
		$res = $this->curl_put($tail, $data);
		if (is_object($res) && $res->created)
			return $hashid;
		return false;
	}
	
	/*
	 *	Put signed form comment to central database
	 *
	 *	@param string $tender
	 *	@param string $thread (form_id)
	 *	@param string $hashid
	 *	@param array $data
	 *	@throws Exception
	 */
	public function put_comment($tender, $thread, $hashid, $data) {
		$tail = sprintf("tender/%s/form/%s/comment/%s", $tender, $thread, $hashid);
		$res = $this->curl_put($tail, $data);
		if (is_object($res) && $res->created)
			return $hashid;
		return false;
	}
}
