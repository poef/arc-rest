<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    require __DIR__.'/../vendor/autoload.php';

    $arcRequest  = \arc\http::serverRequest();
    
    $arcResponse = \arc\prototype::create([
        'status'  => 200,
        'headers' => [],
        'addHeader' => function($name, $value) {
            $this->headers = \arc\http\headers::addHeader($this->headers, $name, $value);
        },
        'write' => function($value) {
            echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        },
        'writeKeyValue' => function($key, $value) {
            echo json_encode($key, JSON_HEX_QUOT) . ':' . json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        },
        'writeStream' => function($nodes, $limit) {
            $delim = '';
            $count = 0;
            $next  = false;
            foreach($nodes as $node) {
                if ($limit && $count>=$limit) {
                    $next = $node;
                    break;
                }
                echo $delim;
                $this->jsonKeyValue($node->path, $node->data).$delim;
                $delim = ",\n";
                $count++;
            }
            return $next;
        },
        'error' => function($message, $status) {
            $this->response->status = $status;
            echo json_encode(["error" => $message]);
        }
    ]);
    
    header_register_callback(function() use ($arcResponse) {
        http_response_code($arcResponse->status);
        foreach($arcResponse->headers as $header => $headerValues) {
            if (is_array($headerValues)) {
                foreach($headerValues as $value) {
                    header($header.': '.$value);
                }
            } else {
                header($header.': '.$headerValues);
            }
        }
        $arcResponse->addHeader = function($name, $value) {
            throw new \arc\IllegalRequest('Headers already sent', \arc\exceptions::HEADERS_SENT);
        };
    });
    
    $dbConf        = getenv('arc-rest-store');
    $resultHandler = array("\arc\store\ResultHandlers","getDBGeneratorHandler");
    $arcStore      = \arc\store::connect($dbConf, $resultHandler);

    function uuidv4(){
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

    $restAPI = \arc\prototype::create([
        'get' => function($path) use ($arcStore, $arcRequest, $arcResponse) {
            $myStore = $arcStore->cd($path);
            $query   = $arcRequest->url->query['query'] ?? '';
            if ($query) {
                $data  = null;
                $nodes = $myStore->find($query);
            } else {
                $data  = $myStore->get();
                $nodes = $myStore->ls();
            }
            $limit = $params['limit'] ?? 0;
            echo '{';
            if ($data) {
                $arcResponse->writeKeyValue('node', $data);
                echo ",\n";
            }
            echo "\"nodes\":{\n";
            $next = $arcResponse->writeStream($nodes, $limit);
            echo "\n}";
            if (!empty($next)) {
                echo ",\n";
                $arcResponse->writeKeyValue('next', $next->path);
            }
            echo "\n}\n";
        },
        'post' => function($path) use ($arcStore, $arcRequest, $arcResponse) {
            $data = json_decode($arcRequest->body);
            if ($data === NULL) {
                $arcResponse->error("Data empty or not valid JSON",400);
                return;
            }

            // create any missing parents
            foreach (\arc\path::parents($path) as $parent) {
                if (!$arcStore->exists($parent)) {
                    $arcStore->save(null, $parent);
                }
            }

            // create a unique id
            $id = uuidv4();

            // store it in the given path
            $arcStore->save($data, $path.$id.'/');
            $arcResponse->write($path.$id.'/');
        },
        'put' => function($path) use ($arcStore, $arcRequest, $arcResponse) {
            $data = json_decode($arcRequest->body);
            if ($data === NULL) {
                $arcResponse->error("Data empty or not valid JSON",400);
                return;
            }
            
            // store it in the given path
            $parents = \arc\path::parents(\arc\path::parent($path));
            foreach ($parents as $parent) {
                if (!$store->exists($parent)) {
                    $store->save(null, $parent);
                }
            }

            $arcStore->save($data, $path);
            $arcResponse->write($path);
        },
        'patch' => function($path) use ($arcStore, $arcRequest, $arcResponse) {
            $patch = json_decode($arcRequest->body);
            if ($patch === NULL || !is_object($patch)) {
                $arcResponse->error("Patch data empty or not a valid JSON object",400);
            }
            $source  = $arcStore->get($path);
            $patched = jsonMergePatch($source->data, $patch);

            // store it in the given path
            $arcStore->save($patched, $path);
            $arcResponse->write($path);
        },
        'delete' => function($path) use ($arcStore, $arcRequest, $arcResponse) {
            if ($arcStore->ls($path)) {
                $arcResponse->error("Directory $path not empty", 412);
                return;
            }
            $result = $arcStore->delete($path);
            $arcResponse->write($result);
        }
    ]);

    try {
        
        $arcResponse->addHeader('Content-Type', 'application/json');
        $origin = $arcRequest->headers['origin'] ?? false;
        if ($origin) {
            $arcResponse->addHeader('Access-Control-Allow-Origin','*');
        } else {
            $arcResponse->addHeader('Access-Control-Allow-Origin',$origin);
        }
        $arcResponse->addHeader('Access-Control-Allow-Methods','POST,GET,PUT,DELETE');
        $arcResponse->addHeader('Access-Control-Allow-Headers','Authorization');
        $arcResponse->addHeader('Access-Control-Allow-Credentials','true');

        $path = \arc\path::collapse($_SERVER['PATH_INFO'] ?: $arcRequest->url->path);
        $restAPI->{strtolower($arcRequest->method)}($path);

    } catch (\arc\MethodNotFound $err) {
        $arcResponse->error($err->getMessage(), 405);
    }
