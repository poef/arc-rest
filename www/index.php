<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require __DIR__ . '/../vendor/autoload.php';
    require_once('../src/secureStore.php');

    global $request;
    $request = \arc\http::serverRequest();

    $dbConfig = getenv("arc-rest-store");
    if (!$dbConfig) {
        response(["error" => "missing store configuration"],$request,501);
        die();
    }

    $resultHandler = array("\arc\store\ResultHandlers","getDBGeneratorHandler");
    $store = \arc\store::connect($dbConfig, $resultHandler);
    $store->initialize();

    function initGrants() {
        global $request;
        $grantsFile = getenv('arc-rest-grants');
        if (!$grantsFile) {
            $grantsFile = dirname(__DIR__).'grants.json';
        }
        if (!is_readable($grantsFile)) {
            response(["error" => "Grants file not found."],$request,501);
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

    $path = \arc\path::collapse($request->url->path ?: '/');
    $grantsTree = $grantsTree->cd($path);

    // find user and check password
    if ($request->user) {
        $htpasswd = \arc\http::htpasswd(file_get_contents('.htpasswd'));
        if (!$htpasswd->check($request->user, $request->password)) {
            response(["error" => "Access denied for user {$request->user}"], $request,401);
            die();
        }
        $grantsTree = $grantsTree->switchUser($request->user);
    }

    $store = new secureStore($grantsTree, $store);
    try {
        switch($request->method) {
            case 'GET':
                $query = $request->url->query['query'] ?? '';
                if ($query) {
                    $nodes = $store->cd($path)->find($query);
                    responseWithLimit($nodes, $request, null, $_GET['limit'] ?? 0);
                } else {
                    $data = $store->get($path);
                    $nodes = $store->ls($path);
                    responseWithLimit($nodes, $request, $data, $_GET['limit'] ?? 0);
                }
            break;
            case 'POST':
                // get json payload
                $json = $request->body;
                $data = json_decode($json);
                if ($data === NULL) {
                    // json not decoded properly
                    response(["error" => "Data empty or not valid JSON"], $request,400);
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
                response($path.$id.'/', $request);
            break;
            case 'PUT':
                $json = $request->body;
                $data = json_decode($json);
                if ($data === NULL) {
                    // json not decoded properly
                    response(["error" => "Data empty or not valid JSON"], $request,400);
                }
                
                // store it in the given path
                $parents = \arc\path::parents(\arc\path::parent($path));
                foreach($parents as $parent) {
                    if (!$store->exists($parent)) {
                        $store->save(null, $parent);
                    }
                }
                $store->save($data, $path);
                response($path, $request);
            break;
            case 'PATCH':
                $json = $request->body;
                $patch = json_decode($json);
                if ($patch === NULL || !is_object($patch)) {
                    // json not decoded properly
                    response(["error" => "Patch data empty or not a valid JSON object"], 400);
                }
                $source = $store->get($path);
                $patched = jsonMergePatch($source->data, $patch);
                // store it in the given path
                $store->save($patched, $path);
                response($path, $request);
            
            break;
            case 'DELETE':
                if ($store->ls($path)) {
                    response(["error" => "Directory $path not empty"], $request, 412);
                    die();
                }
                $result = $store->delete($path);
                response($result,$request);
            break;
            default:
                response($request['method'].' not allowed', $request, 405);
            break;
        }
    } catch ( \arc\AuthenticationError $e) {
        response(["error" => "Access denied"], $request, 401, ["WWW-Authenticate: Basic"]);
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
        $origin = $request->headers['origin'] ?? '';
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
            echo "\"node\":" . json_encode($node, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n";
        }
        if ($nodes) {
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
                echo '"'.$node->path.'"' . ':' . json_encode($node->data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $first = false;
                $count++;
            }
            echo "\n}";
            if ($next) {
                echo ",\n\"next\":\"{$next->path}\"";
            }
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

    function response($data, $request, $status=200, $headers=[])
    {
        http_response_code($status);
        header('Content-Type: application/json');
        $origin = $request->headers['origin'] ?? '';
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
    }