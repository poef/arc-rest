<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    require __DIR__.'/../../vendor/autoload.php';
    require_once __DIR__.'/../../src/SecureStore.php';

    if (!function_exists('str_ends_with')) {
        function str_ends_with($haystack, $needle) {
            $length = strlen($needle);
            return $length > 0 ? substr($haystack, -$length) === $needle : true;
        }
    }

    $request = \arc\http::serverRequest();
    $path    = $_SERVER['PATH_INFO'] ?? $request->url->path;

    /**
     * The url path contains the path of the requested object and
     * the template to run on the object:
     * https://example.com/path/template?query
     * The part of the template before the query ('?') and after the
     * last slash ('/path/') is the template.
     * If no template is in the url, default to 'index.json'.
     */
    if (str_ends_with($path, '/')) {
        $template = 'index.json';
        $path     = \arc\path::collapse($path);
    } else {
        $template = basename($path);
        $path     = \arc\path::parent($path);
    }

    /**
     * arcRequest abstracts away the specific server request type, this way
     * you can make a command line script, for example, and fill the
     * arcRequest from other variables/configuration.
     */
    $arcRequest = \arc\prototype::create([
        'user'     => empty($request->user) ? 'public' : $request->user,
        'path'     => $path,
        'template' => $template,
        'params'   => $request->url->query->import($request->params),
        'input'    => function() {
            return $request->body;
        }
    ]);

    /**
     * arcResponse object to collect headers and http status
     * On first write, the status and headers will be sent. This response object
     * has no write() method and doesn't block output.
     * If you add a header after first write, it will throw an \arc\IllegalRequest
     */
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
    
    /**
     * Simple grants implementation, grants are stored in a json file, which can be converted
     * to an \arc\tree
     */
    $grantsConf = getenv('arc-rest-grants') ?? dirname(dirname(__DIR__)).'grants.json';
    //FIXME: implement something like this: $arcGrants  = \arc\grants::connect($grantsConf);
    $grantsData = json_decode(file_get_contents($grantsConf), true);
    $arcGrants  = new \arc\grants\GrantsTree(\arc\tree::expand($grantsData), $arcRequest->user);
    $arcGrants  = $arcGrants->cd($arcRequest->path);


    /**
     * arcStore contains the real application data
     * But it is wrapped in a SecureStore later (in the try .. catch block)
     */
    $dbConf     = getenv('arc-rest-store');
    $arcStore   = \arc\store::connect($dbConf);
    
/*
    $config     = getenv('arc-rest-config');
    $arcConfig  = \arc\config::connect($config);
*/

    /**
     * This script implements a very basic template system, based on the
     * object type retrieved by $arcRequest->path
     * For each object there is a subdir in the templates folder
     * If a template is not found there, the script searches the folder of
     * the prototype of the object.
     * If no template is found at all, it throws a 404 notfound exception
     */
    $templates = [];
    $templatesRoot = \arc\path::collapse(__DIR__.'/templates/');

    function object($arr) {
        return json_decode(json_encode($arr));
    }

    /**
     * This section defines the object types in use: root, tenant, user, role and client
     */
    $prototypes = [];

    $prototypes['root'] = \arc\prototype::create([
        'path'     => '/',
        'data'     => object([
            'type' => 'root',
            'name' => 'Root'
        ]),
        'grants'   => [ 'get' => function() use ($arcGrants) { return $arcGrants; } ],
        //'config'   => [ 'get' => function() use ($arcConfig) { return $arcConfig; } ],
        'request'  => [ 'get' => function() use ($arcRequest) { return $arcRequest; } ],
        'response' => [ 'get' => function() use ($arcResponse) { return $arcResponse; } ],
        'get' => function($path) use ($arcStore) {
            $node = $arcStore->get($path);
            return \arc\prototype::extend(getPrototype($node->data->type), (array)$node);
        },
        'ls' => function() use ($arcStore) {
            $list = $arcStore->cd($this->path)->ls();
            $list = array_map(function($node) {
                $proto = getPrototype($node->data->type);
                $object = \arc\prototype::extend($proto, (array)$node);
                return $object;
            }, $list);
            return $list;
        },
        'find' => function($query) use ($arcStore) {
            $list = $arcStore->cd($this->path)->find($query);
            return array_map(function($node) {
                return \arc\prototype::extend(getPrototype($node->data->type), (array)$node);
            }, $list);
        },
        'call' => function($template, $params) use ($templatesRoot, $templates) {
            if (!isset($this->data->type)) {
                $this->data->type = 'root';
            }
            // initialize a cache for templates
            if (!isset($templates[$this->data->type])) {
                $templates[$this->data->type] = [];
            }
            // load and compile the template for this type
            if (!isset($templates[$this->data->type][$template])) {
                // clean the type and template name so they cannot contain directory traversals
                $typeName     = \arc\path::head(\arc\path::collapse($this->data->type));
                $templateName = \arc\path::head(\arc\path::collapse($template));
                $path         = $templatesRoot . $typeName .'/'. $templateName ;

                // search the prototypes for a template with the given name
                $prototype = $this->prototype;
                while (!file_exists($path)) {
                    if (!$prototype) {
                        // No more prototypes and the template file is not yet found - so throw an error
                        throw new \arc\MethodNotFound('Template '.$this->data->type.'/'.$template.' is undefined', \arc\exceptions::OBJECT_NOT_FOUND);
                    }
                    $typeName  = \arc\path::head(\arc\path::collapse($prototype->data->type));
                    $path      = $templatesRoot . $typeName .'/'. $templateName;
                    $prototype = $prototype->prototype;
                }
                // load and parse the template inside a function
                // you may use any PHP in a template that would be valid inside a function
                $templates[$this->data->type][$template] = eval("return function(\$params) { include('$path'); };"); //eval avoids leaking $path into the template
            }
            // bind the template to this object 
            $compiled = \Closure::bind($templates[$this->data->type][$template], $this);
            // and run it
            return $compiled((array)$params);
        },
        'save' => function() use ($arcStore) {
            if (!$this->path) {
                throw new Error('no path!',500);
            }
            return $arcStore->cd($this->path)->save($this->data);
        },
        'delete' => function() use ($arcStore) {
            return $arcStore->cd($this->path)->delete();
        }
    ]);

    $prototypes['tenant'] = \arc\prototype::extend($prototypes['root'], [
        'data' => object([
            'type' => 'tenant',
            'name' => 'Tenant'
        ])
    ]);
    $prototypes['user'] = \arc\prototype::extend($prototypes['root'], [
        'data' => object([
            'type' => 'user',
            'name' => 'User'
        ])
    ]);
    $prototypes['role'] = \arc\prototype::extend($prototypes['root'], [
        'data' => object([
            'type' => 'role',
            'name' => 'Role'
        ])
    ]);
    $prototypes['client'] = \arc\prototype::extend($prototypes['root'], [
        'data' => object([
            'type' => 'client',
            'name' => 'Client'
        ])
    ]);

    function getPrototype($name) {
        global $prototypes;
        return $prototypes[$name] ?? $prototypes['root'];
    }


    /**
     * Now handle the request. 
     */
    try {
        /**
         * Using the http request user here, as arcRequest->user is always
         * set (defaults to 'public') and we need to validate it agains
         * the password file only if set.
         */
        if ($request->user) {
            $passwdFile = dirname(__DIR__).'.htpasswd';
            $htpasswd   = \arc\http::htpasswd(file_get_contents($passwdFile));
            if (!$htpasswd->check($request->user, $request->password)) {
                throw new \arc\AuthenticationError("Access denied",403);
            }
            $arcGrants = $arcGrants->switchUser($request->user);
        }
        // upgrade the store to a SecureStore, now we have a validated user
        $arcStore   = new SecureStore($arcGrants, $arcStore);

        // get the raw data for the requested path
        $node      = $arcStore->get($arcRequest->path);

        // instantiate the data as a prototype object with the correct type
        $prototype = $prototypes[$node->data->type ?? 'root'];
        $object    = \arc\prototype::extend($prototype, (array)$node);

        // call the requested template with the given parameters
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
