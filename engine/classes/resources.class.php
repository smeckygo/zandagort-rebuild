<?php
/**
 * Created by PhpStorm.
 * User: Éva
 * Date: 2018.07.14.
 * Time: 19:21
 */

namespace Resources;


class Resources
{
    public $planet, $Planets;
    public $building, $Building;
    public $sExchange, $SExchange;
    public $ls, $gs;

    public function __construct()
    { global $Planets, $Building, $StockExchange, $ls, $gs;
        $this->ls = $ls;
        $this->gs = $gs;
        $this->Planets = $Planets;
        $this->Building = $Building;
        $this->SExchange = $StockExchange;
    }

    /** Bolygó adatok lekérése
     * @param $planetId
     * @return mixed
     */
    public function getPlanet($planetId)
    {
        $this->planet = $this->Planets->getPlanet($planetId);
        return $this->planet;
    }

    /** Nyersanyagok újra generálása
     * @param $resources
     * @param $planet
     */
    public function planetResourcesReset($resources, $planet)
    {
        if(is_array($resources)){
            foreach($resources as $resourceId){
                if($planet->terulet > 2000000){
                    if($planet->osztaly == 1 && $resourceId == 63){
                        $db = round($this->planet->terulet/400);
                    }elseif($planet->osztaly == 2 && $resourceId == 63){
                        $db = round($this->planet->terulet/2000);
                    }

                    if($planet->osztaly == 2 && $resourceId == 61){
                        $db = round($this->planet->terulet/2);
                    }elseif($planet->osztaly == 2 && $resourceId == 61){
                        $db = round($this->planet->terulet/4);
                    }

                    if($planet->osztaly == 3 && $resourceId == 60){
                        $db = (3.5*$this->planet->terulet);
                    }elseif($planet->osztaly == 3 && $resourceId == 60){
                        $db = (1.75*$this->planet->terulet);
                    }

                    if($planet->osztaly == 5 && $resourceId == 62){
                        $db = ($this->planet->terulet);
                    }elseif($planet->osztaly == 5 && $resourceId == 62){
                        $db = ($this->planet->terulet/2);
                    }
                }else{
                    if($resourceId == 60){
                        $db = round($this->planet->terulet*1.75);
                    }elseif($resourceId == 61){
                        $db = round($this->planet->terulet/4);
                    }elseif($resourceId == 62){
                        $db = round($this->planet->terulet/2);
                    }elseif($resourceId == 6){
                        $db = round($this->planet->terulet/2000);
                    }
                }
                $this->gs->execute('UPDATE bolygo_eroforras SET db=? WHERE eroforras_id=? AND bolygo_id=?', array($db, $resourceId, $planet->bolygo_id));
            }
        }else{
            // csak egy erőforrást, megadott kalkulációval
        }

    }

    /** Populáció módosítása
     * @param $count
     * @param $planet
     */
    function setPopulation($count, $planet){
        $this->gs->execute('UPDATE bolygo_ember SET pop=? WHERE bolygo_id=?', array($count, $planet->bolygo_id));
    }

    /** Nyersanyagok nullázása (erőforrások tisztítása)
     * @param $planetId
     */
    public function clearAllResources($planetId){
        $this->gs->execute('update bolygo_eroforras set db=0 where eroforras_id>=55 and bolygo_id=?', array($planetId));
    }

    /** Nyersanyagok módosítása
     * @param $resourceId
     * @param $planet
     * @param int $count
     */
    public function setResources($resourceId, $planet, $count = 0){
        if(is_array($resourceId)){
            foreach($resourceId as $resources){
                $this->gs->execute('UPDATE bolygo_eroforras SET db=? WHERE eroforras_id=? AND bolygo_id=?', array($resources[1], $resources[0], $planet->bolygo_id));
            }
        }else{
            $this->gs->execute('UPDATE bolygo_eroforras SET db=? WHERE eroforras_id=? AND bolygo_id=?', array($count, $resourceId, $planet->bolygo_id));
        }
    }

    /** Nyersanyag módosítás Delta értékkel
     * @param $resourceId
     * @param $planet
     * @param int $count
     * @param int $deltaDb
     */

    public function setResourcesWithDelta($resourceId, $planet, $count = 0, $deltaDb = 0){
        if(is_array($resourceId)){
            foreach($resourceId as $resourceId => $resource){
                $this->gs->execute('UPDATE bolygo_eroforras SET db=?, delta_db=? WHERE eroforras_id=? AND bolygo_id=?', array($resource[0], $resource[1], $resourceId, $planet->bolygo_id));
            }
        }else{
            $this->gs->execute('UPDATE bolygo_eroforras SET db=?, delta_db=? WHERE eroforras_id=? AND bolygo_id=?', array($count, $deltaDb, $resourceId, $planet->bolygo_id));
        }
    }

    /** Bolygó reset
     * @param $planetId
     */
    function resetAllResources($planetId){ // Except Ecosphere
        $this->getPlanet($planetId);
        $this->clearAllResources($planetId);// mindent nullázni
            // 60: nyers_kő, 61: nyers_homok, 62: Titánérc, 63: Uránérc
        $this->planetResourcesReset(array(60, 61, 62, 63), $this->planet);
            // Nyersanyagok adása : 64:fa, 65:kő, 72:üveg
        $this->setResources(array(64 => 400000, 65 => 250000, 72 => 20000), $this->planet);
            // populáció Módosítása
        $this->setPopulation(30000, $this->planet);
            // alap dolgok hozzáadása : 55:lakohely, 56: elelmiszer, 57:munkaero, 59:energia
        $this->setResources(array(55 => 30000, 56 => 40000, 57 => 15000, 59 => 40000), $this->planet);
            // Képzett munkaerő 58/77 :
        $this->setResourcesWithDelta(array(58 => array(5000, 5000), 77 => array(5000, 5000)), $this->planet);
            // építési lista törlése
        $this->Building->resetBuildingQue($planetId);
            // nyersanyagszállítások törlése
        $this->SExchange->deleteAllResourceTransfer($planetId);
    }
}
$resources = new Resources();