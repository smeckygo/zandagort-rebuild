<?php

/**
 * Created by PhpStorm.
 * User: Smeckygo
 * Date: 2018.07.08.
 * Time: 18:11
 */
namespace Account;

class Account {
    public static $userIp;

    private $gs, $ls, $planets;
    public $userEmail, $userName, $userPassword;
    public $unvaliableNames;
    public static $minimumNicknameLength;
    public static $maximumNicknameLength;
    public static $minimumPasswordLength;
    public static $maximumPasswordLength;
    public static $systemSalt;

    /**
     * Account constructor.
     */
    function __construct()
    {  global $gs, $ls, $Planets, $unvaliableNames;
        $this->gs = $gs;
        $this->ls = $ls;
        $this->planets = $Planets;
        $this->unvaliableNames = $unvaliableNames;
        Account::$minimumNicknameLength = 4;
        Account::$maximumNicknameLength = 32;
        Account::$minimumPasswordLength = 6;
        Account::$maximumPasswordLength = 32;
        Account::$systemSalt  = "klghfdkhgfdhj";
    }

    /**
     *  Ideiglenes adatmentés kiíráshoz
     */
    public function prepareUserData(){
        $this->userName     = $_POST['reg_nev'];
        $this->userEmail    = $_POST['reg_email_1'];
        $this->userPassword     = $_POST['reg_jelszo_1'];
    }

    /**
     * @param $name         // Felhasználói név
     * @param $email        // Email
     * @param $password     // Jelszó
     * @param $gtc          // Felhasználói szerződés
     */
    public function register($name, $email, $password, $gtc) {
        $userName   = $this->valiableUserName($name);
        $emailValid      = $this->emailAddressValidate($email);
        $password   = $this->passwordValidation($password);
        if($userName[1]){
            if($emailValid[1]){
                if($password[1]){
                    if($this->addUser($name, $email, $password[2][2], $gtc, OtherFunctions::randomgen(32))){
                        if(isset($_POST['reg_koord'])){ // give coords for register
                            $this->planets->registerNearThisCoords($_POST['reg_koord'], $_POST['reg_osztaly_valaszto']);
                        }
                    }
                }else{
                    echo $password[0];
                    $this->prepareUserData();
                    // Hiba a jelszóval
                }
            }else{
                echo $emailValid[0];
                $this->prepareUserData();
                // Hiba az emaillel
            }
        }else{
            echo $userName[0];
            $this->prepareUserData();
            // Hiba a felhasználónévvel
        }
    }

    public function activateUser($activateCode){
        //mysql_query('update userek set tulaj_szov=-'.$user_id.',vedelem=2,fobolygo='.$bolygo['id'].' where id='.$user_id);  // aktiválás után
        // $penzlimit=mysql2num('select penz_kaphato_max from userek where id=1');  $stockexchange

    }

    /**
     * @param $name
     * @return array
     */
    private function valiableUserName($name){
        if (strlen($name) <= Account::$minimumNicknameLength) {
            return array(1000, false);
        } elseif (strlen($name) > Account::$maximumNicknameLength) {
            return array(1001, false);
        } elseif (in_array(strtolower($name), strtolower($this->unvaliableNames))) {
            return array(1002, false);
        } elseif (Account::findNicknameInDatabase($name)) {
            return array(1003, false);
        } else {
            return array(0, true);
        }
    }

    /**
     * @param $email
     * @return array
     */
    public function emailAddressValidate($email){
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!$this->findEmailAddressInDatabase($email)) {
                return array(0, true);
            } else {
                return array(1010, false);
            }
        } else {
            return array(1011, false);
        }
    }

    private function passwordValidation($password){
        if($password[0] == $password[1]){
            if(strlen($password[0]) >= Account::$minimumPasswordLength && strlen($password[0]) <= Account::$maximumPasswordLength){
                return array(0, true, $this->hashPassword($password));
            }else{
                return array(1021, false);
            }
        }else{
            return array(1020, false);
        }
    }

    /**
     * @param $name
     * @return int
     */
    private function findNicknameInDatabase($name){
        return $this->gs->rowCount('SELECT nev FROM users WHERE username=?', array($name));
    }

    /**
     * @param $email
     * @return int
     */
    private function findEmailAddressInDatabase($email){
        return $this->gs->rowCount('SELECT email FROM users WHERE email=?', array($email));
    }

    /**
     * @param $password
     * @return array
     */
    private function hashPassword($password){
        $passwordSalt = OtherFunctions::randomgen(32);
        $passwordHash = hash('whirlpool',$password.$passwordSalt.Account::$systemSalt);
        $passwordHashSys = hash('whirlpool', $password.Account::$systemSalt);
        return array($passwordSalt, $passwordHash, $passwordHashSys);
    }


    /**
     * @param $username
     * @param $email
     * @param $password
     * @return bool
     */
    private function addUser($username, $email, $password, $gtc, $activateKey){
        // add user to Users Table
        $this->gs->execute('INSERT INTO users (username, email, password, active, gtc)VALUES(?,?,?,?,?)', array($username, $email, $password, 0, $gtc));
        // generate user_configuration
        $this->gs->execute('INSERT INTO users_configuration (user_id, activateKey)VALUES(?,?)', array($this->gs->lastInsertId(), $activateKey));
        if(!$this->gs->errorCode()){
            return true;
        }
    }
}
$Account = new Account();