<?php

class http {

	private static $format = 'json';

	private static function sanitizeTarget($target)
	{
		$target = rawurldecode($target);

		// convert \ to /
		$target = str_replace('\\','/',$target);

		// Only allow A-Z, 0-9, .-_/
		$target = preg_replace('/[^A-Za-z\.\/0-9_-]/', '-', $target);

		// Remove any double periods
		$target = preg_replace('{(^|\/)[\.]{1,2}\/}', '/', $target);

		$target = preg_replace('@^/@', '', $target);

		return $target;
	}

	public static function format($format)
	{
		self::$format = $format;
	}

	private static function getHeader($list, $redirectLevel=0)
	{
		$redirect = 'REDIRECT_';
		if (!is_array($list)) {
			$list = [ $list => false ];
		}
		foreach ( $list as $header => $extraInfo ) {
			for ($i=$redirectLevel; $i>=0; $i--) {
				$check = str_repeat($redirect, $i).$header;
				if ( isset($_SERVER[$check]) ) {
					return [$header, $_SERVER[$check]];
				}
			}
		}
		return [false, ''];
	}

	private static function parseAuthUser($auth) {
		return explode(':',base64_decode(substr($auth, 6)));
	}

	public static function getUser()
	{
		$checks = [ 
			'PHP_AUTH_USER'               => false, 
			'REMOTE_USER'                 => false, 
			'HTTP_AUTHORIZATION'          => function($v) { return http::parseAuthUser($v); },
		];
		list($header, $headerValue) = self::getHeader($checks, 3);
		if (isset($checks[$header]) && is_callable($checks[$header])) {
			$headerValue = ($checks[$header])($headerValue)[0];
		}
		return $headerValue;
	}

	public static function getPassword()
	{
		$checks = [ 
			'PHP_AUTH_PW'                 => false, 
			'HTTP_AUTHORIZATION'          => function($v) { return http::parseAuthUser($v); },
		];
		list($header, $headerValue) = self::getHeader($checks, 3);
		if (isset($checks[$header]) && is_callable($checks[$header])) {
			$headerValue = ($checks[$header])($headerValue)[1];
		}
		return $headerValue;
	}

	public static function getMethod()
	{
		list($header, $headerValue) = self::getHeader('REQUEST_METHOD',3);
		if ($headerValue==='POST') {https://www.php.net/manual/en/function.array-filter.php
			if (isset($_GET["_method"]) && ($_GET['_method']=='PUT'||$_GET['_method']=='DELETE')) {
				$headerValue = $_GET['_method'];
			}
		}
		return $headerValue;
	}

	public static function request()
	{
		$target = preg_replace('@\?.*$@','',$_SERVER["REQUEST_URI"]);
		$target = self::sanitizeTarget($target);

		preg_match('@(?<dirname>.+/)?(?<filename>[^/]*)@',$target,$matches);

		$filename = $matches['filename'] ?? '';
		$dirname  = ( isset($matches['dirname']) ? \arc\path::collapse($matches['dirname']) : '/');
		$docroot  = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
		$subdir   = \arc\path::collapse( substr( dirname($_SERVER['SCRIPT_FILENAME']), strlen($docroot) ) );
		$dirname  = \arc\path::collapse( substr($dirname, strlen($subdir) ) );
		$pathInfo = $_SERVER['PATH_INFO'] ?? '';
		$request = [
			'protocol'  => $_SERVER['SERVER_PROTOCOL']?:'HTTP/1.1',
			'method'    => self::getMethod(),
			'target'    => '/'.$target,
			'directory' => $dirname,
			'filename'  => $filename,
			'user'      => self::getUser(),
			'password'  => self::getPassword(),
			'docroot'   => $docroot,
			'pathinfo'  => $pathInfo,
			'origin'    => $_SERVER['origin'] ?? ''
		];
		return $request;
	}

	public static function response($data, $request, $status=200, $headers=[])
	{
		http_response_code($status);
		header('Content-Type: application/json');
		$origin = $request['origin'];
		if (!$origin) {
			header('Access-Control-Allow-Origin: *');
		} else {
			header('Access-Control-Allow-Origin: '.$origin);
		}
		header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE');
		header('Access-Control-Allow-Headers: Authorization');
		header('Access-Control-Allow-Credentials: true');
		foreach($headers as $header) {
			header($header);
		}
		echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
	}

}
