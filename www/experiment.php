<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    require __DIR__.'/../vendor/autoload.php';
    require_once __DIR__.'/../src/SecureStore.php';

    if (!function_exists('str_ends_with')) {
        function str_ends_with($haystack, $needle) {
            $length = strlen($needle);
            return $length > 0 ? substr($haystack, -$length) === $needle : true;
        }
    }

    $request = \arc\http::serverRequest();
    $path    = $request->url->path;
    if (str_ends_with($path, '/')) {
        $template = 'index.json';
        $path     = \arc\path::collapse($path);
    } else {
        $template = basename($path);
        $path     = \arc\path::parent($path);
    }

    \arc\noxss::detect();
    
    $arcRequest = \arc\prototype::create([
        'user'     => empty($request->user) ? 'public' : $request->user,
        'path'     => $path,
        'template' => $template,
        'params'   => $request->url->query->import($request->params),
        'input'    => function() {
            return $request->body;
        }
    ]);

    $arcResponse = \arc\prototype::create([
        'status'  => 200,
        'headers' => [],
        'addHeader' => function($name, $value) {
            $this->headers = \arc\http\headers::addHeader($this->headers, $name, $value);
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
    
    $grantsConf = getenv('arc-rest-grants') ?? dirname(__DIR__).'grants.json';

    //FIXME: implement something like this: $arcGrants  = \arc\grants::connect($grantsConf);
    $grantsData = json_decode(file_get_contents($grantsConf), true);
    $arcGrants  = new \arc\grants\GrantsTree(\arc\tree::expand($grantsData), $arcRequest->user);
    $arcGrants  = $arcGrants->cd($arcRequest->path);

    $dbConf     = getenv('arc-rest-store');
    $arcStore   = \arc\store::connect($dbConf);
    $arcStore   = new SecureStore($arcGrants, $arcStore);
    
/*
    $config     = getenv('arc-rest-config');
    $arcConfig  = \arc\config::connect($config);
*/

    $templates = [];
    $templatesRoot = \arc\path::collapse(__DIR__.'/templates/');

    $arcRestPrototype = \arc\prototype::create([
        'path'     => '/',
        'data'     => \arc\prototype::create([
            'name' => 'Root'
        ]),
        'grants'   => [ 'get' => function() use ($arcGrants) { return $arcGrants; } ],
        //'config'   => [ 'get' => function() use ($arcConfig) { return $arcConfig; } ],
        'request'  => [ 'get' => function() use ($arcRequest) { return $arcRequest; } ],
        'response' => [ 'get' => function() use ($arcResponse) { return $arcResponse; } ],
        'get' => function($path) use ($arcStore) {
            $data = $arcStore->get($path);
            return $arcPrototype->extend($data);
        },
        'ls' => function() use ($arcStore) {
            $list = $arcStore->ls($this->path);
            return array_map($list, function($data) {
                return $arcPrototype->extend($data);
            });
        },
        'find' => function($query) use ($arcStore) {
            $list = $arcStore->cd($this->path)->find($query);
            return array_map($list, function($data) {
                return $arcPrototype->extend($data);
            });
        },
        'call' => function($template, $params) use ($templatesRoot) {
            if (!isset($templates[$template])) {
                $path = $templatesRoot . \arc\path::head($template);
                if (!file_exists($path)) {
                    throw new \arc\MethodNotFound('Template '.$template.' is undefined', \arc\exceptions::OBJECT_NOT_FOUND);
                }
                $source = file_get_contents($path);
                $templates[$template] = \arc\template::compile($template);
            }
            $compiled = \Closure::bind($templates[$template], $this);
            return $compiled($params);
        }
    ]);

    try {
        
        $data   = $arcStore->get($arcRequest->path);
        $object = \arc\prototype::extend($arcRestPrototype, (array)$data);
        $object->call($arcRequest->template, $arcRequest->params);
    } catch (\arc\AuthenticationError $err) {
        if (headers_sent()) {
            echo $err;
        } else {
            $arcResponse->status = 403;
            $arcResponse->headers = [];
            $arcResponse->addHeader('WWW-Authenticate','Basic Realm=arc-rest');
            echo $err;
        }
    } catch (\arc\MethodNotFound $err) {
        if (headers_sent()) {
            echo $err;
        } else {
            $arcResponse->status = 404;
            $arcResponse->headers = [];
            echo $err;
        }
    }

    \arc\noxss::prevent();

