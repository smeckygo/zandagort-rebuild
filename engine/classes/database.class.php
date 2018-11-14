<?php
include("/engine/classes/config.php");
/**
 * Created by PhpStorm.
 * User: Smeckygo
 * Date: 2018.07.08.
 * Time: 18:16
 */
class ServerConnection extends PDO {

    public static $developing;


    public function __construct($username, $passwd, $host, $dbname)
    {  global $developing;
        $dsn = 'mysql:host='.$host.';dbname='.$dbname;
        $options = array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
        );
        parent::__construct($dsn, $username, $passwd, $options);
        ServerConnection::setDevelopingStatus($developing);
    }

    public static function setDevelopingStatus($status){
        if($status){
            ini_set("display_errors", 1);
            ServerConnection::$developing = true;
        }else{
            ini_set("display_errors", 0);
            ServerConnection::$developing = false;
        }
        return ServerConnection::$developing;
    }

    public function execute($sql, $array = '', $mode = 0){
        $return = $this->prepare($sql);

        if(!is_array($array)){
            $return->execute();
        }else{
            $return->execute($array);
        }

        switch($mode){
            default : echo 'Executed : '. $this->rowCount($sql) . ' Row.'; ;break;
            case 1 : return $return; ;break;
        }
        // Ha hibakód van lekérdezéskor, akkor adatbázisba mentse
        if($return->errorCode() > 0 && ServerConnection::$developing == false){
            //$this->writeErrorInfo();
        }else if($return->errorCode() > 0 && ServerConnection::$developing == true) {
            print_r($return->errorInfo());
        }
    }

    public function fetchRow($sql, $array = '', $options = PDO::FETCH_BOTH){
        $row = $this->execute($sql, $array, 1);
        return $row->fetch($options);
    }

    public function fetchAllRow($sql, $array = '', $options = PDO::FETCH_BOTH){
        $row = $this->execute($sql, $array, 1);
        return $row->fetchAll($options);
    }

    public function rowCount($sql, $array = ''){
        $row = $this->execute($sql, $array, 1);
        return $row->rowCount();
    }
}
// Csatlakozás a játékszerver adatbázisához

$gs = new ServerConnection($connection['user'], $connection['password'], $connection['host'], $connection['gameServer']);
// Csatlakozás a Log szerver adatbázisához
$ls = new ServerConnection($connection['user'], $connection['password'], $connection['host'], $connection['logServer']);
