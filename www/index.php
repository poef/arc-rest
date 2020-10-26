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

    $req = http::request();

    $dbConfig = getenv("arc-rest-store");
    if (!$dbConfig) {
        http::response(["error" => "missing store configuration"],$req,501);
        die();
    }

    function connect($dsn)
    {
        $db = new \PDO($dsn);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if (strpos($dsn, 'mysql:')===0) {
            $storeType = '\arc\store\MySQL';
        }
        if (strpos($dsn, 'pgsql:')===0) {
            $storeType = '\arc\store\PSQL';
        }
        if (!$storeType) {
            throw new \arc\ConfigError('Unknown database type');
        }
        $className = $storeType.'Store';            
        $resultHandler = $className::generatorResultHandler($db);
        $queryParserClassName = $storeType.'QueryParser';
        $store = new $className(
            $db, 
            new $queryParserClassName(array('\arc\store','tokenizer')), 
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
            http::response(["error" => "Grants file not found."],$req,501);
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

    $path = \arc\path::collapse($req['pathinfo'] ?: '/');
    $grantsTree = $grantsTree->cd($path);

    // find user and check password
    if ($req['user']) {
        htpasswd::load('.htpasswd');
        if (!htpasswd::check($req['user'],$req['password'])) {
            http::response(["error" => "Access denied for user {$req['user']}"], $req,401);
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
                    responseWithLimit($nodes, $req, null, $_GET['limit'] ?? 0);
                } else {
                    $data = $store->get($path);
                    $nodes = $store->ls($path);
                    responseWithLimit($nodes, $req, $data, $_GET['limit'] ?? 0);
                }
            break;
            case 'POST':
                // get json payload
                $json = file_get_contents('php://input');
                $data = json_decode($json);
                if ($data === NULL) {
                    // json not decoded properly
                    http::response(["error" => "Data empty or not valid JSON"], $req,400);
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
                http::response($path.$id.'/', $req);
            break;
            case 'PUT':
                $json = file_get_contents('php://input');
                $data = json_decode($json);
                if ($data === NULL) {
                    // json not decoded properly
                    http::response(["error" => "Data empty or not valid JSON"], $req,400);
                }
                
                // store it in the given path
                $parents = \arc\path::parents(\arc\path::parent($path));
                foreach($parents as $parent) {
                    if (!$store->exists($parent)) {
                        $store->save(null, $parent);
                    }
                }
                $store->save($data, $path);
                http::response($path, $req);
            break;
            case 'PATCH':
                $json = file_get_contents('php://input');
                $patch = json_decode($json);
                if ($patch === NULL || !is_object($patch)) {
                    // json not decoded properly
                    http::response(["error" => "Patch data empty or not a valid JSON object"], 400);
                }
                $source = $store->get($path);
                $patched = jsonMergePatch($source->data, $patch);
                // store it in the given path
                $store->save($patched, $path);
                http::response($path, $req);
            
            break;
            case 'DELETE':
                if ($store->ls($path)) {
                    http::response(["error" => "Directory $path not empty"], $req, 412);
                    die();
                }
                $result = $store->delete($path);
                http::response($result,$req);
            break;
            default:
                http::response($req['method'].' not allowed', $req, 405);
            break;
        }
    } catch ( \arc\AuthenticationError $e) {
        http::response(["error" => "Access denied"], $req, 401, ["WWW-Authenticate: Basic"]);
    }

    function uuidv4(){
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    function responseWithLimit($nodes, $request, $node=false, $limit=0) {
        http_response_code(200);
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

        echo "{";
        if ($node) {
            echo "\"node\":" . json_encode($node, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) . ",\n";
        }
        echo "\"nodes\":{\n";
        $first = true;
        $next  = false;
        $count = 0;
        foreach($nodes as $node) {
            if ($limit && $count>=$limit) {
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
        echo "\n}\n";
    }
    
    function jsonMergePatch($source, $patch) {
        foreach ($patch as $name => $value) {
            if ($value === null ) {
                unset($source->$name);
            } else {
                if (!isset($source->$name)) {
                    $source->$name = null;
                }
                if (is_object($value)) {
                    $source->$name = jsonMergePatch($source->$name, $value);
                } else {
                    $source->$name = $value;
                }
            }
        }
        return $source;
    }