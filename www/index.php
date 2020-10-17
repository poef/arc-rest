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

    function connect($dsn)
    {
        $db = new \PDO($dsn);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $resultHandler = \arc\store\PSQLStore::generatorResultHandler($db);
        $store = new \arc\store\PSQLStore(
            $db, 
            new \arc\store\PSQLQueryParser(), 
            $resultHandler
        );
        \arc\context::push([
            'arcStore' => $store
        ]);
        return $store;
    }

    $store = connect($dbConfig);
    $store->initialize();

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
                if (isset($_GET["query"]) && $query=$_GET['query']) {
                    $nodes = $store->cd($path)->find($query);
                    responseWithLimit($nodes, null, $_GET['limit'] ?? 1000);
                } else {
                    $data = $store->get($path);
                    $nodes = $store->ls($path);
                    responseWithLimit($nodes, $data, $_GET['limit'] ?? 1000);
                }
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

    function responseWithLimit($nodes, $node=false, $limit=1000) {
        http_response_code(200);
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo "{";
        if ($node) {
            echo "\"node\":" . json_encode($node, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) . ",\n";
        }
        echo "\"nodes\":{\n";
        $first = true;
        $next  = false;
        $count = 0;
        foreach($nodes as $node) {
            if ($count>=$limit) {
                $next = $node;
                break;
            }
            if (!$first) {
                echo ",\n";
            }
            echo '"'.$node->path.'"' . ':' . json_encode($node->data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
            $first = false;
            $count++;
        }
        echo "\n}";
        if ($next) {
            echo ",\n\"next\":\"{$next->path}\"";
        }
        echo "\n}";

    }