<?php
/**
 * Created by PhpStorm.
 * User: Éva
 * Date: 2018.07.14.
 * Time: 13:10
 */

namespace Planets;


class Planets
{
    public $ls, $gs, $ecosphere, $resources;

    function __construct()
    {   global $ls, $gs, $ecosphere, $resources;
        $this->gs = $gs;
        $this->ecosphere = $ecosphere;
        $this->resources = $resources;
    }

    public function registerNearThisCoords($coordinate, $planetType){
        $getPlanet = $this->getNearPlanetFromCoords($coordinate, $planetType);
    }

    private function getNearPlanetFromCoords($coordinate, $planetType){
        $classFilter = $this->planetTypeFilter($planetType);

        if($coordinate[1] != false){
            $coordinate = $this->coordinateParser($coordinate);
            $row = $this->gs->fetchRow('select * from bolygok where letezik=1 and tulaj=0 and moral=100 and alapbol_regisztralhato=1 ? order by pow(?-x,2)+pow(?-y,2) limit 1', array($classFilter ,$coordinate[0], $coordinate[1]));

           // adott koordinátát ás nincs hiba
        }elseif($coordinate[1] == false){
            // adott koordinátát de hibás
            return array(1302, false);
        }else{
            $row = $this->gs->fetchRow('select * from bolygok where letezik=1 and tulaj=0 and moral=100 and alapbol_regisztralhato=1 ? order by rand() limit 1', array($classFilter));
            //select * from bolygok where letezik=1 and tulaj=0 and moral=100 and alapbol_regisztralhato=1$osztaly_szuro order by rand() limit 1
            // nem adott koordinátát
        }
        return $row;
    }

    public function planetTypeFilter($type = ''){
        if($type >= 1 && $type <= 5){
            return 'and osztaly='.$type;
        }else{
            return '';
        }
    }

    public function getPlanet($planetId)
    {
        $this->planet = $this->gs->fetchRow('SELECT * FROM bolygok WHERE id=?', array($planetId), PDO::FETCH_OBJ);
        return $this->planet;
    }

    /**
     * @param $coordinate
     * @return array
     */
    public function coordinateParser($coordinate){
        $coordinate=explode(',',$coordinate);
        if (count($coordinate)!=2) {
            // Rossz Koordináták
            return array(1300, false);
        }
        $y_str=trim($coordinate[0]);
        $x_str=trim($coordinate[1]);
        //if ($lang_lang=='hu') {
        $k=mb_substr($y_str,0,1,'UTF-8');$y_num=(int)strtr(trim(mb_substr($y_str,1,1000,'UTF-8')),array(' '=>''));
        if ($k=='É' || $k=='E' || $k=='é' || $k=='e') $y=-2*$y_num;
        elseif ($k=='D' || $k=='d') $y=2*$y_num;
        else $y=2*((int)$y_str);

        $k=mb_substr($x_str,0,1,'UTF-8');$x_num=(int)strtr(trim(mb_substr($x_str,1,1000,'UTF-8')),array(' '=>''));
        if ($k=='N' || $k=='n') $x=-2*(int)strtr(trim(mb_substr($x_str,2,1000,'UTF-8')),array(' '=>''));
        elseif ($k=='K' || $k=='k') $x=2*$x_num;
        else $x=2*((int)$x_str);
        /*} else {
            $k=mb_substr($y_str,0,1,'UTF-8');$y_num=(int)strtr(trim(mb_substr($y_str,1,1000,'UTF-8')),array(' '=>''));
            if ($k=='N' || $k=='n') $y=-2*$y_num;
            elseif ($k=='S' || $k=='s') $y=2*$y_num;
            else $y=2*((int)$y_str);
            $k=mb_substr($x_str,0,1,'UTF-8');$x_num=(int)strtr(trim(mb_substr($x_str,1,1000,'UTF-8')),array(' '=>''));
            if ($k=='W' || $k=='w') $x=-2*$x_num;
            elseif ($k=='E' || $k=='e') $x=2*$x_num;
            else $x=2*((int)$x_str);
        }*/

        return array($x, $y);
    }

    public function planetReset($planetId)
    {
        $this->ecosphere->ecosphereResetOnPlanet($planetId);
        $this->resources->resetAllResources($planetId);    // csak az alap dolgokat. nyerskő, nyershomok, titánérc, uránérc

    }
}

$Planets = new Planets();