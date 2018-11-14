<?php
/**
 * Created by PhpStorm.
 * User: Éva
 * Date: 2018.07.14.
 * Time: 20:37
 */

namespace Building;


class Building
{
    function __construct()
    { global $ls, $gs;
        $this->ls = $ls;
        $this->gs = $gs;
    }

    public function resetBuildingQue($planetId){
        $this->gs->execute('delete from queue_epitkezesek where bolygo_id=?', array($planetId));
        $this->gs->execute('delete from cron_tabla where bolygo_id=?', array($planetId));
    }
}

$Building = new Building();