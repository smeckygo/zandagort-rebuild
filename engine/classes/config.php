<?php
/**
 * Created by PhpStorm.
 * User: Smeckygo
 * Date: 2018.07.08.
 * Time: 18:18
 */

    /**
     *  Server Connection
     */
    $connection = array(
        "host"      => "localhost",
        "user"      => "root",
        "password"  => "",
        "gameServer"=> "zandagort",
        "logServer" => "zandagort_nemlog"
    );

    // Fejlesztői mód
    $developing = true;

    // Fut a szerver?
    // TODO : config adatbázis
    $serverStarted = true;

    // Nem választható nevek listája
    $unvaliableNames = array(
        'Zandagort', 'Smeckygo', 'Smecklee', 'Admin', 'Adminisztrátor', 'Moderátor', 'Administrator', 'Moderator'
    );