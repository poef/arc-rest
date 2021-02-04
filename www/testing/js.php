<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    $base = dirname(dirname(__DIR__));
    require $base . 'vendor/autoload.php';
    require $base . 'vendor/arc/web/src/http.php'; //why do I need this composer?

	class V8PromiseFactory
	{
	    private $v8;

	    public function __construct(V8Js $v8)
	    {
	        $this->v8 = $v8;
	    }

	    public function __invoke($executor)
	    {
	        $trampoline = $this->v8->executeString(
	            '(function(executor) { return new Promise(executor); })');
	        return $trampoline($executor);
	    }
	}


	$v8 = new V8Js();
	$promiseFactory = new V8PromiseFactory($v8);
	$v8->fetch = function($url, $options=[]) use ($promiseFactory) {
		return $promiseFactory(function($resolve, $reject) use ($url, $options) {
			$method = $options->method ?? 'GET';
			$response = \arc\http::request($method, $url); //TODO: convert options
			if ($response===false) {
				$reject($response); // TODO: turn this into a proper response object
			} else {
				$resolve($response); // TODO: turn this into a proper response object
			}
		});
	};

	/* basic.js */
$JS = <<< EOT
PHP.fetch('https://muze.nl/').then(d => print(d));
EOT;

	try {
	  $v8->executeString($JS, 'basic.js');
	} catch (V8JsException $e) {
	  var_dump($e);
	}

?>