<?php
/**
 * Created by PhpStorm.
 * User: Smeckygo
 * Date: 2018.07.08.
 * Time: 20:01
 */

class serverFunctions {
    public $serverStarted;

    function __construct()
    { global $serverStarted;
        $this->serverStarted = $serverStarted;
    }
}

$sf = new serverFunctions();