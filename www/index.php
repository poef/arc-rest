<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require __DIR__ . '/../vendor/autoload.php';
    require_once('../src/http.php');
    require_once('../src/htpasswd.php');
    require_once('../src/filesystem.php');
    require_once('../src/secureStore.php');

    filesystem::basedir(__DIR__);

    $dbConfig = getenv("arc-rest-store");
    if (!$dbConfig) {
        http::response(["error" => "missing store configuration"],501);
        die();
    }

//    try {
        $store = \arc\store::connect($dbConfig);
        $store->initialize();
//    } catch(\Exception $e) {
//        http::response(["error" => $e->getMessage()], 501);
//        die();
//    }

    function initGrants() {
        $grantsFile = getenv('arc-rest-grants');
        if (!$grantsFile) {
            $grantsFile = dirname(__DIR__).'/grants.json';
        }
        if (!is_readable($grantsFile)) {
            http::response(["error" => "Grants file not found."],501);
            die();
        }
        $grantsConfig = json_decode(file_get_contents($grantsFile),true);
        $context = \arc\context::$context;
        $grantsTree = new \arc\grants\GrantsTree( 
            \arc\tree::expand($grantsConfig), 'public', 'public' 
        );
        $context->arcGrants = $grantsTree;
        return $grantsTree;
    }
    $grantsTree = initGrants();

    $req = http::request();
    $path = \arc\path::collapse($req['pathinfo'] ?: '/');
    $grantsTree = $grantsTree->cd($path);

    // find user and check password
    if ($req['user']) {
        htpasswd::load('.htpasswd');
        if (!htpasswd::check($req['user'],$req['password'])) {
            http::response(["error" => "Access denied for user {$req['user']}"], 401);
            die();
        }
        $grantsTree = $grantsTree->switchUser($req['user']);
    }

    $store = new secureStore($grantsTree, $store);

    try {
        switch($req['method']) {
            case 'GET':
                $data = $store->get($path);
                $nodes = $store->ls($path);
                http::response(["node" => $data, "childNodes" => $nodes]);
            break;
            case 'POST':
                // get json payload
                $json = file_get_contents('php://input');
                $data = json_decode($json);
                if ($data === NULL) {
                    // json not decoded properly
                    http::response(["error" => "Data empty or not valid JSON"], 400);
                }
                // create any missing parents
                foreach( \arc\path::parents($path) as $parent) {
                    if (!$store->exists($parent)) {
                        $store->save(null, $parent);
                    }
                }
                // create a unique id
                $id = uuidv4();
                // store it in the given path
                $store->save($data, $path.$id.'/');
                http::response($path.$id.'/');
            break;
            case 'PUT':
                $json = file_get_contents('php://input');
                $data = json_decode($json);
                if ($data === NULL) {
                    // json not decoded properly
                    http::response(["error" => "Data empty or not valid JSON"], 400);
                }
                
                // store it in the given path
                $store->save($data, $path);
                http::response($path);
            break;
            case 'DELETE':
                if ($store->ls($path)) {
                    http::response(["error" => "Directory $path not empty"], 412);
                    die();
                }
                $result = $store->delete($path);
                http::response($result);
            break;
            default:
                http::response($req['method'].' not allowed', 405);
            break;
        }
    } catch ( \arc\AuthenticationError $e) {
        http::response(["error" => "Access denied"], 401);
    }
    function uuidv4(){
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }