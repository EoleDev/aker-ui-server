<?php
declare(strict_types=1);

use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Slim\Exception\HttpBadRequestException as HttpBadRequestException;

return function (App $app) {
    $container = $app->getContainer();
    $container->set('dbService', function($tmp) {
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false
        );
        $config = $tmp->get('settings')['database'];
        $bdd = new PDO('mysql:host='.$config['host'].';dbname='.$config['base'].';charset=utf8', $config['user'], $config['password'], $options);
        return $bdd;
    });

    $app->get('/users', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $query = "SELECT u.id, u.username, u.keyfile, GROUP_CONCAT(DISTINCT uu.usergroupsId) as groups FROM users u LEFT JOIN users_usergroups uu ON u.id = uu.usersId GROUP BY u.id, u.username, u.keyfile;";
        $req = $bdd->prepare($query);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();

        foreach($result as $key => &$val){
            if(!is_null($val['groups'])){
                $val['groups'] = explode(',', $val['groups']);
                foreach($val['groups'] as $k => &$v)
                    $v = intval($v);
            }
        }

	$response->getBody()->write(json_encode($result));

        return $response->withHeader('Content-Type', 'application/json');
    });
    $app->put('/users/{id}', function (Request $request, Response $response, $args) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content))
            throw new HttpBadRequestException($request, "Bad Content");
        if(!isset($content['username']) && !isset($content['keyfile']) && !isset($content['usergroups']))
            throw new HttpBadRequestException($request, "Bad Content");

        if(isset($content['username']) || isset($content['keyfile'])){
            $update = "UPDATE users SET ";

            if(isset($content['username'])){
                $name = $content['username'];

                $check = "SELECT * from users WHERE username = '".$name."' AND id != ".$args['id'].";";
                $req = $bdd->prepare($check);
                $req->execute();
                $result = $req->fetchAll(PDO::FETCH_ASSOC);
                $req->closeCursor();
                if(count($result) > 0)
                    throw new HttpBadRequestException($request, "Username Exist Already");

                $update .= "username = '".$name."',";
            }
            if(isset($content['keyfile'])){
                $key = $content['keyfile'];

                $update .= "keyfile = '".$key."',";
            }

            $query = substr($update, 0, -1);
            $query .= " WHERE id = ".$args['id'].";";
            $req = $bdd->prepare($query);
            $req->execute();
            $req->closeCursor();
        }

        if(isset($content['groups'])){
            $usergroups = array_unique($content['groups']);
            $todelete = array();

            $query = "SELECT uu.usergroupsId as id FROM users u JOIN users_usergroups uu ON u.id = uu.usersId AND u.id = ".$args['id'].";";
            $req = $bdd->prepare($query);
            $req->execute();
            $res = $req->fetchAll(PDO::FETCH_ASSOC);
            $req->closeCursor();

            foreach($res as $key => $val){
                if(in_array($val['id'], $usergroups))
                    unset($usergroups[array_search($val['id'], $usergroups)]);
                else
                    $todelete[] = $val['id'];
            }

            if(count($usergroups) > 0){
                $query = "INSERT INTO users_usergroups(usersId, usergroupsId) VALUES ";
                foreach($usergroups as $val){
                    $query .= "(".$args['id'].", ".intval($val)."),";
                }
                $query = substr($query, 0, -1);
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
            if(count($todelete) > 0){
                $query = "DELETE FROM users_usergroups WHERE usersId = ".$args['id']." AND usergroupsId IN (".implode($todelete, ',').");";
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
        }

        return $response;
    });
    $app->post('/users', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content) || !isset($content['username']) || !isset($content['keyfile']))
            throw new HttpBadRequestException($request, "Bad Content");

        $name = $content['username'];
        $key = $content['keyfile'];

        $check = "SELECT * from users WHERE username = '".$name."';";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(count($result) > 0)
            throw new HttpBadRequestException($request, "Username Exist Already");

        $query = "INSERT INTO users (username, keyfile) VALUES ('".$name."', '".$key."');";
        $req = $bdd->prepare($query);
        $req->execute();
        $req->closeCursor();

        $id = $bdd->lastInsertId();

        if(isset($content['groups'])){
            $usergroups = array_unique($content['groups']);

            if(count($usergroups) > 0){
                $query = "INSERT INTO users_usergroups(usersId, usergroupsId) VALUES ";
                foreach($usergroups as $val){
                    $query .= "(".$id.", ".intval($val)."),";
                }
                $query = substr($query, 0, -1);
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
        }

        $query = "SELECT u.id, u.username, u.keyfile, GROUP_CONCAT(DISTINCT uu.usergroupsId) as groups FROM users u LEFT JOIN users_usergroups uu ON u.id = uu.usersId AND u.id = ".$id." WHERE u.id = ".$id." GROUP BY u.id, u.username, u.keyfile;";
        $req = $bdd->prepare($query);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(!is_null($result[0]['groups'])){
            $result[0]['groups'] = explode(',', $result[0]['groups']);
            foreach($result[0]['groups'] as $k => &$v)
                $v = intval($v);
        }
        $response->getBody()->write(json_encode($result[0]));
        return $response;
    });
    $app->delete('/users/{id}', function (Request $request, Response $response, $args) {
        $bdd = $this->get('dbService');
        $query = "DELETE FROM users WHERE id = ".$args['id'].";";
        $req = $bdd->prepare($query);
        $req->execute();
        $req->closeCursor();

        return $response;
    });

    $app->get('/usergroups', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $query = "SELECT id, name FROM usergroups;";
        $req = $bdd->prepare($query);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();

	$response->getBody()->write(json_encode($result));

        return $response->withHeader('Content-Type', 'application/json');
    });
    $app->put('/usergroups/{id}', function (Request $request, Response $response, $args) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content))
            throw new HttpBadRequestException($request, "Bad Content");
        if(!isset($content['name']))
            throw new HttpBadRequestException($request, "Bad Content");

        $name = $content['name'];

        $check = "SELECT * from usergroups WHERE name = '".$name."' AND id != ".$args['id'].";";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(count($result) > 0)
            throw new HttpBadRequestException($request, "Name Exist Already");

        $update = "UPDATE usergroups SET name = '".$name."' WHERE id = ".$args['id'].";";
        $req = $bdd->prepare($update);
        $req->execute();
        $req->closeCursor();

        return $response;
    });
    $app->post('/usergroups', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content) || !isset($content['name']))
            throw new HttpBadRequestException($request, "Bad Content");

        $name = $content['name'];

        $check = "SELECT * from usergroups WHERE name = '".$name."';";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(count($result) > 0)
            throw new HttpBadRequestException($request, "Name Exist Already");

        $query = "INSERT INTO usergroups (name) VALUES ('".$name."');";
        $req = $bdd->prepare($query);
        $req->execute();
        $req->closeCursor();

        $id = $bdd->lastInsertId();

        $query = "SELECT id, name FROM usergroups WHERE id = ".$id.";";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        $response->getBody()->write(json_encode($result[0]));
        return $response;
    });

    $app->get('/hostgroups', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $query = "SELECT id, name FROM hostgroups;";
        $req = $bdd->prepare($query);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();

	$response->getBody()->write(json_encode($result));

        return $response->withHeader('Content-Type', 'application/json');
    });
    $app->put('/hostgroups/{id}', function (Request $request, Response $response, $args) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content))
            throw new HttpBadRequestException($request, "Bad Content");
        if(!isset($content['name']))
            throw new HttpBadRequestException($request, "Bad Content");

        $name = $content['name'];

        $check = "SELECT * from hostgroups WHERE name = '".$name."' AND id != ".$args['id'].";";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(count($result) > 0)
            throw new HttpBadRequestException($request, "Name Exist Already");

        $update = "UPDATE hostgroups SET name = '".$name."' WHERE id = ".$args['id'].";";
        $req = $bdd->prepare($update);
        $req->execute();
        $req->closeCursor();

        return $response;
    });
    $app->post('/hostgroups', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content) || !isset($content['name']))
            throw new HttpBadRequestException($request, "Bad Content");

        $name = $content['name'];

        $check = "SELECT * from hostgroups WHERE name = '".$name."';";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(count($result) > 0)
            throw new HttpBadRequestException($request, "Name Exist Already");

        $query = "INSERT INTO hostgroups (name) VALUES ('".$name."');";
        $req = $bdd->prepare($query);
        $req->execute();
        $req->closeCursor();

        $id = $bdd->lastInsertId();

        $query = "SELECT id, name FROM hostgroups WHERE id = ".$id.";";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        $response->getBody()->write(json_encode($result[0]));
        return $response;
    });

    $app->get('/hosts', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $query = "SELECT h.id, h.name, h.hostname, h.port, h.key, GROUP_CONCAT(DISTINCT hu.usergroupsId) as usergroups, GROUP_CONCAT(DISTINCT hh.hostgroupsId) as hostgroups FROM hosts h LEFT JOIN hosts_usergroups hu ON h.id = hu.hostsId LEFT JOIN hosts_hostgroups hh ON h.id = hh.hostsId GROUP BY h.id, h.name, h.hostname, h.port, h.key;";
        $req = $bdd->prepare($query);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        foreach($result as $key => &$val){
            if(!is_null($val['usergroups'])){
                $val['usergroups'] = explode(',', $val['usergroups']);
                foreach($val['usergroups'] as $k => &$v)
                    $v = intval($v);
            }
            if(!is_null($val['hostgroups'])){
                $val['hostgroups'] = explode(',', $val['hostgroups']);
                foreach($val['hostgroups'] as $k => &$v)
                    $v = intval($v);
            }
        }

	$response->getBody()->write(json_encode($result));

        return $response->withHeader('Content-Type', 'application/json');
    });
    $app->put('/hosts/{id}', function (Request $request, Response $response, $args) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content))
            throw new HttpBadRequestException($request, "Bad Content");
        if(!isset($content['name']) && !isset($content['hostname']) && !isset($content['port']) && !isset($content['key']) && !isset($content['usergroups']) && !isset($content['hostgroups']))
            throw new HttpBadRequestException($request, "Bad Content");

        if(isset($content['name']) || isset($content['hostname']) || isset($content['port']) || isset($content['key'])){
            $update = "UPDATE hosts SET ";

            if(isset($content['name'])){
                $name = $content['name'];

                $check = "SELECT * from hosts WHERE name = '".$name."' AND id != ".$args['id'].";";
                $req = $bdd->prepare($check);
                $req->execute();
                $result = $req->fetchAll(PDO::FETCH_ASSOC);
                $req->closeCursor();
                if(count($result) > 0)
                    throw new HttpBadRequestException($request, "Name Exist Already");

                $update .= "`name` = '".$name."',";
            }
            if(isset($content['hostname'])){
                $hostname = $content['hostname'];

                $update .= "`hostname` = '".$hostname."',";
            }
            if(isset($content['port'])){
                $port = $content['port'];

                $update .= "`port` = '".$port."',";
            }
            if(isset($content['key'])){
                $key = $content['key'];

                $update .= "`key` = '".$key."',";
            }

            $query = substr($update, 0, -1);
            $query .= " WHERE id = ".$args['id'].";";
            $req = $bdd->prepare($query);
            $req->execute();
            $req->closeCursor();
        }

        if(isset($content["usergroups"])){
            $usergroups = array_unique($content["usergroups"]);
            $todelete = array();

            $query = "SELECT hu.usergroupsId as id FROM hosts h JOIN hosts_usergroups hu ON h.id = hu.hostsId AND h.id = ".$args['id'].";";
            $req = $bdd->prepare($query);
            $req->execute();
            $res = $req->fetchAll(PDO::FETCH_ASSOC);
            $req->closeCursor();

            foreach($res as $key => $val){
                if(in_array($val['id'], $usergroups))
                    unset($usergroups[array_search($val['id'], $usergroups)]);
                else
                    $todelete[] = $val['id'];
            }

            if(count($usergroups) > 0){
                $query = "INSERT INTO hosts_usergroups(hostsId, usergroupsId) VALUES ";
                foreach($usergroups as $val){
                    $query .= "(".$args['id'].", ".intval($val)."),";
                }
                $query = substr($query, 0, -1);
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
            if(count($todelete) > 0){
                $query = "DELETE FROM hosts_usergroups WHERE hostsId = ".$args['id']." AND usergroupsId IN (".implode($todelete, ',').");";
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
        }

        if(isset($content["hostgroups"])){
            $hostgroups = array_unique($content["hostgroups"]);
            $todelete = array();

            $query = "SELECT hh.hostgroupsId as id FROM hosts h JOIN hosts_hostgroups hh ON h.id = hh.hostsId AND h.id = ".$args['id'].";";
            $req = $bdd->prepare($query);
            $req->execute();
            $res = $req->fetchAll(PDO::FETCH_ASSOC);
            $req->closeCursor();

            foreach($res as $key => $val){
                if(in_array($val['id'], $hostgroups))
                    unset($hostgroups[array_search($val['id'], $hostgroups)]);
                else
                    $todelete[] = $val['id'];
            }

            if(count($hostgroups) > 0){
                $query = "INSERT INTO hosts_hostgroups(hostsId, hostgroupsId) VALUES ";
                foreach($hostgroups as $val){
                    $query .= "(".$args['id'].", ".intval($val)."),";
                }
                $query = substr($query, 0, -1);
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
            if(count($todelete) > 0){
                $query = "DELETE FROM hosts_hostgroups WHERE hostsId = ".$args['id']." AND hostgroupsId IN (".implode($todelete, ',').");";
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
        }

        return $response;
    });
    $app->post('/hosts', function (Request $request, Response $response) {
        $bdd = $this->get('dbService');
        $content = $request->getParsedBody();
        if(!is_array($content) || !isset($content['name']) || !isset($content['hostname']) || !isset($content['port']) || !isset($content['key']))
            throw new HttpBadRequestException($request, "Bad Content");

        $name = $content['name'];
        $hostname = $content['hostname'];
        $port = $content['port'];
        $key = $content['key'];

        $check = "SELECT * from hosts WHERE name = '".$name."';";
        $req = $bdd->prepare($check);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(count($result) > 0)
            throw new HttpBadRequestException($request, "Name Exist Already");

        $query = "INSERT INTO hosts (`name`, `hostname`, `port`, `key`) VALUES ('".$name."', '".$hostname."', '".$port."', '".$key."');";
        $req = $bdd->prepare($query);
        $req->execute();
        $req->closeCursor();

        $id = $bdd->lastInsertId();

        if(isset($content["usergroups"])){
            $usergroups = array_unique($content["usergroups"]);

            if(count($usergroups) > 0){
                $query = "INSERT INTO hosts_usergroups(hostsId, usergroupsId) VALUES ";
                foreach($usergroups as $val){
                    $query .= "(".$id.", ".intval($val)."),";
                }
                $query = substr($query, 0, -1);
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
        }
        if(isset($content["hostgroups"])){
            $hostgroups = array_unique($content["hostgroups"]);

            if(count($usergroups) > 0){
                $query = "INSERT INTO hosts_hostgroups(hostsId, hostgroupsId) VALUES ";
                foreach($hostgroups as $val){
                    $query .= "(".$id.", ".intval($val)."),";
                }
                $query = substr($query, 0, -1);
                $req = $bdd->prepare($query);
                $req->execute();
                $req->closeCursor();
            }
        }

        $query = "SELECT h.id, h.name, h.hostname, h.port, h.key, GROUP_CONCAT(DISTINCT hu.usergroupsId) as usergroups, GROUP_CONCAT(DISTINCT hh.hostgroupsId) as hostgroups FROM hosts h LEFT JOIN hosts_usergroups hu ON h.id = hu.hostsId AND h.id = ".$id." LEFT JOIN hosts_hostgroups hh ON h.id = hh.hostsId AND h.id = ".$id." WHERE h.id = ".$id." GROUP BY h.id, h.name, h.hostname, h.port, h.key;";
        $req = $bdd->prepare($query);
        $req->execute();
        $result = $req->fetchAll(PDO::FETCH_ASSOC);
        $req->closeCursor();
        if(!is_null($result[0]['usergroups'])){
            $result[0]['usergroups'] = explode(',', $result[0]['usergroups']);
            foreach($result[0]['usergroups'] as $k => &$v)
                $v = intval($v);
        }
        if(!is_null($result[0]['hostgroups'])){
            $result[0]['hostgroups'] = explode(',', $result[0]['hostgroups']);
            foreach($result[0]['hostgroups'] as $k => &$v)
                $v = intval($v);
        }
        $response->getBody()->write(json_encode($result[0]));
        return $response;
    });

};
