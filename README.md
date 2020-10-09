# A simple rest api that learns by access

## Installing

You need Apache 2, PHP 7 and PostgreSQL installed. Apache needs mod_env
(environment module) and mod_rewrite. In debian/ubuntu you can install these
like this:

    > sudo a2enmod env
    > sudo a2enmod rewrite

In PHP you need the postgres and dom extensions. In debian/ubuntu install these like
this:

    > sudo apt-get install php-psql
    > sudo apt-get install php-dom

### Initialize PostgreSQL

Create a user for postgres like this:

    > sudo -s
    > su - postgres
    > createuser --interactive --pwprompt

You don't need the user to be a superuser or be able to create databases or
new users.

Create a database like this:

    > sudo -s
    > su - postgrest
    > createdb -O user dbname

Initialize the crypto extension:

    > sudo -s
    > su - postgres
    > psql dbname
    dbname=# CREATE EXTENSION pgcrypto

If you get an error like 'Could not open extension control file', you must
install the postgresql-contrib-X.Y package, e.g.:

    > sudo apt-get install postgresql-contrib-9.1


### Configure Apache

Make sure you have your site configured to allow overrides from .htaccess,
or copy the contents of the .htaccess to your site config. Enabling
overrides can be done like this:

```
    <Directory /var/www/html>
        Options Indexes FollowSymlinks
        AllowOverride All
        Require all granted
    </Directory>
```

Either put it in the global apache config, or your site specific config.
Make sure to change `/var/www/html` to the document root of your site.

If you change the configuration, restart apache, e.g.:

    > sudo apache2ctl graceful

### Configure arc-rest

Edit the `www/.htaccess` file and change the line:

    SetEnv arc-rest-store "pgsql:host=localhost;port=5432;dbname=arcstore;user=arcstore;password=arcstore" 

Make sure you enter the correct database name, user and password.

### Test the installation

Run this command:

    > curl http://localhost/arc-rest/

Or open that URL with your browser. You should get a result like this:

    {
        "node": {
            "path": "/",
            "id": "5b50ae3e-ee70-4661-b054-45d9d54aeb6c",
            "parent": "",
            "name": "",
            "data": {
                "name": "Root"
            },
            "ctime": 1602232295,
            "mtime": 1602232295
        },
        "childNodes": {}
    }

## Access control

By default arc-rest gives read access to anyone, and no write access to
anyone. 

You can add a new user like this:

    > cd www/
    > htpasswd -b .htpasswd username password

The edit the `grants.json` file in the root, it should look like this:

    {
        "/": {
            "users": {
                "public":" read "
            }
        }
    }

And add you new user like this:

    {
        "/": {
            "users": {
                "public":" read ",
                "username": " read create edit delete "
            }
        }
    }

## Adding data 

    > curl --user username:password --request POST --data '{"name":"foo"}' http://localhost/arc-rest/

Will return something like this:

    "/f297ddb8-7875-4f39-b068-6913fd9cfaf5/"

This means that arc-rest has created a new object with this path. The
default filename is a uuid v4. If you want to specify a different filename,
you must use the PUT method:

    > curl --user username:password --request PUT --data '{"name":"bar"}' \
      http://localhost/arc-rest/bar/

Which will return:

    "/bar/"

If you now request the root like this:

    > curl http://localhost/arc-rest/

You should get a result like this:

    {
        "node": {
            "path": "/",
            "id": "5b50ae3e-ee70-4661-b054-45d9d54aeb6c",
            "parent": "",
            "name": "",
            "data": {
                "name": "Root",
                "prototype": null
            },
            "ctime": 1602232295,
            "mtime": 1602232295
        },
        "childNodes": {
            "/0de385d9-af7f-460c-8625-8c2a86599c3a/": {
                "path": "/0de385d9-af7f-460c-8625-8c2a86599c3a/",
                "id": "9c94ac32-2f25-4204-bddc-d604e81a67af",
                "parent": "/",
                "name": "0de385d9-af7f-460c-8625-8c2a86599c3a",
                "data": {
                    "name": "foo"
                },
                "ctime": 1602236360,
                "mtime": 1602236360
            },
            "/bar/": {
                "path": "/bar/",
                "id": "e7140de1-0457-4acf-9003-00547d2981d3",
                "parent": "/",
                "name": "bar",
                "data": {
                    "name": "bar"
                },
                "ctime": 1602236947,
                "mtime": 1602236947
            }
        }
    }

