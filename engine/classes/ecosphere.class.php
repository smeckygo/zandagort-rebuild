<?php
/**
 * Created by PhpStorm.
 * User: Éva
 * Date: 2018.07.14.
 * Time: 18:57
 */

namespace Ecosphere;


class Ecosphere
{
    public $ls, $gs, $planet;
    public $ecosphereData;

    public function __construct()
    { global $ls, $gs, $Planets;
        $this->planets = $Planets;
        $this->ls = $ls;
        $this->gs = $gs;

    }

    public function getPlanet($planetId)
    {
        $this->planet = $this->planets->getPlanet($planetId);
        return $this->planet;
    }

    public function getAverageEcosphereData($planetId)
    {
        $this->ecosphereData = $this->gs->fetchAllRow('SELECT * FROM bolygo_faj_celszam WHERE osztaly=? AND terulet=?', array($this->planet->osztaly, round($this->planet->terulet/100000)));
        return $this->ecosphereData;
    }

    public function ecosphereResetOnPlanet($planetId)
    {
        $this->getPlanet($planetId);
        $this->getAverageEcosphereData($planetId);

        foreach($this->ecosphereData as $data){
            $this->gs->execute('UPDATE bolygo_eroforras SET db=? WHERE eroforras_id=? AND bolygo_id=?', array($data->db, $data->eroforras_id, $this->planet->id));
        }
    }

}

$ecosphere = new Ecosphere();