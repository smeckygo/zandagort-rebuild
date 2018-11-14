<?php

/**
 * Created by PhpStorm.
 * User: Éva
 * Date: 2018.07.14.
 * Time: 15:03
 */
class stockExchange
{
    public $ls, $gs;
    function __construct()
    {global $ls, $gs;
        $this->ls = $ls;
        $this->gs = $gs;
    }

    public function deleteAllResourceTransfer($planetId){
        $this->gs->execute('delete from cron_tabla_eroforras_transzfer where honnan_bolygo_id=?', array($planetId));
        $this->gs->execute('delete from cron_tabla_eroforras_transzfer where hova_bolygo_id=?', array($planetId));
    }

}

$StockExchange = new stockExchange();