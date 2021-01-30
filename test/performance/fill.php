<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require __DIR__ . '/../../vendor/autoload.php';
    require __DIR__ . '/dict.php';

    function getRandomName() {
    	global $adjectives, $nouns;
    	return $adjectives[array_rand($adjectives)]
    		.'-'.$nouns[array_rand($nouns)];
    }

    function createObject($name) {
    	$ob = new class {};
    	$ob->name = $name;
    	return $ob;
    }

    $dbConfig = getenv("arc-rest-store");
    if (!$dbConfig) {
        http::response(["error" => "missing store configuration"],501);
        die();
    }

    $store = \arc\store::connect($dbConfig);
    set_time_limit(0);

    for ($i=0; $i<1000; $i++) {

    	do {
    		$name = getRandomName();
		    $path = '/'.$name.'/';
		} while($store->exists($path));
		$store->save( createObject($name), $path);

	    for ($ii=0; $ii<100; $ii++) {

	    	do {
	    		$name = getRandomName();
			    $subpath = $path . $name . '/';
			} while($store->exists($subpath));
			$store->save( createObject($name), $subpath );

		    for ($iii=0; $iii<10; $iii++) {

		    	do {
		    		$name = getRandomName();
				    $subsubpath = $subpath . $name . '/';
				} while($store->exists($subsubpath));
				$store->save( createObject($name), $subsubpath );
				echo ".";
		    }

	    }

    }
