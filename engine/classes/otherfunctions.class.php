<?php
/**
 * Created by PhpStorm.
 * User: Smeckygo
 * Date: 2018.07.10.
 * Time: 17:00
 */

class OtherFunctions {
    public static function randomgen($length) {
        $x='';for($i=0;$i<$length;$i++) $x.=chr(96+mt_rand(1,26));
        return $x;
    }
}