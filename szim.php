<?php
include_once('config.php');

include_once('lang_s.php');
include_once('tutorial_szovegek.php');
include_once('tut_levelek_hu.php');
include_once('tut_levelek_en.php');

if (!isset($argv[1]) or $argv[1]!=$zanda_private_key) exit;
ini_set('DISPLAY_ERRORS', 1);

set_time_limit(0);//igy lefuthat minden, max neha egy-egy percet kesik a galaxis oraja (a lock miatt)
header('Cache-Control: no-cache');
header('Expires: -1');
header('Content-type: text/plain; charset=utf-8');


$fantom_lebukas=true;


//include('csatlak.php');


if ($fut_a_szim) {

	class SimulateRounds
	{
		function __construct()
		{
			global $gs, $ls;
			$this->gs = $gs->pdo;
			$this->ls = $ls->pdo;
		}

		function BuildNewFactory($planet_id, $factory_id, $active, $count = 1)
		{
			if ($count <= 0) return false;
			$q = $this->gs->prepare('SELECT * FROM bolygok WHERE id=:planetId');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			$planet = $q->fetch(PDO::FETCH_ASSOC);
			//
			//van-e eleg hely, mire van eleg hely
			$q = $this->gs->prepare('SELECT gyt.terulet FROM gyartipusok gyt, gyarak gy WHERE gyt.id=gy.tipus AND gy.id=:factoryId');
			$q->execute(
					array(
							':factoryId' => $factory_id
					)
			);
			$factoryType = $q->fetch(PDO::FETCH_ASSOC);
			//ha (terulet_beepitett+db*gyar_terulet)/terraformaltsag*10000 > terulet, akkor csak részteljesítés:
			//db = floor((terulet/10000*terraformaltsag-terulet_beepitett)/gyar_terulet)
			//if (($bolygo['terulet_beepitett']+$darab*$gyartipus['terulet'])/$bolygo['terraformaltsag']*10000>$bolygo['terulet']) {
			if ($planet['terulet_beepitett'] / $planet['terraformaltsag'] * 10000 > $planet['terulet']) {//mivel az epulofelben levo mar beleszamit, es itt meg nincs torolve, ezert sajat magat mar ne adja hozza
				$planet = floor(($planet['terulet'] / 10000 * $planet['terraformaltsag'] - $planet['terulet_beepitett']) / $factoryType['terulet']);
				if ($count <= 0) return false;
			}
			//
			if (!$this->reachableThisFactory($planet['osztaly'], $planet['hold'], $factory_id, $planet['tulaj'])) $active = 0;//ha barmi miatt nem kaphat ilyet, akkor inaktivalni
			//if(0 == 1)$active = 0;
			$q = $this->gs->prepare('SELECT * FROM bolygo_gyar WHERE bolygo_id=:planetId AND gyar_id=:factoryId');
			$q->execute(
					array(
							':planetId' => $planet_id,
							':factoryId' => $factory_id
					)
			);
			$result = $q->rowCount();
			if ($result) {//van mar ilyen -> update
				if ($active) {
					$q = $this->gs->prepare('UPDATE bolygo_gyar SET db=db+:c,aktiv_db=aktiv_db+:c WHERE bolygo_id=:planetId AND gyar_id=:factoryId');
					$q->execute(
							array(
									':c' => $count,
									':planetId' => $planet_id,
									':factoryId' => $factory_id
							)
					);

				} else {
					$q = $this->gs->prepare('UPDATE bolygo_gyar SET db=db+:c WHERE bolygo_id=:planetId AND gyar_id=:factoryId');
					$q->execute(
							array(
									':c' => $count,
									':planetId' => $planet_id,
									':factoryId' => $factory_id
							)
					);
				}
			} else {//nincs meg -> insert
				if ($active) $a = $count; else $a = 0;
				$q = $this->gs->prepare('INSERT INTO bolygo_gyar (bolygo_id,gyar_id,db,aktiv_db) VALUES(:planetId,:factoryId,:c,:a)');
				$q->execute(
						array(
								':planetId' => $planet_id,
								':factoryId' => $factory_id,
								':c' => $count,
								':a' => $a
						)
				);
				$q = $this->gs->prepare('
				INSERT INTO bolygo_gyar_eroforras
				SELECT ?,?,eroforras_id,0,0,0
				FROM gyar_eroforras
				WHERE gyar_id=?');
				$q->execute(
						array($planet_id, $factory_id, $factory_id)
				);
			}
			$this->planetFactoryResources($planet_id);
		}

		function reachableThisFactory($bolygo_osztaly, $bolygo_hold, $gyar_id, $user_id)
		{
			$q = $this->gs->prepare('SELECT * FROM gyarak WHERE id=:factoryId');
			$q->execute(
					array(':factoryId' => $gyar_id)
			);
			$gyar = $q->fetch(PDO::FETCH_BOTH);

			$q = $this->gs->prepare('SELECT * FROM eroforrasok e WHERE id=' . $gyar['uzemmod']);
			$q->execute(array(':factType' => $gyar['uzemmod']));
			$aux = $q->fetch(PDO::FETCH_BOTH);

			$lehet = true;
			if ($aux['tipus'] == 1) {
				if ($aux['bolygo_osztaly'] & pow(2, $bolygo_osztaly - 1)) $lehet = true; else $lehet = false;
			}
			switch ($gyar['tipus']) {
				case 1:
					if ($bolygo_osztaly != 2) $lehet = false;//nap
					break;
				case 5:
					if ($bolygo_osztaly != 1 && $bolygo_osztaly != 4) $lehet = false;//viz
					break;
				case 6:
					if ($bolygo_osztaly < 3) $lehet = false;//hullam
					break;
				case 7:
					if ($bolygo_osztaly < 3 || $bolygo_hold == 0) $lehet = false;//arapaly
					break;
				case 8:
					if ($bolygo_osztaly != 4) $lehet = false;//ozmozis
					break;
				case 9:
					if ($bolygo_osztaly != 5) $lehet = false;//geoterm
					break;
				case 11:
					if ($bolygo_osztaly == 5) $lehet = false;//bioetanol
					break;
			}
			$q = $this->gs->prepare('
			SELECT coalesce(min(if(uksz.szint>=gyksz.szint,1,0)),999)
			FROM gyar_kutatasi_szint gyksz, user_kutatasi_szint uksz
			WHERE gyksz.gyar_id=' . $gyar_id . ' AND gyksz.kf_id=uksz.kf_id AND uksz.user_id=' . $user_id . '
			');
			$q->execute(array(':factoryId' => $gyar_id, ':userId' => $user_id));
			$aux2 = $q->fetch(PDO::FETCH_BOTH);
			if (!$aux2) return $lehet;//nincs is ra igeny
			if ($aux2[0] == 0) return false; else return $lehet;
		}


		function planetBuildingAreaRefresh($planet_id)
		{
			//beepitett terulet
			$buildedArea = 0;
			$q = $this->gs->prepare('SELECT coalesce(sum(bgy.db*gyt.terulet),0) FROM bolygo_gyar bgy, gyarak gy, gyartipusok gyt WHERE bgy.gyar_id=gy.id AND gy.tipus=gyt.id AND bgy.bolygo_id=:planetId');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			$res[0] = $q->fetch(PDO::FETCH_BOTH);
			$buildedArea += $res[0][0];

			//epulofelben levok hozzaadasa
			$q = $this->gs->prepare('SELECT coalesce(sum(c.darab*gyt.terulet),0) FROM cron_tabla c,gyarak gy,gyartipusok gyt WHERE c.feladat=1 AND c.bolygo_id=:planetId AND c.gyar_id=gy.id AND gy.tipus=gyt.id');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			$res_[1] = $q->fetch(PDO::FETCH_BOTH);
			$buildedArea += $res_[1][0];

			//rombolofelben levok hozzaadasa
			$q = $this->gs->prepare('SELECT coalesce(sum(c.darab*gyt.terulet),0) FROM cron_tabla c,gyarak gy,gyartipusok gyt WHERE c.feladat=2 AND c.bolygo_id=:planetId AND c.gyar_id=gy.id AND gy.tipus=gyt.id');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			$res__[2] = $q->fetch(PDO::FETCH_BOTH);
			$buildedArea += $res__[2][0];
			//terraformaltsag, effektiv beepitett terulet
			$q = $this->gs->prepare('SELECT terraformaltsag FROM bolygok WHERE id=:planetId');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			$result = $q->fetch(PDO::FETCH_BOTH);
			$buildedEffectiveArea = round($buildedArea / $result[0] * 10000);
			//
			$q = $this->gs->prepare('UPDATE bolygok SET terulet_beepitett=:buildedArea,terulet_beepitett_effektiv=:buildedAreaEffective,terulet_szabad=greatest(terulet-:buildedAreaEffective,0) WHERE id=:planetId');
			$q->execute(
					array(
							':buildedArea' => $buildedArea,
							':buildedAreaEffective' => $buildedEffectiveArea,
							':planetId' => $planet_id
					)
			);
		}

		function planetFactoryResources($planet_id)
		{
			//ha valamelyik gyarbol 0 maradt, akkor a bgy es bgye-bol torolni kell
			$q = $this->gs->prepare('SELECT * FROM bolygo_gyar WHERE bolygo_id=:planetId AND db=0');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			foreach ($q->fetchAll(PDO::FETCH_BOTH) as $res) {
				$q = $this->gs->prepare('DELETE FROM bolygo_gyar_eroforras WHERE bolygo_id=:planetId AND gyar_id=:factoryId');
				$q->execute(
						array(
								':planetId' => $planet_id,
								':factoryId' => $res['gyar_id']
						)
				);
				echo $q->rowCount();
			}
			$q = $this->gs->prepare('DELETE FROM bolygo_gyar WHERE db=0 AND bolygo_id=:planetId');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			//
			$this->planetBuildingAreaRefresh($planet_id);
			$q = $this->gs->prepare('
			UPDATE bolygo_gyar_eroforras bgye,(
				SELECT bgy.bolygo_id,bgy.gyar_id,gye.eroforras_id,bgy.aktiv_db,gye.io,coalesce(if(gye.io>=0,0,round(bgy.aktiv_db*gye.io/sumiotabla.sumio*1000000000)),0) AS reszarany
				FROM (
					SELECT bgy.bolygo_id,gye.eroforras_id,sum(bgy.aktiv_db*if(gye.io>=0,0,gye.io)) AS sumio
					FROM bolygo_gyar bgy,gyar_eroforras gye
					WHERE bgy.gyar_id=gye.gyar_id AND bgy.bolygo_id=:planetId
					GROUP BY bgy.bolygo_id,gye.eroforras_id
				) sumiotabla,bolygo_gyar bgy,gyar_eroforras gye
				WHERE bgy.gyar_id=gye.gyar_id AND bgy.bolygo_id=:planetId AND gye.eroforras_id=sumiotabla.eroforras_id
			) apdet
			SET bgye.aktiv_db=apdet.aktiv_db,
			bgye.io=apdet.io,
			bgye.reszarany=apdet.reszarany
			WHERE bgye.bolygo_id=:planetId AND bgye.gyar_id=apdet.gyar_id AND bgye.eroforras_id=apdet.eroforras_id
			');
			$q->execute(
					array(
							':planetId' => $planet_id
					)
			);
			echo $q->rowCount();
		}


		function planetOwnerChange($bolygo_id, $regi_tulaj_id, $uj_tulaj_id, $regi_tulaj_szov, $uj_tulaj_szov)
		{
			//regiok: ha a regi tulajnak nem marad bolygoja vmelyik aktualis regioban, akkor valtani
			if ($regi_tulaj_id > 0) {
				$r01 = $this->gs->prepare('SELECT * FROM userek WHERE id=?');
				$r01->execute(array($regi_tulaj_id));

				$regi_tulaj = $r01->fetch(PDO::FETCH_BOTH);
				if ($regi_tulaj['karrier'] == 1 and $regi_tulaj['speci'] == 2) {//kereskedo
					$r02 = $this->gs->prepare('SELECT count(1) FROM bolygok WHERE tulaj=? AND regio=?');
					$r02->execute(array($regi_tulaj_id, $regi_tulaj['aktualis_regio']));
					$r02 = $r02->fetch(PDO::FETCH_BOTH);

					$r03 = $this->gs->prepare('SELECT count(1) FROM bolygok WHERE tulaj=? AND regio=?');
					$r03->execute(array($regi_tulaj_id, $regi_tulaj['aktualis_regio2']));
					$r03 = $r03->fetch(PDO::FETCH_BOTH);

					$bolygok_szama_az_aktualis_regioban = $r02[0];
					$bolygok_szama_az_aktualis_regio2ben = $r03[0];
					if ($bolygok_szama_az_aktualis_regioban == 0 and $bolygok_szama_az_aktualis_regio2ben == 0) {//ilyen elvileg nem fordulhat elo
						$r04 = $this->gs->prepare('SELECT regio FROM bolygok WHERE tulaj=? GROUP BY regio ORDER BY sum(iparmeret) DESC LIMIT 1');
						$r04->execute(array($regi_tulaj_id));
						$r04 = $r04->fetch(PDO::FETCH_BOTH);

						$uj_tobbsegi_regio = $r04[0];
						$r05 = $this->gs->prepare('UPDATE userek SET tobbsegi_regio=?,aktualis_regio=?,aktualis_regio2=?,valasztott_regio=?,valasztott_regio2=? WHERE id=?');
						$r05->execute(array($uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $regi_tulaj_id));
					} elseif ($bolygok_szama_az_aktualis_regioban == 0) {
						$r04 = $this->gs->prepare('UPDATE userek SET aktualis_regio=aktualis_regio2,valasztott_regio=valasztott_regio2 WHERE id=?');
						$r04->execute(array($regi_tulaj_id));
					} elseif ($bolygok_szama_az_aktualis_regio2ben == 0) {
						$r04 = $this->gs->prepare('UPDATE userek SET aktualis_regio2=aktualis_regio,valasztott_regio2=valasztott_regio WHERE id=?');
						$r04->execute(array($regi_tulaj_id));
					}
				} else {//nem kereskedo
					$r02 = $this->gs->prepare('SELECT count(1) FROM bolygok WHERE tulaj=? AND regio=?');
					$r02->execute(array($regi_tulaj_id, $regi_tulaj['aktualis_regio']));
					$r02 = $r02->fetch(PDO::FETCH_BOTH);
					$bolygok_szama_az_aktualis_regioban = $r02[0];
					if ($bolygok_szama_az_aktualis_regioban == 0) {//ha mar nem maradt, akkor ujraszamolni a tobbsegit
						$r04 = $this->gs->prepare('SELECT regio FROM bolygok WHERE tulaj=? GROUP BY regio ORDER BY sum(iparmeret) DESC LIMIT 1');
						$r04->execute(array($regi_tulaj_id));
						$r04 = $r04->fetch(PDO::FETCH_BOTH);

						$uj_tobbsegi_regio = $r04[0];
						$r05 = $this->gs->prepare('UPDATE userek SET tobbsegi_regio=?,aktualis_regio=?,aktualis_regio2=?,valasztott_regio=?,valasztott_regio2=? WHERE id=?');
						$r05->execute(array($uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $uj_tobbsegi_regio, $regi_tulaj_id));
					}
				}
			}
			//szabadpiaci ajanlatokat is atadni
			$r06 = $this->gs->prepare('UPDATE szabadpiaci_ajanlatok aj, bolygok b
				SET aj.user_id=b.tulaj
				WHERE aj.bolygo_id=b.id AND aj.user_id!=b.tulaj');
			$r06->execute();
			//autotranszfereket leallitani, ha a tulaj_szov megvaltozott
			if ($uj_tulaj_szov != $regi_tulaj_szov) {
				$r07 = $this->gs->prepare('DELETE FROM cron_tabla_eroforras_transzfer WHERE honnan_bolygo_id=?');
				$r07->execute(array($bolygo_id));

				$r08 = $this->gs->prepare('DELETE FROM cron_tabla_eroforras_transzfer WHERE hova_bolygo_id=?');
				$r08->execute(array($bolygo_id));

			}
			//specko epuletek inaktivalasa, ha kell
			$r09 = $this->gs->prepare('
				SELECT bgy.gyar_id,coalesce(min(if(uksz.szint>=gyksz.szint,1,0))) FROM bolygo_gyar bgy, gyar_kutatasi_szint gyksz, user_kutatasi_szint uksz
				WHERE bgy.bolygo_id=? AND bgy.aktiv_db>0 AND gyksz.gyar_id=bgy.gyar_id AND gyksz.kf_id=uksz.kf_id AND uksz.user_id=?
				GROUP BY bgy.gyar_id
				');
			$r09->execute(array($bolygo_id, $uj_tulaj_id));
			foreach ($r09->fetchAll(PDO::FETCH_BOTH) as $aux) if ($aux[1] == 0) {
				$r10 = $this->gs->prepare('UPDATE bolygo_gyar SET aktiv_db=0 WHERE bolygo_id=? AND gyar_id=?');
				$r10->execute(array($bolygo_id, $aux[0]));
			}
			$this->planetFactoryResources($bolygo_id);
			//flottak bazisa: az adott bolygóról el kell tűnniük
			$r11 = $this->gs->prepare('SELECT id FROM bolygok WHERE tulaj=? ORDER BY nev LIMIT 1');
			$r11->execute(array($regi_tulaj_id));

			$aux_anya = $r11->fetch(PDO::FETCH_BOTH);
			$r12 = $this->gs->prepare('UPDATE flottak f
				SET f.bazis_bolygo=?
				WHERE f.bazis_bolygo=? AND f.tulaj_szov!=?');
			$r12->execute(array($aux_anya[0], $bolygo_id, $uj_tulaj_szov));
			//flottak allomasozasa
			$r13 = $this->gs->prepare('
				UPDATE flottak f,bolygok b
				SET f.statusz=2
				WHERE f.bolygo=b.id AND f.statusz=1 AND b.tulaj_szov!=f.tulaj_szov');
			$r13->execute();
			//felderites (szim.php-ben megtortenik)
			//regi es uj tulaj vedelmi szintjeit ujraszamolni
			$r14 = $this->gs->prepare('SELECT round(terulet/1000000) FROM bolygok WHERE id=' . $bolygo_id);
			$r14->execute(array($bolygo_id));
			$r14 = $r14->fetch(PDO::FETCH_BOTH);
			$bolygo_terulete = $r14[0];
			//
			if ($uj_tulaj_id > 0) {
				$r15 = $this->gs->prepare('SELECT round(sum(terulet)/1000000) FROM bolygok WHERE tulaj=?');
				$r15->execute(array($uj_tulaj_id));
				$r15 = $r15->fetch(PDO::FETCH_BOTH);

				$uj_tulaj_terulete = $r15[0];
				$r16 = $this->gs->prepare('UPDATE userek SET valaha_elert_max_terulet=greatest(valaha_elert_max_terulet,?) WHERE id=?');
				$r16->execute(array($uj_tulaj_terulete, $uj_tulaj_id));

				$this->refreshDefenseLevels($uj_tulaj_id, 0, $uj_tulaj_terulete - $bolygo_terulete);
			} else {//ha npc lesz, akkor vedelmi bonusz nullazasa
				$r16 = $this->gs->prepare('UPDATE bolygok SET vedelmi_bonusz=0 WHERE id=');
				$r16->execute(array($bolygo_id));
			}
			if ($regi_tulaj_id > 0) {
				$r15 = $this->gs->prepare('SELECT round(sum(terulet)/1000000) FROM bolygok WHERE tulaj=?');
				$r15->execute(array($uj_tulaj_id));
				$r15 = $r15->fetch(PDO::FETCH_BOTH);

				$uj_tulaj_terulete = $r15[0];
				$this->refreshDefenseLevels($regi_tulaj_id, 0, $regi_tulaj_terulete + $bolygo_terulete);
			}
		}


		function refreshDefenseLevels($user_id, $teruletet_frissit = 0, $regi_terulet = 0)
		{
			/*mysql_select_db($database_mmog_nemlog);
			mysql_query('insert into terulet_valtozasok (user_id,terulet) values('.$user_id.','.$regi_terulet.')');
			mysql_select_db($database_mmog);*/
			//
			$r01 = $this->gs->prepare('SELECT round(sum(terulet)/1000000) FROM bolygok WHERE tulaj=?');
			$r01->execute(array($user_id));
			$r01 = $r01->fetch(PDO::FETCH_BOTH);
			$ter = $r01[0];
			if ($teruletet_frissit) {
				$r02 = $this->gs->prepare('UPDATE userek SET valaha_elert_max_terulet=greatest(valaha_elert_max_terulet,?) WHERE id=?');
				$r02->execute(array($ter, $user_id));
			}
			$r03 = $this->gs->prepare('SELECT valaha_elert_max_terulet FROM userek WHERE id=?');
			$r03->execute(array($user_id));
			$r03 = $r03->fetch(PDO::FETCH_BOTH);
			$valaha_elert_max_terulet = $r03[0];
			$x = $valaha_elert_max_terulet / 2;
			if ($x == 0) {
				$abszolut_vedettseg = 0;
			} elseif ($x == 1) {
				$abszolut_vedettseg = 1;
			} elseif ($x < 3) {
				$abszolut_vedettseg = 0.5;
			} else {
				$abszolut_vedettseg = 0.4;
			}
			if ($x == 0) {
				$relativ_vedettseg = 0;
			} elseif ($x <= 2) {
				$relativ_vedettseg = 1;
			} elseif ($x < 6) {
				$relativ_vedettseg = 0.75;
			} else {
				$relativ_vedettseg = 0.6;
			}
			$abszolut_vedett_terulet = floor($abszolut_vedettseg * $valaha_elert_max_terulet);
			$reszben_vedett_terulet = floor($relativ_vedettseg * $valaha_elert_max_terulet);
			$r04 = $this->gs->prepare('SELECT id,terulet FROM bolygok WHERE tulaj=? ORDER BY uccso_foglalas_mikor');
			$r04->execute(array($user_id));


			$utolso_abszolut_vedett = 0;
			$elso_nem_vedett = 0;
			$n = 0;
			$terx = 0;
			foreach ($r04->fetchAll(PDO::FETCH_BOTH) as $aux) {
				if ($elso_nem_vedett == 0) {
					$n++;
					$terx += round($aux['terulet'] / 1000000);
					if ($terx <= $abszolut_vedett_terulet) $utolso_abszolut_vedett = $n;
					if ($elso_nem_vedett == 0) if ($terx > $reszben_vedett_terulet) $elso_nem_vedett = $n;
				}
			}
			$jelenlegi_terulet = $ter;
			if ($elso_nem_vedett == 0) $elso_nem_vedett = $n + 1;
			if ($n > 0) {
				//legalabb 1 bolygoja van
				$r040 = $this->gs->prepare('SELECT id,terulet FROM bolygok WHERE tulaj=? ORDER BY uccso_foglalas_mikor', array(PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL));
				$r040->execute(array($user_id));
				$n = 0;
				foreach ($r040->fetchAll(PDO::FETCH_ASSOC) as $aux) {
					$n++;
					if ($n <= $utolso_abszolut_vedett) {
						if ($abszolut_vedett_terulet >= 10) {//5 2M-es vedett bolygotol kezdve az elso is foszthato
							if ($utolso_abszolut_vedett == 1) $vb = 900;
							else $vb = 900 - 100 * ($n - 1) / ($utolso_abszolut_vedett - 1);
						} else {
							if ($utolso_abszolut_vedett == 1) $vb = 1000;
							else $vb = 1000 - 200 * ($n - 1) / ($utolso_abszolut_vedett - 1);
						}
					} elseif ($n < $elso_nem_vedett) {
						if ($utolso_abszolut_vedett == 1) $vb = (($abszolut_vedett_terulet >= 10) ? 900 : 1000) * (1 - ($n - $utolso_abszolut_vedett) / ($elso_nem_vedett - $utolso_abszolut_vedett));
						else $vb = 800 * (1 - ($n - $utolso_abszolut_vedett) / ($elso_nem_vedett - $utolso_abszolut_vedett));
					} else $vb = 0;
					$r05 = $this->gs->prepare('UPDATE bolygok SET vedelmi_bonusz=?,foglalasi_sorszam=? WHERE id=?');
					$r05->execute(array(round($vb), $n, $aux[0]));
				}
			}

			$r08 = $this->gs->prepare('UPDATE userek SET jelenlegi_terulet=?,abszolut_vedett_terulet=?,reszben_vedett_terulet=? WHERE id=?');
			$r08->execute(array($jelenlegi_terulet, $abszolut_vedett_terulet, $reszben_vedett_terulet, $user_id));
		}


		/*
		 * Badge Functions
		 */

		function getBadgeCounts($idk)
		{
			foreach ($idk as $id) {
				$b[$id] = array(0, 0, 0, 0);
			}
			$r = $this->gs->prepare('SELECT badge_id,count(1),coalesce(sum(szin=1),0),coalesce(sum(szin=2),0),coalesce(sum(szin=3),0) FROM user_badge WHERE badge_id IN (?) GROUP BY badge_id');
			$r->execute(array(implode(',', $idk)));

			foreach ($r->fetchAll(PDO::FETCH_BOTH) as $aux) {
				$b[$aux[0]] = array($aux[1], $aux[2], $aux[3], $aux[4]);
			}
			return $b;
		}

		function getBadgeCaseSTR($idk)
		{
			$badgek_szama = $this->getBadgeCounts($idk);
			$case_str = '';
			foreach ($badgek_szama as $id => $szam) $case_str .= "when $id then " . ($szam[0] == 0 ? '1' : ((($szam[1] > 0) and ($szam[2] < 9) and ($szam[3] == 0)) ? '2' : '3')) . "\n";
			return $case_str;
		}

		function giveBadges($user_id, $badge_id)
		{
			$r = $this->gs->prepare('SELECT * FROM user_badge WHERE user_id=? AND badge_id=?');
			$r->execute(array($user_id, $badge_id));
			$aux = $r->fetch(PDO::FETCH_BOTH);

			if ($aux) return false;
			$r = $this->gs->prepare('SELECT count(1),coalesce(sum(szin=1),0),coalesce(sum(szin=2),0),coalesce(sum(szin=3),0) FROM user_badge WHERE badge_id=?');
			$r->execute(array($badge_id));
			$aux = $r->fetch(PDO::FETCH_BOTH);

			if ($aux[0] == 0) $szin = 1;
			//elseif (($szam[1]>0) and ($szam[2]<9) and ($szam[3]==0)) $szin=2;
			else $szin = 3;
			$r_0 = $this->gs->prepare('INSERT IGNORE INTO user_badge (user_id,badge_id,szin) VALUES(?,?,?)');
			$r_0->execute(array($user_id, $badge_id, $szin));
			return true;
		}

		/*
		 *	Message System Functions
		 */

		function technicLevelMessage($kinek, $nev, $szint, $email = '', $nyelv = 'hu', $aktivalva_van_e_mar = 0, $ebsz_tag = 0)
		{
			switch ($szint) {
				case 1:
					$this->tutorialMessages($kinek, 4, array($nev, $nyelv));
					break;
				case 2:
					$this->tutorialMessages($kinek, 6, array($nev, $nyelv));
					break;
				case 3:
					$this->tutorialMessages($kinek, 8, array($nev, $nyelv));
					break;
				case 4:
					$this->tutorialMessages($kinek, 10, array($nev, $nyelv));
					break;
				case 5:
					$r = $this->gs->prepare('UPDATE userek SET karrier=leendo_karrier WHERE id=? AND karrier=0');
					$r->execute(array($kinek));
					$this->tutorialMessages($kinek, 13, array($nev, $nyelv));
					break;
				case 6:
					$this->tutorialMessages($kinek, 14, array($nev, $nyelv));
					break;
			}
			$r1 = $this->gs->prepare('UPDATE userek SET techszint_ertesites=? WHERE id=?');
			$r1->execute(array($szint, $kinek));
			$this->giveBadges($kinek, $szint + 6);
		}

		function tutorialMessages($kinek, $level, $egyeb_adatok = null)
		{
			global $zanda_ingame_msg_ps;
			if (!is_array($egyeb_adatok)) {
				$r = $this->gs->prepare('SELECT nev,nyelv FROM userek WHERE id=?');
				$r->execute(array($kinek));
				$aux = $r->fetch(PDO::FETCH_BOTH);
				$egyeb_adatok = array(
						$aux['nev'], $aux['nyelv']
				);
			}
			if ($egyeb_adatok[1] == 'hu') {
				global $tut_level_szovegek_hu;
				$uzi = "Kedves " . $egyeb_adatok[0] . "!\n\n";
				$uzi .= $tut_level_szovegek_hu[$level][1];
				$uzi .= "\n\n\nZandagort és népe";
				$uzi .= "\n\n" . $zanda_ingame_msg_ps['hu'];
				$this->systemMessageFromCentralOffice($kinek, $tut_level_szovegek_hu[$level][0], $uzi, $egyeb_adatok[1]);
			} else {
				global $tut_level_szovegek_en;
				$uzi = "Dear " . $egyeb_adatok[0] . "!\n\n";
				$uzi .= $tut_level_szovegek_en[$level][1];
				$uzi .= "\n\n\nZandagort and his people";
				$uzi .= "\n\n" . $zanda_ingame_msg_ps['en'];
				$this->systemMessageFromCentralOffice($kinek, $tut_level_szovegek_en[$level][0], $uzi, $egyeb_adatok[1]);
			}
			$r01 = $this->gs->prepare('UPDATE userek SET tut_level=?,tut_uccso_level=now() WHERE id=?');
			$r01->execute(array($level, $kinek));
		}

		function systemMessage($kinek, $targy, $uzenet, $targy_en = '', $uzenet_en = '')
		{
			$r = $this->gs->prepare('SELECT nyelv FROM userek WHERE id=?');
			$r->execute(array($kinek));
			$leveltulaj = $r->fetch(PDO::FETCH_BOTH);
			$datum = date('Y-m-d H:i:s');

			$r01 = $this->gs->prepare('INSERT INTO levelek (felado,tulaj,ido,targy,uzenet,cimzettek,mappa)
 			VALUES(?,?,?,?,?,?,?)');
			if ($leveltulaj[0] == 'hu') {
				$s = 'Bejövő';
			} else {
				$s = 'Incoming';
			}

			$r01->execute(array(
					0, $kinek, $datum, $targy, $uzenet, $kinek, $s
			));

			$r02 = $this->gs->prepare('INSERT IGNORE INTO cimzettek (level_id, cimzett_tipus, cimzett_id) VALUES(?,?,?)');
			$r02->execute(array($this->gs->lastInsertId(), 1, $kinek));
		}

		function systemMessageFromCentralOffice($kinek, $targy, $uzenet, $nyelv = 'hu')
		{
			/*	$datum=date('Y-m-d H:i:s');
                //strip_tags kiszedve, h linkeket is lehessen
                if ($nyelv=='hu') {
            //		mysql_query('insert into levelek (felado,tulaj,ido,targy,uzenet,cimzettek) values('.KOZPONTI_SZOLGALTATOHAZ_HU_USER_ID.','.$kinek.',"'.$datum.'","'.mysql_real_escape_string(trim(strip_tags($targy))).'","'.mysql_real_escape_string(trim($uzenet)).'",",'.$kinek.',")') or hiba(__FILE__,__LINE__,mysql_error());
                } else {
            //		mysql_query('insert into levelek (felado,tulaj,ido,targy,uzenet,cimzettek,mappa) values('.KOZPONTI_SZOLGALTATOHAZ_EN_USER_ID.','.$kinek.',"'.$datum.'","'.mysql_real_escape_string(trim(strip_tags($targy))).'","'.mysql_real_escape_string(trim($uzenet)).'",",'.$kinek.',","Incoming")') or hiba(__FILE__,__LINE__,mysql_error());
                }
                $er=mysql_query('select last_insert_id() from levelek') or hiba(__FILE__,__LINE__,mysql_error());
                $aux=mysql_fetch_array($er);
                mysql_query('insert ignore into cimzettek (level_id,cimzett_tipus,cimzett_id) values('.$aux[0].','.CIMZETT_TIPUS_USER.','.$kinek.')') or hiba(__FILE__,__LINE__,mysql_error());
            */
		}

		function fantomPlanet($random_bolygo, $ucs)
		{
			$forras_info = '';
			$forras_info_en = '';
			if ($ucs['helyezes'] <= 50) {
				$forras_info = ' A fantom támadás lehetséges forrása: ' . $random_bolygo['kulso_nev'] . ' (' . $this->mapcoords($random_bolygo['x'], $random_bolygo['y'], 'hu') . ').';
				$forras_info_en = ' Possible source of the phantom attack: ' . $random_bolygo['kulso_nev'] . ' (' . $this->mapcoords($random_bolygo['x'], $random_bolygo['y'], 'en') . ').';
			} else {
				if ($ucs['helyezes'] <= 100) {//1000pc
					$fantom_x = round($random_bolygo['x'] / 2000) * 2000;
					$fantom_y = round($random_bolygo['x'] / 2000) * 2000;
				} else {//2000pc
					$fantom_x = round($random_bolygo['x'] / 4000) * 4000;
					$fantom_y = round($random_bolygo['x'] / 4000) * 4000;
				}
				$forras_info = ' A fantom támadás lehetséges forrása valahol errefelé van: ' . $this->mapcoords($fantom_x, $fantom_y, 'hu') . '.';
				$forras_info_en = ' Possible source of the phantom attack is somewhere around here: ' . $this->mapcoords($fantom_x, $fantom_y, 'en') . '.';
			}
			return array($forras_info, $forras_info_en);
		}


		/*
		 * Fleet controler functions
		 */

		function newPirateFleet($x, $y, $bolygo_id, $nev, $hajok)
		{
			$r01 = $this->gs->prepare('INSERT INTO flottak (nev,kaloz_bolygo_id,statusz,sebesseg,x,y) VALUES(?,?,?,?,?)');
			$r01->execute(array($nev, $bolygo_id, 1, $x, $y));

			$r02 = $this->gs->prepare('SELECT last_insert_id() FROM flottak');
			$r02->execute();
			$r02 = $r02->fetch(PDO::FETCH_BOTH);
			$flotta_id = $r02[0];

			$r03 = $this->gs->prepare('INSERT INTO flotta_hajo (flotta_id,hajo_id,ossz_hp) VALUES(?,0,100)');
			$r03->execute(array($flotta_id));
			foreach ($hajok as $tipus => $darab) {
				$r04 = $this->gs->prepare('INSERT INTO flotta_hajo (flotta_id,hajo_id,ossz_hp) VALUES(?,?,?)');
				$r04->execute(array($flotta_id, $tipus, 100 * $darab));
			}
			$this->refreshFleet($flotta_id);
		}

		function refreshFleet($melyik)
		{
			//specko hajok aranya
			$r01 = $this->gs->prepare('
					UPDATE flottak f,(
					SELECT sum(if(h.id=212,fh.ossz_hp*h.ar,NULL))/sum(fh.ossz_hp*h.ar) AS koordi_arany
					,sum(if(h.id=218,fh.ossz_hp*h.ar,NULL))/sum(fh.ossz_hp*h.ar) AS ohs_arany
					,coalesce(sum(if(h.id=216,fh.ossz_hp*h.ar,NULL))/sum(if(h.id!=210,fh.ossz_hp*h.ar,NULL)),0) AS anyahajo_arany
					,sum(if(h.id=223,fh.ossz_hp*h.ar,NULL))/sum(fh.ossz_hp*h.ar) AS castor_arany
					,sum(if(h.id=224,fh.ossz_hp*h.ar,NULL))/sum(fh.ossz_hp*h.ar) AS pollux_arany
					FROM flotta_hajo fh, hajok h
					WHERE fh.flotta_id=? AND fh.hajo_id=h.id
					) t
					SET f.koordi_arany=round(100*t.koordi_arany)
					,f.ohs_arany=round(100*t.ohs_arany)
					,f.anyahajo_arany=round(100*t.anyahajo_arany)
					,f.castor_arany=round(100*t.castor_arany)
					,f.pollux_arany=round(100*t.pollux_arany)
					WHERE f.id=?');
			$r01->execute(array($melyik, $melyik));

			$r02 = $this->gs->prepare('UPDATE flotta_hajo fh, hajok h, flottak f
					SET fh.koordi_arany=f.koordi_arany
					,fh.ohs_arany=f.ohs_arany
					,fh.anyahajo_arany=f.anyahajo_arany
					,fh.castor_arany=f.castor_arany
					,fh.pollux_arany=f.pollux_arany
					,fh.tamado_ero=h.tamado_ero
					,fh.valodi_hp=h.valodi_hp
					WHERE fh.flotta_id=? AND fh.hajo_id=h.id AND f.id=?');
			$r02->execute(array($melyik, $melyik));
			//latotav, rejtozes, egyenertek
			$r03 = $this->gs->prepare('
					UPDATE flottak f, (
						SELECT max(if(fh.ossz_hp>0,h.latotav,0)) AS ossz_latotav,
						round(sum(if(h.vadasz=1,fh.ossz_hp*h.ar,0))/sum(fh.ossz_hp*h.ar)*coalesce(min(if(fh.ossz_hp>0 AND h.vadasz=1,h.rejtozes,NULL)),0)+sum(if(h.vadasz=0,fh.ossz_hp*h.ar,0))/sum(fh.ossz_hp*h.ar)*coalesce(min(if(fh.ossz_hp>0 AND h.vadasz=0,h.rejtozes,NULL)),0)) AS ossz_rejtozes,
						round(sum(fh.ossz_hp/100*h.ar)) AS ossz_egyenertek
						FROM flotta_hajo fh, hajok h
						WHERE fh.flotta_id=? AND fh.hajo_id=h.id
					) ossztab
					SET
					f.latotav=ossztab.ossz_latotav,
					f.rejtozes=ossztab.ossz_rejtozes,
					f.egyenertek=ossztab.ossz_egyenertek
					WHERE f.id=?');
			$r03->execute(array($melyik, $melyik));

			//sebesseg
			$r04 = $this->gs->prepare('SELECT sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.hajo_id=h.id AND h.vadasz=1 AND fh.flotta_id=?');
			$r04->execute(array($melyik));
			$r04 = $r04->fetch(PDO::FETCH_BOTH);
			$vadasz_egyenertek = $r04[0];

			$r05 = $this->gs->prepare('SELECT sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.hajo_id=h.id AND h.vadasz=0 AND fh.flotta_id=?');
			$r05->execute(array($melyik));
			$r05 = $r05->fetch(PDO::FETCH_BOTH);
			$nemvadasz_egyenertek = $r05[0];

			$r06 = $this->gs->prepare('SELECT sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.hajo_id=h.id AND h.id=216 AND fh.flotta_id=?');
			$r06->execute(array($melyik));
			$r06 = $r06->fetch(PDO::FETCH_BOTH);
			$anyahajo_egyenertek = $r06[0];

			if ($vadasz_egyenertek <= $nemvadasz_egyenertek) {
				//befert minden vadasz
				//csak a nemvadaszokat kell bepakolni az anyahajokba
				if ($anyahajo_egyenertek > 0) {//van anyahajo
					$r07 = $this->gs->prepare('SELECT sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.hajo_id=h.id AND h.vadasz=0 AND h.id NOT IN (216,210) AND fh.flotta_id=?');
					$r07->execute(array($melyik));
					$r07 = $r07->fetch(PDO::FETCH_BOTH);
					$cipelendo_egyenertek = $r07[0];
					if ($cipelendo_egyenertek <= $anyahajo_egyenertek) {//minden befert
						$anyahajok_terhelese = $cipelendo_egyenertek / $anyahajo_egyenertek;
						$sebesseg = 40 - 10 * $anyahajok_terhelese;
					} else {//van ami kimaradt
						$szabad_hely = $anyahajo_egyenertek;
						$r08 = $this->gs->prepare('SELECT h.sebesseg,sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.flotta_id=? AND fh.hajo_id=h.id AND h.vadasz=0 AND h.sebesseg<80 AND fh.ossz_hp>0 GROUP BY h.sebesseg');
						$r08->execute(array($melyik));
						unset($leglassabb_ami_kimarad);
						foreach ($r08->fetchAll(PDO::FETCH_BOTH) as $aux) {
							$szabad_hely -= $aux[1];
							if (!isset($leglassabb_ami_kimarad)) if ($szabad_hely < 0) $leglassabb_ami_kimarad = $aux[0];
						}
						$sebesseg = $leglassabb_ami_kimarad / 2;
					}
				} else {//nincs anyahajo
					$r09 = $this->gs->prepare('SELECT min(if(fh.ossz_hp>0,h.sebesseg,1000)) FROM flotta_hajo fh, hajok h WHERE fh.flotta_id= AND fh.hajo_id=h.id AND h.vadasz=0');
					$r09->execute(array($melyik));
					$r09 = $r09->fetch(PDO::FETCH_BOTH);
					$sebesseg = $r09[0] / 2;
				}
			} else {
				//kimaradt vadasz
				$kimaradt_vadasz_egyenertek = $vadasz_egyenertek - $nemvadasz_egyenertek;
				//a nemvadaszokat es a kimaradt vadaszokat bepakolni az anyahajokba
				if ($anyahajo_egyenertek > 0) {//van anyahajo
					$r10 = $this->gs->prepare('SELECT sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.hajo_id=h.id AND h.vadasz=0 AND h.id NOT IN (216,210) AND fh.flotta_id=?');
					$r10->execute(array($melyik));
					$r10 = $r10->fetch(PDO::FETCH_BOTH);


					$cipelendo_egyenertek = $kimaradt_vadasz_egyenertek + $r10[0];
					if ($cipelendo_egyenertek <= $anyahajo_egyenertek) {//minden befert
						$anyahajok_terhelese = $cipelendo_egyenertek / $anyahajo_egyenertek;
						$sebesseg = 40 - 10 * $anyahajok_terhelese;
					} else {//van ami kimaradt
						$szabad_hely = $anyahajo_egyenertek;
						$aux_vadasz_egyenertek = 0;
						$volt_mar_kimaradt_vadasz = false;
						$r11 = $this->gs->prepare('SELECT h.sebesseg,sum(fh.ossz_hp*h.ar),h.vadasz FROM flotta_hajo fh, hajok h WHERE fh.flotta_id=? AND fh.hajo_id=h.id AND h.sebesseg<80 AND fh.ossz_hp>0 GROUP BY h.sebesseg');
						$r11->execute(array($melyik));
						unset($leglassabb_ami_kimarad);
						foreach ($r11->fetchAll(PDO::FETCH_BOTH) as $aux) {
							if ($aux[2] == 1) {//vadasz
								$aux_vadasz_egyenertek += $aux[1];
								if ($aux_vadasz_egyenertek > $nemvadasz_egyenertek) {
									if (!$volt_mar_kimaradt_vadasz) $aux[1] = $aux_vadasz_egyenertek - $nemvadasz_egyenertek;
									$szabad_hely -= $aux[1];
									if (!isset($leglassabb_ami_kimarad)) if ($szabad_hely < 0) $leglassabb_ami_kimarad = $aux[0];
									$volt_mar_kimaradt_vadasz = true;
								}
							} else {//nem vadasz
								$szabad_hely -= $aux[1];
								if (!isset($leglassabb_ami_kimarad)) if ($szabad_hely < 0) $leglassabb_ami_kimarad = $aux[0];
							}
						}
						$sebesseg = $leglassabb_ami_kimarad / 2;
					}
				} else {//nincs anyahajo
					//kimaradt vadaszok
					$szabad_hely = $nemvadasz_egyenertek;
					$r12 = $this->gs->prepare('SELECT h.sebesseg,sum(fh.ossz_hp*h.ar) FROM flotta_hajo fh, hajok h WHERE fh.flotta_id=? AND fh.hajo_id=h.id AND h.vadasz=1 AND fh.ossz_hp>0 GROUP BY h.sebesseg');
					$r12->execute(array($melyik));
					unset($leglassabb_ami_kimarad);
					foreach ($r12->fetchAll(PDO::FETCH_BOTH) as $aux) {
						$szabad_hely -= $aux[1];
						if (!isset($leglassabb_ami_kimarad)) if ($szabad_hely < 0) $leglassabb_ami_kimarad = $aux[0];
					}
					$sebesseg = $leglassabb_ami_kimarad / 2;
					$r13 = $this->gs->prepare('SELECT min(if(fh.ossz_hp>0,h.sebesseg,1000)) FROM flotta_hajo fh, hajok h WHERE fh.flotta_id=? AND fh.hajo_id=h.id AND h.vadasz=0');
					$r13->execute(array($melyik));
					$r13 = $r13->fetch(PDO::FETCH_BOTH);
					$sebesseg_nemvadasz = $r13[0] / 2;
					if ($sebesseg_nemvadasz < $sebesseg) $sebesseg = $sebesseg_nemvadasz;
				}
			}
		}


		function otherFleetsRefresh($fid)
		{
			$r01 = $this->gs->prepare('DELETE FROM resz_flotta_hajo WHERE flotta_id=? AND hp=0');
			$r01->execute(array($fid));
			//
			$r02 = $this->gs->prepare('UPDATE resz_flotta_hajo rfh, flotta_hajo fh
				SET rfh.ossz_hp=fh.ossz_hp
				WHERE rfh.flotta_id=? AND fh.flotta_id=? AND rfh.hajo_id=fh.hajo_id');
			$r02->execute(array($fid, $fid));
			//
			$r03 = $this->gs->prepare('UPDATE resz_flotta_hajo rfh, (
				SELECT rfh1.hajo_id,rfh1.user_id,round(rfh1.hp/sum(rfh2.hp)*rfh1.ossz_hp) AS uj_hp
				FROM resz_flotta_hajo rfh1, resz_flotta_hajo rfh2
				WHERE rfh1.flotta_id=? AND rfh2.flotta_id=? AND rfh1.hajo_id=rfh2.hajo_id
				GROUP BY rfh1.hajo_id,rfh1.user_id
				) t
				SET rfh.hp=t.uj_hp
				WHERE rfh.flotta_id=? AND rfh.hajo_id=t.hajo_id AND rfh.user_id=t.user_id');
			$r03->execute(array($fid, $fid, $fid));
			//
			$r04 = $this->gs->prepare('DELETE FROM resz_flotta_hajo WHERE flotta_id=? AND hp=0');
			$r04->execute(array($fid));
			//
			//ha a flottanak kizarolag egy resztulaja van, akkor a resztulajsagot felszamolni es a tulajt arra beallitani
			$r05 = $this->gs->prepare('SELECT count(DISTINCT user_id) FROM resz_flotta_hajo WHERE flotta_id=?');
			$r05->execute(array($fid));
			$r05 = $r05->fetch(PDO::FETCH_BOTH);
			$resztulajok_szama = $r05[0];
			if ($resztulajok_szama == 1) {
				$r06 = $this->gs->prepare('SELECT user_id FROM resz_flotta_hajo WHERE flotta_id=? LIMIT 1');
				$r06->execute(array($fid));
				$r06 = $r06->fetch(PDO::FETCH_BOTH);
				$valodi_tulaj = $r06[0];
				if ($valodi_tulaj > 0) {
					$r07 = $this->gs->prepare('SELECT tulaj_szov FROM userek WHERE id=?');
					$r07->execute(array($valodi_tulaj));
					$r07 = $r07->fetch(PDO::FETCH_BOTH);
					$valodi_tulaj_szov = $r07[0];
					$r08 = $this->gs->prepare('UPDATE flottak SET tulaj=?, tulaj_szov=? WHERE id=?');
					$r08->execute(array($valodi_tulaj, $valodi_tulaj_szov, $fid));

					$r09 = $this->gs->prepare('DELETE FROM resz_flotta_hajo WHERE flotta_id=?');
					$r09->execute(array($fid));
				}
			}
		}

		function fleetDelete($melyik)
		{
			$r01 = $this->gs->prepare('DELETE FROM resz_flotta_hajo WHERE flotta_id=?');
			$r01->execute(array($melyik));
			$r02 = $this->gs->prepare('DELETE FROM flotta_hajo WHERE flotta_id=?');
			$r02->execute(array($melyik));
			$r03 = $this->gs->prepare('DELETE FROM flottak WHERE id=?');
			$r03->execute(array($melyik));
			$r04 = $this->gs->prepare('UPDATE flottak SET statusz=2,cel_flotta=0 WHERE cel_flotta=? AND (statusz=12 OR statusz=13 OR statusz=14)');
			$r04->execute(array($melyik));
		}

		/*
		 * Matching Functions
		 */


		function sanitint($x)
		{
			$x = trim((string)$x);
			$y = '';
			for ($i = 0; $i < strlen($x); $i++) if (strpos('0123456789', $x[$i]) !== false) $y .= $x[$i];
			if ($y == '') return 0;
			if (strtolower($x[strlen($x) - 1]) == 'k') $y .= '000';
			if (strtolower($x[strlen($x) - 1]) == 'm') $y .= '000000';
			if (strtolower($x[strlen($x) - 1]) == 'g') $y .= '000000000';
			if (strpos($x, '-') !== false) return -$y;
			return $y;
		}

		function mapcoords($x, $y, $nyelv = 'hu')
		{
			$s = '';
			if ($nyelv == 'hu') {
				if ($y < 0) $s .= 'É ' . number_format(round(-$y / 2), 0, ',', ' ');
				if ($y > 0) $s .= 'D ' . number_format(round($y / 2), 0, ',', ' ');
				$s .= ', ';
				if ($x < 0) $s .= 'NY ' . number_format(round(-$x / 2), 0, ',', ' ');
				if ($x > 0) $s .= 'K ' . number_format(round($x / 2), 0, ',', ' ');
			} else {
				if ($y < 0) $s .= 'N ' . number_format(round(-$y / 2), 0, ',', ' ');
				if ($y > 0) $s .= 'S ' . number_format(round($y / 2), 0, ',', ' ');
				$s .= ', ';
				if ($x < 0) $s .= 'W ' . number_format(round(-$x / 2), 0, ',', ' ');
				if ($x > 0) $s .= 'E ' . number_format(round($x / 2), 0, ',', ' ');
			}
			return $s;
		}
	}
		function hist_snapshot($hist_termelesek=1) {
			global $database_mmog,$database_mmog_nemlog,$specko_szovetsegek_listaja,$specko_userek_listaja, $gs, $ls;


			$r200 = $ls->pdo->prepare('insert into hist_idopontok(id) values(null)');
			$r200->execute();
			$r201 = $ls->pdo->prepare('select last_insert_id() from hist_idopontok');
			$r201->execute();
			$aux = $r201->fetch(PDO::FETCH_BOTH);
			$idopont=$aux[0];
			if ($hist_termelesek) {//a vegjatek alatt is csak 6 orankent legyen, kulonben megugrik a tozsdei limit
				//hist_termelesek -> ez nincs felosztva osztaly,regio-ra, csak sima szumma eroforrasonkent -> a tozsdei limithez
				$r202 = $ls->pdo->prepare('
				insert into hist_termelesek(idopont,id,brutto_termeles)
				select ?,gye.eroforras_id,coalesce(sum(bgy.aktiv_db*if(gye.io>0,gye.io,0)),0)
				from ?.bolygok b, ?.bolygo_gyar bgy, ?.gyar_eroforras gye
				where b.id=bgy.bolygo_id and bgy.gyar_id=gye.gyar_id and b.tulaj>0 and gye.eroforras_id>54
				group by gye.eroforras_id
					');
				$r202->execute(array($idopont, $database_mmog, $database_mmog, $database_mmog));
			}
			//hist_bolygok
			$r203 = $ls->pdo->prepare('
insert into hist_bolygok(idopont,id,nev,tulaj,tulaj_szov,pop)
select ?,b.id,b.nev,b.tulaj,b.tulaj_szov,be.pop
from ?.bolygok b, ?.bolygo_ember be
where b.id=be.bolygo_id
	');
			$r203->execute(array($idopont, $database_mmog, $database_mmog));
			//hist_eroforrasok
			$r204 = $ls->pdo->prepare('
insert into hist_eroforrasok(idopont,osztaly,regio,id,db)
select ?,b.osztaly,b.regio,be.eroforras_id, sum(be.db)
from ?.bolygo_eroforras be, ?.bolygok b
where be.bolygo_id=b.id
group by b.osztaly,b.regio,be.eroforras_id
	');
			$r204->execute(array($idopont, $database_mmog, $database_mmog));
			//hist_flottak
			$r205 = $ls->pdo->prepare('
insert into hist_flottak(idopont,id,nev,tulaj,x,y,tulaj_szov,egyenertek)
select ?,f.id,f.nev,f.tulaj,f.x,f.y,f.tulaj_szov,round(sum(fh.ossz_hp/100*h.ar))
from ?.flottak f, ?.flotta_hajo fh, ?.hajok h
where f.id=fh.flotta_id and fh.hajo_id=h.id
group by f.id
	');
			$r205->execute(array($idopont, $database_mmog, $database_mmog, $database_mmog));
			//hist_gyarak
			$r206 = $ls->pdo->prepare('
insert into hist_gyarak(idopont,osztaly,regio,id,db)
select ?,b.osztaly,b.regio,bgy.gyar_id,sum(bgy.db)
from ?.bolygo_gyar bgy, ?.bolygok b
where bgy.bolygo_id=b.id
group by b.osztaly,b.regio,bgy.gyar_id
	');
			$r206->execute(array($idopont, $database_mmog, $database_mmog));
						//hist_hajok
			$r207 = $ls->pdo->prepare('
insert into hist_hajok(idopont,id,tul,ossz_hp)
select ?,fh.hajo_id,if(f.tulaj>0,1,if(f.tulaj<0,-1,0)),sum(fh.ossz_hp)
from ?.flottak f, ?.flotta_hajo fh
where fh.flotta_id=f.id
group by fh.hajo_id,if(f.tulaj>0,1,if(f.tulaj<0,-1,0))
	');
			$r207->execute(array($idopont, $database_mmog, $database_mmog));
			//hist_szovetsegek
			$r208 = $ls->pdo->prepare('
insert into hist_szovetsegek(idopont,id,nev,rovid_nev)
select ?,id,nev,rovid_nev
from ?.szovetsegek
	');
			$r208->execute(array($idopont,$database_mmog));
			//hist_userek
			$r209 = $ls->pdo->prepare('
insert into hist_userek(idopont,id,nev,szovetseg,pontszam,uccso_akt,terulet,pontszam_exp_atlag,karrier,speci)
select ?,id,nev,szovetseg,pontszam,uccso_akt,jelenlegi_terulet,pontszam_exp_atlag,karrier,speci
from ?.userek
	');
			$r209->execute(array($idopont, $database_mmog));
			//hist_userek helyezes
			$r = $ls->pdo->prepare('select id,karrier,speci from hist_userek where idopont=? and szovetseg not in (?) and id not in (?) order by pontszam_exp_atlag desc');
			$r->execute(array($idopont, implode(',',$specko_szovetsegek_listaja), implode(',',$specko_userek_listaja)));
			$n=0;
			foreach($r->fetchAll(PDO::FETCH_BOTH) as $aux){

				if ($aux['karrier']!=3 or $aux['speci']!=3) $n++;
				$r210 = $ls->pdo->prepare('update hist_userek set helyezes=? where idopont=? and id=?');
				$r210->execute(array($n, $idopont, $aux[0]));
				$r211 = $ls->pdo->prepare('update ?.userek set helyezes=? where id=?');
				$r211->execute(array($database_mmog, $n, $aux[0]));

			}
			//
			//hist_diplomacia_statuszok
			$r212 = $ls->pdo->prepare('
insert into hist_diplomacia_statuszok(idopont,ki,kivel,mi,miota,kezdemenyezo,felbontasi_ido,felbontas_alatt,felbontas_mikor,diplo_1,diplo_2,nyilvanos)
select ?,ki,kivel,mi,miota,kezdemenyezo,felbontasi_ido,felbontas_alatt,felbontas_mikor,diplo_1,diplo_2,nyilvanos
from ?.diplomacia_statuszok
	');
			$r212->execute(array($idopont, $database_mmog));
			//
		}




	$szimClass = new SimulateRounds;

print_r($gs->pdo->errorInfo());

if ($inaktiv_szerver) {//inaktiv szerveren automatikus aktivalas
	$d=date('Y-m-d H:i:s',time()-3600*24);
	$offline = $gs->pdo->prepare('update userek set uccso_akt="'.$d.'"');
	$offline->execute();
	echo 'Utso Akt : ' . $offline->rowCount();
}

//echo "\n".date('Y-m-d H:i:s');
//mysql_query('do release_lock("'.$szimlock_name.'")');//ha vmiert befagy a lock; de igazabol ha beall a lock, akkor valamelyik query irgalmatlan lassan fut, vagyis a processlist-et kell lecsekkolni

define('SZIM_SEB',5);//ha atirod, akkor bolygok es bolygo_eroforras-ban is a bolygo_id_mod-ot ujra kell generalni
$mikor_indul=microtime(true);

	$res = $gs->pdo->prepare('SELECT get_lock(?, 0)');
	$res->execute(
			array($szimlock_name)
	);
	$aux = $res->fetch(PDO::FETCH_BOTH);


if ($aux[0]==1) {

	$er = $gs->pdo->prepare('SELECT * FROM ido');
	$er->execute();
	$rendszer_idopont = $er->fetch(PDO::FETCH_ASSOC);
	$idopont = $rendszer_idopont['idopont'];
	$perc = $idopont%SZIM_SEB;
	$hanyadik_kor = floor($idopont/SZIM_SEB);

	$szimlog_hossz_npc = 0;
	$szimlog_hossz_monetaris = 0;
	$szimlog_hossz_termeles = 0;
	$szimlog_hossz_felderites = 0;
	$szimlog_hossz_flottamoral = 0;
	$szimlog_hossz_flottak = 0;
	$szimlog_hossz_csatak = 0;
	$szimlog_hossz = 0;
	$szimlog_hossz_debug_elott = 0;
	$szimlog_hossz_debug_utan = 0;
	$szimlog_hossz_ostromok = 0;
	$szimlog_hossz_fog = 0;
	$mostani_datum = date('Y-m-d H:i:s');
	$mai_nap = date('Y-m-d');
	$tegnap = date('Y-m-d',time()-3600*24);
	$tegnap_minusz_egy_het = date('Y-m-d',time()-3600*24*7);
	$ora_perc = date('H:i');

	$gs->pdo->prepare('UPDATE ido SET idopont=idopont+1, idopont_kezd=idopont_kezd+1')->execute();

/*******************************************************************************************************/
//TP frissites
//rang frissites
//harcos karrier frissites
if (substr($ora_perc,0,2)=='00') if (substr($rendszer_idopont['uccso_tp_frissites'],0,10)<$mai_nap) {
	$gs->pdo->prepare('UPDATE ido SET uccso_tp_frissites=:lastTpRefresh')->execute(array(':lastTpRefresh' => $mostani_datum));

	//
	//mysql_select_db($database_mmog_nemlog);
	//0. hist_csata_lelottek (ez nem a tp-hez kell, hanem a speci nyitashoz, meg amugy se jon rosszul)
    $fh = $ls->pdo->prepare('insert into hist_csata_lelottek (csata_id,user_id,lelott_ember,lelott_kaloz,lelott_zanda)
select cs.id as csata_id,csft.iranyito as user_id
,round(sum(if(csfv.iranyito>0,css.sebzes,0)/csfhv.serules*(csfhv.ossz_hp_elotte-csfhv.ossz_hp_utana)*hv.ar)) as lelott_ember
,round(sum(if(csfv.iranyito=0 and csfv.tulaj=0,css.sebzes,0)/csfhv.serules*(csfhv.ossz_hp_elotte-csfhv.ossz_hp_utana)*hv.ar)) as lelott_kaloz
,round(sum(if(csfv.iranyito=0 and csfv.tulaj=-1,css.sebzes,0)/csfhv.serules*(csfhv.ossz_hp_elotte-csfhv.ossz_hp_utana)*hv.ar)) as lelott_zanda
from hist_csatak cs
inner join hist_csata_sebzesek css on css.csata_id=cs.id
inner join hist_csata_flotta csft on csft.csata_id=cs.id and csft.flotta_id=css.tamado_flotta_id
inner join hist_csata_flotta csfv on csfv.csata_id=cs.id and csfv.flotta_id=css.vedo_flotta_id
inner join hist_csata_flotta_hajo csfhv on csfhv.csata_id=cs.id and csfhv.flotta_id=css.vedo_flotta_id and csfhv.hajo_id=css.vedo_hajo_id
inner join ?.hajok hv on hv.id=css.vedo_hajo_id
where css.tamado_hajo_id!=206 and css.vedo_hajo_id!=206
and csft.kezdo=0 and csfv.kezdo=0
and csft.iranyito>0
and cs.mikor between "? 00:00:00" and "? 23:59:59"
group by cs.id,csft.iranyito');
    $fh->execute(array($database_mmog, $tegnap, $tegnap));

	//1. hist_csata_tp
	$hcsp = $ls->pdo->prepare('insert into hist_csata_tpk (csata_id,user_id,lelott)
select cs.id,csft.iranyito,round(sum(css.sebzes/csfhv.serules*(csfhv.ossz_hp_elotte-csfhv.ossz_hp_utana)*hv.ar)) as lelott
from hist_csatak cs
inner join hist_csata_sebzesek css on css.csata_id=cs.id
inner join hist_csata_flotta csft on csft.csata_id=cs.id and csft.flotta_id=css.tamado_flotta_id
inner join hist_csata_flotta csfv on csfv.csata_id=cs.id and csfv.flotta_id=css.vedo_flotta_id
inner join hist_csata_flotta_hajo csfhv on csfhv.csata_id=cs.id and csfhv.flotta_id=css.vedo_flotta_id and csfhv.hajo_id=css.vedo_hajo_id
inner join :gsName.hajok hv on hv.id=css.vedo_hajo_id
where css.tamado_hajo_id!=206 and css.vedo_hajo_id!=206
and csft.kezdo=0 and csfv.kezdo=0
and csft.iranyito>0 and csfv.iranyito>0
and cs.mikor between ":lastDay 00:00:00" and ":lastDay 23:59:59"
group by cs.id,csft.iranyito');
        $hcsp->execute(
			array(
				':gsName' 	=> $database_mmog,
				':lastDay'	=> $tegnap
			)
	);

	$ls->pdo->prepare('update hist_csata_tpk cstp, (
select cs.id,csfv.iranyito,round(sum(css.sebzes/csfhv.serules*(csfhv.ossz_hp_elotte-csfhv.ossz_hp_utana)*hv.ar)) as bukott
from hist_csatak cs
inner join hist_csata_sebzesek css on css.csata_id=cs.id
inner join hist_csata_flotta csft on csft.csata_id=cs.id and csft.flotta_id=css.tamado_flotta_id
inner join hist_csata_flotta csfv on csfv.csata_id=cs.id and csfv.flotta_id=css.vedo_flotta_id
inner join hist_csata_flotta_hajo csfhv on csfhv.csata_id=cs.id and csfhv.flotta_id=css.vedo_flotta_id and csfhv.hajo_id=css.vedo_hajo_id
inner join :gsName.hajok hv on hv.id=css.vedo_hajo_id
where css.tamado_hajo_id!=206 and css.vedo_hajo_id!=206
and csft.kezdo=0 and csfv.kezdo=0
and csft.iranyito>0 and csfv.iranyito>0
and cs.mikor between ":lastDay 00:00:00" and ":lastDay 23:59:59"
group by cs.id,csfv.iranyito
) t
set cstp.bukott=t.bukott
where cstp.csata_id=t.id
and cstp.user_id=t.iranyito')->execute(
			array(
					':gsName' 	=> $database_mmog,
					':lastDay'	=> $tegnap
			)
	);

	//ossz_lelott: heti atlag
	$r = $ls->pdo->prepare('select round(sum(css.sebzes/csfhv.serules*(csfhv.ossz_hp_elotte-csfhv.ossz_hp_utana)*hv.ar)/greatest(count(distinct date(cs.mikor)),1)) as lelott
from hist_csatak cs
inner join hist_csata_sebzesek css on css.csata_id=cs.id
inner join hist_csata_flotta csft on csft.csata_id=cs.id and csft.flotta_id=css.tamado_flotta_id
inner join hist_csata_flotta csfv on csfv.csata_id=cs.id and csfv.flotta_id=css.vedo_flotta_id
inner join hist_csata_flotta_hajo csfhv on csfhv.csata_id=cs.id and csfhv.flotta_id=css.vedo_flotta_id and csfhv.hajo_id=css.vedo_hajo_id
inner join :gsName.hajok hv on hv.id=css.vedo_hajo_id
where css.tamado_hajo_id!=206 and css.vedo_hajo_id!=206
and csft.kezdo=0 and csfv.kezdo=0
and csft.iranyito>0 and csfv.iranyito>0
and cs.mikor between ":lastWeek 00:00:00" and ":lastDay 23:59:59"');
	$r->execute(
			array(
				':gsName' 	=> $database_mmog,
				':lastWeek'	=> $tegnap_minusz_egy_het,
				':lastDay'	=> $tegnap
			)
	);
	$aux = $r->fetch(PDO::FETCH_BOTH);
	$ls->pdo->prepare('update hist_csata_tpk cstp, hist_csatak cs
set cstp.ossz_lelott=:fired
where cstp.csata_id=cs.id
and cs.mikor between ":lastDay 00:00:00" and ":lastDay 23:59:59"')->execute(
			array(
				':fired'	=> sanitint($aux['lelott']),
				':lastDay'	=> $tegnap
			)
	);
	//
	$ls->pdo->prepare('update hist_csata_tpk cstp, (
select csf.csata_id,csf.iranyito,max(csf.tp) as maxtp
from hist_csatak cs
inner join hist_csata_flotta csf on csf.csata_id=cs.id
where csf.iranyito>0
and csf.kezdo=0
and cs.mikor between ":lastDay 00:00:00" and ":lastDay 23:59:59"
group by csf.csata_id,csf.iranyito
) t
set cstp.sajat_tp=t.maxtp
where cstp.csata_id=t.csata_id and cstp.user_id=t.iranyito')->execute(
			array(
				':lastDay'	=> $tegnap
			)
	);

	$ls->pdo->prepare('update hist_csata_tpk cstp, (
select cs.id,csft.iranyito,max(csfv.tp) as maxtp
from hist_csatak cs
inner join hist_csata_sebzesek css on css.csata_id=cs.id
inner join hist_csata_flotta csft on csft.csata_id=cs.id and csft.flotta_id=css.tamado_flotta_id
inner join hist_csata_flotta csfv on csfv.csata_id=cs.id and csfv.flotta_id=css.vedo_flotta_id
where csft.iranyito>0 and csfv.iranyito>0
and csft.kezdo=0 and csfv.kezdo=0
and css.sebzes>0
and cs.mikor between ":lastDay 00:00:00" and ":lastDay 23:59:59"
group by cs.id,csft.iranyito
) t
set cstp.ellen_tp=t.maxtp
where cstp.csata_id=t.id and cstp.user_id=t.iranyito')->execute(
			array(
				':lastDay'	=> $tegnap
			)
	);

	$ls->pdo->prepare('update hist_csata_tpk
set szerzett_tp=round(
least(greatest(lelott/if(bukott=0,1,bukott)-0.8,0),2)
*least(sqrt(least(0.01,lelott/if(ossz_lelott=0,1,ossz_lelott))),1)
*least(greatest((ellen_tp+1)/(sajat_tp+1),0.2),1)
*1000)')->execute();
//2. hist_tp_szerzesek
	$ls->pdo->prepare('insert into hist_tp_szerzesek
select cstp.user_id,date(cs.mikor) as mikor,sum(cstp.szerzett_tp)
from hist_csata_tpk cstp, hist_csatak cs
where cstp.csata_id=cs.id
and cs.mikor between ":lastDay 00:00:00" and ":lastDay 23:59:59"
group by cstp.user_id')->execute(
			array(
				':lastDay' => $tegnap
			)
	);

	//3. userek.tp
	$gs->pdo->prepare('update userek u, :lsName.hist_tp_szerzesek htpsz
set u.tp=u.tp+htpsz.szerzett_tp
where htpsz.user_id=u.id
and htpsz.mikor=":lastDay"')->execute(
			array(
				':lsName'	=> $database_mmog_nemlog,
				':lastDay'	=> $tegnap
			)
	);
	//
	//harci toplista
	$gs->pdo->prepare('lock tables userek u write, :lsName.hist_tp_szerzesek write')->execute(
			array(
				':lsName'	=> $database_mmog_nemlog
			)
	);

	$e1 = $gs->pdo->prepare('update userek u set u.heti_harci_toplista=0');
	$e1->execute();
	$e2 = $gs->pdo->prepare('set @x:=0');
	$e2->execute();
	$e3 = $gs->pdo->prepare('update userek u, (
select @x:=@x+1 as sorszam,t.user_id,t.tp
from (select user_id,sum(szerzett_tp) as tp from :lsName.hist_tp_szerzesek where mikor>=":lastWeek 00:00:00" group by user_id having tp>0) t
order by t.tp desc
) tt
set u.heti_harci_toplista=tt.sorszam, u.heti_tp=tt.tp
where u.id=tt.user_id');
	$e3->execute(
			array(
				':lsName'	=> $database_mmog_nemlog,
				':lastWeek'	=> 	$tegnap_minusz_egy_het
			)
	);

	$e4 = $gs->pdo->prepare('unlock tables');
	$e4->execute();

	//rangok
	$e5 = $gs->pdo->prepare('update userek set rang=4 where rang<4 and tp>=20000 and heti_harci_toplista between 1 and 10');
	$e5->execute();
	$e6 = $gs->pdo->prepare('update userek set rang=3 where rang<3 and tp>=4000 and heti_harci_toplista between 1 and 25');
	$e6->execute();
	$e7 = $gs->pdo->prepare('update userek set rang=2 where rang<2 and tp>=1000 and heti_harci_toplista between 1 and 100');
	$e7->execute();

	//
	//specik nyitasa
	//speci=1 (vedelmezo)
	$e8 = $gs->pdo->prepare('update userek u,(select user_id from :lsName.hist_csata_lelottek group by user_id
having sum(lelott_kaloz)/10000>=20000 and sum(lelott_kaloz)>sum(lelott_ember)) t
set u.speci_2_1=1
where u.id=t.user_id and u.karrier=2');
	$e8->execute(
			array(
				':lsName'	=> $database_mmog_nemlog
			)
	);
	//speci=2 (marsall)
	$e9 = $gs->pdo->prepare('update userek u,(select user_id from :lsName.hist_csata_lelottek group by user_id
having sum(lelott_ember)/10000>=20000 and sum(lelott_ember)>sum(lelott_kaloz)) t
set u.speci_2_2=1
where u.id=t.user_id and u.karrier=2 and u.rang>=3');
	$e9->execute(
			array(
				':lsName'	=>	$database_mmog_nemlog
			)
	);
	//speci=3 (fejvadasz)
	$e10 = $gs->pdo->prepare('update userek u,(select user_id from :lsName.hist_csata_lelottek group by user_id
having sum(lelott_ember)/10000>=20000 and sum(lelott_ember)>=3*sum(lelott_kaloz)) t
set u.speci_2_3=1
where u.id=t.user_id and u.karrier=2 and u.rang>=3');
	$e10->execute(
			array(
				':lsName'	=>	$database_mmog_nemlog
			)
	);
	//speci=4 (zelota)
	if ($mai_nap>=$zelota_mikortol_valaszthato) if ($mai_nap<$zelota_meddig_valaszthato) {
		$e11 = $gs->pdo->prepare('update userek set speci_2_4=1 where karrier=2');
		$e11->execute();
	}
}


//PREMIUM CSEKK
if ($idopont%60==19) {
	//epitesi lista torlese
	//mysql_query('delete q from queue_epitkezesek q, bolygok b, userek u where q.bolygo_id=b.id and b.tulaj=u.id and (u.premium=0 and u.premium_alap<=now())') or hiba(__FILE__,__LINE__,mysql_error());
	//helyett befagyasztas
	//mysql_query('update bolygok b, userek u set b.befagy_eplista=1 where b.tulaj=u.id and (u.premium=0 and u.premium_alap<=now())');
	//aki meg egyszer sem fizett elo, annak az 5 folottieket levagni (aki igen, annak semmi)
	//mysql_query('delete q from queue_epitkezesek q, bolygok b, userek u where q.bolygo_id=b.id and b.tulaj=u.id and (u.premium=0 and u.premium_alap<=now()) and q.sorszam>5 and u.premium_ertesito=0');//premium_ertesito=1, ha legalabb egyszer fizetett be konkretan penzt
	//autotranszer torlese, emelt szintű
	$gs->pdo->prepare('delete c from cron_tabla_eroforras_transzfer c, bolygok b, userek u where c.honnan_bolygo_id=b.id and b.tulaj=u.id and (u.premium<2 and u.premium_emelt<=now())')->execute();
}


if ($idopont%60==17) {//valaha_elert_max_terulet esetleges csokkentese, ennek fuggvenyeben a vedelmi szintek ujraszamitasa
	$e12 = $gs->pdo->prepare('update userek u
left join (
select user_id as id,max(terulet) as elmult_3_heti_max
from :lsName.terulet_valtozasok
where timestampdiff(day,mikor,now())<21
group by user_id
) tvt on u.id=tvt.id
left join (
select hu.id,max(hu.terulet) as elmult_3_heti_max
from :lsName.hist_userek hu, :lsName.hist_idopontok hi
where hu.idopont=hi.id and timestampdiff(day,hi.mikor,now())<21
group by hu.id
) hut on u.id=hut.id
set u.valaha_elert_max_terulet=greatest(u.jelenlegi_terulet,coalesce(hut.elmult_3_heti_max,0),coalesce(tvt.elmult_3_heti_max,0))');
	$e12->execute(
			array(
				':lsName'	=> $database_mmog_nemlog
			)
	);

	$er = $gs->pdo->prepare('select id from userek');
	$er->execute();
	while($aux = $er->fetchAll(PDO::FETCH_BOTH)){
//		frissit_user_vedelmi_szintek($aux[0]);
	}
}

//egyseges premium uzenet mindenkinek, akinek 5 nap mulva jar le, es tobb mint egy hete kuldott ertesitot
	$er = $gs->pdo->prepare('select id,nev,nyelv from userek where timestampdiff(day,premium_lejar_ertesito_mikor,now())>7 and premium=0 and premium_alap>now() and timestampdiff(day,now(),premium_alap)<5 order by id limit 100');
	$er->execute();
	while($aux = $er->fetchAll(PDO::FETCH_OBJ)){
		premium_lejar_uzenet($aux[0],$aux[1],$aux[2]);
	}


//diplomata karrierek frissitese
if (substr($ora_perc,0,2)=='06') if (substr($rendszer_idopont['uccso_diplomata_frissites'],0,10)<$mai_nap) {

	$e13 = $gs->pdo->prepare('update ido set uccso_diplomata_frissites=":now"');
	$e13->execute(
			array(
				':now' => $mostani_datum
			)
	);
	//bekebiro: min 2 bolygo, min 5 fos szovi tagja, min 2 48 oras mnt, ebbol min 1 top5 szovivel
	$e14 = $gs->pdo->prepare('update userek u,(select u.id,coalesce(sz.tagletszam,0) as letszam
,count(distinct b.id) as bolygok_szama
,count(distinct dsz.id) as mntk_szama
,count(distinct dsz5.id) as mntk_szama_top5_szovivel
from userek u
inner join szovetsegek sz on sz.id=u.szovetseg
left join bolygok b on b.tulaj=u.id
left join diplomacia_statuszok ds on ds.mi=3 and ds.ki>0 and ds.kivel>ds.ki and (ds.diplo_1=u.id or ds.diplo_2=u.id) and ds.felbontasi_ido>=48
left join szovetsegek dsz on dsz.id=ds.kivel
left join szovetsegek dsz5 on dsz5.id=ds.kivel and dsz5.helyezes between 1 and 5
where u.karrier=4
group by u.id
having letszam>=5 and bolygok_szama>=2 and mntk_szama>=2 and mntk_szama_top5_szovivel>=1) t
set u.speci_4_1=1
where u.id=t.id');
	$e14->execute();
	//taacsnok: min 2 bolygo, min 4 kul szoviben tutoralt bolygo
	$e15 = $gs->pdo->prepare('update userek u,(select u.id
,count(distinct b.id) as bolygok_szama
,count(distinct sz.id) as tutoralt_szovik_szama
from userek u
left join bolygok b on b.tulaj=u.id
left join bolygok bt on bt.kezelo=u.id
left join szovetsegek sz on sz.id=bt.tulaj_szov
where u.karrier=4
group by u.id
having bolygok_szama>=2 and tutoralt_szovik_szama>=4) t
set u.speci_4_2=1
where u.id=t.id'); $e15->execute();
}


//DIPLOMACIA
	$e16 = $gs->pdo->prepare('delete from diplomacia_statuszok where felbontas_alatt>0 and felbontas_mikor<now()'); $e16->execute();

	$er = $gs->pdo->prepare('select * from diplomacia_leendo_statuszok where miota<now()');
	$er->execute();
while($aux= $er->fetchAll(PDO::FETCH_OBJ)) {
	$e17 = $gs->pdo->prepare('insert ignore into diplomacia_statuszok (ki,kivel,mi,miota,szoveg_id,kezdemenyezo,szoveg_reszlet,felbontasi_ido,diplo_1,diplo_2,nyilvanos) values
	(:ki, :kivel, :mi, :miota, :szoveg_id, :kezdemenyezo, :szoveg_reszlet, :felbontasi_ido, :diplo_1, :diplo_2, :nyilvanos)');
	$e17->execute(
			array(
				':ki'			=> $aux->ki,
				':kivel'		=> $aux->kivel,
				':mi'			=> $aux->mi,
				':miota'		=> $aux->miota,
				':szoveg_id'	=> $aux->szoveg_id,
				':kezdemenyezo'	=> $aux->kezdemenyezo,
				':szoveg_reszlet'=> $aux->szoveg_reszlet,
				':felbontasi_id'=> $aux->felbontasi_ido,
				':diplo_1'		=> $aux->diplo_1,
				':diplo_2'		=> $aux->diplo_2,
				':nyilvanos'	=> $aux->nyilvanos
			)
	);
	$e18 = $gs->pdo->prepare('delete from diplomacia_leendo_statuszok where ki=:ki and kivel=:kivel');
	$e18->execute(
			array(
				':ki'	=> $aux->ki,
				':kivel'=> $aux->kivel
			)
	);
}

//BEKEBIRO: potencialis es valodi kozti valtas
	$e19 = $gs->pdo->prepare('update userek u, (
select u.id,coalesce(sz.tagletszam,0) as letszam
from userek u
left join szovetsegek sz on u.szovetseg=sz.id
where u.karrier=4 and u.speci in (1,3)
) t
set u.speci=if(t.letszam<5,3,1)
where u.id=t.id');
	$e19->execute();


//CRON

$e20 = $gs->pdo->prepare('update bolygok set van_e_epites_alatti_epulet=0');$e20->execute();
$e21 = $gs->pdo->prepare('update bolygok b, (select distinct bolygo_id from cron_tabla where feladat=1) t
set b.van_e_epites_alatti_epulet=1
where b.id=t.bolygo_id');$e21->execute();

$cron_er = $gs->pdo->prepare('select c.*,b.tulaj,u.nyelv from cron_tabla c, bolygok b, userek u where c.mikor_aktualis<=now() and c.bolygo_id=b.id and b.tulaj=u.id order by c.id');
    $cron_er->execute(
		array(
			':date'	=> date('Y-m-d H:i:s')
		)
);

    foreach($cron_er->fetchAll(PDO::FETCH_BOTH) as $cron)
//while($cron = $cron_er->fetchAll(PDO::FETCH_BOTH))
{
    print_r($cron);
	switch($cron['feladat']) {
		case 1:
			$szimClass->BuildNewFactory($cron['bolygo_id'],$cron['gyar_id'],$cron['aktiv'],$cron['darab']);
			// TODO : ACHIEVEMENT
			//if ($cron['gyar_id']==78) achievement_uzenet($cron['tulaj'],ACHIEVEMENT_ELSO_VAROS,$cron['nyelv']);
			//if ($cron['gyar_id']==87) achievement_uzenet($cron['tulaj'],ACHIEVEMENT_ELSO_HIRSZERZO,$cron['nyelv']);
			//if (($cron['gyar_id']>=79 && $cron['gyar_id']<=86) || $cron['gyar_id']==90 || $cron['gyar_id']==91) achievement_uzenet($cron['tulaj'],ACHIEVEMENT_ELSO_KUTATO,$cron['nyelv']);
			//if ($cron['gyar_id']>=59 && $cron['gyar_id']<=76) achievement_uzenet($cron['tulaj'],ACHIEVEMENT_ELSO_URHAJOGYAR,$cron['nyelv']);
			//if ($cron['gyar_id']==89) achievement_uzenet($cron['tulaj'],ACHIEVEMENT_ELSO_TELEPORT,$cron['nyelv']);
			//
		break;
		case 2:
			if ($cron['indulo_allapot']>0) {//uj rendszer
				if ($cron['indulo_allapot']==1) {//nullarol epites
					$szazalek=round(100-$cron['szazalek']/2);
				}
				if ($cron['indulo_allapot']==2) {//keszrol rombolas
					$szazalek=50;
				}
				$e22 = $gs->pdo->prepare('update gyar_epitesi_koltseg gyek,gyarak gy,bolygo_eroforras be set be.db=be.db+:c*gyek.db*:percent/100 where gyek.tipus=gy.tipus and gy.id=:fid and gyek.szint=gy.szint and gyek.eroforras_id=be.eroforras_id and be.bolygo_id=:pid');
				$e22->execute(
						array(
							':c'	=> $cron['darab'],
							':fid'	=> $cron['gyar_id'],
							':percent'	=> $szazalek,
							':pid'	=> $cron['bolygo_id']
						)
				);
			}
			//a tenyleges rombolas mar a parancs kiadasakor megtortenik, most csak lejar
		break;
	}
	$e23 = $gs->pdo->prepare('delete from cron_tabla where id=:cid');
	$e23->execute(
			array(
				':cid'	=> $cron['id']
			)
	);
	//bolygo_terulet_frissites($cron['bolygo_id']);
}

	$e24 = $gs->pdo->prepare('update bolygok set maradt_epites_alatti_epulet=0');
	$e24->execute();
	$e25 = $gs->pdo->prepare('update bolygok b, (select distinct bolygo_id from cron_tabla where feladat=1) t
set b.maradt_epites_alatti_epulet=1
where b.id=t.bolygo_id');
	$e25->execute();



//NPC
//KALOZOK
//ZANDAGORT
/*
$er_fl=mysql_query('select * from flottak where tulaj=-1 and statusz in ('.STATUSZ_ALLOMAS.','.STATUSZ_ALL.')');
while($flotta=mysql_fetch_array($er_fl)) {
	switch($flotta['zanda_statusz']) {
		case 5://jatekos bolygok ellen
			$er=mysql_query('select id from bolygok where letezik=1 and tulaj='.$flotta['zanda_cel_tulaj_szov'].' and pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2)<=pow(20000,2) order by rand() limit 1');
			//$er=mysql_query('select id from bolygok where letezik=1 and tulaj='.$flotta['zanda_cel_tulaj_szov'].' order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit 1');
			$aux=mysql_fetch_array($er);
			if ($aux) {
				mysql_query('update flottak set bolygo=0,statusz='.STATUSZ_TAMAD_BOLYGORA.',cel_bolygo='.$aux[0].' where id='.$flotta['id']);
			} else {
				$er=mysql_query('select id from bolygok where letezik=1 order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit '.mt_rand(0,5).',1');
				$aux=mysql_fetch_array($er);
				if ($aux) {
					mysql_query('update flottak set bolygo=0,statusz='.STATUSZ_TAMAD_BOLYGORA.',cel_bolygo='.$aux[0].' where id='.$flotta['id']);
				}
			}
		break;
		case 6://npc bolygok ellen
			$er=mysql_query('select id from bolygok where letezik=1 and tulaj=0 order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit '.mt_rand(0,5).',1');
			$aux=mysql_fetch_array($er);
			if ($aux) {
				mysql_query('update flottak set bolygo=0,statusz='.STATUSZ_TAMAD_BOLYGORA.',cel_bolygo='.$aux[0].' where id='.$flotta['id']);
			}
		break;
		case 7://npc flottak ellen
			//$er=mysql_query('select id from flottak where tulaj=0 order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit '.mt_rand(0,5).',1');
			$er=mysql_query('select id from flottak where tulaj=0 order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit 1');
			$aux=mysql_fetch_array($er);
			if ($aux) {
				mysql_query('update flottak set bolygo=0,statusz='.STATUSZ_TAMAD_FLOTTARA.',cel_flotta='.$aux[0].' where id='.$flotta['id']);
			}
		break;
		case 60://origo kozeli npc bolygok ellen
			//$er=mysql_query('select id from bolygok where letezik=1 and tulaj=0 and pow(x,2)+pow(y,2)<pow(10000,2) order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit '.mt_rand(0,5).',1');
			$er=mysql_query('select id from bolygok where letezik=1 and tulaj=0 and pow(x,2)+pow(y,2)<pow(5000,2) order by pow('.$flotta['x'].'-x,2)+pow('.$flotta['y'].'-y,2) limit '.mt_rand(0,5).',1');
			$aux=mysql_fetch_array($er);
			if ($aux) {
				mysql_query('update flottak set bolygo=0,statusz='.STATUSZ_TAMAD_BOLYGORA.',cel_bolygo='.$aux[0].' where id='.$flotta['id']);
			} else {
				mysql_query('update flottak set bolygo=0,statusz='.STATUSZ_MEGY_XY.',cel_x=-200,cel_y='.mt_rand(-200,200).',zanda_statusz=0 where id='.$flotta['id']);
			}
		break;
	}
}

$er_fl=mysql_query('select id from flottak where tulaj=-1 and egyenertek=0 and sebesseg=1000');//veletlenul megmaradt ures flottak
while($flotta=mysql_fetch_array($er_fl)) flotta_torles($flotta['id']);
*/


//mysql_query('update ido set idopont_npc=idopont_npc+1');
	$e26 = $gs->pdo->prepare('update ido set idopont_npc=idopont_npc+1');
	$e26->execute();
	$szimlog_hossz_npc=round(1000*(microtime(true)-$mikor_indul));



//epito karrierek frissitese
if (substr($ora_perc,0,2)=='18') if (substr($rendszer_idopont['uccso_epito_frissites'],0,10)<$mai_nap) {
	$e27 = $gs->pdo->prepare('update ido set uccso_epito_frissites=:date');
	$e27->execute(
			array(
				':date'	=> $mostani_datum
			)
	);
	//mernok: beepitett bolygo
	$q = $gs->pdo->prepare('update userek u,(select distinct tulaj from bolygok where terulet_beepitett>=terulet and tulaj>0) t
set u.speci_1_1=1
where u.id=t.tulaj and u.karrier=1');
	$q->execute();
	//kereskedo, speki: napi eladott osszforgal
	$x=date('Y-m-d H:i:s',time()-3600*24);
	$q = $gs->pdo->prepare('update userek u,(om > 2mrd SHY
select elado,sum(mennyiseg*arfolyam) as forg
from ?.tozsdei_kotesek
where mikor>=? and elado>0
group by elado
having forg>2000000000
) t
set u.speci_1_2=1,u.speci_1_3=1
where u.id=t.elado and u.karrier=1');
	$q->execute(
		array($database_mmog_nemlog, $x	)
	);

}



//regio frissites
if (substr($ora_perc,0,2)=='19') if (substr($rendszer_idopont['uccso_regio_frissites'],0,10)<$mai_nap) {
	$e30 = $gs->pdo->prepare('update ido set uccso_regio_frissites=:date');
	$e30->execute(
			array(
				':date'	=> $mostani_datum
			)
	);
	//tobbsegi regio
	$e31 = $gs->pdo->prepare('update userek u, (
select tulaj
,left(group_concat(lpad(regio,2,"0") order by ipar desc),2)+0 as reg
from (select tulaj,regio,sum(iparmeret) as ipar from bolygok where tulaj>0 group by tulaj,regio) t
group by tulaj
) tt
set u.tobbsegi_regio=tt.reg
where u.id=tt.tulaj');
	$e31->execute();

	//nem kerekedoknek ez az aktualis (es a valasztott is, h specializaciokor ne legyen gond)
	$e32 = $gs->pdo->prepare('update userek
set aktualis_regio=tobbsegi_regio,aktualis_regio2=tobbsegi_regio,valasztott_regio=tobbsegi_regio,valasztott_regio2=tobbsegi_regio
where karrier!=1 or speci!=2');
	$e32->execute();

}




//GALAKTIKUS KOZPONTI BANK
//regio szuzesseg
	$e33 = $gs->pdo->prepare('update regiok r,(
select r.id,count(b.id) as jatekos_bolygo
from regiok r
left join bolygok b on b.regio=r.id and b.tulaj>0
group by r.id
) t
set r.szuz=if(t.jatekos_bolygo>0,0,1)
where r.id=t.id');
	$e33->execute();

//nem szuz regiok atarazasa
	$e34 = $gs->pdo->prepare('update tozsdei_arfolyamok tarf,(
select tk.regio,tk.termek_id,2*sum(if(vevo=0,0,tk.mennyiseg))/sum(tk.mennyiseg)-1+:inflation as delta_ar
from :lsName.tozsdei_kotesek tk
where tk.mikor>timestampadd(minute,-1440,now())
group by tk.termek_id
) t,regiok r
set tarf.ppm_arfolyam=greatest(round(tarf.ppm_arfolyam+66.19/1000000*tarf.ppm_arfolyam*t.delta_ar),1000000)
where tarf.termek_id=t.termek_id and tarf.regio=t.regio and tarf.regio=r.id and r.szuz=0');
	$e34->execute(
			array(
				':inflation'	=> $inflacio,
				':lsName'		=> $database_mmog_nemlog
			)
	);


//szuz regiok arazasa
	$e35 = $gs->pdo->prepare('update tozsdei_arfolyamok tarf, (
select tarf.termek_id,round(avg(tarf.ppm_arfolyam)) as ppm_arfolyam
from tozsdei_arfolyamok tarf, regiok r
where tarf.regio=r.id and r.szuz=0
group by tarf.termek_id
) t, regiok r
set tarf.ppm_arfolyam=t.ppm_arfolyam
where tarf.termek_id=t.termek_id and tarf.regio=r.id and r.szuz=1');
	$e35->execute();

//ppm_arfolyam->arfolyam
	$e36 = $gs->pdo->prepare('update tozsdei_arfolyamok set arfolyam=greatest(round(ppm_arfolyam/1000000),1)');
	$e36->execute();


//minden nap este 8-kor
if (substr($ora_perc,0,2)=='20') if (substr($rendszer_idopont['uccso_veteli_limit_frissites'],0,10)<$mai_nap) {
	//tozsdei veteli limit
	$e37 = $gs->pdo->prepare('update ido set uccso_veteli_limit_frissites=?');
	$e37->execute(
			array( $mostani_datum
			)
	);
	$e38 = $gs->pdo->prepare('update ido set uccso_veteli_limit_frissites=?');
	$e38->execute(
			array(
				$mostani_datum
			)
	);
	//TODO átírni
	$r400 = $gs->pdo->prepare('select round(pow(sum(pontszam_exp_atlag),2)/sum(pow(pontszam_exp_atlag,2))) from userek where szovetseg not in (?) and id not in (?)');
	$r400->execute(array(implode(',',$specko_szovetsegek_listaja), implode(',',$specko_userek_listaja)));
	$r400 = $r400->fetch(PDO::FETCH_BOTH);
	if ($r400[0] ==0){
		$effektiv_jatekosszam=1;
	}else{
		$effektiv_jatekosszam = $r400[0];
	}

	$e39 = $gs->pdo->prepare('update user_veteli_limit uvl,(select ht.id,round(avg(brutto_termeles)*96*7/4.8/?) as mai_varhato_ossztermeles_egy_fore
from zandagort_nemlog.hist_termelesek ht, zandagort_nemlog.hist_idopontok hi
where ht.idopont=hi.id and timestampdiff(day,hi.mikor,now())<7
group by ht.id) t,userek u
set uvl.maximum=round(if(u.karrier=1,if(u.speci=3,1.5,if(u.speci=2,1.4,1.1)),1)*t.mai_varhato_ossztermeles_egy_fore),
uvl.felhasznalt=0
where uvl.termek_id=t.id
and uvl.user_id=u.id');
	$e39->execute(
			array($effektiv_jatekosszam
			)
	);

	//penzatutalasi limit
	$e40 = $gs->pdo->prepare('update userek set penz_adhato_max=round(pontszam_exp_atlag/10), penz_adott=0');
	$e40->execute();
/*	$wau=mysql2num('select akt_7_nap from '.$database_mmog_nemlog.'.akt_stat order by id desc limit 1');if ($wau==0) $wau=1;
	$penzfogadasi_limit=mysql2num('select round(sum(x)/7) from (
select ht.id,avg(brutto_termeles)*96*7/4.8/'.$wau.'*0.1*tarf.arfolyam as x
from '.$database_mmog_nemlog.'.hist_termelesek ht, '.$database_mmog_nemlog.'.hist_idopontok hi, (select termek_id,avg(arfolyam) as arfolyam from tozsdei_arfolyamok group by termek_id) tarf
where ht.idopont=hi.id and timestampdiff(day,hi.mikor,now())<7 and tarf.termek_id=ht.id
group by ht.id) tt');
	mysql_query('update userek
set penz_adhato_max=round(pontszam_exp_atlag/10),
penz_adott=0,
penz_kaphato_max='.$penzfogadasi_limit.',
penz_kapott=0');*/
}


$szimlog_hossz_monetaris=round(1000*(microtime(true)-$mikor_indul));
$e41 = $gs->pdo->prepare('update ido set idopont_monetaris=idopont_monetaris+1');
	$e41->execute();


//BOLYGO MORAL // itt változtatni a 0át 1-re h ne legyen morálnövekedés
	$e42 = $gs->pdo->prepare('
update bolygok
set moral=least(moral+if(vedelmi_bonusz<800,0+floor(vedelmi_bonusz/200),10),100)
where bolygo_id_mod=:m');
	$e42->execute(
			array(
				':m'	=> $perc
			)
	);

//EMBEREK
	$q = $gs->pdo->prepare('
update bolygo_ember be,bolygo_eroforras bkaja,bolygo_eroforras blakohely,bolygok b
set
be.pop=if(
	be.pop<1000,
	1000,
	if(
		least(bkaja.db,blakohely.db)>be.pop+10,
		round(be.pop+(least(bkaja.db,blakohely.db)-be.pop)/500*b.moral),
		if(
			least(bkaja.db,blakohely.db)<be.pop-10,
			round(be.pop+(least(bkaja.db,blakohely.db)-be.pop)*(0.15-b.moral/1000)),
			least(bkaja.db,blakohely.db)
		)
	)
)
where be.bolygo_id=bkaja.bolygo_id and bkaja.eroforras_id=:foodId
and be.bolygo_id=blakohely.bolygo_id and blakohely.eroforras_id=:habitation
and be.bolygo_id=b.id
and b.bolygo_id_mod=:m
and b.tulaj!=0
');
	$q->execute(
			array(
				':foodId'		=> 56,
				':habitation'	=> 55,
				':m'		=> $perc
			)
	);

//npc nem
	$q = $gs->pdo->prepare('
update bolygo_ember be,bolygo_eroforras bkaja,bolygok b
set bkaja.db=if(bkaja.db>be.pop,bkaja.db-be.pop,0)
where be.bolygo_id=bkaja.bolygo_id and bkaja.eroforras_id=:foodId
and bkaja.bolygo_id_mod=:m
and be.bolygo_id=b.id and b.tulaj!=0
');
	$q->execute(
			array(
					':foodId'		=> 56,
					':m'		=> $perc
			)
	);
//npc nem
	$e43 = $gs->pdo->prepare('
update bolygo_eroforras be,bolygok b set be.db=0
where be.bolygo_id_mod=:m and be.eroforras_id=:habitation
and be.bolygo_id=b.id and b.tulaj!=0
'); $e43->execute(
			array(
					':habitation'	=> 55,
					':m'		=> $perc
			)
	);
//npc nem
	$e44 = $gs->pdo->prepare('
update userek u,(
select u.id,u.nev,t.onep,max(bl.bolygolimit) as boli from userek u,(
select u.id,coalesce(sum(be.pop),0) as onep
from userek u
left join bolygok b on b.tulaj=u.id
left join bolygo_ember be on be.bolygo_id=b.id
group by u.id
) t, bolygolimitek bl
where u.id=t.id and bl.nepesseg<=t.onep
group by u.id
order by t.onep
) tt
set u.ossz_nepesseg=tt.onep, u.bolygo_limit=tt.boli
where u.id=tt.id
');
	$e44->execute();
//TECH-SZINT
	$e45 = $gs->pdo->prepare('
update user_kutatasi_szint uksz,(
	select u.id,sum(be.pop) as nep
	from bolygo_ember be, bolygok b, userek u
	where be.bolygo_id=b.id and b.tulaj=u.id
	group by u.id
) t, userek u
set uksz.szint=greatest(uksz.szint,if(t.nep>=500000,6,if(t.nep>=340000,5,if(t.nep>=190000,4,if(t.nep>=140000,3,if(t.nep>=90000,2,if(t.nep>=45000,1,0)))))))
,u.techszint=greatest(u.techszint,if(t.nep>=500000,6,if(t.nep>=340000,5,if(t.nep>=190000,4,if(t.nep>=140000,3,if(t.nep>=90000,2,if(t.nep>=45000,1,0)))))))
where uksz.user_id=t.id and uksz.kf_id=1 and t.id=u.id');
	$e45->execute();

	$er = $gs->pdo->prepare('select id,nev,techszint,nyelv from userek where techszint!=techszint_ertesites');
	$er->execute();
foreach($er->fetchAll(PDO::FETCH_BOTH) as $aux)
	$szimClass->technicLevelMessage($aux[0],$aux[1],$aux[2],'',$aux[3]);
//OSSZEOMLOTT
if (!$inaktiv_szerver) if ($vegjatek==0) {
	$er = $gs->pdo->prepare('select id,nev,email,nyelv from userek where ossz_nepesseg<15000 and osszeomlott=0 and timestampdiff(minute,mikortol,now())>120 and jelenlegi_terulet>0');
	$er->execute();
	foreach($er->fetchAll(PDO::FETCH_BOTH) as $aux) osszeomlott_uzenet($aux[0],$aux[1],$aux[2],$aux[3]);
	$gs->pdo->prepare('update userek set osszeomlott=0 where ossz_nepesseg>25000')->execute();
}

//FEJLETLEN KEZBE KERULT BOLYGOK
	$er = $gs->pdo->prepare('select b.id,bgy.gyar_id,coalesce(min(if(uksz.szint>=gyksz.szint,1,0))) from bolygo_gyar bgy, gyar_kutatasi_szint gyksz, user_kutatasi_szint uksz, bolygok b
where bgy.bolygo_id=b.id and bgy.aktiv_db>0 and gyksz.gyar_id=bgy.gyar_id and gyksz.kf_id=uksz.kf_id and uksz.user_id=b.tulaj
group by b.id,bgy.gyar_id
having coalesce(min(if(uksz.szint>=gyksz.szint,1,0)))=0');
	$er->execute();
foreach($er->fetchAll(PDO::FETCH_BOTH) as $aux) {
	$gs->pdo->prepare('update bolygo_gyar set aktiv_db=0 where bolygo_id=? and gyar_id=?')->execute(
			array($aux[0], $aux[1])
	);
	$szimClass->planetFactoryResources($aux[0]);
}

//MUNKAERO
	$e46 = $gs->pdo->prepare('
update bolygo_eroforras berr,bolygo_ember be
set berr.db=round(be.pop/2)
where berr.bolygo_id=be.bolygo_id and berr.eroforras_id=:wForceId
and berr.bolygo_id_mod=:m
');
	$e46->execute(
			array(
				':wForceId'	=> 57,
				':m'	=> $perc
			)
	);

//TERMELES
	$e47 = $gs->pdo->prepare('update bolygo_eroforras set delta_db=0 where bolygo_id_mod=:m');
	$e47->execute(
			array(
				':m'	=> $perc
			)
	);


	$e48 = $gs->pdo->prepare('
update bolygo_eroforras be,(
	select be.bolygo_id,be.eroforras_id,round(sum(if(
		(gye.gyar_id=84 and gye.eroforras_id=60 and b.osztaly=3) or
		(gye.gyar_id=85 and gye.eroforras_id=61 and b.osztaly=2) or
		(gye.gyar_id=90 and gye.eroforras_id=63 and b.osztaly=1) or
		(gye.gyar_id=86 and gye.eroforras_id=62 and b.osztaly=5)
		,2*gye.io,gye.io
	)*bgy_eff.effektiv_db)) as delta
	from (
		select bgye.bolygo_id,bgye.gyar_id,min(if(bgye.io>=0,bgye.aktiv_db,if(bgye.aktiv_db*bgye.io+be.db/1000000000*bgye.reszarany>=0,bgye.aktiv_db,-be.db/1000000000*bgye.reszarany/bgye.io))) as effektiv_db
		from bolygo_gyar_eroforras bgye,bolygo_eroforras be
		where bgye.bolygo_id=be.bolygo_id and bgye.eroforras_id=be.eroforras_id and be.bolygo_id_mod=:m
		group by bgye.bolygo_id,bgye.gyar_id
	) bgy_eff,bolygo_eroforras be,gyar_eroforras gye,bolygok b
	where be.eroforras_id=gye.eroforras_id and be.bolygo_id=bgy_eff.bolygo_id and bgy_eff.gyar_id=gye.gyar_id
	and be.bolygo_id=b.id and b.tulaj!=0
	group by be.bolygo_id,be.eroforras_id
) deltatabla
set be.db=be.db+deltatabla.delta,
be.delta_db=deltatabla.delta
where be.bolygo_id=deltatabla.bolygo_id and be.eroforras_id=deltatabla.eroforras_id
'); $e48->execute(
			array(
				':m'	=> $perc
			)
	);
//npc nem
//AUTOMATIKUS FELTARAS
//nyers ko, nyers homok
	$e49 = $gs->pdo->prepare('
update bolygo_eroforras be,bolygok b
set db=if(
(be.eroforras_id=60 and b.osztaly=3) or
(be.eroforras_id=61 and b.osztaly=2)
,db+200,db+100)
where be.bolygo_id=b.id and b.bolygo_id_mod=:m and be.eroforras_id in (60,61) and b.tulaj!=0'); $e49->execute(
			array(
					':m'	=> $perc
			)
	);//npc nem
//titanerc
	$e50 = $gs->pdo->prepare('
update bolygo_eroforras be,bolygok b
set db=if(b.osztaly=5,db+1000,db+500)
where be.bolygo_id=b.id and b.bolygo_id_mod=:m and be.eroforras_id=62 and b.tulaj!=0');
	$e50->execute(
			array(
				':m'	=> $perc
			)
	);//npc nem
//uranerc
	$e51 = $gs->pdo->prepare('
update bolygo_eroforras be,bolygok b
set db=if(b.osztaly=1,db+20,db+10)
where be.bolygo_id=b.id and b.bolygo_id_mod=:m and be.eroforras_id=63 and b.tulaj!=0'); $e51->execute(
			array(
					':m'	=> $perc
			)
	);//npc nem
//MUNKAERO
	$e52 = $gs->pdo->prepare('
update bolygo_eroforras berr,bolygo_ember be
set berr.db=round(be.pop/2)
where berr.bolygo_id=be.bolygo_id and berr.eroforras_id=:wForceId
and berr.bolygo_id_mod=:m
'); $e52->execute(
			array(
				':wForceId'	=> 57,
				':m'	=> $perc
			)
	);
//KEPZETT MUNKAERO
	$e53 = $gs->pdo->prepare('
update bolygo_eroforras be1, bolygo_eroforras be2, bolygok b
set be1.db=be2.delta_db,be2.db=0
where be1.bolygo_id_mod=:m and be1.eroforras_id=:skilledWForce
and be2.bolygo_id=be1.bolygo_id and be2.eroforras_id=:skilledWPlace
and be1.bolygo_id=b.id and b.tulaj!=0
'); $e53->execute(
			array(
				':m'	=> $perc,
				':skilledWForce'	=> 58,
				':skilledWPlace'	=> 77
			)
	);
//npc nem
//UGYNOKOK -------------------> a 10 az valojaban KAPACITAS/DELTA_DB (vagyis hany kor alatt telik meg)
//ugynok karrier: be.delta_db=2*be.delta_db
	$e54 = $gs->pdo->prepare('update bolygok b, userek u, bolygo_eroforras be
set be.delta_db=2*be.delta_db
where b.bolygo_id_mod=:m and b.tulaj=u.id and u.karrier=3
and be.bolygo_id_mod=:m and be.bolygo_id=b.id and be.eroforras_id=76'); $e54->execute(
			array(
				':m'	=> $perc
			)
	);
//kapacitasok osszegzese
	$gs->pdo->prepare('update userek u, (
	select u.id,sum(10*be.delta_db) as kapacitas
	from userek u, bolygo_eroforras be, bolygok b
	where be.bolygo_id=b.id and b.tulaj=u.id and be.eroforras_id=76
	group by u.id
) ugynoktabla
set u.ugynok_kapacitas=ugynoktabla.kapacitas
where u.id=ugynoktabla.id')->execute();
//ugynokok osszegzese
	$gs->pdo->prepare('update userek u
left join (select tulaj,sum(darab) as fo from ugynokcsoportok group by tulaj) ugynoktabla on u.id=ugynoktabla.tulaj
set u.ugynokok_szama=coalesce(ugynoktabla.fo,0)')->execute();
//uj ugynokcsoportok
	$e55 = $gs->pdo->prepare('
insert into ugynokcsoportok (tulaj,tulaj_szov,darab,bolygo_id)
select b.tulaj,b.tulaj_szov,round(be.delta_db*t.termeles_szazalek),b.id
from bolygok b, bolygo_eroforras be, (
select b.tulaj
,if(u.ugynok_kapacitas>u.ugynokok_szama
,if(u.ugynokok_szama+sum(be.delta_db)>u.ugynok_kapacitas
,(u.ugynok_kapacitas-u.ugynokok_szama)/sum(be.delta_db)
,1)
,0) as termeles_szazalek
from bolygo_eroforras be, bolygok b, userek u
where be.bolygo_id_mod=:m and be.eroforras_id=76
and be.bolygo_id=b.id and b.tulaj=u.id and b.tulaj!=0 and b.bolygo_id_mod=:m
group by b.tulaj
) t
where be.bolygo_id_mod=:m and be.eroforras_id=76
and be.bolygo_id=b.id and b.tulaj!=0 and b.tulaj=t.tulaj and b.bolygo_id_mod=:m
and round(be.delta_db*t.termeles_szazalek)>0
'); $e55->execute(
			array(
					':m'	=> $perc
			)
	);
//ugynokok ujraosszegzese
	$gs->pdo->prepare('update userek u
left join (select tulaj,sum(darab) as fo from ugynokcsoportok group by tulaj) ugynoktabla on u.id=ugynoktabla.tulaj
set u.ugynokok_szama=coalesce(ugynoktabla.fo,0)')->execute();
//TELEPORTTOLTES -------------------> a 100 az valojaban KAPACITAS/DELTA_DB (vagyis hany kor alatt telik meg)
	$gs->pdo->prepare('
update bolygo_eroforras be, bolygok b
set be.db=least(be.db,100*be.delta_db)
where be.bolygo_id_mod='.$perc.' and be.eroforras_id=78
and be.bolygo_id=b.id and b.tulaj!=0
')->execute(
			array(
					':m'	=> $perc
			)
	);//npc nem
//KOCSMAK, MATROZMORAL
	$gs->pdo->prepare('
update bolygo_eroforras be, bolygok b
set be.db=be.delta_db
where be.bolygo_id_mod=:m and be.eroforras_id=75
and be.bolygo_id=b.id and b.tulaj!=0
')->execute(
			array(
					':m'	=> $perc
			)
	);//npc nem
//K+F
    $q = $gs->pdo->prepare('
update userek u,(
	select b.tulaj,sum(be.db) as termeles
	from bolygok b, bolygo_eroforras be
	where be.eroforras_id=150 and be.bolygo_id=b.id and be.bolygo_id_mod=:m
	group by b.tulaj
) deltatabla
set u.kp=u.kp+deltatabla.termeles,u.megoszthato_kp=u.megoszthato_kp+deltatabla.termeles
where u.id=deltatabla.tulaj
');
	$q->execute(
        array(
            ':m'	=> $perc
        )
    );
    $q = $gs->pdo->prepare('update bolygo_eroforras set db=0 where eroforras_id=150 and bolygo_id_mod=:m');
	$q->execute(
        array(
            ':m'	=> $perc
        )
    );




//AUTO TRANSZFER
//erthetetlen okbol bekerult, kulonbozo tulajdonosnal levo bolygok kozti szallitasok torlese
	$gs->pdo->prepare('delete cron_tabla_eroforras_transzfer from cron_tabla_eroforras_transzfer, bolygok b1, bolygok b2
where b1.id=cron_tabla_eroforras_transzfer.honnan_bolygo_id and b2.id=cron_tabla_eroforras_transzfer.hova_bolygo_id and b1.tulaj!=b2.tulaj')->execute();

//npc bolygokat is kivesszuk, ha netan vannak
	$gs->pdo->prepare('delete cron_tabla_eroforras_transzfer from cron_tabla_eroforras_transzfer, bolygok b where b.id=cron_tabla_eroforras_transzfer.honnan_bolygo_id and b.tulaj=0')->execute();
	$gs->pdo->prepare('delete cron_tabla_eroforras_transzfer from cron_tabla_eroforras_transzfer, bolygok b where b.id=cron_tabla_eroforras_transzfer.hova_bolygo_id and b.tulaj=0')->execute();
//autotranszfer
	$q = $gs->pdo->prepare('
select c.honnan_bolygo_id,c.eroforras_id,be.db as keszlet,
c.darab,c.hova_bolygo_id,e.savszel_igeny,
b.tulaj,b2.tulaj,b.tulaj_szov,b2.tulaj_szov,b.uccso_emberi_tulaj,b.uccso_emberi_tulaj_szov
from
cron_tabla_eroforras_transzfer c, bolygok b, bolygo_eroforras be, eroforrasok e, userek u, bolygok b2
where c.honnan_bolygo_id=b.id and b.bolygo_id_mod=:m
and c.hova_bolygo_id=b2.id
and c.honnan_bolygo_id=be.bolygo_id and c.eroforras_id=be.eroforras_id
and c.eroforras_id=e.id
and b.tulaj=u.id and (u.premium=2 or u.premium_emelt>now())
order by c.honnan_bolygo_id
');
	$q->execute(
			array(
				':m'	=> $perc
			)
	);
$bolygo_id=0;
foreach($q->fetchAll(PDO::FETCH_BOTH) as $aux) {
	if ($bolygo_id!=$aux[0]) {
		$q = $gs->pdo->prepare('select db from bolygo_eroforras where bolygo_id=:planetId and eroforras_id=78');
		$q->execute(
				array(
					':planetId'	=> $aux[0]
				)
		);
		$result = $q->fetch(PDO::FETCH_BOTH);
		$toltes = $result[0];

	}
	$bolygo_id=$aux[0];
	$ef_id=$aux[1];
	$mennyiseg=$aux[3];
	if ($aux[2]<$mennyiseg) $mennyiseg=$aux[2];
	if ($aux[5]*$toltes<$mennyiseg) $mennyiseg=$aux[5]*$toltes;
	if ($mennyiseg>0) {
		$q_0 = $gs->pdo->prepare('update bolygo_eroforras set db=if(db-?<0,0,db-?) where bolygo_id=? and eroforras_id=?');
		$q_0->execute(array($mennyiseg, $mennyiseg, $aux[0], $ef_id));
		$q_1 = $gs->pdo->prepare('update bolygo_eroforras set db=db+? where bolygo_id=? and eroforras_id=?');
		$q_1->execute(array($mennyiseg, $aux[4], $ef_id));
		//toltes
		$delta_toltes=ceil($mennyiseg/$aux[5]);
		$q_2 = $gs->pdo->prepare('update bolygo_eroforras set db=if(db-?<0,0,db-?) where bolygo_id=? and eroforras_id=78');
		$q_2->execute(array($delta_toltes, $delta_toltes, $aux[0]));
		$toltes-=$delta_toltes;if ($toltes<0) $toltes=0;
		// TODO Normális log létrehozása (talán egy normális admin felülettel)
		//insert_into_transzfer_log($aux[10],$aux[11],$aux[6],$aux[8],$aux[0],$aux[7],$aux[9],$aux[4],$ef_id,$mennyiseg,1);
	}
}
//AUTO TOZSDE
$datum=date('Y-m-d H:i:s');
	$q = $gs->pdo->prepare('
select c.honnan_bolygo_id,c.eroforras_id,be.db as keszlet
,c.darab,0 as nulla1,e.savszel_igeny
,b.tulaj,0 as nulla2,b.tulaj_szov,0 as nulla3,b.uccso_emberi_tulaj,b.uccso_emberi_tulaj_szov
,u.megoszthato_kp
,if(c.regio_slot=2,u.aktualis_regio2,u.aktualis_regio) as regio
from cron_tabla_eroforras_transzfer c
inner join bolygok b on c.honnan_bolygo_id=b.id
inner join bolygo_eroforras be on c.honnan_bolygo_id=be.bolygo_id and c.eroforras_id=be.eroforras_id
inner join eroforrasok e on c.eroforras_id=e.id
inner join userek u on b.tulaj=u.id
where b.bolygo_id_mod=?
and c.hova_bolygo_id=0
and (u.premium=2 or u.premium_emelt>now())
order by c.honnan_bolygo_id
');
	$q->execute(array($perc));
$bolygo_id=0;
foreach($q->fetchAll(PDO::FETCH_BOTH) as $aux) {
	if ($bolygo_id!=$aux['honnan_bolygo_id']) {
		$q = $gs->pdo->prepare('select db from bolygo_eroforras where bolygo_id=:planetId and eroforras_id=78');
		$q->execute(
				array(
						':planetId'	=> $aux[0]
				)
		);
		$result = $q->fetch(PDO::FETCH_BOTH);
		$toltes = $result[0];
	}
	$bolygo_id=$aux['honnan_bolygo_id'];
	$ef_id=$aux[1];
	$mennyiseg=$aux[3];
	if ($ef_id<150) {//nyersi
		if ($aux['keszlet']<$mennyiseg) $mennyiseg=$aux['keszlet'];//keszlet
	} else {//KP
		if ($aux['megoszthato_kp']<$mennyiseg) $mennyiseg=$aux['megoszthato_kp'];//megoszthato
	}
	if ($aux[5]*$toltes<$mennyiseg) $mennyiseg=$aux[5]*$toltes;
	if ($mennyiseg>0) {
		$q = $gs->pdo->prepare('select arfolyam from tozsdei_arfolyamok where termek_id=? and regio=?');
		$q->execute(array($ef_id, $aux['regio']));
		$result = $q->fetch(PDO::FETCH_BOTH);
		$arfolyam = $result[0];

		$q_1 = $gs->pdo->prepare('update userek set vagyon=vagyon+?*? where id=?');
		$q_1->execute(array($mennyiseg, $arfolyam, $aux[6]));
		if ($ef_id<150) {//nyersi
			$q = $gs->pdo->prepare('update bolygo_eroforras set db=if(db-?>0,db-?,0) where bolygo_id=? and eroforras_id=?');
			$q->execute(array($mennyiseg, $mennyiseg, $bolygo_id, $ef_id));
		} else {//KP
			$q = $gs->pdo->prepare('update userek set megoszthato_kp=if(megoszthato_kp-?>0,megoszthato_kp-?,0) where id=?');
			$q->execute(array($mennyiseg, $mennyiseg, $aux[6]));
		}
		//toltes
		$delta_toltes=ceil($mennyiseg/$aux[5]);
		$q = $gs->pdo->prepare('update bolygo_eroforras set db=if(db-?<0,0,db-?) where bolygo_id=? and eroforras_id=78');
		$q->execute(array($delta_toltes, $delta_toltes, $bolygo_id));
		$toltes-=$delta_toltes;if ($toltes<0) $toltes=0;
		//
		$q_2 = $ls->pdo->prepare('insert into tozsdei_kotesek (vevo,vevo_tulaj_szov,elado,elado_tulaj_szov,regio,termek_id,mennyiseg,arfolyam,mikor,vevo_bolygo_id,elado_bolygo_id)
		values(?,?,?,?,?,?,?,?,?,?,?)');
		$q_2->execute(array(0, 0, $aux[6], $aux[8], $aux['regio'], $ef_id, $mennyiseg, $arfolyam, $datum, 0, $bolygo_id));
	}
}






//SZAPORODAS
	$q = $gs->pdo->prepare('
update bolygo_eroforras be,(
	select egyik.bolygo_id,egyik.eroforras_id,
	if(b.terulet>0,sum((masik.db/1)*ff.coef/if(masik.eroforras_id>0,b.terulet/100000,1))*egyik.db/1000000-if(egyik.db<1000,1000-egyik.db,0),-egyik.db) as delta
	from bolygo_eroforras egyik,bolygo_eroforras masik,faj_faj ff,bolygok b
	where egyik.eroforras_id=ff.faj_id and masik.eroforras_id=ff.masik_faj_id and egyik.bolygo_id=masik.bolygo_id
	and b.id=egyik.bolygo_id
	and b.bolygo_id_mod=:m
	and b.tulaj!=0
	group by egyik.bolygo_id,egyik.eroforras_id
) deltatabla
set be.db=if(floor(be.db+deltatabla.delta)>0,floor(be.db+deltatabla.delta),0)
where be.bolygo_id=deltatabla.bolygo_id and be.eroforras_id=deltatabla.eroforras_id
');
	$q->execute(
			array(
				':m'	=> $perc
			)
	);




//QUEUE EPITKEZESEK
	$gs->pdo->prepare('update bolygok set van_e_eplistaban_epulet=0')->execute();
	$gs->pdo->prepare('update bolygok b, (select distinct bolygo_id from queue_epitkezesek) t
set b.van_e_eplistaban_epulet=1
where b.id=t.bolygo_id')->execute();

	$q = $gs->pdo->prepare('select distinct q.bolygo_id,u.karrier,u.speci from queue_epitkezesek q, bolygok b, userek u where q.bolygo_id=b.id and b.tulaj=u.id and b.befagy_eplista=0');
	$q->execute();

	foreach($q->fetchAll(PDO::FETCH_BOTH) as $aux) {
		$q_3 = $gs->pdo->prepare('select q.*,gy.tipus from queue_epitkezesek q, gyarak gy where q.bolygo_id=? and q.gyar_id=gy.id order by sorszam limit 1');
		$q_3->execute(array($aux[0]));
		$parancs = $q_3->fetch(PDO::FETCH_BOTH);
	if ($parancs['bolygo_id']>0) {//ha a kulso select ota toroltek egy epitkezest
			$q_4 = $gs->pdo->prepare('
select coalesce(min(if(gyek.db>0,mar.maradek_keszlet/gyek.db,999999)),0)
from gyar_epitesi_koltseg gyek,(
select be.eroforras_id as id,be.db+coalesce(t.fogy,0) as maradek_keszlet from
bolygo_eroforras be
left join (
select gye.eroforras_id,sum(if(gye.io<0,gye.io,0)*bgy_eff.effektiv_db) as fogy from
(select bgye.bolygo_id,bgye.gyar_id,min(if(bgye.io>=0,bgye.aktiv_db,if(bgye.aktiv_db*bgye.io+be.db/1000000000*bgye.reszarany>=0,bgye.aktiv_db,-be.db/1000000000*bgye.reszarany/bgye.io))) as effektiv_db
from bolygo_gyar_eroforras bgye,bolygo_eroforras be
where bgye.bolygo_id=be.bolygo_id and bgye.eroforras_id=be.eroforras_id and be.bolygo_id=?
group by bgye.gyar_id) bgy_eff,gyar_eroforras gye where bgy_eff.gyar_id=gye.gyar_id
group by gye.eroforras_id
) t on be.eroforras_id=t.eroforras_id
where be.bolygo_id=?
) mar
where mar.id=gyek.eroforras_id and gyek.tipus=?
		');
		$q_4->execute(array($parancs['bolygo_id'], $parancs['bolygo_id'], $parancs['tipus']));
		$aux2= $q_4->fetch(PDO::FETCH_BOTH);
		$hanyat=floor($aux2[0]);if ($hanyat>$parancs['darab']) $hanyat=$parancs['darab'];
		//teruleti korlatozas
		$q = $gs->pdo->prepare('select * from bolygok where id=?');
		$q->execute(array($aux[0]));
		$bolygo = $q->fetch(PDO::FETCH_BOTH);

		$q = $gs->pdo->prepare('select * from gyartipusok where id=?');
		$q->execute(array($parancs['tipus']));
		$gyartipus = $q->fetch(PDO::FETCH_BOTH);

		//ha (terulet_beepitett+db*gyar_terulet)/terraformaltsag*10000 > terulet, akkor csak részteljesítés:
		//db = floor((terulet/10000*terraformaltsag-terulet_beepitett)/gyar_terulet)
		if (($bolygo['terulet_beepitett']+$hanyat*$gyartipus['terulet'])/$bolygo['terraformaltsag']*10000>$bolygo['terulet']) {
			$hanyat=floor(($bolygo['terulet']/10000*$bolygo['terraformaltsag']-$bolygo['terulet_beepitett'])/$gyartipus['terulet']);
		}
		//
		if ($hanyat>=1) {
			$q = $gs->pdo->prepare('update gyar_epitesi_koltseg gyek,bolygo_eroforras be set be.db=if(be.db>?*gyek.db,be.db-?*gyek.db,0)
			where gyek.tipus=? and gyek.szint=1 and gyek.eroforras_id=be.eroforras_id and be.bolygo_id=?');
			$q->execute(array($hanyat, $hanyat, $parancs['tipus'], $parancs['bolygo_id']));

			$q = $gs->pdo->prepare('select * from gyar_epitesi_ido where tipus=? and szint=1');
			$q->execute(array($parancs['tipus']));
			$aux4 = $q->fetch(PDO::FETCH_BOTH);
			if ($aux['karrier']==1 and $aux['speci']==1) if (in_array($aux4['tipus'],$mernok_8_oras_gyarai)) $aux4['ido']=480;
				$q = $gs->pdo->prepare('insert into cron_tabla (mikor_aktualis,feladat,bolygo_id,gyar_id,aktiv,darab,indulo_allapot)
				values(?,?,?,?,?,?,?)');
				$q->execute(array(date('Y-m-d H:i:s',time()+60*$aux4['ido']), 1, $parancs['bolygo_id'], $parancs['gyar_id'], $parancs['aktiv'], $hanyat, 1));
			if ($hanyat<$parancs['darab']){
				$q = $gs->pdo->prepare('update queue_epitkezesek set darab=darab-? where id=?');
				$q->execute(array($hanyat, $parancs['id']));
			} else {
				$q = $gs->pdo->prepare('delete from queue_epitkezesek where id=?');
				$q->execute(array($parancs['id']));

				$q = $gs->pdo->prepare('update queue_epitkezesek set sorszam=sorszam-1 where bolygo_id=? and sorszam>?');
				$q->execute(array($parancs['bolygo_id'], $parancs['sorszam']));
			}
			$szimClass->planetBuildingAreaRefresh($parancs['bolygo_id']);
		}
	}
}

	$gs->pdo->prepare('update bolygok set maradt_eplistaban_epulet=0')->execute();
	$gs->pdo->prepare('update bolygok b, (select distinct bolygo_id from queue_epitkezesek) t
set b.maradt_eplistaban_epulet=1
where b.id=t.bolygo_id')->execute();

	$gs->pdo->prepare('update ido set idopont_termeles=idopont_termeles+1')->execute();
	$szimlog_hossz_termeles=round(1000*(microtime(true)-$mikor_indul));


//ugynok karrierek frissitese
if (substr($ora_perc,0,2)=='12') if (substr($rendszer_idopont['uccso_ugynok_frissites'],0,10)<$mai_nap) {
	$gs->pdo->prepare('update ido set uccso_ugynok_frissites=?')->execute(array($mostani_datum));
	//kem, szabotor: 10 hk
    $gs->pdo->prepare('update userek u,(select u.id,coalesce(sum(bgy.db),0) as hk
from userek u
inner join bolygok b on b.tulaj=u.id
inner join bolygo_gyar bgy on bgy.bolygo_id=b.id and bgy.gyar_id=87
where u.karrier=3
group by u.id
having hk>=10) t
set u.speci_3_1=1,u.speci_3_2=1
where u.id=t.id')->execute();
}

//ugynokok mozgatasa
    $gs->pdo->prepare('update ugynokcsoportok ucs, bolygok b
set ucs.bolygo_id=if(pow(ucs.x-b.x,2)+pow(ucs.y-b.y,2)<=40000,b.id,0)
,ucs.cel_bolygo_id=if(pow(ucs.x-b.x,2)+pow(ucs.y-b.y,2)<=40000,0,b.id)
,ucs.x=if(pow(ucs.x-b.x,2)+pow(ucs.y-b.y,2)<=40000,b.x,round(ucs.x+(b.x-ucs.x)/sqrt(pow(ucs.x-b.x,2)+pow(ucs.y-b.y,2))*200))
,ucs.y=if(pow(ucs.x-b.x,2)+pow(ucs.y-b.y,2)<=40000,b.y,round(ucs.y+(b.y-ucs.y)/sqrt(pow(ucs.x-b.x,2)+pow(ucs.y-b.y,2))*200))
where ucs.cel_bolygo_id=b.id')->execute();
//40000=200^2=ugynoksebesseg^2 (felparsecben)

//idegen bolygon nem lehet elharitani (1,2)
    $gs->pdo->prepare('update ugynokcsoportok ucs, bolygok b
set ucs.statusz=0
where ucs.bolygo_id=b.id and ucs.tulaj_szov!=b.tulaj_szov and ucs.statusz in (1,2)')->execute();

//sajat bolygon nem lehet kemkedni,szabotalni (3,4)
    $gs->pdo->prepare('update ugynokcsoportok ucs, bolygok b
set ucs.statusz=0
where ucs.bolygo_id=b.id and ucs.tulaj_szov=b.tulaj_szov and ucs.statusz in (3,4)')->execute();

//koltopenz (shy_most) kiszamitasa es levonasa
    $gs->pdo->prepare('lock tables ugynokcsoportok ucs write, ugynokcsoportok ucsr read, bolygok b write, bolygok br read, userek u write, userek ur read')->execute();
    $gs->pdo->prepare('update ugynokcsoportok ucs set ucs.shy_most=0')->execute();
    $q = $gs->pdo->prepare('update ugynokcsoportok ucs, bolygok b, userek u, (
select ur.id,sum(ucsr.shy_per_akcio) as teljes_penzigeny
from ugynokcsoportok ucsr, bolygok br, userek ur
where ucsr.bolygo_id=br.id and br.bolygo_id_mod=? and ucsr.statusz!=0 and ucsr.tulaj=ur.id
group by ur.id
) t
set ucs.shy_most=if(u.vagyon>=t.teljes_penzigeny,ucs.shy_per_akcio,round(ucs.shy_per_akcio/t.teljes_penzigeny*u.vagyon))
* case ucs.statusz
	when 1 then if(u.karrier=3 and u.speci=3,2,1)
	when 2 then if(u.karrier=3 and u.speci=3,2,1)
	when 3 then if(u.karrier=3 and u.speci=1,2,1)
	when 4 then if(u.karrier=3 and u.speci=2,2,1)
	else 1
end
where ucs.bolygo_id=b.id and b.bolygo_id_mod=? and ucs.statusz!=0 and ucs.tulaj=u.id and u.id=t.id');
    $q->execute(array($perc, $perc));

    $gs->pdo->prepare('update userek u, (select ucsr.tulaj,sum(ucsr.shy_most) as teljes_koltes from ugynokcsoportok ucsr where ucsr.statusz in (3,4) group by ucsr.tulaj) t
set u.vagyon=if(u.vagyon>t.teljes_koltes,u.vagyon-t.teljes_koltes,0)
where u.id=t.tulaj')->execute();
$gs->pdo->prepare('unlock tables')->execute();


//aktiv elharitok
$q = $gs->pdo->prepare('select b.id,ucs.tulaj,coalesce(sum(ucs.darab),0),coalesce(sum(ucs.shy_most),0),b.nev,b.tulaj_szov,b.x,b.y
from ugynokcsoportok ucs, bolygok b
where b.letezik=1 and b.tulaj!=0 and ucs.bolygo_id=b.id and b.bolygo_id_mod=? and ucs.statusz=2 group by b.id,ucs.tulaj');
    $q->execute(array($perc));


$elozo_bolygo_id=0;
foreach($q->fetchAll(PDO::FETCH_BOTH) as $bolygo_tulaj){


    if ($bolygo_tulaj[2]>0) {
        $aktiv_elharitok_szama = $bolygo_tulaj[2];
        $aktiv_elharitok_penze = $bolygo_tulaj[3];
        $aktiv_elharitok_penz_per_fo = $aktiv_elharitok_penze / $aktiv_elharitok_szama;
        if ($bolygo_tulaj[0] != $elozo_bolygo_id) {
            $elozo_bolygo_id = $bolygo_tulaj[0];
            $q = $gs->pdo->prepare('SELECT coalesce(sum(if(ucs.statusz=0,ucs.darab*2,ucs.darab)),0),coalesce(sum(ucs.shy_most),0) FROM ugynokcsoportok ucs, bolygok b WHERE ucs.bolygo_id=b.id AND ucs.tulaj_szov!=b.tulaj_szov AND b.id=?');
            $q->execute(array($bolygo_tulaj[0]));
            $aux = $q->fetch(PDO::FETCH_BOTH);
            $ellenseges_ugynokok_szama = $aux[0];
            $ellenseges_ugynokok_penze = $aux[1];
        }
        if ($ellenseges_ugynokok_szama > 0) {
            //aktiv elharito tenylegesen csak itt kolti el a penzet
            $levonando_penz = min($aktiv_elharitok_penze, $ellenseges_ugynokok_penze);
            $q = $gs->pdo->prepare('UPDATE userek SET vagyon=if(vagyon>?,vagyon-?,0) WHERE id=?');
            $q->execute(array($levonando_penz, $levonando_penz, $bolygo_tulaj['tulaj']));
            //
            $likvidalasi_arany = $aktiv_elharitok_szama / $ellenseges_ugynokok_szama * 2;//aktiv elharito hatekony
            if ($likvidalasi_arany > 1) $likvidalasi_arany = 1;
            $q = $gs->pdo->prepare('UPDATE ugynokcsoportok SET likvidalt=0 WHERE bolygo_id=?');
            $q->execute($bolygo_tulaj[0]);
            $gs->pdo->prepare('UPDATE ugynokcsoportok ucs, bolygok b SET ucs.likvidalt=if(?*if(ucs.statusz=0,0.5,1)*least(if(ucs.shy_most>0,/ucs.shy_most*ucs.darab,1),1)*ucs.darab>=1,round(?*if(ucs.statusz=0,0.5,1)*least(if(ucs.shy_most>0,?/ucs.shy_most*ucs.darab,1),1)*ucs.darab),if(rand()<?*if(ucs.statusz=0,0.5,1)*least(if(ucs.shy_most>0,?/ucs.shy_most*ucs.darab,1),1)*ucs.darab,1,0)) WHERE ucs.bolygo_id=b.id AND ucs.tulaj_szov!=b.tulaj_szov AND b.id=?')->execute(
                array($likvidalasi_arany, $aktiv_elharitok_penz_per_fo, $likvidalasi_arany, $aktiv_elharitok_penz_per_fo, $likvidalasi_arany, $aktiv_elharitok_penz_per_fo, $bolygo_tulaj[0])
            );
            $r2 = $gs->pdo->prepare('SELECT ucs.*,u.nev AS tulaj_nev FROM ugynokcsoportok ucs, userek u WHERE ucs.likvidalt>0 AND ucs.bolygo_id=? AND ucs.tulaj=u.id');
            $r2->execute(array($bolygo_tulaj[0]));
            foreach($r2->fetchAll(PDO::FETCH_BOTH) as $ucs) {
                //kopes
                if ($ucs['shy_most'] > 0) $kopesi_valoszinuseg = 100 - 100 / (1 + $aktiv_elharitok_penze / $ucs['shy_most']); else $kopesi_valoszinuseg = 100;
                if (mt_rand(0, 99) < $kopesi_valoszinuseg) {
                    $reszletes_info = ' ' . ($ucs['statusz'] == 0 ? 'Alvóügynökök' : ($ucs['statusz'] == 3 ? 'Kémek' : 'Szabotőrök')) . ' voltak, akiket ' . $ucs['tulaj_nev'] . ' küldött.';
                    $reszletes_info_en = ' They were ' . ($ucs['statusz'] == 0 ? 'sleeper agents' : ($ucs['statusz'] == 3 ? 'spies' : 'saboteurs')) . ' sent by ' . $ucs['tulaj_nev'] . '.';
                } else {
                    $reszletes_info = '';
                    $reszletes_info_en = '';
                }
                //jelentes az elharitonak
                /*rendszeruzenet($bolygo_tulaj['tulaj']
                    , 'Sikeres elhárítás', $bolygo_tulaj['nev'] . ' bolygódon elhárítottál ' . $ucs['likvidalt'] . ' ügynököt.' . $reszletes_info
                    , 'Successful counterintelligence action', 'You have countered ' . $ucs['likvidalt'] . ' agents on your planet ' . $bolygo_tulaj['nev'] . '.' . $reszletes_info_en
                );
                //jelentes a tamadonak
                //rendszeruzenet($ucs['tulaj']
                    , 'Ellenséges elhárítás', $bolygo_tulaj['nev'] . ' bolygón ' . $ucs['likvidalt'] . ' ügynöködet likvidálták.'
                    , 'Hostile counterintelligence action', $ucs['likvidalt'] . ' of your agents have been liquidated on planet ' . $bolygo_tulaj['nev'] . '.'
                );*/
            }
            $q = $gs->pdo->prepare('UPDATE ugynokcsoportok SET darab=if(darab>likvidalt,darab-likvidalt,0) WHERE likvidalt>0 AND bolygo_id=?');
            $q->execute(array($bolygo_tulaj[0]));
        }
    }
	//kozelgo ellenseges ugynokok (10kpc-nel kozelebb, 100 perc uton belul, 6-7 kor mulva ernek ide)
    $kucs = $gs->pdo->prepare('select ucs.tulaj,u.nev as tulaj_nev,round(ucs.x/1000)*1000 as xx,round(ucs.y/1000)*1000 as yy,sum(ucs.darab) as ossz_darab from ugynokcsoportok ucs, userek u where ucs.cel_bolygo_id=? and ucs.tulaj_szov!=? and ucs.tulaj=u.id and pow(?-ucs.x,2)+pow(?-ucs.y,2)<pow(20000,2) group by ucs.tulaj,xx,yy order by rand() limit 1');
    $kucs->execute(array($bolygo_tulaj[0], $bolygo_tulaj['tulaj_szov'], $bolygo_tulaj['x'], $bolygo_tulaj['y']));
	$kozelgo_ucs = $kucs->fetch(PDO::FETCH_BOTH);
    if ($kucs->rowCount()) if ($kozelgo_ucs['ossz_darab']>0) {
		$lebukasi_valoszinuseg=100-100/(1+$aktiv_elharitok_szama/$kozelgo_ucs['ossz_darab']/10);//1:1 arany eseten r=0.1 vagyis p=100-100/1.1 = 9,1% vagyis  6-7 kor alatt kb 50% a lebukas
		if (mt_rand(0,99)<$lebukasi_valoszinuseg) {

            /*rendszeruzenet($bolygo_tulaj['tulaj']
				,'Közelgő veszély',$bolygo_tulaj['nev'].' bolygódra ellenséges ügynökök közelednek. Megbízójuk: '.$kozelgo_ucs['tulaj_nev'].', létszámuk: '.$kozelgo_ucs['ossz_darab'].', várható érkezési idejük: kb '.ceil(sqrt(pow($kozelgo_ucs['xx']-$bolygo_tulaj['x'],2)+pow($kozelgo_ucs['yy']-$bolygo_tulaj['y'],2))/200).' perc.'
				,'Incoming agents','Hostile agents are approaching your planet '.$bolygo_tulaj['nev'].'. Commissioned by: '.$kozelgo_ucs['tulaj_nev'].', number: '.$kozelgo_ucs['ossz_darab'].', expected time of arrival: cca '.ceil(sqrt(pow($kozelgo_ucs['xx']-$bolygo_tulaj['x'],2)+pow($kozelgo_ucs['yy']-$bolygo_tulaj['y'],2))/200).' minutes.'
			);*/
		}
	}
}
    $gs->pdo->prepare('delete from ugynokcsoportok where darab=0')->execute();



//passziv elharitok
    $r = $gs->pdo->prepare('select b.id,ucs.tulaj,coalesce(sum(ucs.darab),0),coalesce(sum(ucs.shy_most),0),b.nev
from ugynokcsoportok ucs, bolygok b
where b.letezik=1 and b.tulaj!=0 and ucs.bolygo_id=b.id and b.bolygo_id_mod=? and ucs.statusz=1 group by b.id,ucs.tulaj');
    $r->execute(array($perc));

$elozo_bolygo_id=0;
    foreach($r->fetchAll(PDO::FETCH_BOTH) as $bolygo_tulaj){
        if ($bolygo_tulaj[2]>0) {
            $aktiv_elharitok_szama=$bolygo_tulaj[2];
            $aktiv_elharitok_penze=$bolygo_tulaj[3];
            $aktiv_elharitok_penz_per_fo=$aktiv_elharitok_penze/$aktiv_elharitok_szama*4;//passziv elharito olcson mukodik
            if ($bolygo_tulaj[0]!=$elozo_bolygo_id) {
                $elozo_bolygo_id=$bolygo_tulaj[0];
                $r_1 = $gs->pdo->prepare('select coalesce(sum(ucs.darab),0),coalesce(sum(ucs.shy_most),0) from ugynokcsoportok ucs, bolygok b where ucs.statusz!=0 and ucs.bolygo_id=b.id and ucs.tulaj_szov!=b.tulaj_szov and b.id=?');
                $r_1->execute(array($bolygo_tulaj[0]));
                $aux = $r_1->fetch(PDO::FETCH_BOTH);
                $ellenseges_ugynokok_szama=$aux[0];//alvougynokok nem szamitanak bele
                $ellenseges_ugynokok_penze=$aux[1];
            }
            if ($ellenseges_ugynokok_szama>0) {
                //elharito tenylegesen csak itt kolti el a penzet
                $levonando_penz=min($aktiv_elharitok_penze,$ellenseges_ugynokok_penze);
                $r_2 = $gs->pdo->prepare('update userek set vagyon=if(vagyon>?,vagyon-?,0) where id=?');
                $r_2->execute(array($levonando_penz, $levonando_penz, $bolygo_tulaj['tulaj']));
                //
                $likvidalasi_arany=$aktiv_elharitok_szama/$ellenseges_ugynokok_szama;
                if ($likvidalasi_arany>1) $likvidalasi_arany=1;
                $r01 = $gs->pdo->prepare('update ugynokcsoportok set likvidalt=0 where bolygo_id=?');
                $r01->execute(array($bolygo_tulaj[0]));
                $r02 = $gs->pdo->prepare('update ugynokcsoportok ucs, bolygok b set ucs.likvidalt=if(?*least(if(ucs.shy_most>0,?/ucs.shy_most*ucs.darab,1),1)*ucs.darab>=1,round(?*least(if(ucs.shy_most>0,?/ucs.shy_most*ucs.darab,1),1)*ucs.darab),if(rand()<?*least(if(ucs.shy_most>0,?/ucs.shy_most*ucs.darab,1),1)*ucs.darab,1,0)) where ucs.statusz!=0 and ucs.bolygo_id=b.id and ucs.tulaj_szov!=b.tulaj_szov and b.id=?');
                $r02->execute(array($likvidalasi_arany, $aktiv_elharitok_penz_per_fo, $likvidalasi_arany, $aktiv_elharitok_penz_per_fo, $likvidalasi_arany, $aktiv_elharitok_penz_per_fo, $bolygo_tulaj[0]));

                $r03 = $gs->pdo->prepare('select * from ugynokcsoportok where likvidalt>0 and bolygo_id=?');
                $r03->execute(array($bolygo_tulaj[0]));
                foreach($r03->fetchAll(PDO::FETCH_BOTH) as $ucs){
                    //jelentes az elharitonak
                    /*rendszeruzenet($bolygo_tulaj['tulaj']
                        ,'Sikeres elhárítás',$bolygo_tulaj['nev'].' bolygódon sikeresen elhárítottál.'
                        ,'Successful counterintelligence action','You have countered some agents on your planet '.$bolygo_tulaj['nev'].'.'
                    );
                    //jelentes a tamadonak
                    rendszeruzenet($ucs['tulaj']
                        ,'Ellenséges elhárítás',$bolygo_tulaj['nev'].' bolygón '.$ucs['likvidalt'].' ügynöködet likvidálták.'
                        ,'Hostile counterintelligence action',$ucs['likvidalt'].' of your agents have been liquidated on planet '.$bolygo_tulaj['nev'].'.'
                    );*/
                }
                $r04 = $gs->pdo->prepare('update ugynokcsoportok set darab=if(darab>likvidalt,darab-likvidalt,0) where likvidalt>0 and bolygo_id=?');
                $r04->execute(array($bolygo_tulaj[0]));
            }
        }
    }
    $gs->pdo->prepare('delete from ugynokcsoportok where darab=0')->execute();


$most=date('Y-m-d H:i:s');
//kemek
$kemriportok=array();
    $kr0 = $gs->pdo->prepare('select b.id as bolygo_id,ucs.tulaj,ucs.feladat_domen,ucs.feladat_id,coalesce(sum(ucs.darab),0) as ossz_darab,coalesce(sum(ucs.shy_most),0) as ossz_shy_most,b.nev as bolygo_nev, ucs.tulaj_szov
from ugynokcsoportok ucs, bolygok b
where ucs.bolygo_id=b.id and b.bolygo_id_mod=? and ucs.statusz=3 group by b.id,ucs.tulaj,ucs.feladat_domen,ucs.feladat_id');
    $kr0->execute(array($perc));

foreach($kr0->fetchAll(PDO::FETCH_BOTH) as $ucs) if ($ucs['ossz_darab']>0) if ($ucs['ossz_shy_most']>0) {
	$shy_per_fo=$ucs['ossz_shy_most']/$ucs['ossz_darab'];
	switch($ucs['feladat_domen']) {
		case 1://gyar
            $kr01 = $gs->pdo->prepare('select * from gyartipusok where id=?');
            $kr01->execute(array($ucs['feladat_id']));
            $kr01 = $kr01->fetch(PDO::FETCH_BOTH);
            $gyartipus = $kr01[0];

            $kr02 = $gs->pdo->prepare('select sum(bgy.db),sum(bgy.aktiv_db) from bolygo_gyar bgy, gyarak gy where bgy.bolygo_id=? and bgy.gyar_id=gy.id and gy.tipus=?');
            $kr02->execute(array($ucs['bolygo_id'], $ucs['feladat_id']));
            $kr02 = $kr02[0];
            $gyarak_szama = $kr02;
			//ar kiszamitasa
			//$ar=$gyartipus['pontertek']/1000/10;//1 pont = 1000 shy
			//$ar=1;
			$ar=$gyartipus['kemkedes_ara'];
			//
			$valseg=$shy_per_fo/$ar;if ($valseg>1) $valseg=1;
			$n=$valseg*$ucs['ossz_darab'];
			if ($n<1) {
				if (mt_rand(0,99)<100*$n) $n=1;else $n=0;
			}
			if ($n>0) {
				$n=round($n);
				if (!$gyarak_szama) {//nincs
					$kemriportok[]=array($ucs['tulaj'],$ucs['tulaj_szov'],$ucs['bolygo_id'],0,$ucs['feladat_domen'],$ucs['feladat_id'],0,0,1);
				} else {
					if ($gyarak_szama[0]>$n) {//n+
						$kemriportok[]=array($ucs['tulaj'],$ucs['tulaj_szov'],$ucs['bolygo_id'],0,$ucs['feladat_domen'],$ucs['feladat_id'],$n,0,0);
					} else {//n
						$kemriportok[]=array($ucs['tulaj'],$ucs['tulaj_szov'],$ucs['bolygo_id'],0,$ucs['feladat_domen'],$ucs['feladat_id'],$gyarak_szama[0],$gyarak_szama[1],1);
					}
				}
			}
		break;
		case 2://eroforras
            $er01 = $gs->pdo->prepare('select * from eroforrasok where id=?');
            $er01->execute(array($ucs['feladat_id']));
            $er01 = $er->fetch(PDO::FETCH_BOTH);
            $eroforras = $er01[0];

            $er02 = $gs->pdo->prepare('select db from bolygo_eroforras where bolygo_id='.$ucs['bolygo_id'].' and eroforras_id='.$ucs['feladat_id']);
            $er02->execute(array($ucs['bolygo_id'], $ucs['feladat_id']));
            $er02 = $er->fetch(PDO::FETCH_BOTH);
            $eroforras_mennyisege = $er02[0];


			//ar kiszamitasa
			//$ar=$eroforras['pontertek']/1000/10;//1 pont = 1000 shy
			//$ar=1;
			$ar=$eroforras['kemkedes_ara']/100;//eroforrasoknal fillerben van tarolva az ar
			//
			$n=$ucs['ossz_shy_most']/$ar;
			if ($n>0) {
				$n=round($n);
				if (!$eroforras_mennyisege) {//nincs
					$kemriportok[]=array($ucs['tulaj'],$ucs['tulaj_szov'],$ucs['bolygo_id'],0,$ucs['feladat_domen'],$ucs['feladat_id'],0,0,1);
				} else {
					if ($eroforras_mennyisege[0]>$n) {//n+
						$kemriportok[]=array($ucs['tulaj'],$ucs['tulaj_szov'],$ucs['bolygo_id'],0,$ucs['feladat_domen'],$ucs['feladat_id'],$n,0,0);
					} else {//n
						$kemriportok[]=array($ucs['tulaj'],$ucs['tulaj_szov'],$ucs['bolygo_id'],0,$ucs['feladat_domen'],$ucs['feladat_id'],$eroforras_mennyisege[0],0,1);
					}
				}
			}
		break;
	}
}
if (sizeof($kemriportok)>0) {
	//mysql_select_db($database_mmog_nemlog);
	foreach($kemriportok as $kemriport) {
        $kr = $ls->pdo->prepare('insert into kemriportok (tulaj,tulaj_szov,bolygo_id,user_id,mikor,feladat_domen,feladat_id,darab,aktiv_darab,pontos) values(?,?,?,?,?,?,?,?,?,?)');
        $kr->execute(array($kemriport[0], $kemriport[1], $kemriport[2], $kemriport[3], $most, $kemriport[4], $kemriport[5], $kemriport[6], $kemriport[7], $kemriport[8]));
	}

}



$egy_nap_mulva=date('Y-m-d H:i:s',time()+3600*24);
$egy_hettel_ezelott=date('Y-m-d H:i:s',time()-3600*24*7);
//szabotorok

	$szr = $gs->pdo->prepare('select b.id as bolygo_id,ucs.tulaj,ucs.feladat_domen,ucs.feladat_id,coalesce(sum(ucs.darab),0) as ossz_darab,coalesce(sum(ucs.shy_most),0) as ossz_shy_most,b.nev as bolygo_nev,b.tulaj as bolygo_tulaj,b.vedelmi_bonusz,u.uccso_szabotazs_mikor,if(ut.karrier=3 and ut.speci=3,1,0) as fantom_tamado,ut.helyezes
from ugynokcsoportok ucs, bolygok b, userek u, userek ut
where ucs.bolygo_id=b.id and b.bolygo_id_mod=? and ucs.statusz=4 and b.tulaj=u.id and ucs.tulaj=ut.id
group by b.id,ucs.tulaj,ucs.feladat_domen,ucs.feladat_id');
	$szr->execute(array($perc));
	foreach($szr->fetchAll(PDO::FETCH_BOTH) as $ucs) if ($ucs['ossz_darab']>0) if ($ucs['ossz_shy_most']>0) /*if ($ucs['vedelmi_bonusz']<800)*/ {
	$shy_per_fo=$ucs['ossz_shy_most']/$ucs['ossz_darab'];
	switch($ucs['feladat_domen']) {
		case 1://gyar inaktivalas 1 napra
			$szr01 = $gs->pdo->prepare('select * from gyartipusok where id=?');
			$szr01->execute(array($ucs['feladat_id']));
			$szr01 = $szr01->fetch(PDO::FETCH_BOTH);
			$gyartipus = $szr01[0];

			//ar kiszamitasa
			//$ar=$gyartipus['pontertek']/1000/10;//1 pont = 1000 shy
			$ar=$gyartipus['szabotazs_ara'];
			//
			$valseg=$shy_per_fo/$ar;if ($valseg>1) $valseg=1;
			$n=$valseg*$ucs['ossz_darab'];
			if ($n<1) {
				if (mt_rand(0,99)<100*$n) $n=1;else $n=0;
			}
			if ($n>0) {
				$n=round($n);
				//vedelmi bonusz alapjan korlat (figyelembe veve a user.uccso_szabotazs_mikor-t is)
				$szr02 = $gs->pdo->prepare('select coalesce(sum(bgy.db),0) from bolygo_gyar bgy, gyarak gy where bgy.bolygo_id=? and bgy.gyar_id=gy.id and gy.tipus=?');
				$szr02->execute(array($ucs['bolygo_id'], $gyartipus['id']));
				$szr02 = $szr02->fetch(PDO::FETCH_BOTH);
				$letezo = $szr02[0];

				$szr03 = $gs->pdo->prepare('select coalesce(sum(ct.darab),0) from cron_tabla ct, gyarak gy where ct.bolygo_id=? and ct.gyar_id=gy.id and gy.tipus=?');
				$szr03->execute(array($ucs['bolygo_id'], $gyartipus['id']));
				$szr03 = $szr03->fetch(PDO::FETCH_BOTH);
				$epulo = $szr03[0];

				if ($ucs['uccso_szabotazs_mikor']>$egy_hettel_ezelott) $max_n=$letezo+$epulo;
				else {
					if ($ucs['vedelmi_bonusz']<200) $max_n=$letezo+$epulo;
					elseif ($ucs['vedelmi_bonusz']<400) $max_n=round(0.80*($letezo+$epulo));
					elseif ($ucs['vedelmi_bonusz']<600) $max_n=round(0.60*($letezo+$epulo));
					elseif ($ucs['vedelmi_bonusz']<800) $max_n=round(0.40*($letezo+$epulo));
					else $max_n=0;
				}
				if ($n>$max_n) $n=$max_n;
				if ($n>0) {
					//fantom tamado egy bolygoja lebukik
					$forras_info='';$forras_info_en='';
					if ($fantom_lebukas) if ($ucs['fantom_tamado']) {
						$szr04 = $gs->pdo->prepare('select * from bolygok where tulaj='.$ucs['tulaj'].' order by rand() limit 1');
						$szr04->execute(array($ucs['tulaj']));
						$random_bolygo = $szr04->fetch(PDO::FETCH_BOTH);
						if ($random_bolygo) list($forras_info,$forras_info_en)=fantom_bolygo_uzenet($random_bolygo,$ucs);
					}
					//n gyarat inaktivalni 24 orara
					$szr05 = $gs->pdo->prepare('insert into bolygo_gyartipus_szabotazs (bolygo_id,tipus,db,meddig) values(?,?,?,?)');
					$szr05->execute(array($ucs['bolygo_id'], $gyartipus['id'], $n, $egy_nap_mulva));
					//ertesitesek



					$szimClass->systemMessage($ucs['tulaj']
						,'Sikeres szabotázs',$ucs['bolygo_nev'].' bolygón sikeresen szabotáltál '.$n.' '.$gyartipus['nev'].'-t.'
						,'Successful sabotage','You have sabotaged '.$n.' '.$gyartipus['nev_en'].' on planet '.$ucs['bolygo_nev'].'.'
					);
					$szimClass->systemMessage($ucs['bolygo_tulaj']
						,'Ellenséges szabotázs',$ucs['bolygo_nev'].' bolygódon szabotáltak '.$n.' '.$gyartipus['nev'].'-t.'.$forras_info
						,'Hostile sabotage',$n.' '.$gyartipus['nev_en'].' have been sabotaged on your planet '.$ucs['bolygo_nev'].'.'.$forras_info_en
					);

					//uccso_szabotazs_mikor frissitese
					$szr06 = $gs->pdo->prepare('update userek set uccso_szabotazs_mikor=? where id=?');
					$szr06->execute(array($most, $ucs['tulaj']));
				}
			}
		break;
		case 2://gyar robbantas
		break;
	}
}
//szabotalt gyarakat inaktivalni
	$szgyi = $gs->pdo->prepare('select bgytsz.bolygo_id,bgytsz.tipus,max(bgytsz.db) as szabotalt,sum(bgy.db) as osszesen,sum(bgy.aktiv_db) as aktiv
,min(gy.id) as min_gyar_id
,max(gy.id) as max_gyar_id
from bolygo_gyar bgy, gyarak gy, bolygo_gyartipus_szabotazs bgytsz
where bgy.bolygo_id=bgytsz.bolygo_id
and bgy.gyar_id=gy.id
and gy.tipus=bgytsz.tipus
group by bgytsz.bolygo_id,bgytsz.tipus
having osszesen<szabotalt+aktiv');
	$szgyi->execute();
	foreach($szgyi->fetchAll(PDO::FETCH_BOTH) as $bgy) {
	$inaktivalni_kell=$bgy['szabotalt']+$bgy['aktiv']-$bgy['osszesen'];
	if ($bgy['min_gyar_id']!=$bgy['max_gyar_id']) {
		$szgyi02 = $gs->pdo->prepare('select bgy.* from bolygo_gyar bgy, gyarak gy where bgy.bolygo_id=? and bgy.gyar_id=gy.id and gy.tipus=? order by bgy.gyar_id');
		$szgyi02->execute(array($bgy['bolygo_id'], $bgy['tipus']));
		while(($inaktivalni_kell>0) and ($aux = $szgyi02->fetchAll(PDO::FETCH_BOTH))) {
			$most_inaktivalni_kell=$inaktivalni_kell;
			if ($most_inaktivalni_kell>$aux['aktiv_db']) $most_inaktivalni_kell=$aux['aktiv_db'];
			$szgyi03 = $gs->pdo->prepare('update bolygo_gyar set aktiv_db=if(aktiv_db>?,aktiv_db-?,0) where bolygo_id=? and gyar_id=?');
			$szgyi03->execute(array($most_inaktivalni_kell, $most_inaktivalni_kell, $bgy['bolygo_id'], $aux['gyar_id']));

			$inaktivalni_kell-=$most_inaktivalni_kell;
		}
	} else {
		$szgyi04 = $gs->pdo->prepare('update bolygo_gyar set aktiv_db=if(aktiv_db>?,aktiv_db-?,0) where bolygo_id=? and gyar_id=?');
		$szgyi04->execute(array($inaktivalni_kell, $inaktivalni_kell, $bgy['bolygo_id'], $bgy['max_gyar_id']));
	}
		$szimClass->planetFactoryResources($bgy['bolygo_id']);
}
//lejart szabotazsokat torolni
	$gs->pdo->prepare('delete from bolygo_gyartipus_szabotazs where meddig<now()')->execute();


//hanyszor-- es ha vege, statusz=0 meg a tobbit is kinullazni
	$hsz = $gs->pdo->prepare('update ugynokcsoportok ucs, bolygok b
set ucs.statusz=if(ucs.hanyszor=1,0,ucs.statusz)
,ucs.hanyszor=if(ucs.hanyszor>0,ucs.hanyszor-1,0)
,ucs.shy_per_akcio=if(ucs.hanyszor=1,0,ucs.shy_per_akcio)
,ucs.feladat_domen=if(ucs.hanyszor=1,0,ucs.feladat_domen)
,ucs.feladat_id=if(ucs.hanyszor=1,0,ucs.feladat_id)
where ucs.bolygo_id=b.id and b.bolygo_id_mod=? and ucs.statusz!=0');
	$hsz->execute(array($perc));

//ugynokok ujraosszegzese
	$uu = $gs->pdo->prepare('update userek u, (
select tulaj,sum(darab) as fo from ugynokcsoportok group by tulaj
) ugynoktabla
set u.ugynokok_szama=ugynoktabla.fo
where u.id=ugynoktabla.tulaj')->execute();

	$gs->pdo->prepare('update ido set idopont_felderites=idopont_felderites+1')->execute();
$szimlog_hossz_felderites=round(1000*(microtime(true)-$mikor_indul));




/******************************************************** FLOTTAK ELEJE ******************************************************************/

//tech 5 alatti flottak nem tamadhatnak jatekos bolygot

	$r160 = $gs->pdo->prepare('update flottak f, userek u, bolygok b
set f.statusz=2,f.cel_bolygo=0
where f.tulaj=u.id
and f.cel_bolygo=b.id
and f.statusz in (7,8,9,10)
and b.tulaj>0
and u.techszint<5');
	$r160->execute();


//define('STATUSZ_ALLOMAS',1);
	$r159 = $gs->pdo->prepare('
update flottak f
inner join bolygok b on f.bolygo=b.id
left join diplomacia_statuszok dsz on dsz.ki=b.tulaj_szov and dsz.kivel=f.tulaj_szov
set f.statusz=2
where f.statusz=1
and b.tulaj_szov!=f.tulaj_szov
and coalesce(dsz.mi,0)!=2
');
	$r159->execute();

	$r158 = $gs->pdo->prepare('
update flottak f, bolygok b
set f.x=b.x, f.y=b.y
where f.bolygo=b.id and f.statusz=1
'); $r158->execute();

//define('STATUSZ_ALL',2);
	$r157 = $gs->pdo->prepare('
update flottak f
inner join bolygok b on f.x=b.x and f.y=b.y
left join diplomacia_statuszok dsz on dsz.ki=b.tulaj_szov and dsz.kivel=f.tulaj_szov
set f.statusz=1, f.bolygo=b.id
where f.statusz=2
and (b.tulaj_szov=f.tulaj_szov or coalesce(dsz.mi,0)=2)
'); $r157->execute();



//tech 5 alatti flottak csak sajat bolygo felett allomasozhatnak (kulonben a csatatiltas miatt vedeni tudnanak mas bolygokat)
	$r156 = $gs->pdo->prepare('update flottak f, userek u, bolygok b
set f.statusz=2,f.bolygo=0
where f.tulaj=u.id
and f.bolygo=b.id
and f.statusz=1
and b.tulaj!=f.tulaj
and u.techszint<5');
	$r156->execute();


//define('STATUSZ_PATROL_1',3);
	$r155 = $gs->pdo->prepare('
update flottak
set
statusz=if(pow(cel_x-x,2)+pow(cel_y-y,2)<=pow(sebesseg,2),4,statusz),
x=if(pow(cel_x-x,2)+pow(cel_y-y,2)<=pow(sebesseg,2),cel_x,round(x+(cel_x-x)/sqrt(pow(cel_x-x,2)+pow(cel_y-y,2))*sebesseg)),
y=if(pow(cel_x-x,2)+pow(cel_y-y,2)<=pow(sebesseg,2),cel_y,round(y+(cel_y-y)/sqrt(pow(cel_x-x,2)+pow(cel_y-y,2))*sebesseg))
where statusz=3 and elkerules=0
'); $r155->execute();
//define('STATUSZ_PATROL_2',4);
	$r154 = $gs->pdo->prepare('
update flottak
set
statusz=if(pow(bazis_x-x,2)+pow(bazis_y-y,2)<=pow(sebesseg,2),3,statusz),
x=if(pow(bazis_x-x,2)+pow(bazis_y-y,2)<=pow(sebesseg,2),bazis_x,round(x+(bazis_x-x)/sqrt(pow(bazis_x-x,2)+pow(bazis_y-y,2))*sebesseg)),
y=if(pow(bazis_x-x,2)+pow(bazis_y-y,2)<=pow(sebesseg,2),bazis_y,round(y+(bazis_y-y)/sqrt(pow(bazis_x-x,2)+pow(bazis_y-y,2))*sebesseg))
where statusz=4 and elkerules=0
'); $r154->execute();

//define('STATUSZ_MEGY_XY',5);
	$r153 = $gs->pdo->prepare('
update flottak
set
statusz=if(pow(cel_x-x,2)+pow(cel_y-y,2)<=pow(sebesseg,2),2,statusz),
x=if(pow(cel_x-x,2)+pow(cel_y-y,2)<=pow(sebesseg,2),cel_x,round(x+(cel_x-x)/sqrt(pow(cel_x-x,2)+pow(cel_y-y,2))*sebesseg)),
y=if(pow(cel_x-x,2)+pow(cel_y-y,2)<=pow(sebesseg,2),cel_y,round(y+(cel_y-y)/sqrt(pow(cel_x-x,2)+pow(cel_y-y,2))*sebesseg))
where statusz=5 and elkerules=0
'); $r153->execute();

//define('STATUSZ_VISSZA',11);
	$r152 = $gs->pdo->prepare('update flottak set statusz=2 where bazis_bolygo=0 and statusz=11');
	$r152->execute();
	$r151 = $gs->pdo->prepare('
update flottak f,bolygok b
set
f.statusz=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),1,f.statusz),
f.bolygo=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),f.bazis_bolygo,0),
f.x=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.x,round(f.x+(b.x-f.x)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg)),
f.y=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.y,round(f.y+(b.y-f.y)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg))
where f.bazis_bolygo=b.id and f.statusz=11 and f.elkerules=0
'); $r151->execute();

//define('STATUSZ_MEGY_BOLYGO',6);
//ha idegen bolygo ellen nem tamadsz, hanem mesz, akkor atrak megy_xy-ra
	$r150 = $gs->pdo->prepare('
update flottak f
inner join bolygok b on f.cel_bolygo=b.id
left join diplomacia_statuszok dsz on dsz.ki=b.tulaj_szov and dsz.kivel=f.tulaj_szov
set
f.statusz=5
f.cel_x=b.x,
f.cel_y=b.y
where f.statusz=6
and b.tulaj_szov!=f.tulaj_szov
and coalesce(dsz.mi,0)!=2
'); $r150->execute();


//ha sajat vagy szovitars vagy testverszovi bolygora megy, akkor allomasozik a celba eresnel
	$r149 = $gs->pdo->prepare('
update flottak f
inner join bolygok b on f.cel_bolygo=b.id
left join diplomacia_statuszok dsz on dsz.ki=b.tulaj_szov and dsz.kivel=f.tulaj_szov
set
f.statusz=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),1,f.statusz),
f.bolygo=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),f.cel_bolygo,0),
f.x=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.x,round(f.x+(b.x-f.x)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg)),
f.y=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.y,round(f.y+(b.y-f.y)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg))
where f.statusz=6
and (b.tulaj_szov=f.tulaj_szov or coalesce(dsz.mi,0)=2)
and f.elkerules=0
');
	$r149->execute();
//define('STATUSZ_TAMAD_BOLYGORA',7);
	$r148 = $gs->pdo->prepare('
update flottak f,bolygok b
set
f.statusz=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),8,f.statusz),
f.x=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.x,round(f.x+(b.x-f.x)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg)),
f.y=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.y,round(f.y+(b.y-f.y)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg))
where f.cel_bolygo=b.id and f.statusz=7 and f.elkerules=0
');
	$r148->execute();

//define('STATUSZ_RAID_BOLYGORA',9);
	$r147 = $gs->pdo->prepare('
update flottak f,bolygok b
set
f.statusz=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),10,f.statusz),
f.x=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.x,round(f.x+(b.x-f.x)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg)),
f.y=if(pow(b.x-f.x,2)+pow(b.y-f.y,2)<=pow(f.sebesseg,2),b.y,round(f.y+(b.y-f.y)/sqrt(pow(b.x-f.x,2)+pow(b.y-f.y,2))*f.sebesseg))
where f.cel_bolygo=b.id and f.statusz=9 and f.elkerules=0
');
	$r147->execute();


//cel_flotta kolcsonosseg
	$r146 = $gs->pdo->prepare('update flottak f1, flottak f2
set
f1.x=round((f1.sebesseg*f2.x+f2.sebesseg*f1.x)/(f1.sebesseg+f2.sebesseg))
,f1.y=round((f1.sebesseg*f2.y+f2.sebesseg*f1.y)/(f1.sebesseg+f2.sebesseg))
,f2.x=round((f1.sebesseg*f2.x+f2.sebesseg*f1.x)/(f1.sebesseg+f2.sebesseg))
,f2.y=round((f1.sebesseg*f2.y+f2.sebesseg*f1.y)/(f1.sebesseg+f2.sebesseg))
where f1.cel_flotta=f2.id and f2.cel_flotta=f1.id
and f1.id!=f2.id
and f1.statusz in (12,13,14) and f2.statusz in (12,13,14)
and pow(f2.x-f1.x,2)+pow(f2.y-f1.y,2)<=pow(f1.sebesseg+f2.sebesseg,2)');
	$r146->execute();


//define('STATUSZ_MEGY_FLOTTAHOZ',12);
	$r145 = $gs->pdo->prepare('
update flottak f,flottak f2
set
f.statusz=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),2,f.statusz),
f.x=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f2.x,round(f.x+(f2.x-f.x)/sqrt(pow(f2.x-f.x,2)+pow(f2.y-f.y,2))*f.sebesseg)),
f.y=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f2.y,round(f.y+(f2.y-f.y)/sqrt(pow(f2.x-f.x,2)+pow(f2.y-f.y,2))*f.sebesseg))
where f.cel_flotta=f2.id and f.statusz=12 and f.elkerules=0
');
	$r145->execute();


//define('STATUSZ_TAMAD_FLOTTARA',13);
	$r144 = $gs->pdo->prepare('
update flottak f,flottak f2
set
f.statusz=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),14,f.statusz),
f.x=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f2.x,round(f.x+(f2.x-f.x)/sqrt(pow(f2.x-f.x,2)+pow(f2.y-f.y,2))*f.sebesseg)),
f.y=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f2.y,round(f.y+(f2.y-f.y)/sqrt(pow(f2.x-f.x,2)+pow(f2.y-f.y,2))*f.sebesseg))
where f.cel_flotta=f2.id and f.statusz=13 and f.elkerules=0
');
	$r144->execute();

//define('STATUSZ_TAMAD_BOLYGOT',8);
//define('STATUSZ_RAID_BOLYGOT',10);

//define('STATUSZ_TAMAD_FLOTTAT',14);
	$r143 = $gs->pdo->prepare('
update flottak f,flottak f2
set
f.statusz=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f.statusz,13),
f.x=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f2.x,round(f.x+(f2.x-f.x)/sqrt(pow(f2.x-f.x,2)+pow(f2.y-f.y,2))*f.sebesseg)),
f.y=if(pow(f2.x-f.x,2)+pow(f2.y-f.y,2)<=pow(f.sebesseg,2),f2.y,round(f.y+(f2.y-f.y)/sqrt(pow(f2.x-f.x,2)+pow(f2.y-f.y,2))*f.sebesseg))
where f.cel_flotta=f2.id and f.statusz=14
'); $r143->execute();

//sajat, szovi, testverszovi es mnt elleni tamadasok leallitasa
	$r142 = $gs->pdo->prepare('update flottak f
inner join bolygok b on f.cel_bolygo=b.id and f.statusz in (7,8,9,10)
left join diplomacia_statuszok dsz on f.tulaj_szov=dsz.ki and b.tulaj_szov=dsz.kivel
set f.statusz=6
where f.tulaj_szov=b.tulaj_szov or coalesce(dsz.mi,0) in (2,3)');
	$r142->execute();

	$r141 = $gs->pdo->prepare('update flottak f
inner join flottak f2 on f.cel_flotta=f2.id and f.statusz in (13,14)
left join diplomacia_statuszok dsz on f.tulaj_szov=dsz.ki and f2.tulaj_szov=dsz.kivel
set f.statusz=12
where f.tulaj_szov=f2.tulaj_szov or coalesce(dsz.mi,0) in (2,3)');
			$r141->execute();




//feregjaratok

/*mysql_query('update feregjaratok fj, flottak f
set f.x=round(fj.cel_x+200*rand()-100),f.y=round(fj.cel_y+200*rand()-100)
where fj.forras_x=f.x and fj.forras_y=f.y and f.statusz='.STATUSZ_ALL);
*/



//csak paros koordinatak lehetnek!!!
	$r140 = $gs->pdo->prepare('update flottak set x=round(x/2)*2, y=round(y/2)*2');
	$r140->execute();


//hexak meghatarozasa, utana annak fuggvenyeben moralvaltozas (lasd lentebb)
//left join, hogy a nagy hexakoron kivuli flottaknak 0 legyen a hexa_voronoi_bolygo_id-ja (es ne megmaradjon a korabbi)
	$r139 = $gs->pdo->prepare('update flottak f
inner join (select id,x,y,
@hx:=floor(x/217),
@hy:=floor(y/125),
@hatarparitas:=(abs(@hx%2)+abs(@hy%2))%2,
@xmar:=x-217*@hx,
@ymar:=y-125*@hy,
@m:=if(@hatarparitas=0,-1,1)*217/125,
@N:=if(@ymar-125/2>@m*(@xmar-217/2),1,0),
@hx+if(@hatarparitas=1,1-@N,@N) as hexa_x,
if(@hy%2=0,round(@hy/2)+@N,round((@hy+1)/2)) as hexa_y
from flottak) t on f.id=t.id
left join hexak h on t.hexa_x=h.x and t.hexa_y=h.y
set f.hexa_x=t.hexa_x, f.hexa_y=t.hexa_y, f.hexa_voronoi_bolygo_id=coalesce(h.voronoi_bolygo_id,0)');
	$r139->execute();


//sajat_teruleten,idegen_teruleten
	$r138 = $gs->pdo->prepare('update flottak f
left join bolygok b on b.id=f.hexa_voronoi_bolygo_id
set f.sajat_teruleten=if(b.tulaj is not null and f.tulaj=b.tulaj,1,0)
,f.idegen_teruleten=if(b.tulaj is not null,if(f.tulaj!=b.tulaj and b.tulaj>0,1,0),1)');
	$r138->execute();


	$gs->pdo->prepare('update ido set idopont_flottak=idopont_flottak+1')->execute();
$szimlog_hossz_flottak=round(1000*(microtime(true)-$mikor_indul));
/******************************************************** FLOTTAK VEGE ******************************************************************/


/******************************************************** FLOTTAMORAL ELEJE ******************************************************************/
//kocsmaszazalek kiszamitasa: 0..100 kozott
	$r137 = $gs->pdo->prepare('
update bolygok b,(
	select b.id,if(be.db>0,if(sum(fh.ossz_hp*h.ar)>10000*be.db,round(be.db/sum(fh.ossz_hp*h.ar)*1000000),100),0) as kocsmasz
	from flotta_hajo fh, hajok h, flottak f, bolygok b, bolygo_eroforras be
	where fh.flotta_id=f.id and fh.hajo_id=h.id and f.bolygo=b.id and f.bolygo=be.bolygo_id and be.eroforras_id=75 and f.statusz=1 and fh.hajo_id!=206
	group by f.bolygo
) t
set b.kocsmaszazalek=t.kocsmasz
where b.id=t.id
');
	$r137->execute();

//100%-os kocsmaszazaleknal percenkent +1%, vagyis kb masfel ora alatt lehet fullosra tolteni egy flottat (10-szer gyorsabban toltodik, mint fogy)
	$r136 = $gs->pdo->prepare('
update flotta_hajo fh,(
select fh.flotta_id, fh.hajo_id, b.kocsmaszazalek
from flotta_hajo fh, flottak f, bolygok b
where fh.flotta_id=f.id and f.bolygo=b.id and f.statusz=1 and fh.hajo_id!=206
group by fh.flotta_id, fh.hajo_id
) t
set fh.moral=least(fh.moral+t.kocsmaszazalek,10000)
where fh.flotta_id=t.flotta_id and fh.hajo_id=t.hajo_id
');
	$r136->execute();

//percenkent -0,1%, kiveve allomasozas, szonda, fekete (nem piros) terulet (sajat, szovi, testver, mnt, npc), npc, Zanda, kalózok, Zharg'al, olyan hexa, amihez nincs bolygo (inner join)
//es kiveve, ha a hexa tulaja fantom (mert ekkor nem is piros)
//ha a flottanak jar a fejvadasz bonusz (f.fejvadasz_bonusz=1), akkor csak haboru eseten van csokkenes
//ha a bolygo bekebiroe, akkor a moralcsokkenes 3-szoros
	$r135 = $gs->pdo->prepare('
update flotta_hajo fh
inner join flottak f on fh.flotta_id=f.id
inner join bolygok b on b.id=f.hexa_voronoi_bolygo_id
left join userek u on u.id=b.tulaj
left join diplomacia_statuszok dsz on dsz.ki=f.tulaj_szov and dsz.kivel=b.tulaj_szov
set fh.moral=greatest(fh.moral-if(coalesce(u.karrier,0)=4 and coalesce(u.speci,0)=1,30,10),0)
where f.statusz!=1
and fh.hajo_id!=206
and b.tulaj_szov!=f.tulaj_szov
and coalesce(dsz.mi,0)!=2
and coalesce(dsz.mi,0)!=3
and b.tulaj!=0
and b.letezik=1
and (coalesce(u.karrier,0)!=3 or coalesce(u.speci,0)!=3)
and (f.fejvadasz_bonusz=0 or coalesce(dsz.mi,0)=1)
and f.tulaj!=0 and f.tulaj!=-1 and f.tulaj_szov!=-1 and f.tulaj!=-1
');
	$r135->execute();
//barmi van, 0 es 100% kozott maradjon
	$r134 = $gs->pdo->prepare('update flotta_hajo set moral=greatest(least(moral,10000),0)');
	$r134->execute();


//csak szondas es nulla moralos flottak ne tamadjanak/portyazzanak (egyszerubb itt atirni, mint az ostromnal, ahol tobbszor is le vannak valogatva az erintett flottak
	$r133 = $gs->pdo->prepare('update flottak f,(select f.id,coalesce(round(sum(fh.ossz_hp*fh.moral)/sum(fh.ossz_hp)),0) as moral_szonda_nelkul
from flottak f, flotta_hajo fh, hajok h
where f.id=fh.flotta_id and fh.hajo_id=h.id
and h.id!=206
and h.id!=212
and h.id!=218
group by f.id) t
set f.moral_szonda_nelkul=t.moral_szonda_nelkul
where f.id=t.id');
	$r133->execute();

	$r132 = $gs->pdo->prepare('update flottak f
set f.statusz=1
where (f.statusz=8 or f.statusz=10) and f.moral_szonda_nelkul=0');
	$r132->execute();

	$gs->pdo->prepare('update ido set idopont_flottamoral=idopont_flottamoral+1')->execute();
$szimlog_hossz_flottamoral=round(1000*(microtime(true)-$mikor_indul));
/******************************************************** FLOTTAMORAL VEGE ******************************************************************/



/******************************************************** CSATAK ELEJE ******************************************************************/

//0. flotta tp

	$r131 = $gs->pdo->prepare('update flottak f, userek u set f.tp=u.tp where f.uccso_parancs_by=u.id');
	$r131->execute();
	$r130 = $gs->pdo->prepare('update flottak f, userek u set f.tp=greatest(f.tp,u.tp) where f.tulaj=u.id');
	$r130->execute();
	$r129 = $gs->pdo->prepare('update flottak f, resz_flotta_aux rfa, userek u set f.tp=greatest(f.tp,u.tp) where f.id=rfa.flotta_id and rfa.user_id=u.id');
	$r129->execute();

//1. csatak
	$r128 = $gs->pdo->prepare('delete from csatak');
	$r128->execute();
	$r127 = $gs->pdo->prepare('insert into csatak (x,y,zanda)
select x,y,if(sum(tulaj=-1)>0,1,0)
from flottak
group by x,y
having count(distinct tulaj_szov)>1');
	$r127->execute();


//2. csata_flotta
	$r126 = $gs->pdo->prepare('truncate csata_flotta');
	$r126->execute();
	$r125 = $gs->pdo->prepare('insert into csata_flotta (csata_id,flotta_id,tulaj,tulaj_szov,tulaj_nev,tulaj_szov_nev,nev,kozos,iranyito,iranyito_nev,tp,iranyito_karrier,iranyito_speci,iranyito_rang,kezdo)
select cs.id,f.id,f.tulaj,f.tulaj_szov,if(f.tulaj=-1,"Zandagort",coalesce(u.nev,"")),coalesce(sz.nev,""),f.nev,f.kozos,f.uccso_parancs_by,coalesce(ui.nev,""),f.tp,ui.karrier,ui.speci,ui.rang,u.techszint<5
from csatak cs
inner join flottak f on cs.x=f.x and cs.y=f.y
left join userek u on f.tulaj=u.id
left join szovetsegek sz on f.tulaj_szov=sz.id
left join userek ui on f.uccso_parancs_by=ui.id');
	$r125->execute();


//2,5. TE/VE-frissites, egyenertek-szamitas
	$fr = $gs->pdo->prepare('select flotta_id from csata_flotta');
	$fr->execute();
	foreach($fr->fetchAll(PDO::FETCH_BOTH) as $aux){
		// flotta_frissites
		$szimClass->refreshFleet($aux[0]);
	};
	$r124 = $gs->pdo->prepare('update csata_flotta csf, flottak f
set csf.egyenertek_elotte=f.egyenertek
where csf.flotta_id=f.id');
			$r124->execute();
	$r123 = $gs->pdo->prepare('update csatak cs,(select csata_id,sum(egyenertek_elotte) as ossz from csata_flotta group by csata_id) t
set cs.resztvett_egyenertek=t.ossz
where cs.id=t.csata_id');
	$r123->execute();

//3. csata_flottamatrix
$r122 = $gs->pdo->prepare('truncate csata_flottamatrix');
		$r122->execute();

//kozvetlen tamadasok
	$r121 = $gs->pdo->prepare('insert into csata_flottamatrix (csata_id,egyik_flotta_id,masik_flotta_id)
select csf1.csata_id,f1.id,f2.id
from csata_flotta csf1
inner join csata_flotta csf2 on csf2.csata_id=csf1.csata_id
inner join flottak f1 on f1.id=csf1.flotta_id
inner join flottak f2 on f2.id=csf2.flotta_id
left join diplomacia_statuszok dsz on dsz.ki=f1.tulaj_szov and dsz.kivel=f2.tulaj_szov
where
greatest(
if(f1.statusz=14 and f1.cel_flotta=f2.id,1,if(f2.statusz=14 and f2.cel_flotta=f1.id,1,0)),
if(f1.statusz in (8,10) and f2.statusz=1 and f1.cel_bolygo=f2.bolygo,1,if(f2.statusz in (8,10) and f1.statusz=2 and f2.cel_bolygo=f1.bolygo,1,0)),
if(coalesce(dsz.mi=1,0),1,0)
)=1');
	$r121->execute();

//elso koros tarsbepakolas
	$r120 = $gs->pdo->prepare('insert ignore into csata_flottamatrix (csata_id,egyik_flotta_id,masik_flotta_id)
select csfm.csata_id,csfm.masik_flotta_id,fu.id
from csata_flottamatrix csfm
inner join flottak fr on fr.id=csfm.egyik_flotta_id
inner join flottak frm on frm.id=csfm.masik_flotta_id
inner join csata_flotta csfu on csfu.csata_id=csfm.csata_id
inner join flottak fu on fu.id=csfu.flotta_id and fu.id!=csfm.egyik_flotta_id and fu.id!=csfm.masik_flotta_id
left join diplomacia_statuszok dsz on dsz.ki=fr.tulaj_szov and dsz.kivel=fu.tulaj_szov
left join diplomacia_statuszok dsz2 on dsz2.ki=frm.tulaj_szov and dsz2.kivel=fu.tulaj_szov
where (fr.tulaj_szov=fu.tulaj_szov or coalesce(dsz.mi,0)=2) and (coalesce(dsz2.mi,0)!=3)');
	$r120->execute();

//ez kell, hogy a masodik koros bepakolas lefedjen minden masodrendu esetet
	$r119= $gs->pdo->prepare('insert ignore into csata_flottamatrix (csata_id,egyik_flotta_id,masik_flotta_id)
select csata_id,masik_flotta_id,egyik_flotta_id
from csata_flottamatrix');
	$r119->execute();

//masodik koros tarsbepakolas (elvileg vegtelen kor kene)
	$r118 = $gs->pdo->prepare('insert ignore into csata_flottamatrix (csata_id,egyik_flotta_id,masik_flotta_id)
select csfm.csata_id,csfm.masik_flotta_id,fu.id
from csata_flottamatrix csfm
inner join flottak fr on fr.id=csfm.egyik_flotta_id
inner join flottak frm on frm.id=csfm.masik_flotta_id
inner join csata_flotta csfu on csfu.csata_id=csfm.csata_id
inner join flottak fu on fu.id=csfu.flotta_id and fu.id!=csfm.egyik_flotta_id and fu.id!=csfm.masik_flotta_id
left join diplomacia_statuszok dsz on dsz.ki=fr.tulaj_szov and dsz.kivel=fu.tulaj_szov
left join diplomacia_statuszok dsz2 on dsz2.ki=frm.tulaj_szov and dsz2.kivel=fu.tulaj_szov
where (fr.tulaj_szov=fu.tulaj_szov or coalesce(dsz.mi,0)=2) and (coalesce(dsz2.mi,0)!=3)');
	$r118->execute();

//hogy szimmetrikus (redundans) matrix legyen
	$r117 = $gs->pdo->prepare('insert ignore into csata_flottamatrix (csata_id,egyik_flotta_id,masik_flotta_id)
select csata_id,masik_flotta_id,egyik_flotta_id
from csata_flottamatrix');
	$r117->execute();

//tech 5 alattiak csak npc-vel csatazhatnak (kiveve mas jatekos teruleten)
	$r116 = $gs->pdo->prepare('delete csfm from
csata_flottamatrix csfm, csata_flotta csf1, csata_flotta csf2, userek u1, userek u2, flottak f1, flottak f2
where csf1.csata_id=csfm.csata_id and csf1.flotta_id=csfm.egyik_flotta_id
and csf2.csata_id=csfm.csata_id and csf2.flotta_id=csfm.masik_flotta_id
and u1.id=csf1.tulaj
and u2.id=csf2.tulaj
and f1.id=csf1.flotta_id
and f2.id=csf2.flotta_id
and u1.id=f1.tulaj
and u2.id=f2.tulaj
and if(u1.techszint<5 and f1.sajat_teruleten=1,1,0)+if(u2.techszint<5 and f2.sajat_teruleten=1,1,0)>0');
	$r116->execute();

//ures csatakat kidobalni (ahol egy pozin eltero tulaj_szov van, de nincs koztuk osszecsapas
	$ucs = $gs->pdo->prepare('select cs.id
from csatak cs
left join csata_flottamatrix csfm on cs.id=csfm.csata_id
group by cs.id
having count(csfm.egyik_flotta_id)=0');
	$ucs->execute();
	foreach($ucs->fetchAll(PDO::FETCH_BOTH) as $aux){
		$r115 = $gs->pdo->prepare('delete from csatak where id=?');
				$r115->execute(array($aux[0]));
		$r114 = $gs->pdo->prepare('delete from csata_flotta where csata_id=?');
				$r114->execute(array($aux[0]));
	}

	$rq = $gs->pdo->prepare('select id from csatak');
	$rq->execute();
if ($rq->rowCount()) {

	$r113 = $gs->pdo->prepare('update flotta_hajo set effektiv_moral=moral');
	$r113->execute();

if ($vegjatek==1) {//effektiv_moral = 100%, ha Zanda is ott van
	$er = $gs->pdo->prepare('select csf.flotta_id from csatak cs, csata_flotta csf where cs.id=csf.csata_id and cs.zanda=1');
	$er->execute();
	foreach($er->fetchAll(PDO::FETCH_BOTH) as $aux){
		$r = $gs->pdo->prepare('update flotta_hajo set effektiv_moral=10000 where flotta_id=?');
		$r->execute(array($aux[0]));

	}
} elseif ($vegjatek==2) {//effektiv_moral = 100%, mindig
	$r112 = $gs->pdo->prepare('update flotta_hajo set effektiv_moral=10000');
	$r112->execute();
}


//4. csata_flotta_hajo
	$r110 = $gs->pdo->prepare('truncate csata_flotta_hajo');
	$r110->execute();
	$r109 = $gs->pdo->prepare('insert into csata_flotta_hajo (csata_id,flotta_id,hajo_id,ossz_hp_elotte)
select csf.csata_id,csf.flotta_id,fh.hajo_id,fh.ossz_hp
from csata_flotta csf, flotta_hajo fh
where csf.flotta_id=fh.flotta_id');
	$r109->execute();


//5. normalo_osszeg
	$r108 = $gs->pdo->prepare('update csata_flotta_hajo tamado_csfh,(
select tamado_csfh.csata_id,tamado_csfh.flotta_id,tamado_csfh.hajo_id,sum(vedo_fh.ossz_hp*vedo_fh.valodi_hp*hh.coef*hh.coef) as uj_normalo_osszeg
from csata_flotta_hajo tamado_csfh, flotta_hajo tamado_fh, csata_flotta_hajo vedo_csfh, flotta_hajo vedo_fh, hajo_hajo hh, csata_flottamatrix csfm
where csfm.csata_id=tamado_csfh.csata_id and csfm.csata_id=vedo_csfh.csata_id
and csfm.egyik_flotta_id=tamado_csfh.flotta_id and csfm.egyik_flotta_id=tamado_fh.flotta_id
and csfm.masik_flotta_id=vedo_csfh.flotta_id and csfm.masik_flotta_id=vedo_fh.flotta_id
and tamado_csfh.hajo_id=tamado_fh.hajo_id
and vedo_csfh.hajo_id=vedo_fh.hajo_id
and tamado_fh.hajo_id=hh.hajo_id and vedo_fh.hajo_id=hh.masik_hajo_id and hh.masik_hajo_id>0
group by tamado_csfh.csata_id,tamado_csfh.flotta_id,tamado_csfh.hajo_id
) t
set tamado_csfh.normalo_osszeg=t.uj_normalo_osszeg
where tamado_csfh.csata_id=t.csata_id and tamado_csfh.flotta_id=t.flotta_id and tamado_csfh.hajo_id=t.hajo_id');
	$r108->execute();


//6. sebzesek (azert kell az if(tamado_fh.ossz_hp>0,greatest(tamado_fh.ossz_hp,3)/100,0), hogy ne legyen vegtelen csata)

//csata_sebzesek
	$r107 = $gs->pdo->prepare('truncate csata_sebzesek');
	$r107->execute();
	$r106 = $gs->pdo->prepare('insert into csata_sebzesek
select vedo_csfh.csata_id
,tamado_csfh.flotta_id as tamado_flotta_id,tamado_csfh.hajo_id as tamado_hajo_id
,vedo_csfh.flotta_id as vedo_flotta_id,vedo_csfh.hajo_id as vedo_hajo_id
,round(sum(if(
tamado_csfh.normalo_osszeg=0
,0

,(
100
+ 10*least(tamado_fh.koordi_arany,10) + if(tamado_fh.hajo_id=224,2.5*least(tamado_fh.castor_arany,20),0)
+ case tamado_csf.iranyito_rang
	when 2 then 10
	when 3 then 30
	when 4 then 50
	else 0
end
+ if(tamado_csf.iranyito_karrier=2,10,0)
+ case tamado_csf.iranyito_speci
	when 2 then 50
	when 4 then if(vedo_csf.tulaj=-1,70,0)
	else 0
end
)/100

* greatest(
100
- 5*least(vedo_fh.ohs_arany,10) - if(vedo_fh.hajo_id=223,2.5*least(vedo_fh.pollux_arany,20),0)
- if(vedo_csf.iranyito_karrier=2,3,0)
- case vedo_csf.iranyito_speci
	when 1 then 7
	when 2 then 2
	when 4 then if(tamado_csf.tulaj=-1,7,0)
	else 0
end
,0)/100

* sqrt(tamado_fh.moral/10000)
* if(tamado_fh.ossz_hp>0,greatest(tamado_fh.ossz_hp,3)/100,0)
/tamado_csfh.normalo_osszeg*(vedo_fh.ossz_hp*vedo_fh.valodi_hp*hh.coef*hh.coef) *
hh.coef/10 * tamado_fh.tamado_ero / vedo_fh.valodi_hp * 100 * if(tamado_fh.hajo_id=222,if(rand()<0.1,1,0),1)
))) as sebzes
from csata_flotta tamado_csf, csata_flotta_hajo tamado_csfh, flotta_hajo tamado_fh, csata_flotta vedo_csf, csata_flotta_hajo vedo_csfh, flotta_hajo vedo_fh, hajo_hajo hh, csata_flottamatrix csfm
where csfm.csata_id=tamado_csfh.csata_id and csfm.csata_id=tamado_csf.csata_id
and csfm.csata_id=vedo_csfh.csata_id and csfm.csata_id=vedo_csf.csata_id
and csfm.egyik_flotta_id=tamado_csfh.flotta_id and csfm.egyik_flotta_id=tamado_csf.flotta_id and csfm.egyik_flotta_id=tamado_fh.flotta_id
and csfm.masik_flotta_id=vedo_csfh.flotta_id and csfm.masik_flotta_id=vedo_csf.flotta_id and csfm.masik_flotta_id=vedo_fh.flotta_id
and tamado_csfh.hajo_id=tamado_fh.hajo_id
and vedo_csfh.hajo_id=vedo_fh.hajo_id
and tamado_fh.hajo_id=hh.hajo_id and vedo_fh.hajo_id=hh.masik_hajo_id and hh.masik_hajo_id>0
group by vedo_csfh.csata_id
,tamado_csfh.flotta_id,tamado_csfh.hajo_id
,vedo_csfh.flotta_id,vedo_csfh.hajo_id');
	$r106->execute();

//tenyleges sebzesek
	$r105 = $gs->pdo->prepare('update csata_flotta_hajo vedo_csfh,(
select csata_id,vedo_flotta_id as flotta_id,vedo_hajo_id as hajo_id,sum(sebzes) as uj_serules
from csata_sebzesek
group by csata_id,vedo_flotta_id,vedo_hajo_id) t
set vedo_csfh.serules=t.uj_serules
where vedo_csfh.csata_id=t.csata_id and vedo_csfh.flotta_id=t.flotta_id and vedo_csfh.hajo_id=t.hajo_id');
	$r105->execute();

	$r104 = $gs->pdo->prepare('update flotta_hajo fh, csata_flotta_hajo csfh
set fh.ossz_hp=if(csfh.serules<0,fh.ossz_hp,if(csfh.serules>fh.ossz_hp,0,fh.ossz_hp-csfh.serules))
where fh.flotta_id=csfh.flotta_id and fh.hajo_id=csfh.hajo_id');
	$r104->execute();

	$r103 = $gs->pdo->prepare('update csata_flotta_hajo csfh, flotta_hajo fh
set csfh.ossz_hp_utana=fh.ossz_hp
where fh.flotta_id=csfh.flotta_id and fh.hajo_id=csfh.hajo_id');
	$r103->execute();



//csata_user tablat meg azelott tolteni, h torlodnenek a kilott flottak resztulajai
	$r100 = $gs->pdo->prepare('insert ignore into csata_user (csata_id,user_id,olvasott)
select csata_id,tulaj,0 from csata_flotta where tulaj!=0');
	$r100->execute();
	$r101 = $gs->pdo->prepare('insert ignore into csata_user (csata_id,user_id,olvasott)
select csata_id,iranyito,0 from csata_flotta where iranyito!=0');
	$r101->execute();
	$r102 = $gs->pdo->prepare('insert ignore into csata_user (csata_id,user_id,olvasott)
select distinct csf.csata_id,rfh.user_id,0 from csata_flotta csf, resz_flotta_hajo rfh where csf.flotta_id=rfh.flotta_id');
	$r102->execute();



//7. TE/VE-frissites, egyenertek-szamitas
	$er = $gs->pdo->prepare('select flotta_id from csata_flotta');
	$er->execute();
foreach($er->fetchAll(PDO::FETCH_BOTH) as $aux){
	$szimClass->refreshFleet($aux[0]);
	$szimClass->otherFleetsRefresh($aux[0]);
}
	$gs->pdo->prepare('update csata_flotta csf, flottak f
set csf.egyenertek_utana=f.egyenertek
where csf.flotta_id=f.id')->execute();
	$gs->pdo->prepare('update csatak cs,(select csata_id,sum(egyenertek_elotte)-sum(egyenertek_utana) as ossz from csata_flotta group by csata_id) t
set cs.megsemmisult_egyenertek=t.ossz
where cs.id=t.csata_id')->execute();


$database = 'zandagort';
//9. historizalas, ebbol lesznek a csatajelentesek
	$hcs = $ls->pdo->prepare('insert into zandagort_nemlog.hist_csatak select * from zandagort.csatak');
	$hcs->execute();

	$hcsf = $ls->pdo->prepare('insert into zandagort_nemlog.hist_csata_flotta (csata_id,flotta_id,tulaj,tulaj_szov,egyenertek_elotte,egyenertek_utana,tulaj_nev,tulaj_szov_nev,nev,kozos,iranyito,iranyito_nev,tp,iranyito_karrier,iranyito_speci,iranyito_rang,kezdo)
select csata_id,flotta_id,tulaj,tulaj_szov,egyenertek_elotte,egyenertek_utana,tulaj_nev,tulaj_szov_nev,nev,kozos,iranyito,iranyito_nev,tp,iranyito_karrier,iranyito_speci,iranyito_rang,kezdo from zandagort.csata_flotta');
	$hcsf->execute();

	$hcsfm = $ls->pdo->prepare('insert into zandagort_nemlog.hist_csata_flottamatrix select * from zandagort.csata_flottamatrix');
	$hcsfm->execute();

	$hcsfh = $ls->pdo->prepare('insert into zandagort_nemlog.hist_csata_flotta_hajo select csata_id,flotta_id,hajo_id,serules,ossz_hp_elotte,ossz_hp_utana from zandagort.csata_flotta_hajo where hajo_id>0');
	$hcsfh->execute();

	$hcsh = $ls->pdo->prepare('insert into zandagort_nemlog.hist_csata_sebzesek select * from zandagort.csata_sebzesek where sebzes>0');
	$hcsh->execute();

//10. meghalt flottakat felszamolni
	$er = $gs->pdo->prepare('select flotta_id from csata_flotta where egyenertek_utana=0');
	$er->execute();
	foreach ($er->fetchAll(PDO::FETCH_BOTH) as $aux){
		$szimClass->fleetDelete($aux[0]);
	}


if (false) {
	//11. Zanda flottak egy resze dezertal
	$er = $gs->pdo->prepare('select csata_id,flotta_id from csata_flotta where egyenertek_utana>0 and tulaj=-1');
	$er->execute();
	foreach($er->fetchAll(PDO::FETCH_BOTH) as $aux) {
		if (mt_rand(1, 100) <= 10) {
			$er2 = $gs->pdo->prepare('SELECT tulaj,tulaj_szov FROM csata_flotta WHERE egyenertek_utana>0 AND tulaj>0 AND csata_id= ORDER BY rand() LIMIT 1');
			$er2->execute(array($aux['csata_id']));
			$aux2 = $er2->fetch(PDO::FETCH_BOTH);
			if ($aux2) {
				$info_hu = '';
				$info_en = '';
				if (false) {//dezertalo flottaban levo hajokrol informacio!!!
					$er3 = $gs->pdo->prepare('SELECT hajo_id FROM flotta_hajo WHERE flotta_id=' . $aux['flotta_id'] . ' AND hajo_id>218 AND ossz_hp>0 ORDER BY rand() LIMIT 1');
					$er3->execute(array($aux['flotta_id']));
					$er3 = $er3->fetch(PDO::FETCH_BOTH);
					$idegen_hajo_id = $er3[0];

					if ($idegen_hajo_id > 0) {
						$er4 = $gs->pdo->prepare('SELECT * FROM hajok WHERE id=' . $idegen_hajo_id);
						$er4->execute(array($idegen_hajo_id));
						$idegen_hajo_adatok = $er4->fetch(PDO::FETCH_BOTH);
						$anev_hu = 'a' . ($idegen_hajo_id == 222 ? 'z' : '') . ' ' . $idegen_hajo_adatok['nev'];
						$melyik_info_legyen = mt_rand(1, 7);
						$info_hu = ' Mérnökeidnek pedig sikerült kiderítenie, hogy <b>';
						$info_en = ' And your engineers discovered that <b>';
						switch ($melyik_info_legyen) {
							case 1:
								$info_hu .= $anev_hu . ' támadóereje ' . $idegen_hajo_adatok['tamado_ero'] . '</b>.';
								$info_en .= 'the attack value of ' . $idegen_hajo_adatok['nev_en'] . ' is ' . $idegen_hajo_adatok['tamado_ero'] . '</b>.';
								break;
							case 2:
								$info_hu .= $anev_hu . ' HP-ja ' . $idegen_hajo_adatok['valodi_hp'] . '</b>.';
								$info_en .= 'the HP of ' . $idegen_hajo_adatok['nev_en'] . ' is ' . $idegen_hajo_adatok['valodi_hp'] . '</b>.';
								break;
							case 3:
								$info_hu .= $anev_hu . ' sebessége ' . round($idegen_hajo_adatok['sebesseg'] / 2) . '</b>.';
								$info_en .= 'the speed of ' . $idegen_hajo_adatok['nev_en'] . ' is ' . round($idegen_hajo_adatok['sebesseg']) . '</b>.';
								break;
							case 4:
								$info_hu .= $anev_hu . ' látótávolsága ' . $idegen_hajo_adatok['latotav'] . '</b>.';
								$info_en .= 'the vision of ' . $idegen_hajo_adatok['nev_en'] . ' is ' . $idegen_hajo_adatok['latotav'] . '</b>.';
								break;
							case 5:
								$info_hu .= $anev_hu . ' rejtőzése ' . $idegen_hajo_adatok['rejtozes'] . '</b>.';
								$info_en .= 'the stealth of ' . $idegen_hajo_adatok['nev_en'] . ' is ' . $idegen_hajo_adatok['rejtozes'] . '</b>.';
								break;
							case 6:
								switch ($idegen_hajo_id) {
									case 219:
										$mi_hu = 'cirkálók';
										$mi_en = 'cruisers';
										break;
									case 220:
										$mi_hu = '';
										$mi_en = '';
										break;
									case 221:
										$mi_hu = 'vadászok';
										$mi_en = 'fighters';
										break;
									case 222:
										$mi_hu = 'csatahajók';
										$mi_en = 'battleships';
										break;
									case 223:
										$mi_hu = 'rombolók';
										$mi_en = 'destroyers';
										break;
									case 224:
										$mi_hu = 'interceptorok';
										$mi_en = 'interceptors';
										break;
								}
								if ($mi_hu == '') {
									$info_hu = '';
									$info_en = '';
								} else {
									$info_hu .= $anev_hu . ' ' . $mi_hu . ' ellen jó</b>.';
									$info_en .= 'the ' . $idegen_hajo_adatok['nev_en'] . ' is good against ' . $mi_en . '</b>.';
								}
								break;
							case 7:
								switch ($idegen_hajo_id) {
									case 219:
										$mi_hu = 'csatahajók';
										$mi_en = 'battleships';
										break;
									case 220:
										$mi_hu = '';
										$mi_en = '';
										break;
									case 221:
										$mi_hu = 'cirkálók';
										$mi_en = 'cruisers';
										break;
									case 222:
										$mi_hu = 'rombolók';
										$mi_en = 'destroyers';
										break;
									case 223:
										$mi_hu = 'interceptorok';
										$mi_en = 'interceptors';
										break;
									case 224:
										$mi_hu = 'vadászok';
										$mi_en = 'fighters';
										break;
								}
								if ($mi_hu == '') {
									$info_hu = '';
									$info_en = '';
								} else {
									$info_hu .= $anev_hu . ' ' . $mi_hu . ' ellen rossz</b>.';
									$info_en .= 'the ' . $idegen_hajo_adatok['nev_en'] . ' is bad against ' . $mi_en . '</b>.';
								}
								break;
						}
					}
				}
				//
				$f01 = $gs->pdo->prepare('UPDATE flottak SET tulaj=?, tulaj_szov=?  WHERE id=?');
				$f01->execute(array($aux2['tulaj'], $aux2['tulaj_szov'], $aux['flotta_id']));

				$f02 = $gs->pdo->prepare('UPDATE flottak SET statusz=12 WHERE cel_flotta=? AND (statusz=13 OR statusz=14)');
				$f02->execute(array($aux['flotta_id']));
				$szimClass->systemMessage($aux2['tulaj']
						, 'Dezertáló idegen flotta'
						, 'Zandagort egyik flottája megadta magát és átállt hozzád. Idegen hajókat továbbra sem tudsz gyártani, de az így megszerzetteket szabadon használhatod.' . $info_hu
						, 'Deserting alien fleet'
						, 'One of the fleets of Zandagort has surrendered and defected to you. You still cannot produce alien ships, but you may freely use those you acquired now.' . $info_en);
			}
		}
	}
}



}//voltak-e egyaltalan csatak

	$gs->pdo->prepare('update ido set idopont_csatak=idopont_csatak+1')->execute();$szimlog_hossz_csatak=round(1000*(microtime(true)-$mikor_indul));
/******************************************************** CSATAK VEGE ******************************************************************/


/******************************************************** OSTROMOK ELEJE ******************************************************************/

//bolygok ponterteke
	$gs->pdo->prepare('update bolygok set aux_pontertek=0')->execute();
	$gs->pdo->prepare('update bolygok b,(select b.id,sum(be.db*e.pontertek) as pontertek,sum(if(e.szallithato=1,be.db/e.savszel_igeny,0)) as ttertek,sum(if(e.szallithato=1,be.db*e.pontertek,0)) as uj_keszlet_pontertek
from bolygok b, bolygo_eroforras be, eroforrasok e
where b.id=be.bolygo_id and be.eroforras_id=e.id and e.pontertek>0
group by b.id) t
set b.aux_pontertek=b.aux_pontertek+round(coalesce(t.pontertek,0)),
b.keszlet_ttertek=round(coalesce(t.ttertek,0)),
b.keszlet_pontertek=round(coalesce(t.uj_keszlet_pontertek,0))
where t.id=b.id')->execute();

	$gs->pdo->prepare('update bolygok b,(select b.id,sum(bgy.db*gyt.pontertek) as pontertek
from bolygok b, bolygo_gyar bgy, gyarak gy, gyartipusok gyt
where b.id=bgy.bolygo_id and bgy.gyar_id=gy.id and gy.tipus=gyt.id
group by b.id) t
set b.aux_pontertek=b.aux_pontertek+round(coalesce(t.pontertek,0))
where t.id=b.id')->execute();
	$gs->pdo->prepare('update bolygok b, eroforrasok e
set b.aux_pontertek=b.aux_pontertek+b.raforditott_kornyezet_kp*e.pontertek
where e.id=150')->execute();

	$gs->pdo->prepare('update bolygok set pontertek=aux_pontertek')->execute();

//bolygok iparmerete
	$gs->pdo->prepare('update bolygok set aux_iparmeret=0');
	$gs->pdo->prepare('update bolygok b,(select b.id,sum(bgy.db*gyt.pontertek) as pontertek
from bolygok b, bolygo_gyar bgy, gyarak gy, gyartipusok gyt
where b.id=bgy.bolygo_id and bgy.gyar_id=gy.id and gy.tipus=gyt.id
group by b.id) t
set b.aux_iparmeret=b.keszlet_pontertek+round(coalesce(t.pontertek,0))
where t.id=b.id')->execute();

	$gs->pdo->prepare('update bolygok b, eroforrasok e
set b.aux_iparmeret=b.aux_iparmeret+b.raforditott_kornyezet_kp*e.pontertek
where e.id=150')->execute();
	$gs->pdo->prepare('update bolygok set iparmeret=aux_iparmeret')->execute();

//flottak ponterteke
	$gs->pdo->prepare('update flottak f,(
select f.id,sum(fh.ossz_hp*e.pontertek) as pontertek
from flottak f, flotta_hajo fh, eroforrasok e
where f.id=fh.flotta_id and fh.hajo_id=e.id
group by f.id) t
set f.pontertek=round(coalesce(t.pontertek,0))
where f.id=t.id')->execute();



$ostromok_listaja=array();//befejezetlen, a bekebiro elleni aggresszio detektalasahoz lett volna jo, asszem...
//tipus
//flotta_id,flotta_tulaj,flotta_tulaj_szov
//bolygo_id,bolygo_tulaj,bolygo_tulaj_szov
//0 annihilacio
//1 fantom utes
//2 npc utes
//3 fosztas
//4 szuperfosztas
//5 foglalas


$datum=date('Y-m-d H:i:s');
//Zandagort ostroma
	$er_cel_bolygo = $gs->pdo->prepare('
select b.*
from bolygok b, flottak f
where b.x=f.x and b.y=f.y
group by b.id
having sum(if(f.statusz=8 and f.cel_bolygo=b.id and f.tulaj=-1,1,0))>0
and sum(if(f.statusz=1 and f.bolygo=b.id,1,0))=0');
	$er_cel_bolygo->execute();
foreach($er_cel_bolygo->fetchAll(PDO::FETCH_BOTH) as $cel_bolygo) {
//van tamado _idegen_ flotta, nincs vedo
	$r = $gs->pdo->prepare('SELECT f.* FROM flottak f WHERE f.x=? AND f.y=? AND f.cel_bolygo=? AND f.statusz=8 AND f.tulaj=-1');
	$r->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));
	foreach ($r->fetchAll(PDO::FETCH_BOTH) as $foszto_flotta) {
		$ostromok_listaja[] = array(0, $foszto_flotta['id'], $foszto_flotta['tulaj'], $foszto_flotta['tulaj_szov'], $cel_bolygo['id'], $cel_bolygo['tulaj'], $cel_bolygo['tulaj_szov']);
		//
		//valami porfelhő ikon maradjon a térképen egy ideig
		//ertesites
		$poz = terkep_koordinatak($cel_bolygo['x'], $cel_bolygo['y']);
		$poz_en = terkep_koordinatak($cel_bolygo['x'], $cel_bolygo['y'], 'en');
		if ($cel_bolygo['tulaj'] > 0) {
			$szimClass->systemMessage($cel_bolygo['tulaj']
					, 'Bolygó annihiláció', 'Zandagort megsemmisítette ' . $cel_bolygo['nev'] . ' (' . $poz . ') bolygódat.'
					, 'Annihilation of planet', 'Zandagort has destroyed your planet ' . $cel_bolygo['nev'] . ' (' . $poz_en . ').'
			);
		}

		//ide jovo flottak megallitasa (beleertve a Zanda flottat is
		$f001 = $gs->pdo->prepare('UPDATE flottak SET statusz=2,cel_bolygo=0 WHERE cel_bolygo=?');
		$f001->execute(array($cel_bolygo['id']));
		//ugynokok megsemmisitese
		$f002 = $gs->pdo->prepare('DELETE FROM ugynokcsoportok WHERE bolygo_id=?');
		$f002->execute(array($cel_bolygo['id']));
		//barmilyen ugynok tevekenyseg torlese
		$f003 = $gs->pdo->prepare('UPDATE ugynokcsoportok SET cel_bolygo_id=0 WHERE cel_bolygo_id=?');
		$f003->execute(array($cel_bolygo['id']));
		//szallitasok torlese
		$f004 = $gs->pdo->prepare('DELETE FROM cron_tabla_eroforras_transzfer WHERE honnan_bolygo_id=?');
		$f004->execute(array($cel_bolygo['id']));
		$f005 = $gs->pdo->prepare('DELETE FROM cron_tabla_eroforras_transzfer WHERE hova_bolygo_id=?');
		$f005->execute(array($cel_bolygo['id']));
		//epitkezesek torlese
		$f006 = $gs->pdo->prepare('DELETE FROM cron_tabla WHERE bolygo_id=' . $cel_bolygo['id']);
		$f006->execute(array($cel_bolygo['id']));
		//bolygo annihilalasa
		$f007 = $gs->pdo->prepare('UPDATE bolygok SET letezik=0,tulaj_szov=-6,tulaj=-1 WHERE id=' . $cel_bolygo['id']);
		$f007->execute(array($cel_bolygo['id']));
		//mindenfele valtozasok regisztralasa
		$szimClass->planetOwnerChange($cel_bolygo['id'], $cel_bolygo['tulaj'], -1, $cel_bolygo['tulaj_szov'], -6);

	}
}
//fantomok 1000-es vedelmi_bonuszu bolygoja elleni tamadasok
//mivel ezek nincsenek benne a sima tamadasi listaban
	$rq = $gs->pdo->prepare('
select b.*,u.nev as tulaj_nev
from bolygok b, flottak f, userek u
where b.x=f.x and b.y=f.y and b.tulaj=u.id
and b.moratorium_mikor_jar_le<=now()
and b.vedelmi_bonusz=1000
and coalesce(u.karrier,0)=3 and coalesce(u.speci,0)=3
group by b.id
having sum(if((f.statusz=8 or f.statusz=7) and f.cel_bolygo=b.id,1,0))>0
and sum(if(f.statusz=1 and f.bolygo=b.id,1,0))=0');
	$rq->execute();

foreach($rq->fetchAll(PDO::FETCH_BOTH) as $cel_bolygo){


	$poz = $szimClass->mapcoords($cel_bolygo['x'],$cel_bolygo['y']);
	$poz_en = $szimClass->mapcoords($cel_bolygo['x'],$cel_bolygo['y'],'en');
	//uzenet a tamado(k)nak es a bolygo tulajanak
		$er = $gs->pdo->prepare('select f.*,u.nev as tulaj_nev,u2.nev as iranyito_nev from flottak f
inner join userek u on u.id=f.tulaj
left join userek u2 on u2.id=f.uccso_parancs_by
where f.x=? and f.y=? and f.cel_bolygo=? and (f.statusz=8 or f.statusz=7) and f.tulaj>0
order by f.tulaj,f.id');
		$er->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));

	//$elozo_tulaj=0;
	foreach($er->fetchAll(PDO::FETCH_BOTH) as $foszto_flotta){
		$ostromok_listaja[]=array(1,$foszto_flotta['id'],$foszto_flotta['tulaj'],$foszto_flotta['tulaj_szov'],$cel_bolygo['id'],$cel_bolygo['tulaj'],$cel_bolygo['tulaj_szov']);
		//if ($foszto_flotta['tulaj']!=$elozo_tulaj) {
			//$elozo_tulaj=$foszto_flotta['tulaj'];

			$szimClass->systemMessage($foszto_flotta['tulaj']
				,$lang['hu']['kisphpk']['Fantom bolygó'],strtr($lang['hu']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) bolygón. Mint kiderült, ez YYY bolygója.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$cel_bolygo['tulaj_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
				,$lang['en']['kisphpk']['Fantom bolygó'],strtr($lang['en']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) bolygón. Mint kiderült, ez YYY bolygója.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$cel_bolygo['tulaj_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
			);
			$szimClass->systemMessage($cel_bolygo['tulaj']
				,$lang['hu']['kisphpk']['Lebuktál'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával megütötte ZZZ (POZ) bolygódat, így megtudta, hogy ez nem NPC bolygó, hanem a tied.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
				,$lang['en']['kisphpk']['Lebuktál'],strtr($lang['en']['kisphpk']['XXX YYY flottájával megütötte ZZZ (POZ) bolygódat, így megtudta, hogy ez nem NPC bolygó, hanem a tied.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
			);

			if ($foszto_flotta['uccso_parancs_by']>0) if ($foszto_flotta['uccso_parancs_by']!=$foszto_flotta['tulaj']) {
				$szimClass->systemMessage($foszto_flotta['uccso_parancs_by']
					,$lang['hu']['kisphpk']['Fantom bolygó'],strtr($lang['hu']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) bolygón. Mint kiderült, ez YYY bolygója.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$cel_bolygo['tulaj_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
					,$lang['en']['kisphpk']['Fantom bolygó'],strtr($lang['en']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) bolygón. Mint kiderült, ez YYY bolygója.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$cel_bolygo['tulaj_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
				);
				$szimClass->systemMessage($cel_bolygo['tulaj']
					,$lang['hu']['kisphpk']['Lebuktál'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával megütötte ZZZ (POZ) bolygódat, így megtudta, hogy ez nem NPC bolygó, hanem a tied.'],array('XXX'=>$foszto_flotta['iranyito_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
					,$lang['en']['kisphpk']['Lebuktál'],strtr($lang['en']['kisphpk']['XXX YYY flottájával megütötte ZZZ (POZ) bolygódat, így megtudta, hogy ez nem NPC bolygó, hanem a tied.'],array('XXX'=>$foszto_flotta['iranyito_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
				);
			}
		//}
	}
	//moratorium beallitasa, hogy ne folyamatosan kuldje az uzeneteket$
	$qr = $gs->pdo->prepare('update bolygok set moratorium_mikor_jar_le=? where id=?');
	$qr->execute(array(date('Y-m-d H:i:s',time()+1200), $cel_bolygo['id']));
}

//emberek ostroma
	$ecb = $gs->pdo->prepare('
select b.*
from bolygok b, flottak f
where b.x=f.x and b.y=f.y
and b.moratorium_mikor_jar_le<=now() and b.vedelmi_bonusz<1000
group by b.id
having sum(if((f.statusz=8 or f.statusz=10) and f.cel_bolygo=b.id,1,0))>0
and sum(if(f.statusz=1 and f.bolygo=b.id,1,0))=0');
	$ecb->execute();
	foreach($ecb->fetchAll(PDO::FETCH_BOTH) as $cel_bolygo){//nincs moratorium, 1000-nel kisebb a vedelmi bonusz, van tamado, nincs vedo
		$poz=$szimClass->mapcoords($cel_bolygo['x'],$cel_bolygo['y']);
		$poz_en=$szimClass->mapcoords($cel_bolygo['x'],$cel_bolygo['y'],'en');
	//moralvaltozas
	$moral_csokkenes=20;//legyen fixen 20, ahogy regen
	$b11 = $gs->pdo->prepare('update bolygok set moral=greatest(moral-?,0) where id=?');
	$b11->execute(array($moral_csokkenes, $cel_bolygo['id']));
	if ($moral_csokkenes<$cel_bolygo['moral'])
	{//fosztas
		if ($cel_bolygo['tulaj']>0) {//jatekos bolygo -> fosztas
/********************************************************************************************************************************************/
			//egy-ket alap cucc

			$uvj = $gs->pdo->prepare('select * from userek where id=?');
			$uvj->execute(array($cel_bolygo['tulaj']));
			$vedo_jatekos = $uvj->fetch(PDO::FETCH_BOTH);

			$epf = $gs->pdo->prepare('select sum(egyenertek),sum(pontertek) from flottak where x=? and y=? and cel_bolygo=? and (statusz=8 or statusz=10)');
			$epf->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));
			$aux = $epf->fetch(PDO::FETCH_BOTH);

			$teljes_tamado_egyenertek=$aux[0];$teljes_tamado_pontertek=$aux[1];
			//zsakmany es veszteseg szazalekok
			$b=$cel_bolygo['vedelmi_bonusz']/1000;
			$maximalis_fosztas_szazalek=$maximalis_fosztas_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)];
			//SHY-fosztas
			$tv = $gs->pdo->prepare('select vagyon from userek where id='.$cel_bolygo['tulaj']);
			$tv->execute(array($cel_bolygo['tulaj']));
			$tv = $tv->fetch(PDO::FETCH_BOTH);
			$tulaj_vagyona = $tv[0];

			$shyp = $gs->pdo->prepare('select sum(pontertek) from bolygok where tulaj='.$cel_bolygo['tulaj']);
			$shyp->execute(array($cel_bolygo['tulaj']));
			$shyp = $shyp->fetch(PDO::FETCH_BOTH);
			$shyp = $shyp[0];


			$bolygora_eso_penz=round($cel_bolygo['pontertek']/$shyp*$tulaj_vagyona);
			if ($bolygora_eso_penz>0) $penz_fosztas_szazalek=$teljes_tamado_pontertek/$bolygora_eso_penz; else $penz_fosztas_szazalek=0;//a pontertek SHY-ban merve, ezert osszevethetok
			if ($penz_fosztas_szazalek>$maximalis_fosztas_szazalek) $penz_fosztas_szazalek=$maximalis_fosztas_szazalek;
			$penz_zsakmany_szazalek=(1-$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)])*$penz_fosztas_szazalek;
			$penz_veszteseg_szazalek=$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)]*$penz_fosztas_szazalek;
			//
			if ($cel_bolygo['keszlet_pontertek']>0) $fosztas_szazalek=$teljes_tamado_pontertek/$cel_bolygo['keszlet_pontertek'];else $fosztas_szazalek=0;
			if ($fosztas_szazalek>$maximalis_fosztas_szazalek) $fosztas_szazalek=$maximalis_fosztas_szazalek;
			$zsakmany_szazalek=(1-$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)])*$fosztas_szazalek;
			$veszteseg_szazalek=$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)]*$fosztas_szazalek;
			//1. mindenfele keszleteket osszeszamolni (nyersi, penz, ajanlatok)
			unset($keszletek);

			$r = $gs->pdo->prepare('select e.id
,vedo.db,coalesce(sum(a.mennyiseg),0) as ajanlott_db,vedo.db+coalesce(sum(a.mennyiseg),0) as ossz_db
,e.mertekegyseg,e.nev,e.mertekegyseg_en,e.nev_en
from bolygo_eroforras vedo
inner join eroforrasok e on e.id=vedo.eroforras_id
left join szabadpiaci_ajanlatok a on a.termek_id=e.id and a.user_id=? and a.bolygo_id=? and a.vetel=0
where vedo.bolygo_id=? and e.tipus=2 and e.szallithato=1
group by e.id');
			$r->execute(array($cel_bolygo['tulaj'], $cel_bolygo['id'], $cel_bolygo['id']));

			foreach($r->fetchAll(PDO::FETCH_BOTH) as $aux) $keszletek[$aux[0]]=$aux;
			$vagyon=$vedo_jatekos['vagyon'];

			$ea = $gs->pdo->prepare('select sum(mennyiseg*arfolyam) from szabadpiaci_ajanlatok where bolygo_id=? and user_id=? and vetel=1');
			$ea->execute(array($cel_bolygo['id'], $cel_bolygo['tulaj']));
			$ea = $ea->fetch(PDO::FETCH_BOTH);
			$ea = $ea[0];

			$eladasi_ajanlatok = $szimClass->sanitint($ea);
			$keszletek[0]=array(
				'id'=>0
/*				,'db'=>$vagyon
				,'ajanlott_db'=>$eladasi_ajanlatok
				,'ossz_db'=>$vagyon+$eladasi_ajanlatok*/
				,'db'=>$bolygora_eso_penz
				,'ajanlott_db'=>$eladasi_ajanlatok
				,'ossz_db'=>$bolygora_eso_penz+$eladasi_ajanlatok
				,'mertekegyseg'=>'SHY'
				,'nev'=>'pénz'
				,'mertekegyseg_en'=>'SHY'
				,'nev_en'=>'money'
			);
			//foszto flottakon vegig
			$er_f = $gs->pdo->prepare('select f.*,u.nev as tulaj_nev,b.nev as bazis_nev,u.nyelv as tulaj_nyelv,u2.nev as iranyito_nev,u2.nyelv as iranyito_nyelv,if(u.karrier=3 and u.speci=3,1,0) as fantom_tamado,u.helyezes
from flottak f
inner join userek u on u.id=f.tulaj
inner join bolygok b on b.id=f.bazis_bolygo
left join userek u2 on u2.id=f.uccso_parancs_by
where f.x=? and f.y=? and f.cel_bolygo=? and (f.statusz=8 or f.statusz=9)
order by f.tulaj,f.id');
			$er_f->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));

			if ($er_f->rowCount()) {
				//2. zsakmanyok, vesztesegek, ertesitesek, transzferek
				foreach($er_f->fetchAll(PDO::FETCH_BOTH) as $foszto_flotta){
					//fantom tamado egy bolygoja lebukik
					$forras_info='';$forras_info_en='';
					if ($fantom_lebukas) if ($foszto_flotta['fantom_tamado']) {
						$rb = $gs->pdo->prepare('select * from bolygok where tulaj=? order by rand() limit 1');
						$rb->execute(array($foszto_flotta['tulaj']));
						$random_bolygo  = $rb->fetch(PDO::FETCH_BOTH);

						if ($random_bolygo) list($forras_info,$forras_info_en) = $szimClass->fantomPlanet($random_bolygo,$foszto_flotta);
					}
					//reszflottak
					$szimClass->otherFleetsRefresh($foszto_flotta['id']);
					$ver = $gs->pdo->prepare('select coalesce(count(distinct user_id),0) from resz_flotta_hajo where flotta_id='.$foszto_flotta['id']);
					$ver->execute(array($foszto_flotta['id']));
					$ver = $ver->fetch(PDO::FETCH_BOTH);

					$vannak_e_reszflottai_a_fosztonak = $szimClass->sanitint($ver);
					if ($vannak_e_reszflottai_a_fosztonak>1) {//foszto flotta resztulajdonosokkal
						$iranyito_kapott_e=false;
						//reszeken vegigmenni
						$er2 = $gs->pdo->prepare('select user_id,coalesce(sum(rfh.hp/100*h.ar),0) as egyenertek from resz_flotta_hajo rfh, hajok h where rfh.hajo_id=h.id and rfh.flotta_id=? group by user_id');
						$er2->execute(array($foszto_flotta['id']));

						foreach($er2->fetchAll(PDO::FETCH_BOTH) as $resz_flotta){
							//bazis = jobb hijan az elso bolygo
							$bb = $gs->pdo->prepare('select * from bolygok where tulaj='.$resz_flotta['user_id'].' order by uccso_foglalas_mikor limit 1');
							$bb->execute(array($resz_flotta['user_id']));
							$bazis_bolygo = $bb->fetch(PDO::FETCH_BOTH);
							//zsakmany
							if ($teljes_tamado_pontertek>0 && $foszto_flotta['egyenertek']>0) {
								$zsakmany_mennyisege_szazalekban=$resz_flotta['egyenertek']/$foszto_flotta['egyenertek']*$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$zsakmany_szazalek;
								$penz_zsakmany_mennyisege_szazalekban=$resz_flotta['egyenertek']/$foszto_flotta['egyenertek']*$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_zsakmany_szazalek;
							} else {
								$zsakmany_mennyisege_szazalekban=0;
								$penz_zsakmany_mennyisege_szazalekban=0;
							}
							//resztulaj
							$zsakmany_hu='';$zsakmany_en='';
							foreach($keszletek as $ef_id=>$keszlet) {
								if ($ef_id>0) {
									$x=round($zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
									if ($x>0) {
										if ($zsakmany_hu!='') {$zsakmany_hu.=', ';$zsakmany_en.=', ';}
										$zsakmany_hu.=$x.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
										$zsakmany_en.=$x.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
										$be = $gs->pdo->prepare('update bolygo_eroforras set db=db+? where bolygo_id=? and eroforras_id=?');
										$be->execute(array($x, $bazis_bolygo['id'], $ef_id));
									}
								} else {
									$x=round($penz_zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
									if ($x>0) {
										if ($zsakmany_hu!='') {$zsakmany_hu.=' és ';$zsakmany_en.=' and ';}
										$zsakmany_hu.=$x.' SHY';
										$zsakmany_en.=$x.' SHY';
										$us = $gs->pdo->prepare('update userek set vagyon=vagyon+? where id=?');
										$us->execute(array($x, $resz_flotta['user_id']));
									}
								}
							}
							//
							if ($zsakmany_hu!='') {
								$zsakmany_szoveg=strtr($lang['hu']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$bazis_bolygo['nev'],'YYY'=>$zsakmany_hu));
								$zsakmany_szoveg_en=strtr($lang['en']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$bazis_bolygo['nev'],'YYY'=>$zsakmany_en));
							} else {
								$zsakmany_szoveg='';
								$zsakmany_szoveg_en='';
							}
							if ($resz_flotta['user_id']==$foszto_flotta['uccso_parancs_by']) {
								$iranyito_kapott_e=true;
								//
								$szimClass->systemMessage($resz_flotta['user_id']
									,$lang['hu']['kisphpk']['Fosztogatás'],strtr($lang['hu']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
									,$lang['en']['kisphpk']['Fosztogatás'],strtr($lang['en']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
								);
							} else {

								$szimClass->systemMessage($resz_flotta['user_id']
									,$lang['hu']['kisphpk']['Fosztogatás'],strtr($lang['hu']['kisphpk']['XXX flottáddal (WWW irányításával) kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
									,$lang['en']['kisphpk']['Fosztogatás'],strtr($lang['en']['kisphpk']['XXX flottáddal (WWW irányításával) kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
								);

							}
							//
						}
						//zsakmany es veszteseg osszesitve
						//zsakmany
						if ($teljes_tamado_pontertek>0) {
							$zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$zsakmany_szazalek;
							$veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$veszteseg_szazalek;
							$penz_zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_zsakmany_szazalek;
							$penz_veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_veszteseg_szazalek;
						} else {
							$zsakmany_mennyisege_szazalekban=0;
							$veszteseg_mennyisege_szazalekban=0;
							$penz_zsakmany_mennyisege_szazalekban=0;
							$penz_veszteseg_mennyisege_szazalekban=0;
						}
						//
						$zsakmany_hu='';$zsakmany_en='';
						$veszteseg_hu='';$veszteseg_en='';
						foreach($keszletek as $ef_id=>$keszlet) {
							if ($ef_id>0) {
								$x=round($zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
								if ($x>0) {
									if ($zsakmany_hu!='') {$zsakmany_hu.=', ';$zsakmany_en.=', ';}
									$zsakmany_hu.=$x.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
									$zsakmany_en.=$x.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
								}
								//
								$y=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
								if ($y>0) {
									if ($veszteseg_hu!='') {$veszteseg_hu.=', ';$veszteseg_en.=', ';}
									$veszteseg_hu.=$y.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
									$veszteseg_en.=$y.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
								}
								$y1=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['db']);
								if ($y1>0){
									$ube = $gs->pdo->prepare('update bolygo_eroforras set db=if(db>?,db-?,0) where bolygo_id=? and eroforras_id=?');
									$ube->execute(array($y1, $y1, $cel_bolygo['id'], $ef_id));
								}
							} else {
								$x=round($penz_zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
								if ($x>0) {
									if ($zsakmany_hu!='') {$zsakmany_hu.=' és ';$zsakmany_en.=' and ';}
									$zsakmany_hu.=$x.' SHY';
									$zsakmany_en.=$x.' SHY';
								}
								$y=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
								if ($y>0) {
									if ($veszteseg_hu!='') {$veszteseg_hu.=' és ';$veszteseg_en.=' and ';}
									$veszteseg_hu.=$y.' SHY';
									$veszteseg_en.=$y.' SHY';
								}
								$y1=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['db']);
								if ($y1>0){
									$pzsm = $gs->pdo->prepare('update userek set vagyon=if(vagyon>?,vagyon-?,0) where id=?');
									$pzsm->execute(array($y1, $y1. $cel_bolygo['tulaj']));
								}
							}
						}
						//ajanlatok
						$sza1 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=1');
						$sza1->execute(array($zsakmany_mennyisege_szazalekban, $zsakmany_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));

						$sza2 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=0');
						$sza2->execute(array($penz_zsakmany_mennyisege_szazalekban, $penz_veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));
						//
						if ($zsakmany_hu!='') {
							$zsakmany_szoveg=strtr($lang['hu']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_hu));
							$zsakmany_szoveg_en=strtr($lang['en']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_en));
						} else {
							$zsakmany_szoveg='';
							$zsakmany_szoveg_en='';
						}
						if ($veszteseg_hu!='') {
							$veszteseg_szoveg=strtr($lang['hu']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_hu));
							$veszteseg_szoveg_en=strtr($lang['en']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_en));
						} else {
							$veszteseg_szoveg='';
							$veszteseg_szoveg_en='';
						}
						//iranyito ertesitese
						if (!$iranyito_kapott_e)
							$szimClass->systemMessage($foszto_flotta['uccso_parancs_by']
							,$lang['hu']['kisphpk']['Fosztogatás'],strtr($lang['hu']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
							,$lang['en']['kisphpk']['Fosztogatás'],strtr($lang['en']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
						);
						//bolygo_tulaj ertesitese
						if ($foszto_flotta['tulaj']==$foszto_flotta['uccso_parancs_by']) {

							$szimClass->systemMessage($cel_bolygo['tulaj']
								,$lang['hu']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
								,$lang['en']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
							);
						} else {

							$szimClass->systemMessage($cel_bolygo['tulaj']
								,$lang['hu']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával (WWW irányításával) kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
								,$lang['en']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával (WWW irányításával) kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
							);
						}
					} else {//foszto flotta egy tulajdonossal
						//zsakmany
						if ($teljes_tamado_pontertek>0) {
							$zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$zsakmany_szazalek;
							$veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$veszteseg_szazalek;
							$penz_zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_zsakmany_szazalek;
							$penz_veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_veszteseg_szazalek;
						} else {
							$zsakmany_mennyisege_szazalekban=0;
							$veszteseg_mennyisege_szazalekban=0;
							$penz_zsakmany_mennyisege_szazalekban=0;
							$penz_veszteseg_mennyisege_szazalekban=0;
						}
						//
						$zsakmany_hu='';$zsakmany_en='';
						$veszteseg_hu='';$veszteseg_en='';
						foreach($keszletek as $ef_id=>$keszlet) {
							if ($ef_id>0) {
								$x=round($zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
								if ($x>0) {
									if ($zsakmany_hu!='') {$zsakmany_hu.=', ';$zsakmany_en.=', ';}
									$zsakmany_hu.=$x.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
									$zsakmany_en.=$x.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
									$be01 = $gs->pdo->prepare('update bolygo_eroforras set db=db+? where bolygo_id=? and eroforras_id=?');
									$be01->execute(array($x, $foszto_flotta['bazis_bolygo'], $ef_id));
								}
								//
								$y=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
								if ($y>0) {
									if ($veszteseg_hu!='') {$veszteseg_hu.=', ';$veszteseg_en.=', ';}
									$veszteseg_hu.=$y.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
									$veszteseg_en.=$y.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
								}
								$y1=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['db']);
								if ($y1>0){
									$be02 = $gs->pdo->prepare('update bolygo_eroforras set db=if(db>?,db-?,0) where bolygo_id=? and eroforras_id=?');
									$be02->execute(array($y1, $y1, $cel_bolygo['id'], $ef_id));
								}
							} else {
								$x=round($penz_zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
								if ($x>0) {
									if ($zsakmany_hu!='') {$zsakmany_hu.=' és ';$zsakmany_en.=' and ';}
									$zsakmany_hu.=$x.' SHY';
									$zsakmany_en.=$x.' SHY';
									$be03 = $gs->pdo->prepare('update userek set vagyon=vagyon? where id=?');
									$be03->execute(array($x, $foszto_flotta['tulaj']));
								}
								$y=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
								if ($y>0) {
									if ($veszteseg_hu!='') {$veszteseg_hu.=' és ';$veszteseg_en.=' and ';}
									$veszteseg_hu.=$y.' SHY';
									$veszteseg_en.=$y.' SHY';
								}
								$y1=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['db']);
								if ($y1>0){
									$uv = $gs->pdo->prepare('update userek set vagyon=if(vagyon>?,vagyon-?,0) where id=?');
									$uv->execute(array($y1, $y1, $cel_bolygo['tulaj']));
								}
							}
						}
						//ajanlatok
						$sza3 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=1');
						$sza3->execute(array($zsakmany_mennyisege_szazalekban, $veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));

						$sza4 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=0');
						$sza4->execute(array($penz_zsakmany_mennyisege_szazalekban, $penz_veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));
						//
						if ($zsakmany_hu!='') {
							$zsakmany_szoveg=strtr($lang['hu']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$foszto_flotta['bazis_nev'],'YYY'=>$zsakmany_hu));
							$zsakmany_szoveg_en=strtr($lang['en']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$foszto_flotta['bazis_nev'],'YYY'=>$zsakmany_en));
							$zsakmany_szoveg_iranyito=strtr($lang['hu']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_hu));
							$zsakmany_szoveg_iranyito_en=strtr($lang['en']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_en));
						} else {
							$zsakmany_szoveg='';
							$zsakmany_szoveg_en='';
							$zsakmany_szoveg_iranyito='';
							$zsakmany_szoveg_iranyito_en='';
						}
						if ($veszteseg_hu!='') {
							$veszteseg_szoveg=strtr($lang['hu']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_hu));
							$veszteseg_szoveg_en=strtr($lang['en']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_en));
						} else {
							$veszteseg_szoveg='';
							$veszteseg_szoveg_en='';
						}
						//
						if ($foszto_flotta['tulaj']==$foszto_flotta['uccso_parancs_by']) {
							//tulaj = iranyito ertesitese
							$szimClass->systemMessage($foszto_flotta['tulaj']
								,$lang['hu']['kisphpk']['Fosztogatás'],strtr($lang['hu']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
								,$lang['en']['kisphpk']['Fosztogatás'],strtr($lang['en']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
							);
							//bolygo_tulaj ertesitese
							$szimClass->systemMessage($cel_bolygo['tulaj']
								,$lang['hu']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
								,$lang['en']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
							);
						} else {
							//tulaj ertesitese
							$szimClass->systemMessage($foszto_flotta['tulaj']
								,$lang['hu']['kisphpk']['Fosztogatás'],strtr($lang['hu']['kisphpk']['XXX flottáddal (WWW irányításával) kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
								,$lang['en']['kisphpk']['Fosztogatás'],strtr($lang['en']['kisphpk']['XXX flottáddal (WWW irányításával) kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
							);
							//iranyito ertesitese
							$szimClass->systemMessage($foszto_flotta['uccso_parancs_by']
								,$lang['hu']['kisphpk']['Fosztogatás'],strtr($lang['hu']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg_iranyito
								,$lang['en']['kisphpk']['Fosztogatás'],strtr($lang['en']['kisphpk']['XXX flottáddal kifosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_iranyito_en
							);
							//bolygo_tulaj ertesitese
							$szimClass->systemMessage($cel_bolygo['tulaj']
								,$lang['hu']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával (WWW irányításával) kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
								,$lang['en']['kisphpk']['Ellenséges fosztogatás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával (WWW irányításával) kifosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
							);
						}
					}
				}
			}
/********************************************************************************************************************************************/
		} else {//npc bolygo -> csak moralcsokkenes
			//ertesites, hogy utottel az npc-n
			$errr = $gs->pdo->prepare('select * from flottak where x=? and y=? and cel_bolygo=? and (statusz=8 or statusz=10) and tulaj>0 order by tulaj,id');
			$errr->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));
			$elozo_tulaj=0;
			foreach($errr->fetchAll(PDO::FETCH_BOTH) as $foszto_flotta){
				print_r($foszto_flotta);
				if ($foszto_flotta['tulaj'] != $elozo_tulaj) {
					$elozo_tulaj=$foszto_flotta['tulaj'];
					//		function systemMessage($kinek,$targy,$uzenet,$targy_en='',$uzenet_en='') {

					$szimClass->systemMessage($foszto_flotta['tulaj']
					,$lang['hu']['kisphpk']['NPC-ütés'],strtr($lang['hu']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) npc bolygón. Amint a morálja nullára csökken, tied lesz a bolygó.'],array('XXX'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
					,$lang['en']['kisphpk']['NPC-ütés'],strtr($lang['en']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) npc bolygón. Amint a morálja nullára csökken, tied lesz a bolygó.'],array('XXX'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
					);
				}
			}
		}
		//moratorium beallitasa
		$bm = $gs->pdo->prepare('update bolygok set moratorium_mikor_jar_le=? where id=?');
		$bm->execute(array(date('Y-m-d H:i:s',time()+1200), $cel_bolygo['id']));
	} elseif ($cel_bolygo['vedelmi_bonusz']<800) {//foglalas (elvileg nem kellene ez a csekkolas, de a MORATORIUM_HOSSZA-kavaras miatt megis) vagy szuperfosztas (elvileg lehetne 800 felett is, de a moral miatt valojaban nem lehet)
		//az elso olyan tamadot megkeresni, akinek belefer a bolygolimitjebe!!! es nem RAID-el, hanem TAMAD
		$foglalo_flotta=null;
		$er = $gs->pdo->prepare('select f.*,u.bolygo_limit,u.nyelv as tulaj_nyelv,if(u.karrier=3 and u.speci=3,1,0) as fantom_tamado,u.helyezes from flottak f, userek u where f.x=? and f.y=? and f.cel_bolygo=? and f.statusz=8 and f.tulaj=u.id order by f.tulaj,f.id');
		$er->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));
		foreach($er->fetchAll(PDO::FETCH_BOTH) as $foglalo_flotta_jelolt){
			$bt = $gs->pdo->prepare('select count(1) from bolygok where tulaj=?');
			$bt->execute(array($foglalo_flotta_jelolt['tulaj']));
			$aux9 = $bt->fetch(PDO::FETCH_BOTH);
			if ($foglalo_flotta_jelolt['bolygo_limit']>$aux9[0]) {
				$foglalo_flotta=$foglalo_flotta_jelolt;
				break;
			}
		}
		//utolso bolygo vedelem
		$nem_utolso_bolygo=true;
		if ($cel_bolygo['tulaj']>0) {
			$mbsz = $gs->pdo->prepare('select count(1) from bolygok where letezik=1 and tulaj='.$cel_bolygo['tulaj']);
			$mbsz->execute(array($cel_bolygo['tulaj']));
			$mbsz = $mbsz->fetch(PDO::FETCH_BOTH);

			$megtamadott_bolygoinak_szama = $mbsz[0];
			if ($megtamadott_bolygoinak_szama==1) $nem_utolso_bolygo=false;
		}
		if ((!is_null($foglalo_flotta)) && $nem_utolso_bolygo) {//van olyan tamado, akinek belefer a bolygolimitjebe, es nem utolso bolygo
			//fantom tamado egy bolygoja lebukik
			$forras_info='';$forras_info_en='';
			if ($fantom_lebukas) if ($foglalo_flotta['fantom_tamado']) {
				$rb01 = $gs->pdo->prepare('select * from bolygok where tulaj=? order by rand() limit 1');
				$rb01->execute(array($foglalo_flotta['tulaj']));
				$random_bolygo = $rb01->fetch(PDO::FETCH_BOTH);
				if ($random_bolygo) list($forras_info,$forras_info_en) = $szimClass->fantomPlanet($random_bolygo,$foglalo_flotta);
			}

			$tj = $gs->pdo->prepare('select nev from userek where id=?');
			$tj->execute(array($foglalo_flotta['tulaj']));
			$tamado_jatekos=$tj->fetch(PDO::FETCH_BOTH);
			$veszteseg_szoveg='';
			$veszteseg_szoveg_en='';
			$veszteseg_szazalek=0;
			if ($cel_bolygo['tulaj']>0) {//jatekos bolygo -> veszteseg a bolygonak!!!
				$veszteseg_szazalek=$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)];
				$be04 = $gs->pdo->prepare('update bolygo_eroforras be, eroforrasok e set be.db=0 where be.bolygo_id=? and be.eroforras_id=e.id and e.tipus=3');
				$be04->execute(array($cel_bolygo['id']));

				$be05 = $gs->pdo->prepare('update bolygo_eroforras be, eroforrasok e set be.db=round(be.db*?) where be.bolygo_id=? and be.eroforras_id=e.id and e.raktarozhato=1');
				$be05->execute(array(1-$veszteseg_szazalek, $cel_bolygo['id']));
				//mysql_query('update bolygo_eroforras be, eroforrasok e set be.db=round(be.db*'.(1-$veszteseg_szazalek).') where be.bolygo_id='.$cel_bolygo['id'].' and be.eroforras_id=e.id and (e.raktarozhato=1 or e.tipus=3)');
				$be06 = $gs->pdo->prepare('update bolygo_gyar bgy set bgy.db=round(bgy.db*?) where bgy.bolygo_id=?');
				$be06->execute(array(1-$veszteseg_szazalek, $cel_bolygo['id']));

				$be07 = $gs->pdo->prepare('update bolygo_gyar set aktiv_db=least(db,aktiv_db) where bolygo_id=?');
				$be07->execute(array($cel_bolygo['id']));

				//bontasi/epitesi listat is leosztani!!! -> trukkozes ellen
				$be08 = $gs->pdo->prepare('update cron_tabla set darab=floor(darab*?) where bolygo_id=?');
				$be08->execute(array(1-$veszteseg_szazalek, $cel_bolygo['id']));

				$be09 = $gs->pdo->prepare('delete from cron_tabla where darab=0 and bolygo_id=?');
				$be09->execute(array($cel_bolygo['id']));
					$szimClass->planetFactoryResources($cel_bolygo['id']);
				$veszteseg_szoveg=strtr($lang['hu']['kisphpk'][' A foglaláskor elveszett a bolygó XXX%-a.'],array('XXX'=>round(100*$veszteseg_szazalek)));
				$veszteseg_szoveg_en=strtr($lang['en']['kisphpk'][' A foglaláskor elveszett a bolygó XXX%-a.'],array('XXX'=>round(100*$veszteseg_szazalek)));
			}
			if ($cel_bolygo['tulaj']>0) {
				$vj = $gs->pdo->prepare('select nev from userek where id=?');
				$vj->execute(array($cel_bolygo['tulaj']));
				$vedo_jatekos = $vj->fetch(PDO::FETCH_BOTH);

				$szimClass->systemMessage($foglalo_flotta['tulaj']
				,$lang['hu']['kisphpk']['Foglalás'],strtr($lang['hu']['kisphpk']['XXX flottáddal elfoglaltad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foglalo_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg
				,$lang['en']['kisphpk']['Foglalás'],strtr($lang['en']['kisphpk']['XXX flottáddal elfoglaltad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foglalo_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en
				);
				$szimClass->systemMessage($cel_bolygo['tulaj']
				,$lang['hu']['kisphpk']['Ellenséges foglalás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával elfoglalta ZZZ (POZ) bolygódat.'],array('XXX'=>$tamado_jatekos['nev'],'YYY'=>$foglalo_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$forras_info
				,$lang['en']['kisphpk']['Ellenséges foglalás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával elfoglalta ZZZ (POZ) bolygódat.'],array('XXX'=>$tamado_jatekos['nev'],'YYY'=>$foglalo_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$forras_info_en
				);
			} else {

				$szimClass->systemMessage($foglalo_flotta['tulaj']
				,$lang['hu']['kisphpk']['Foglalás'],strtr($lang['hu']['kisphpk']['XXX flottáddal elfoglaltad ZZZ (POZ) npc bolygót.'],array('XXX'=>$foglalo_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
				,$lang['en']['kisphpk']['Foglalás'],strtr($lang['en']['kisphpk']['XXX flottáddal elfoglaltad ZZZ (POZ) npc bolygót.'],array('XXX'=>$foglalo_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
				);
			}
			//log
			//insert_into_bolygo_transzfer_log($cel_bolygo['id'],$cel_bolygo['uccso_emberi_tulaj'],$cel_bolygo['uccso_emberi_tulaj_szov'],$cel_bolygo['tulaj'],$cel_bolygo['tulaj_szov'],$foglalo_flotta['tulaj'],$foglalo_flotta['tulaj_szov'],1,$cel_bolygo['pontertek'],round((1-$veszteseg_szazalek)*$cel_bolygo['pontertek']),round($veszteseg_szazalek*$cel_bolygo['pontertek']));
			//bolygo tulajt,anyabolygot,moralt atirni
			$aux_anya[0]=$foglalo_flotta['bazis_bolygo'];//a támadó flotta bázisa
			if ($aux_anya[0]==0) {//ha nincs, akkor a támadó egy bolygója
				$aa = $gs->pdo->prepare('select id from bolygok where tulaj=? order by nev limit 1');
				$aa->execute(array($foglalo_flotta['tulaj']));
				$aux_anya = $aa->fetch(PDO::FETCH_BOTH);
			}
			$bt01 = $gs->pdo->prepare('update bolygok set tulaj=?,uccso_emberi_tulaj=?,tulaj_szov=?,uccso_emberi_tulaj_szov=?,kezelo=0,fobolygo=0,uccso_foglalas_mikor=?,moral=least(moral+?,100),anyabolygo=? where id=?');
			$bt01->execute(array($foglalo_flotta['tulaj'], $foglalo_flotta['tulaj'], $foglalo_flotta['tulaj_szov'], $foglalo_flotta['tulaj_szov'], $datum, 0,$aux_anya[0], $cel_bolygo['id']));
			//a tamado flottat ide kotni
			$bt02 = $gs->pdo->prepare('update flottak set bolygo=?,statusz=1 where id=?');
			$bt02->execute(array($cel_bolygo['id'], $foglalo_flotta['id']));
			//bolygo_tulaj_valtozas
			$szimClass->planetOwnerChange($cel_bolygo['id'],$cel_bolygo['tulaj'],$foglalo_flotta['tulaj'],$cel_bolygo['tulaj_szov'],$foglalo_flotta['tulaj_szov']);
			//ha easter egg, akkor felturbozni (bolygo_reset_oriasira fuggveny jelenleg (s8) nincs is)
			//if ($cel_bolygo['uccso_emberi_tulaj']==0) if (in_array($cel_bolygo['id'],array(8007,12733,6487,1310,9670,3555,3970))) bolygo_reset_oriasira($cel_bolygo['id']);
		} else {//szuperfosztas
			/********************************************************************************/
			if ($cel_bolygo['tulaj']>0) {//jatekos bolygo -> szuperfosztas
/********************************************************************************************************************************************/
				//egy-ket alap cucc
				$vj01 = $gs->pdo->prepare('select * from userek where id=?');
				$vj01->execute(array($cel_bolygo['tulaj']));
				$vedo_jatekos = $vj01->fetch(PDO::FETCH_BOTH);

				$au01 = $gs->pdo->prepare('select sum(egyenertek),sum(pontertek) from flottak where x=? and y=? and cel_bolygo=? and (statusz=8 or statusz=10)');
				$au01->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));
				$aux = $au01->fetch(PDO::FETCH_BOTH);

				$teljes_tamado_egyenertek=$aux[0];$teljes_tamado_pontertek=$aux[1];
				//zsakmany es veszteseg szazalekok
				$b=$cel_bolygo['vedelmi_bonusz']/1000;
				$maximalis_fosztas_szazalek=$maximalis_fosztas_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)];
				//SHY-fosztas
				$tv01 = $gs->pdo->prepare('select vagyon from userek where id=?');
				$tv01->execute(array($cel_bolygo['tulaj']));
				$tv01 = $tv01->fetch(PDO::FETCH_BOTH);
				$tulaj_vagyona = $tv01[0];

				$bep = $gs->pdo->prepare('select sum(pontertek) from bolygok where tulaj='.$cel_bolygo['tulaj']);
				$bep->execute(array($cel_bolygo['tulaj']));
				$bep = $bep->fetch(PDO::FETCH_BOTH);
				$bolygora_eso_penz=round($cel_bolygo['pontertek']/$bep[0]*$tulaj_vagyona);
				if ($bolygora_eso_penz>0) $penz_fosztas_szazalek=10*$teljes_tamado_pontertek/$bolygora_eso_penz; else $penz_fosztas_szazalek=0;//a pontertek SHY-ban merve, ezert osszevethetok, szuperfosztas -> a flotta ertekenek 10-szerese szamit
				if ($penz_fosztas_szazalek>$maximalis_fosztas_szazalek) $penz_fosztas_szazalek=$maximalis_fosztas_szazalek;
				$penz_zsakmany_szazalek=(1-$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)])*$penz_fosztas_szazalek;
				$penz_veszteseg_szazalek=$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)]*$penz_fosztas_szazalek;
				//
				if ($cel_bolygo['keszlet_pontertek']>0) $fosztas_szazalek=10*$teljes_tamado_pontertek/$cel_bolygo['keszlet_pontertek'];else $fosztas_szazalek=0;//szuperfosztas -> a flotta ertekenek 10-szerese szamit
				if ($fosztas_szazalek>$maximalis_fosztas_szazalek) $fosztas_szazalek=$maximalis_fosztas_szazalek;
				$zsakmany_szazalek=(1-$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)])*$fosztas_szazalek;
				$veszteseg_szazalek=$veszteseg_tablazat_a_vedelmi_pont_fuggvenyeben[floor($cel_bolygo['vedelmi_bonusz']/200)]*$fosztas_szazalek;
				//epuletek lebontasa

				$er03 = $gs->pdo->prepare('select gyar_id,db,aktiv_db,round(?*db) as lebont_db from bolygo_gyar where bolygo_id=?');
				$er03->execute(array($fosztas_szazalek, $cel_bolygo['id']));
				foreach($er03->fetchAll(PDO::FETCH_BOTH) as $gyar){
					//megadott szamu epulet elbontasa
					if ($gyar['db']>$gyar['lebont_db']) {
						$bgy01 = $gs->pdo->prepare('update bolygo_gyar set db=if(db>?,db-?,0) where bolygo_id=? and gyar_id=?');
						$bgy01->execute(array($gyar['lebont_db'], $gyar['lebont_db'], $cel_bolygo['id'], $gyar['gyar_id']));

						$bgy02 = $gs->pdo->prepare('update bolygo_gyar set aktiv_db=least(db,aktiv_db) where bolygo_id=? and gyar_id=?');
						$bgy02->execute(array($cel_bolygo['id'], $gyar['gyar_id']));
					} else {
						$bgy03 = $gs->pdo->prepare('delete from bolygo_gyar where bolygo_id=? and gyar_id=?');
						$bgy03->execute(array($cel_bolygo['id'], $gyar['gyar_id']));

						$bgy04 = $gs->pdo->prepare('delete from bolygo_gyar_eroforras where bolygo_id=? and gyar_id=?');
						$bgy04->execute(array($cel_bolygo['id'], $gyar['gyar_id']));
					}
					//nyersik (epitoanyag fele) visszaadasa
					$bgy05 = $gs->pdo->prepare('update gyar_epitesi_koltseg gyek,gyarak gy,bolygo_eroforras be set be.db=be.db+?*gyek.db/2 where gyek.tipus=gy.tipus and gy.id=? and gyek.szint=gy.szint and gyek.eroforras_id=be.eroforras_id and be.bolygo_id=?');
					$bgy05->execute(array($gyar['lebont_db'], $gyar['gyar_id'], $cel_bolygo['id']));
				}

										$szimClass->planetFactoryResources($cel_bolygo['id']);

				//1. mindenfele keszleteket osszeszamolni (nyersi, penz, ajanlatok)
				unset($keszletek);
				$r10 = $gs->pdo->prepare('select e.id
,vedo.db,coalesce(sum(a.mennyiseg),0) as ajanlott_db,vedo.db+coalesce(sum(a.mennyiseg),0) as ossz_db
,e.mertekegyseg,e.nev,e.mertekegyseg_en,e.nev_en
from bolygo_eroforras vedo
inner join eroforrasok e on e.id=vedo.eroforras_id
left join szabadpiaci_ajanlatok a on a.termek_id=e.id and a.user_id=? and a.bolygo_id=? and a.vetel=0
where vedo.bolygo_id=? and e.tipus=2 and e.szallithato=1
group by e.id');
				$r10->execute(array($cel_bolygo['tulaj'], $cel_bolygo['id'], $cel_bolygo['id']));

				foreach($r10->fetchAll(PDO::FETCH_BOTH) as $aux) $keszletek[$aux[0]]=$aux;
				$vagyon=$vedo_jatekos['vagyon'];
				$ea01 = $gs->pdo->prepare('select sum(mennyiseg*arfolyam) from szabadpiaci_ajanlatok where bolygo_id=? and user_id=? and vetel=1');
				$ea01->execute(array($cel_bolygo['id'], $cel_bolygo['tulaj']));
				$ea01 = $ea01->fetch(PDO::FETCH_BOTH);
				$eladasi_ajanlatok = $szimClass->sanitint($ea01[0]);
				$keszletek[0]=array(
					'id'=>0
					,'db'=>$vagyon
					,'ajanlott_db'=>$eladasi_ajanlatok
					,'ossz_db'=>$vagyon+$eladasi_ajanlatok
					,'mertekegyseg'=>'SHY'
					,'nev'=>'pénz'
					,'mertekegyseg_en'=>'SHY'
					,'nev_en'=>'money'
				);
				//foszto flottakon vegig
				$erf01 = $gs->pdo->prepare('select f.*,u.nev as tulaj_nev,b.nev as bazis_nev,u.nyelv as tulaj_nyelv,u2.nev as iranyito_nev,u2.nyelv as iranyito_nyelv,if(u.karrier=3 and u.speci=3,1,0) as fantom_tamado,u.helyezes
from flottak f
inner join userek u on u.id=f.tulaj
inner join bolygok b on b.id=f.bazis_bolygo
left join userek u2 on u2.id=f.uccso_parancs_by
where f.x=? and f.y=? and f.cel_bolygo=? and (f.statusz=8 or f.statusz=10)
order by f.tulaj,f.id');
				$erf01->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));


				if ($erf01->rowCount()>0) {
					//2. zsakmanyok, vesztesegek, ertesitesek, transzferek
					foreach($erf01->fetchAll(PDO::FETCH_BOTH) as $foszto_flotta){
						//fantom tamado egy bolygoja lebukik
						$forras_info='';$forras_info_en='';
						if ($fantom_lebukas) if ($foszto_flotta['fantom_tamado']) {
							$rb01 = $gs->pdo->prepare('select * from bolygok where tulaj=? order by rand() limit 1');
							$rb01->execute(array($foszto_flotta['tulaj']));

							$random_bolygo= $rb01->fetch(PDO::FETCH_BOTH);
								if ($random_bolygo) list($forras_info,$forras_info_en) = $szimClass->fantomPlanet($random_bolygo,$foszto_flotta);
						}
						//reszflottak
						$szimClass->otherFleetsRefresh($foszto_flotta['id']);
						$ver01 = $gs->pdo->prepare('select coalesce(count(distinct user_id),0) from resz_flotta_hajo where flotta_id=?');
						$ver01->execute(array($foszto_flotta['id']));
						$ver01 = $ver->fetch(PDO::FETCH_BOTH);
						$vannak_e_reszflottai_a_fosztonak = $szimClass->sanitint($ver01[0]);

						if ($vannak_e_reszflottai_a_fosztonak>1) {//foszto flotta resztulajdonosokkal
							$iranyito_kapott_e=false;
							//reszeken vegigmenni
							$ver02 = $gs->pdo->prepare('select user_id,coalesce(sum(rfh.hp/100*h.ar),0) as egyenertek from resz_flotta_hajo rfh, hajok h where rfh.hajo_id=h.id and rfh.flotta_id=? group by user_id');
							$ver02->execute(array($foszto_flotta['id']));
							foreach($ver02->fetchAll(PDO::FETCH_BOTH) as $resz_flotta){
								//bazis = jobb hijan az elso bolygo
								$bb01 = $gs->pdo->prepare('select * from bolygok where tulaj=? order by uccso_foglalas_mikor limit 1');
								$bb01->execute(array($resz_flotta['user_id']));
								$bazis_bolygo = $bb01->fetch(PDO::FETCH_BOTH);
								//zsakmany
								if ($teljes_tamado_pontertek>0 && $foszto_flotta['egyenertek']>0) {
									$zsakmany_mennyisege_szazalekban=$resz_flotta['egyenertek']/$foszto_flotta['egyenertek']*$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$zsakmany_szazalek;
									$penz_zsakmany_mennyisege_szazalekban=$resz_flotta['egyenertek']/$foszto_flotta['egyenertek']*$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_zsakmany_szazalek;
								} else {
									$zsakmany_mennyisege_szazalekban=0;
									$penz_zsakmany_mennyisege_szazalekban=0;
								}
								//resztulaj
								$zsakmany_hu='';$zsakmany_en='';
								foreach($keszletek as $ef_id=>$keszlet) {
									if ($ef_id>0) {
										$x=round($zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
										if ($x>0) {
											if ($zsakmany_hu!='') {$zsakmany_hu.=', ';$zsakmany_en.=', ';}
											$zsakmany_hu.=$x.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
											$zsakmany_en.=$x.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
											$be10 = $gs->pdo->prepare('update bolygo_eroforras set db=db+? where bolygo_id=? and eroforras_id=?');
											$be10->execute(array($x, $bazis_bolygo['id'], $ef_id));
										}
									} else {
										$x=round($penz_zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
										if ($x>0) {
											if ($zsakmany_hu!='') {$zsakmany_hu.=' és ';$zsakmany_en.=' and ';}
											$zsakmany_hu.=$x.' SHY';
											$zsakmany_en.=$x.' SHY';
											$be11 = $gs->pdo->prepare('update userek set vagyon=vagyon+? where id=?');
											$be11->execute(array($x, $resz_flotta['user_id']));
										}
									}
								}
								//
								if ($zsakmany_hu!='') {
									$zsakmany_szoveg=strtr($lang['hu']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$bazis_bolygo['nev'],'YYY'=>$zsakmany_hu));
									$zsakmany_szoveg_en=strtr($lang['en']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$bazis_bolygo['nev'],'YYY'=>$zsakmany_en));
								} else {
									$zsakmany_szoveg='';
									$zsakmany_szoveg_en='';
								}
								if ($resz_flotta['user_id']==$foszto_flotta['uccso_parancs_by']) {
									$iranyito_kapott_e=true;
									$szimClass->systemMessage($resz_flotta['user_id']
										,$lang['hu']['kisphpk']['Szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
										,$lang['en']['kisphpk']['Szuperfosztás'],strtr($lang['en']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
									);
								} else {
									$szimClass->systemMessage($resz_flotta['user_id']
										,$lang['hu']['kisphpk']['Szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX flottáddal (WWW irányításával) szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
										,$lang['en']['kisphpk']['Szuperfosztás'],strtr($lang['en']['kisphpk']['XXX flottáddal (WWW irányításával) szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
									);
								}
								//
							}
							//zsakmany es veszteseg osszesitve
							//zsakmany
							if ($teljes_tamado_pontertek>0) {
								$zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$zsakmany_szazalek;
								$veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$veszteseg_szazalek;
								$penz_zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_zsakmany_szazalek;
								$penz_veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_veszteseg_szazalek;
							} else {
								$zsakmany_mennyisege_szazalekban=0;
								$veszteseg_mennyisege_szazalekban=0;
								$penz_zsakmany_mennyisege_szazalekban=0;
								$penz_veszteseg_mennyisege_szazalekban=0;
							}
							//
							$zsakmany_hu='';$zsakmany_en='';
							$veszteseg_hu='';$veszteseg_en='';
							foreach($keszletek as $ef_id=>$keszlet) {
								if ($ef_id>0) {
									$x=round($zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
									if ($x>0) {
										if ($zsakmany_hu!='') {$zsakmany_hu.=', ';$zsakmany_en.=', ';}
										$zsakmany_hu.=$x.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
										$zsakmany_en.=$x.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
									}
									//
									$y=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
									if ($y>0) {
										if ($veszteseg_hu!='') {$veszteseg_hu.=', ';$veszteseg_en.=', ';}
										$veszteseg_hu.=$y.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
										$veszteseg_en.=$y.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
									}
									$y1=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['db']);
									if ($y1>0){
										$be12 = $gs->pdo->prepare('update bolygo_eroforras set db=if(db>?,db-?,0) where bolygo_id=? and eroforras_id=?');
										$be12->execute(array($y1, $y1, $cel_bolygo['id'], $ef_id));
									}
								} else {
									$x=round($penz_zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
									if ($x>0) {
										if ($zsakmany_hu!='') {$zsakmany_hu.=' és ';$zsakmany_en.=' and ';}
										$zsakmany_hu.=$x.' SHY';
										$zsakmany_en.=$x.' SHY';
									}
									$y=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
									if ($y>0) {
										if ($veszteseg_hu!='') {$veszteseg_hu.=' és ';$veszteseg_en.=' and ';}
										$veszteseg_hu.=$y.' SHY';
										$veszteseg_en.=$y.' SHY';
									}
									$y1=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['db']);
									if ($y1>0){
										$be13 = $gs->pdo->prepare('update userek set vagyon=if(vagyon>?,vagyon-?,0) where id=?');
										$be13->execute(array($y1, $y1, $cel_bolygo['tulaj']));
									}
								}
							}
							//ajanlatok
							$sza5 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=1');
							$sza5->execute(array($zsakmany_mennyisege_szazalekban, $veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));

							$sza6 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=0');
							$sza6->execute(array($penz_zsakmany_mennyisege_szazalekban, $penz_veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));
							//
							if ($zsakmany_hu!='') {
								$zsakmany_szoveg=strtr($lang['hu']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_hu));
								$zsakmany_szoveg_en=strtr($lang['en']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_en));
							} else {
								$zsakmany_szoveg='';
								$zsakmany_szoveg_en='';
							}
							if ($veszteseg_hu!='') {
								$veszteseg_szoveg=strtr($lang['hu']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_hu));
								$veszteseg_szoveg_en=strtr($lang['en']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_en));
							} else {
								$veszteseg_szoveg='';
								$veszteseg_szoveg_en='';
							}
							//iranyito ertesitese
							if (!$iranyito_kapott_e)
								$szimClass->systemMessage($foszto_flotta['uccso_parancs_by']
								,$lang['hu']['kisphpk']['Szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
								,$lang['en']['kisphpk']['Szuperfosztás'],strtr($lang['en']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
							);
							//bolygo_tulaj ertesitese
							if ($foszto_flotta['tulaj']==$foszto_flotta['uccso_parancs_by']) {
								$szimClass->systemMessage($cel_bolygo['tulaj']
									,$lang['hu']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
									,$lang['en']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
								);
							} else {
								$szimClass->systemMessage($cel_bolygo['tulaj']
									,$lang['hu']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával (WWW irányításával) szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
									,$lang['en']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával (WWW irányításával) szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
								);
							}
						} else {//foszto flotta egy tulajdonossal
							//zsakmany
							if ($teljes_tamado_pontertek>0) {
								$zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$zsakmany_szazalek;
								$veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$veszteseg_szazalek;
								$penz_zsakmany_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_zsakmany_szazalek;
								$penz_veszteseg_mennyisege_szazalekban=$foszto_flotta['pontertek']/$teljes_tamado_pontertek*$penz_veszteseg_szazalek;
							} else {
								$zsakmany_mennyisege_szazalekban=0;
								$veszteseg_mennyisege_szazalekban=0;
								$penz_zsakmany_mennyisege_szazalekban=0;
								$penz_veszteseg_mennyisege_szazalekban=0;
							}
							//
							$zsakmany_hu='';$zsakmany_en='';
							$veszteseg_hu='';$veszteseg_en='';
							foreach($keszletek as $ef_id=>$keszlet) {
								if ($ef_id>0) {
									$x=round($zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
									if ($x>0) {
										if ($zsakmany_hu!='') {$zsakmany_hu.=', ';$zsakmany_en.=', ';}
										$zsakmany_hu.=$x.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
										$zsakmany_en.=$x.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
										$be14 = $gs->pdo->prepare('update bolygo_eroforras set db=db+? where bolygo_id=? and eroforras_id=?');
										$be14->execute(array($x, $foszto_flotta['bazis_bolygo'], $ef_id));
									}
									//
									$y=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
									if ($y>0) {
										if ($veszteseg_hu!='') {$veszteseg_hu.=', ';$veszteseg_en.=', ';}
										$veszteseg_hu.=$y.' '.$keszlet['mertekegyseg'].' '.$keszlet['nev'];
										$veszteseg_en.=$y.' '.$keszlet['mertekegyseg_en'].' of '.$keszlet['nev_en'];
									}
									$y1=round(($zsakmany_mennyisege_szazalekban+$veszteseg_mennyisege_szazalekban)*$keszlet['db']);
									if ($y1>0){
										$be15 = $gs->pdo->prepare('update bolygo_eroforras set db=if(db>?,db-?,0) where bolygo_id=? and eroforras_id=?');
										$be15->execute(array($y1, $y1, $cel_bolygo['id'], $ef_id));
									}
								} else {
									$x=round($penz_zsakmany_mennyisege_szazalekban*$keszlet['ossz_db']);
									if ($x>0) {
										if ($zsakmany_hu!='') {$zsakmany_hu.=' és ';$zsakmany_en.=' and ';}
										$zsakmany_hu.=$x.' SHY';
										$zsakmany_en.=$x.' SHY';
										$be16 = $gs->pdo->prepare('update userek set vagyon=vagyon+? where id=?');
										$be16->execute(array($x, $foszto_flotta['tulaj']));
									}
									$y=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['ossz_db']);
									if ($y>0) {
										if ($veszteseg_hu!='') {$veszteseg_hu.=' és ';$veszteseg_en.=' and ';}
										$veszteseg_hu.=$y.' SHY';
										$veszteseg_en.=$y.' SHY';
									}
									$y1=round(($penz_zsakmany_mennyisege_szazalekban+$penz_veszteseg_mennyisege_szazalekban)*$keszlet['db']);
									if ($y1>0){
										$be17 = $gs->pdo->prepare('update userek set vagyon=if(vagyon>?,vagyon-?,0) where id=?');
										$be17->execute(array($y1, $y1, $cel_bolygo['tulaj']));
									}
								}
							}
							//ajanlatok
							$sza7 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=1');
							$sza7->execute(array($zsakmany_mennyisege_szazalekban, $veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));

							$sza8 = $gs->pdo->prepare('update szabadpiaci_ajanlatok set mennyiseg=greatest(mennyiseg-round(?+?*mennyiseg),0) where user_id=? and bolygo_id=? and vetel=0');
							$sza9->execute(array($penz_zsakmany_mennyisege_szazalekban, $penz_veszteseg_mennyisege_szazalekban, $cel_bolygo['tulaj'], $cel_bolygo['id']));
							//
							if ($zsakmany_hu!='') {
								$zsakmany_szoveg=strtr($lang['hu']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$foszto_flotta['bazis_nev'],'YYY'=>$zsakmany_hu));
								$zsakmany_szoveg_en=strtr($lang['en']['kisphpk'][' A zsákmány (YYY) a flotta bázisára (XXX) került.'],array('XXX'=>$foszto_flotta['bazis_nev'],'YYY'=>$zsakmany_en));
								$zsakmany_szoveg_iranyito=strtr($lang['hu']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_hu));
								$zsakmany_szoveg_iranyito_en=strtr($lang['en']['kisphpk'][' A zsákmány: YYY.'],array('YYY'=>$zsakmany_en));
							} else {
								$zsakmany_szoveg='';
								$zsakmany_szoveg_en='';
								$zsakmany_szoveg_iranyito='';
								$zsakmany_szoveg_iranyito_en='';
							}
							if ($veszteseg_hu!='') {
								$veszteseg_szoveg=strtr($lang['hu']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_hu));
								$veszteseg_szoveg_en=strtr($lang['en']['kisphpk'][' A veszteséged: YYY.'],array('YYY'=>$veszteseg_en));
							} else {
								$veszteseg_szoveg='';
								$veszteseg_szoveg_en='';
							}
							//
							if ($foszto_flotta['tulaj']==$foszto_flotta['uccso_parancs_by']) {
								//tulaj = iranyito ertesitese
								$szimClass->systemMessage($foszto_flotta['tulaj']
									,$lang['hu']['kisphpk']['Szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
									,$lang['en']['kisphpk']['Szuperfosztás'],strtr($lang['en']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
								);
								//bolygo_tulaj ertesitese
								$szimClass->systemMessage($cel_bolygo['tulaj']
									,$lang['hu']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
									,$lang['en']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
								);
							} else {
								//tulaj ertesitese
								$szimClass->systemMessage($foszto_flotta['tulaj']
									,$lang['hu']['kisphpk']['Szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX flottáddal (WWW irányításával) szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg
									,$lang['en']['kisphpk']['Szuperfosztás'],strtr($lang['en']['kisphpk']['XXX flottáddal (WWW irányításával) szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_en
								);
								//iranyito ertesitese
								$szimClass->systemMessage($foszto_flotta['uccso_parancs_by']
									,$lang['hu']['kisphpk']['Szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$zsakmany_szoveg_iranyito
									,$lang['en']['kisphpk']['Szuperfosztás'],strtr($lang['en']['kisphpk']['XXX flottáddal szuperfosztottad YYY ZZZ (POZ) bolygóját.'],array('XXX'=>$foszto_flotta['nev'],'YYY'=>$vedo_jatekos['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$zsakmany_szoveg_iranyito_en
								);
								//bolygo_tulaj ertesitese
								$szimClass->systemMessage($cel_bolygo['tulaj']
									,$lang['hu']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['hu']['kisphpk']['XXX YYY flottájával (WWW irányításával) szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz)).$veszteseg_szoveg.$forras_info
									,$lang['en']['kisphpk']['Ellenséges szuperfosztás'],strtr($lang['en']['kisphpk']['XXX YYY flottájával (WWW irányításával) szuperfosztotta ZZZ (POZ) bolygódat.'],array('XXX'=>$foszto_flotta['tulaj_nev'],'YYY'=>$foszto_flotta['nev'],'WWW'=>$foszto_flotta['iranyito_nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en)).$veszteseg_szoveg_en.$forras_info_en
								);
							}
						}
					}
				}
/********************************************************************************************************************************************/
			} else {//npc bolygo -> csak moralcsokkenes
				//ertesites, hogy utottel az npc-n
				$er04 = $gs->pdo->prepare('select * from flottak where x=? and y=? and cel_bolygo=? and (statusz=8 or statusz=10) and tulaj>0 order by tulaj,id');
				$er04->execute(array($cel_bolygo['x'], $cel_bolygo['y'], $cel_bolygo['id']));
				$elozo_tulaj=0;
				foreach($er04->fetchAll(PDO::FETCH_BOTH) as $foszto_flotta){
					if ($foszto_flotta['tulaj']!=$elozo_tulaj) {
						$elozo_tulaj=$foszto_flotta['tulaj'];

						$szimClass->systemMessage($foszto_flotta['tulaj']
						,$lang['hu']['kisphpk']['NPC-ütés'],strtr($lang['hu']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) npc bolygón. De csak akkor lehet a tied, ha belefér a bolygólimitedbe.'],array('XXX'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz))
						,$lang['en']['kisphpk']['NPC-ütés'],strtr($lang['en']['kisphpk']['XXX flottáddal ütöttél egyet ZZZ (POZ) npc bolygón. De csak akkor lehet a tied, ha belefér a bolygólimitedbe.'],array('XXX'=>$foszto_flotta['nev'],'ZZZ'=>$cel_bolygo['nev'],'POZ'=>$poz_en))
						);
					}
				}
			}
			/********************************************************************************/
		}
		$er05 = $gs->pdo->prepare('update bolygok set moratorium_mikor_jar_le=? where id=?');
		$er05->execute(array(date('Y-m-d H:i:s',time()+1200), $cel_bolygo['id']));
	}
}



if (count($ostromok_listaja)>0) {
	/*$most=date('Y-m-d H:i:s');
	mysql_select_db($database_mmog_nemlog);
	foreach($ostromok_listaja as $ostrom) {
		mysql_query('insert into ostromok (tipus,flotta_id,flotta_tulaj,flotta_tulaj_szov,bolygo_id,bolygo_tulaj,bolygo_tulaj_szov,mikor) values('.$ostrom[0].','.$ostrom[1].','.$ostrom[2].','.$ostrom[3].','.$ostrom[4].','.$ostrom[5].','.$ostrom[6].',"'.$most.'")');
	}
	mysql_select_db($database_mmog);*/
}

$er06 = $gs->pdo->prepare('update ido set idopont_ostromok=idopont_ostromok+1')->execute();
$szimlog_hossz_ostromok=round(1000*(microtime(true)-$mikor_indul));
/******************************************************** OSTROMOK VEGE ******************************************************************/


/************************************** FOG OF WAR ELEJE *****************************************************************/
//flotta_atrendezes.php-ban es flotta_kivonasa.php-ban is megfeleloen igazitani!

if ($fog_of_war) {

	$gs->pdo->prepare('update bolygok b, bolygo_eroforras be
set b.latotav=if(be.db>0,500,0)
where be.eroforras_id=75 and be.bolygo_id=b.id')->execute();

	$gs->pdo->prepare('lock tables lat_user_flotta write, lat_szov_flotta write, hexa_flotta write, hexa_flotta hf read, flottak f read, bolygok bu read, flottak fu read, hexa_bolygo hb read, hexa_kor hk read, resz_flotta_aux write, resz_flotta_hajo read, resz_flotta_aux rfa read')->execute();

	$gs->pdo->prepare('delete from resz_flotta_aux')->execute();

	$gs->pdo->prepare('insert ignore into resz_flotta_aux (flotta_id,user_id)
select distinct flotta_id,user_id from resz_flotta_hajo')->execute();

	$gs->pdo->prepare('delete from hexa_flotta')->execute();//a lock miatt nem lehet truncate!!!
	$gs->pdo->prepare('insert into hexa_flotta (x,y,id)
select f.hexa_x+hk.x,f.hexa_y+hk.y,f.id
from flottak f, hexa_kor hk
where tulaj!=0
and f.latotav>=hk.r')->execute();

	$gs->pdo->prepare('delete from lat_user_flotta')->execute();//a lock miatt nem lehet truncate!!!

//bolygo tulaj lat flottat
	$gs->pdo->prepare('insert into lat_user_flotta (uid,fid,lathatosag)
select bu.tulaj,f.id,if(sqrt(pow(bu.x-f.x,2)+pow(bu.y-f.y,2))<=bu.latotav-f.rejtozes,2,1)
from flottak f, bolygok bu, hexa_bolygo hb
where bu.id=hb.id and bu.tulaj!=0
and f.hexa_x=hb.x and f.hexa_y=hb.y
and sqrt(pow(bu.x-f.x,2)+pow(bu.y-f.y,2))/2<=bu.latotav-f.rejtozes
on duplicate key update lathatosag=greatest(lathatosag,if(sqrt(pow(bu.x-f.x,2)+pow(bu.y-f.y,2))<=bu.latotav-f.rejtozes,2,1))')->execute();

//flotta tulaj lat flottat (nem kell tulaj>0-ra szurni, mert hexa_flotta-t csak a tulaj>0-k kapnak (ld fentebb))
	$gs->pdo->prepare('insert into lat_user_flotta (uid,fid,lathatosag)
select fu.tulaj,f.id,if(sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))<=fu.latotav-f.rejtozes,2,1)
from flottak f, flottak fu, hexa_flotta hf
where fu.id=hf.id
and f.hexa_x=hf.x and f.hexa_y=hf.y
and sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))/2<=fu.latotav-f.rejtozes
on duplicate key update lathatosag=greatest(lathatosag,if(sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))<=fu.latotav-f.rejtozes,2,1))')->execute();

//reszflotta tulaj lat flottat
	$gs->pdo->prepare('insert into lat_user_flotta (uid,fid,lathatosag)
select rfa.user_id,f.id,if(sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))<=fu.latotav-f.rejtozes,2,1)
from flottak f, flottak fu, resz_flotta_aux rfa, hexa_flotta hf
where fu.id=hf.id
and f.hexa_x=hf.x and f.hexa_y=hf.y
and sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))/2<=fu.latotav-f.rejtozes
and rfa.flotta_id=fu.id
on duplicate key update lathatosag=greatest(lathatosag,if(sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))<=fu.latotav-f.rejtozes,2,1))')->execute();

//sajat flottak
	$gs->pdo->prepare('insert into lat_user_flotta (uid,fid,lathatosag)
select f.tulaj,f.id,2 from flottak f where f.tulaj!=0
on duplicate key update lathatosag=2')->execute();

	$gs->pdo->prepare('delete from lat_szov_flotta')->execute();//a lock miatt nem lehet truncate!!!

//bolygo szovije lat flottat
	$gs->pdo->prepare('insert into lat_szov_flotta (szid,fid,lathatosag)
select bu.tulaj_szov,f.id,if(sqrt(pow(bu.x-f.x,2)+pow(bu.y-f.y,2))<=bu.latotav-f.rejtozes,2,1)
from flottak f, bolygok bu, hexa_bolygo hb
where bu.id=hb.id and bu.tulaj_szov!=0
and f.hexa_x=hb.x and f.hexa_y=hb.y
and sqrt(pow(bu.x-f.x,2)+pow(bu.y-f.y,2))/2<=bu.latotav-f.rejtozes
on duplicate key update lathatosag=greatest(lathatosag,if(sqrt(pow(bu.x-f.x,2)+pow(bu.y-f.y,2))<=bu.latotav-f.rejtozes,2,1))')->execute();

//flotta szovije lat flottat
	$gs->pdo->prepare('insert into lat_szov_flotta (szid,fid,lathatosag)
select fu.tulaj_szov,f.id,if(sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))<=fu.latotav-f.rejtozes,2,1)
from flottak f, flottak fu, hexa_flotta hf
where fu.id=hf.id and fu.tulaj_szov!=0
and f.hexa_x=hf.x and f.hexa_y=hf.y
and sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))/2<=fu.latotav-f.rejtozes
on duplicate key update lathatosag=greatest(lathatosag,if(sqrt(pow(fu.x-f.x,2)+pow(fu.y-f.y,2))<=fu.latotav-f.rejtozes,2,1))')->execute();

//sajat flottak
	$gs->pdo->prepare('insert into lat_szov_flotta (szid,fid,lathatosag)
select f.tulaj_szov,f.id,2 from flottak f where f.tulaj_szov!=0
on duplicate key update lathatosag=2')->execute();

	$gs->pdo->prepare('unlock tables')->execute();

}
$gs->pdo->prepare('update ido set idopont_fog=idopont_fog+1')->execute();
$szimlog_hossz_fog=round(1000*(microtime(true)-$mikor_indul));
/************************************** FOG OF WAR VEGE *****************************************************************/



/************************************** TUT_LEVEL ES TECH_SZINT ELEJE *****************************************************************/

//2: fa<50k es ko<50k -> 3. level
	$r07 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv,b.id as bolygo_id,b.x as bolygo_x,b.y as bolygo_y
from userek u
inner join bolygok b on b.tulaj=u.id
inner join bolygo_eroforras be1 on be1.bolygo_id=b.id and be1.eroforras_id=64
inner join bolygo_eroforras be2 on be2.bolygo_id=b.id and be2.eroforras_id=65
where u.tut_level=2
group by u.id
having coalesce(sum(be1.db),0)<50000 and coalesce(sum(be1.db),0)<50000');
	$r07->execute();
	foreach($r07->fetchAll(PDO::FETCH_BOTH) as $aux){
	$szimClass->tutorialMessages($aux[0],3,array($aux[1],$aux[2]));
	//kalozok
		$r08 = $gs->pdo->prepare('update userek set tut_kaloz=1 where id=?');
		$r08->execute(array($aux[0]));

	if ($aux[2]=='hu') $kaloz_nev='Kalóz';else $kaloz_nev='Pirate';

		$szimClass->newPirateFleet($aux['bolygo_x']-80,$aux['bolygo_y']+20,$aux['bolygo_id'],$kaloz_nev.'-1',array(206=>1));
		$szimClass->newPirateFleet($aux['bolygo_x']+60,$aux['bolygo_y']-40,$aux['bolygo_id'],$kaloz_nev.'-2',array(206=>1));
		$szimClass->newPirateFleet($aux['bolygo_x']-10,$aux['bolygo_y']-50,$aux['bolygo_id'],$kaloz_nev.'-3',array(206=>1));
		$szimClass->newPirateFleet($aux['bolygo_x']+10,$aux['bolygo_y']+70,$aux['bolygo_id'],$kaloz_nev.'-4',array(206=>1,204=>1));

}

//ha valaki mashogy epitkezett, az is kapjon kalozokat
	$r09 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv,b.id as bolygo_id,b.x as bolygo_x,b.y as bolygo_y
from userek u
inner join bolygok b on b.tulaj=u.id
where u.tut_level>3 and u.tut_kaloz=0 and u.tut_kalozjutalom=0
group by u.id');
	$r09->execute();
	foreach($r09->fetchAll(PDO::FETCH_BOTH) as $aux){
	$szimClass->tutorialMessages($aux[0],3,array($aux[1],$aux[2]));
	//kalozok
		$r10 = $gs->pdo->prepare('update userek set tut_kaloz=1 where id=?');
		$r10->execute(array($aux[0]));	if ($aux[2]=='hu') $kaloz_nev='Kalóz';else $kaloz_nev='Pirate';

		$szimClass->newPirateFleet($aux['bolygo_x']-80,$aux['bolygo_y']+20,$aux['bolygo_id'],$kaloz_nev.'-1',array(206=>1));
		$szimClass->newPirateFleet($aux['bolygo_x']+60,$aux['bolygo_y']-40,$aux['bolygo_id'],$kaloz_nev.'-2',array(206=>1));
		$szimClass->newPirateFleet($aux['bolygo_x']-10,$aux['bolygo_y']-50,$aux['bolygo_id'],$kaloz_nev.'-3',array(206=>1));
		$szimClass->newPirateFleet($aux['bolygo_x']+10,$aux['bolygo_y']+70,$aux['bolygo_id'],$kaloz_nev.'-4',array(206=>1,204=>1));

}

//kalozok lelovese
	$r11 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv,b.id as bolygo_id
from userek u
inner join bolygok b on b.tulaj=u.id
left join flottak f on f.kaloz_bolygo_id=b.id and f.tulaj=0
where u.tut_kaloz=1 and u.tut_kalozjutalom=0
group by u.id
having count(f.id)=0');
	$r11->execute();
	foreach($r11->fetchAll(PDO::FETCH_BOTH) as $aux){

	if ($aux[2]=='hu') {
		$uzi="Kedves ".$aux[1]."!\n\n";
		$uzi.="Gratulálunk! Minden kalóztól megszabadultál a környéken. Veszteségeid pótlására a jutalom 1000 félvezető.";
		$uzi.="\n\n\nZandagort és népe";
		$uzi.="\n\n".$zanda_ingame_msg_ps['hu'];
		$szimClass->systemMessageFromCentralOffice($aux[0],'Jutalom',$uzi,'hu');
	} else {
		$uzi="Dear ".$aux[1]."!\n\n";
		$uzi.="Congratulations! You got rid of all pirates around. To compensate for your losses you get 1000 units of chip as bounty.";
		$uzi.="\n\n\nZandagort and his people";
		$uzi.="\n\n".$zanda_ingame_msg_ps['en'];
		$szimClass->systemMessageFromCentralOffice($aux[0],'Bounty',$uzi,'en');
	}
	$r12 = $gs->pdo->prepare('update bolygo_eroforras set db=db+1000 where bolygo_id=? and eroforras_id=73');
		$r12->execute(array($aux['bolygo_id']));

	$r13 = $gs->pdo->prepare('update userek set tut_kalozjutalom=1,tut_kaloz=0 where id=?');
		$r13->execute(array($aux[0]));
}

//4: 15 perc eltelt a tut_uccso_level ota -> 5. level
	$r14 = $gs->pdo->prepare('select id,nev,nyelv from userek where tut_level=4 and timestampdiff(minute,tut_uccso_level,now())>15');
	$r14->execute();
	foreach($r14->fetchAll(PDO::FETCH_BOTH) as $aux){
		$szimClass->tutorialMessages($aux[0],5,array($aux[1],$aux[2]));
	}


//6: titanmu elkezd epulni -> 7. level
	$r15 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv
from userek u
inner join bolygok b on b.tulaj=u.id
inner join cron_tabla ct on ct.bolygo_id=b.id and ct.gyar_id=52
where u.tut_level=6
group by u.id');
	$r15->execute();
	foreach($r15->fetchAll(PDO::FETCH_BOTH) as $aux){
		$szimClass->tutorialMessages($aux[0],7,array($aux[1],$aux[2]));
	}

//8: 4 uveggyar -> 9. level
	$r16 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv
from userek u
inner join bolygok b on b.tulaj=u.id
inner join bolygo_gyar bgy on bgy.bolygo_id=b.id and bgy.gyar_id=54
where u.tut_level=8
group by u.id
having coalesce(sum(bgy.db),0)>=4');
	$r16->execute();
	foreach($r16->fetchAll(PDO::FETCH_BOTH) as $aux){
		$szimClass->tutorialMessages($aux[0],9,array($aux[1],$aux[2]));
	}

//10: 4500 muanyag -> 11. level
	$r17 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv
from userek u
inner join bolygok b on b.tulaj=u.id
inner join bolygo_eroforras be on be.bolygo_id=b.id and be.eroforras_id=71
where u.tut_level=10
group by u.id
having coalesce(sum(be.db),0)>=4500');
	$r17->execute();
	foreach ($r17->fetchAll(PDO::FETCH_BOTH) as $item) {
		$szimClass->tutorialMessages($aux[0],11,array($aux[1],$aux[2]));
	}

//11: felvezgyar -> 12. level
	$r18 = $gs->pdo->prepare('select u.id,u.nev,u.nyelv
from userek u
inner join bolygok b on b.tulaj=u.id
inner join bolygo_gyar bgy on bgy.bolygo_id=b.id and bgy.gyar_id=55
where u.tut_level=11
group by u.id');
	$r18->execute();
	foreach($r18->fetchAll(PDO::FETCH_BOTH) as $aux){
		$szimClass->tutorialMessages($aux[0],12,array($aux[1],$aux[2]));
	}

/************************************** TUT_LEVEL ES TECH_SZINT VEGE *****************************************************************/




//automatikus torlesek
//inaktivak torlese (14 napnal regebb ota nem csinalt semmit)
	$r19 = $gs->pdo->prepare('select id from userek where timestampdiff(day,uccso_akt,now())>14 order by id limit 1');
	$r19->execute();
	if ($r19->rowCount()) //TODO del_ures_user($aux[0]);
	if ($admin_nyaral==0) {
		//7 napnal regebb ota inaktiv es nincs aktivalva a regisztracioja
		$r20 = $gs->pdo->prepare('select id from userek where aktivalo_kulcs!="" and timestampdiff(day,uccso_akt,now())>7 order by id limit 1');
		$r20->execute();
		if ($r20->rowCount()){} //TODO del_ures_user($aux[0]);
	}


//torlendo userek (akik tech 4 utan toroltek magukat)
	$r21 = $gs->pdo->prepare('select user_id from torlendo_userek where mikor<now()');
	$r21->execute();
	foreach($r21->fetchAll(PDO::FETCH_BOTH) as $aux){
		//TODO:HIANY
		//del_ures_user($aux[0]);
		$r22 = $gs->pdo->prepare('delete from torlendo_userek where user_id=?');
		$r22->execute(array($aux[0]));
	}


//eplista es epites alatti email ertesitesek
	/*
$er=mysql_query('select b.nev as bolygo_nev,b.van_e_eplistaban_epulet,b.maradt_eplistaban_epulet,b.van_e_epites_alatti_epulet,b.maradt_epites_alatti_epulet
,u.nev as user_nev,u.email,u.nyelv
,ub.email_noti_eplista,ub.email_noti_epites_alatt
from bolygok b, userek u, user_beallitasok ub where b.tulaj>0 and b.tulaj=u.id and u.id=ub.user_id and (b.van_e_eplistaban_epulet=1 and b.maradt_eplistaban_epulet=0 or b.van_e_epites_alatti_epulet=1 and b.maradt_epites_alatti_epulet=0 and b.maradt_eplistaban_epulet=0)');
while($aux=mysql_fetch_array($er)) {
	if ($aux['van_e_eplistaban_epulet']==1) if ($aux['maradt_eplistaban_epulet']==0) if ($aux['email_noti_eplista']==1) {//eplista kifogyott
		if ($aux['nyelv']=='hu') {
			zandamail('hu',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['user_nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - '.$aux['bolygo_nev'].' bolygódon kifogyott az építési lista',
				'html'	=>	"<p>Kedves {$aux['user_nev']}!</p>
<p>{$aux['bolygo_nev']} bolygódon kifogyott az építési lista, vagyis elkezdődött az utolsó listában lévő gyár építése is. Nem tudjuk, mit a terveid, de talán itt az ideje feltölteni a listát, ha tovább akarod fejleszteni a bolygót.</p>
<p>Ha nem szeretnél a továbbiakban ehhez hasonló értesítéseket kapni, azt a PROFIL menüben tudod beállítani.</p>
",
				'plain'	=>	"Kedves {$aux['user_nev']}!

{$aux['bolygo_nev']} bolygódon kifogyott az építési lista, vagyis elkezdődött az utolsó listában lévő gyár építése is. Nem tudjuk, mit a terveid, de talán itt az ideje feltölteni a listát, ha tovább akarod fejleszteni a bolygót.
Ha nem szeretnél a továbbiakban ehhez hasonló értesítéseket kapni, azt a PROFIL menüben tudod beállítani.
"
			));
		} else {
			zandamail('en',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['user_nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - The construction queue got empty on planet '.$aux['bolygo_nev'],
				'html'	=>	"<p>Dear {$aux['user_nev']}!</p>
<p>The construction queue got empty on planet {$aux['bolygo_nev']}. We don't know your plans, but it might be the right time to fill up the queue, so your planet can develop further.</p>
<p>If you don't want notifications like this, you can change your settings in the PROFILE menu.</p>
",
				'plain'	=>	"Dear {$aux['user_nev']}!

The construction queue got empty on planet {$aux['bolygo_nev']}. We don't know your plans, but it might be the right time to fill up the queue, so your planet can develop further.
If you don't want notifications like this, you can change your settings in the PROFILE menu.
"
			));
		}
	}
	if ($aux['van_e_epites_alatti_epulet']==1) if ($aux['maradt_epites_alatti_epulet']==0) if ($aux['maradt_eplistaban_epulet']==0) if ($aux['email_noti_epites_alatt']==1) {//epites alatti kifogyott
		if ($aux['nyelv']=='hu') {
			zandamail('hu',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['user_nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - '.$aux['bolygo_nev'].' bolygódon megépült minden',
				'html'	=>	"<p>Kedves {$aux['user_nev']}!</p>
<p>{$aux['bolygo_nev']} bolygódon megépült minden. Nem tudjuk, mit a terveid, de talán itt az ideje belekezdeni pár új építkezésbe, ha tovább akarod fejleszteni a bolygót.</p>
<p>Ha nem szeretnél a továbbiakban ehhez hasonló értesítéseket kapni, azt a PROFIL menüben tudod beállítani.</p>
",
				'plain'	=>	"Kedves {$aux['user_nev']}!

{$aux['bolygo_nev']} bolygódon megépült minden. Nem tudjuk, mit a terveid, de talán itt az ideje belekezdeni pár új építkezésbe, ha tovább akarod fejleszteni a bolygót.
Ha nem szeretnél a továbbiakban ehhez hasonló értesítéseket kapni, azt a PROFIL menüben tudod beállítani.
"
			));
		} else {
			zandamail('en',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['user_nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - Everything has been built on planet '.$aux['bolygo_nev'],
				'html'	=>	"<p>Dear {$aux['user_nev']}!</p>
<p>Everything has been built on planet {$aux['bolygo_nev']}. We don't know your plans, but it might be the right time to start building some more factories, so your planet can develop further.</p>
<p>If you don't want notifications like this, you can change your settings in the PROFILE menu.</p>
",
				'plain'	=>	"Dear {$aux['user_nev']}!

Everything has been built on planet {$aux['bolygo_nev']}. We don't know your plans, but it might be the right time to start building some more factories, so your planet can develop further.
If you don't want notifications like this, you can change your settings in the PROFILE menu.
"
			));
		}
	}
}




//email ertesites
if (!$inaktiv_szerver) if ($vegjatek==0) {
	//7 nap
	$er=mysql_query('select id,nev,email,nyelv from userek where timestampdiff(day,uccso_akt,now())>7 and inaktivitasi_ertesito<7 order by uccso_akt desc limit 1') or hiba(__FILE__,__LINE__,mysql_error());
	while($aux=mysql_fetch_array($er)) {
		if ($aux['nyelv']=='hu') {
			zandamail('hu',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - Merre vagy?',
				'html'	=>	"<p>Kedves {$aux['nev']}!</p><p>Azért kapod ezt a levelet, mert több, mint egy hete nem jelentkeztél be a játékba. Természetesen ez szíved joga, de ne feledd, hogy 14 nap inaktivitás után törlődik az accountod. Vagyis, ha szeretnél tovább játszani, érdemes bejelentkezned a <a href=\"".$zanda_game_url['hu']."\">".$zanda_game_url['hu']."</a> címen. További szép napot!</p>",
				'plain'	=>	"Kedves {$aux['nev']}!\n\nAzért kapod ezt a levelet, mert több, mint egy hete nem jelentkeztél be a játékba. Természetesen ez szíved joga, de ne feledd, hogy 14 nap inaktivitás után törlődik az accountod. Vagyis, ha szeretnél tovább játszani, érdemes bejelentkezned a ".$zanda_game_url['hu']." címen. További szép napot!\n"
			));
		} else {
			zandamail('en',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - Where are you?',
				'html'	=>	"<p>Dear {$aux['nev']}!</p><p>You're getting this mail because you haven't been playing for more than a week now. It's your choice of course, but don't forget that your account will be deleted after 14 days of inactivity. So if you want to continue playing sign in at <a href=\"".$zanda_game_url['en']."\">".$zanda_game_url['en']."</a>. Have a nice day!</p>",
				'plain'	=>	"Dear {$aux['nev']}!\n\nYou're getting this mail because you haven't been playing for more than a week now. It's your choice of course, but don't forget that your account will be deleted after 14 days of inactivity. So if you want to continue playing sign in at ".$zanda_game_url['en']." . Have a nice day!\n"
			));
		}
		mysql_query('update userek set inaktivitasi_ertesito=7 where id='.$aux['id']) or hiba(__FILE__,__LINE__,mysql_error());
	}
	//3 nap
	$er=mysql_query('select id,nev,email,nyelv from userek where timestampdiff(day,uccso_akt,now())>3 and inaktivitasi_ertesito<3 order by uccso_akt desc limit 1') or hiba(__FILE__,__LINE__,mysql_error());
	while($aux=mysql_fetch_array($er)) {
		if ($aux['nyelv']=='hu') {
			zandamail('hu',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - Merre vagy?',
				'html'	=>	"<p>Kedves {$aux['nev']}!</p><p>Azért kapod ezt a levelet, mert több, mint 3 napja nem jelentkeztél be a játékba. Természetesen ez szíved joga, de ne feledd, hogy 14 nap inaktivitás után törlődik az accountod. Vagyis, ha szeretnél tovább játszani, érdemes bejelentkezned a <a href=\"".$zanda_game_url['hu']."\">".$zanda_game_url['hu']."</a> címen. További szép napot!</p>",
				'plain'	=>	"Kedves {$aux['nev']}!\n\nAzért kapod ezt a levelet, mert több, mint 3 napja nem jelentkeztél be a játékba. Természetesen ez szíved joga, de ne feledd, hogy 14 nap inaktivitás után törlődik az accountod. Vagyis, ha szeretnél tovább játszani, érdemes bejelentkezned a ".$zanda_game_url['hu']." címen. További szép napot!\n"
			));
		} else {
			zandamail('en',array(
				'email'	=>	$aux['email'],
				'name'	=>	$aux['nev'],
				'subject'	=>	'Zandagort '.$szerver_prefix.' - Where are you?',
				'html'	=>	"<p>Dear {$aux['nev']}!</p><p>You're getting this mail because you haven't been playing for more than 3 days now. It's your choice of course, but don't forget that your account will be deleted after 14 days of inactivity. So if you want to continue playing sign in at <a href=\"".$zanda_game_url['en']."\">".$zanda_game_url['en']."</a>. Have a nice day!</p>",
				'plain'	=>	"Dear {$aux['nev']}!\n\nYou're getting this mail because you haven't been playing for more than 3 days now. It's your choice of course, but don't forget that your account will be deleted after 14 days of inactivity. So if you want to continue playing sign in at ".$zanda_game_url['en']." . Have a nice day!\n"
			));
		}
		mysql_query('update userek set inaktivitasi_ertesito=3 where id='.$aux['id']) or hiba(__FILE__,__LINE__,mysql_error());
	}
}


/************************************** STATISZTIKAK ELEJE *****************************************************************/
if ($idopont%60==27) {
$huszonnegy_oraja=date('Y-m-d H:i:s',time()-3600*24);
//admin, sock puppetek es egyebek aktivalasa
foreach($specko_userek_listaja as $id) {
	$r23 = $gs->pdo->prepare('update userek set uccso_akt=? where id=?');
	$r23->execute(array($huszonnegy_oraja, $id));
}
foreach($specko_szovetsegek_listaja as $id) {
	$r24 = $gs->pdo->prepare('update userek set uccso_akt=? where szovetseg=?');
	$r24->execute(array($huszonnegy_oraja, $id));
}
//hataridoig kitiltottak aktivalasa
	$r25 = $gs->pdo->prepare('update userek set uccso_akt=? where kitiltva_meddig>now()');
	$r25->execute(array($huszonnegy_oraja));




//regi cset hozzaszolasok torlese
	$r26 = $gs->pdo->prepare('delete from cset_hozzaszolasok where szov_id>-1000 and mikor<?');
	$r26->execute(array(date('Y-m-d H:i:s',time()-3600)));

	$r27 = $gs->pdo->prepare('delete from cset_hozzaszolasok where szov_id<=-1000 and mikor<?');
	$r27->execute(array(date('Y-m-d H:i:s',time()-3600*24)));


//pontszam frissites (uj, reszflottas)
//a nullpontot ujraszamolni, ha valtozik a kezdokeszlet (pl anyahajo, varos)
	$r28 = $gs->pdo->prepare('
update userek u, (select u.id,coalesce(bpont,0)+coalesce(fpont,0)-coalesce(levonando.pontertek,0)+coalesce(hozzaadando.pontertek,0) as pont
from userek u
left join (select u.id,coalesce(sum(b.pontertek),0) as bpont from userek u left join bolygok b on b.tulaj=u.id group by u.id) b on b.id=u.id
left join (select u.id,coalesce(sum(f.pontertek),0) as fpont from userek u left join flottak f on f.tulaj=u.id group by u.id) f on f.id=u.id
left join (select f.tulaj,round(sum(rfh.hp/rfh.ossz_hp*fh.ossz_hp*e.pontertek)) as pontertek
from resz_flotta_hajo rfh, flottak f, flotta_hajo fh, eroforrasok e
where rfh.flotta_id=f.id and rfh.flotta_id=fh.flotta_id
and rfh.hajo_id=fh.hajo_id and rfh.hajo_id=e.id
group by f.tulaj) levonando on levonando.tulaj=u.id
left join (select rfh.user_id,round(sum(rfh.hp/rfh.ossz_hp*fh.ossz_hp*e.pontertek)) as pontertek
from resz_flotta_hajo rfh, flottak f, flotta_hajo fh, eroforrasok e
where rfh.flotta_id=f.id and rfh.flotta_id=fh.flotta_id
and rfh.hajo_id=fh.hajo_id and rfh.hajo_id=e.id
group by rfh.user_id) hozzaadando on hozzaadando.user_id=u.id
group by u.id) t
set u.pontszam=round(greatest(u.vagyon+t.pont-2894180000,0)/1000)
where u.id=t.id
');
	$r28->execute();



// 3 napos -> 3x24 oras
// alfa = 2/(N+1) = 2/(3x24+1) = 0.02739726 = 0.027
// 1-alfa = 0.972602739 = 0.973
	$r29 = $gs->pdo->prepare('update userek set pontszam_exp_atlag=round(0.027*pontszam+0.973*pontszam_exp_atlag)');
	$r29->execute();



//szovetseg helyezesek
	$r30 = $gs->pdo->prepare('update szovetsegek set helyezes=0');
	$r30->execute();

	$r31 = $gs->pdo->prepare('select sz.id from szovetsegek sz, userek u where u.szovetseg=sz.id and sz.id not in (?) and u.id not in (?) group by sz.id order by sum(u.pontszam_exp_atlag) desc');
	$r31->execute(array(implode(',',$specko_szovetsegek_listaja), implode(',',$specko_userek_listaja)));

	$n=0;
	foreach($r31->fetchAll(PDO::FETCH_BOTH) as $aux){
	$n++;
		$r32 = $gs->pdo->prepare('update szovetsegek set helyezes='.$n.' where id=?');
		$r32->execute(array($aux[0]));
	}


//akt_stat
	$r33 = $gs->pdo->prepare('select count(1) from userek');
	$r33->execute();
	$r33 = $r33->fetch(PDO::FETCH_BOTH);
	$user_szam = $r33[0];
	$akt_stat_hosszak=array(1,5,10,15,60,1440,4320,10080);
for($i=0;$i<sizeof($akt_stat_hosszak);$i++) {
	$r34 = $gs->pdo->prepare('select count(1) from (select id,nev,pontszam,coalesce(timestampdiff(minute,uccso_akt,now()),coalesce(1440-timestampdiff(minute,now(),session_ervenyesseg),coalesce(timestampdiff(minute,uccso_login,now()),timestampdiff(minute,mikortol,now())))) as utoljara from userek) t where utoljara<=?');
	$r34->execute(array($akt_stat_hosszak[$i]));
	$r34 = $r34->fetch(PDO::FETCH_BOTH);
	$akt_stat_db[$i] = $r34[0];
}
	/*
mysql_select_db($database_mmog_nemlog);
mysql_query('insert into akt_stat (mikor,akt_1_perc,akt_5_perc,akt_10_perc,akt_15_perc,akt_1_ora,akt_24_ora,akt_3_nap,akt_7_nap,ossz) values("'.date('Y-m-d H:i:s').'",'.implode(',',$akt_stat_db).','.$user_szam.')') or hiba(__FILE__,__LINE__,mysql_error());
mysql_select_db($database_mmog);*/
}
if ($idopont%60==27) {
	if ($vegjatek==1) {
		hist_snapshot($idopont%360==327);//a vegso csata alatt orankenti mentes, leszamitva a hist_termelesek-et, ami csak 6 orankent
	} else {
		if ($idopont%360==327) hist_snapshot();//6 orankent mentes az adattarhazba
	}
}
/************************************** STATISZTIKAK VEGE *****************************************************************/
if ($idopont%1440==35) {
	$r35 = $ls->pdo->prepare('update multi_matrix set pont=round(0.9*pont),minusz_pont=round(0.9*minusz_pont)');
	$r35->execute();//naponta egyszer csokkenteni a multipontokat
	$r36 = $ls->pdo->prepare('delete from loginek where timestampdiff(minute,mikor,now())>1440');
	$r36->execute();//24 oranal regebbi loginek torlese, h gyorsabb legyen a tabla
}

/************************************** TOZSDEI GYERTYAK ELEJE *****************************************************************/
if ($idopont%60==15) {
//tozsdei gyertyak
	$r37 = $gs->pdo->prepare('select id from eroforrasok where tozsdezheto order by id');
	$r37->execute();
	foreach($r37->fetchAll(PDO::FETCH_BOTH) as $aux) $termek_idk[]=$aux[0];
		$r38 = $gs->pdo->prepare('select id from regiok order by id');
		$r38->execute();
	foreach($r38->fetchAll(PDO::FETCH_BOTH) as $aux) $regiok[]=$aux[0];

foreach($regiok as $regio) {
	$tozsdei_kotesek_tabla='tozsdei_kotesek';
	//napi
	if (date('H')=='00') {
		$datumtol=date('Y-m-d',time()-3600).' 00:00:00';
		$datumig=date('Y-m-d',time()-3600).' 23:59:59';
		for($termek_sorszam=0;$termek_sorszam<count($termek_idk);$termek_sorszam++) {
			$termek=$termek_idk[$termek_sorszam];
			$r39 = $ls->pdo->prepare('select count(1),sum(mennyiseg),min(arfolyam),max(arfolyam) from ? where regio=? and termek_id=? and mikor>=? and mikor<=?');
			$r39->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
			$aux = $r39->fetch(PDO::FETCH_BOTH);
			$kotesszam=$aux[0];$otszazalek=round($kotesszam*0.05);
			if ($kotesszam>0) {
				$forgalom=$aux[1];
				$min_ar=$aux[2];
				$max_ar=$aux[3];
					$r40 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by mikor limit 1');
					$r40->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
					$aux = $r40->fetch(PDO::FETCH_BOTH);
				$nyito_ar=$aux[0];
					$r41 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by mikor desc limit 1');
					$r41->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
					$aux = $r41->fetch(PDO::FETCH_BOTH);
				$zaro_ar=$aux[0];
					$r42 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by arfolyam limit ?,1');
					$r42->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig, $otszazalek));
					$aux = $r42->fetch(PDO::FETCH_BOTH);
				$min5_ar=$aux[0];
					$r43 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by arfolyam desc limit ?,1');
					$r43->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig, $otszazalek));
					$aux = $r43->fetch(PDO::FETCH_BOTH);
				$max5_ar=$aux[0];
				$r44 = $ls->pdo->prepare('insert into tozsdei_gyertyak (regio,termek_id,felbontas,mikor,nyito_ar,zaro_ar,min_ar,max_ar,min5_ar,max5_ar,forgalom) values(?,?,?,?,?,?,?,?,?,?,?)');
				$r44->execute(array($regio, $termek, 3, $datumtol, $nyito_ar, $zaro_ar, $min_ar, $max_ar, $min5_ar, $max5_ar, $forgalom));
			}
		}
	}
	//harmadnapi
	if (date('H')=='00' || date('H')=='08' || date('H')=='16') {
		$datumtol=date('Y-m-d H',time()-8*3600).':00:00';
		$datumig=date('Y-m-d H',time()-3600).':59:59';
		for($termek_sorszam=0;$termek_sorszam<count($termek_idk);$termek_sorszam++) {
			$termek=$termek_idk[$termek_sorszam];
			$r45 = $ls->pdo->prepare('select count(1),sum(mennyiseg),min(arfolyam),max(arfolyam) from ? where regio=? and termek_id=? and mikor>=? and mikor<=?');
			$r45->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
			$aux = $r45->fetch(PDO::FETCH_BOTH);
			$kotesszam=$aux[0];$otszazalek=round($kotesszam*0.05);
			if ($kotesszam>0) {
				if ($kotesszam>0) {
					$forgalom=$aux[1];
					$min_ar=$aux[2];
					$max_ar=$aux[3];
					$r46 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by mikor limit 1');
					$r46->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
					$aux = $r46->fetch(PDO::FETCH_BOTH);
					$nyito_ar=$aux[0];
					$r47 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by mikor desc limit 1');
					$r47->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
					$aux = $r47->fetch(PDO::FETCH_BOTH);
					$zaro_ar=$aux[0];
					$r48 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by arfolyam limit ?,1');
					$r48->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig, $otszazalek));
					$aux = $r48->fetch(PDO::FETCH_BOTH);
					$min5_ar=$aux[0];
					$r49 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by arfolyam desc limit ?,1');
					$r49->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig, $otszazalek));
					$aux = $r49->fetch(PDO::FETCH_BOTH);
					$max5_ar=$aux[0];
					$r50 = $ls->pdo->prepare('insert into tozsdei_gyertyak (regio,termek_id,felbontas,mikor,nyito_ar,zaro_ar,min_ar,max_ar,min5_ar,max5_ar,forgalom) values(?,?,?,?,?,?,?,?,?,?,?)');
					$r50->execute(array($regio, $termek, 3, $datumtol, $nyito_ar, $zaro_ar, $min_ar, $max_ar, $min5_ar, $max5_ar, $forgalom));
				}
			}
		}
	}
	//orankenti
	$datumtol=date('Y-m-d H',time()-3600).':00:00';
	$datumig=date('Y-m-d H',time()-3600).':59:59';
	for($termek_sorszam=0;$termek_sorszam<count($termek_idk);$termek_sorszam++) {
		$termek=$termek_idk[$termek_sorszam];
		$r51 = $ls->pdo->prepare('select count(1),sum(mennyiseg),min(arfolyam),max(arfolyam) from ? where regio=? and termek_id=? and mikor>=? and mikor<=?');
		$r51->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
		$aux = $r51->fetch(PDO::FETCH_BOTH);
		$kotesszam=$aux[0];$otszazalek=round($kotesszam*0.05);
		if ($kotesszam>0) {
			if ($kotesszam>0) {
				$forgalom=$aux[1];
				$min_ar=$aux[2];
				$max_ar=$aux[3];
				$r52 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by mikor limit 1');
				$r52->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
				$aux = $r52->fetch(PDO::FETCH_BOTH);
				$nyito_ar=$aux[0];
				$r53 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by mikor desc limit 1');
				$r53->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig));
				$aux = $r53->fetch(PDO::FETCH_BOTH);
				$zaro_ar=$aux[0];
				$r54 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by arfolyam limit ?,1');
				$r54->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig, $otszazalek));
				$aux = $r54->fetch(PDO::FETCH_BOTH);
				$min5_ar=$aux[0];
				$r55 = $ls->pdo->prepare('select arfolyam from ? where regio=? and termek_id=? and mikor>=? and mikor<=? order by arfolyam desc limit ?,1');
				$r55->execute(array($tozsdei_kotesek_tabla, $regio, $termek, $datumtol, $datumig, $otszazalek));
				$aux = $r55->fetch(PDO::FETCH_BOTH);
				$max5_ar=$aux[0];
				$r56 = $ls->pdo->prepare('insert into tozsdei_gyertyak (regio,termek_id,felbontas,mikor,nyito_ar,zaro_ar,min_ar,max_ar,min5_ar,max5_ar,forgalom) values(?,?,?,?,?,?,?,?,?,?,?)');
				$r56->execute(array($regio, $termek, 3, $datumtol, $nyito_ar, $zaro_ar, $min_ar, $max_ar, $min5_ar, $max5_ar, $forgalom));
			}
		}
	}
}
}
/************************************** TOZSDEI GYERTYAK VEGE *****************************************************************/



/************************************** BADGE-EK ELEJE *****************************************************************/
//nepesseg (1-6)
$case_str = $szimClass->getBadgeCaseSTR(array(1,2,3,4,5,6));
	$r57 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select u.id as user_id
,if(u.ossz_nepesseg<1000000,1
,if(u.ossz_nepesseg<10000000,2
,if(u.ossz_nepesseg<100000000,3
,if(u.ossz_nepesseg<1000000000,4
,if(u.ossz_nepesseg<10000000000,5,6
))))) as badge_id
from userek u
where u.ossz_nepesseg>=100000

) t');
	$r57->execute(array($case_str));

//varosok (13-18)
$case_str = $szimClass->getBadgeCaseSTR(array(13,14,15,16,17,18));
	$r58 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select u.id as user_id
,if(sum(bgy.aktiv_db)<100,13
,if(sum(bgy.aktiv_db)<1000,14
,if(sum(bgy.aktiv_db)<10000,15
,if(sum(bgy.aktiv_db)<100000,16
,if(sum(bgy.aktiv_db)<1000000,17,18
))))) as badge_id
from userek u, bolygok b, bolygo_gyar bgy
where u.id=b.tulaj and b.id=bgy.bolygo_id and bgy.gyar_id=78 and b.tulaj>0
group by u.id
having sum(bgy.aktiv_db)>=10

) t');
	$r58->execute(array($case_str));

//bolygok (19-23)
$case_str = $szimClass->getBadgeCaseSTR(array(19,20,21,22,23));
	$r59 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select b.tulaj as user_id
,if(count(1)<20,19
,if(count(1)<30,20
,if(count(1)<40,21
,if(count(1)<50,22,23
)))) as badge_id
from bolygok b
where b.tulaj>0
group by b.tulaj
having count(1)>=10

) t');
	$r59->execute(array($case_str));

//A-bolygok
$case_str = $szimClass->getBadgeCaseSTR(array(24,25,26));
	$r60 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select b.tulaj as user_id
,if(count(1)<5,24
,if(count(1)<10,25,26
)) as badge_id
from bolygok b
where b.tulaj>0 and b.osztaly=1
group by b.tulaj
having count(1)>=3

) t');
	$r60->execute(array($case_str));

//B-bolygok
$case_str = $szimClass->getBadgeCaseSTR(array(27,28,29));
	$r61 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select b.tulaj as user_id
,if(count(1)<5,27
,if(count(1)<10,28,29
)) as badge_id
from bolygok b
where b.tulaj>0 and b.osztaly=2
group by b.tulaj
having count(1)>=3

) t');
	$r61->execute(array($case_str));
//C-bolygok
$case_str = $szimClass->getBadgeCaseSTR(array(30,31,32));
	$r62 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select b.tulaj as user_id
,if(count(1)<5,30
,if(count(1)<10,31,32
)) as badge_id
from bolygok b
where b.tulaj>0 and b.osztaly=3
group by b.tulaj
having count(1)>=3

) t');
	$r62->execute(array($case_str));
//D-bolygok
$case_str = $szimClass->getBadgeCaseSTR(array(33,34,35));
	$r63 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select b.tulaj as user_id
,if(count(1)<5,33
,if(count(1)<10,34,35
)) as badge_id
from bolygok b
where b.tulaj>0 and b.osztaly=4
group by b.tulaj
having count(1)>=3

) t');
	$r63->execute(array($case_str));
//E-bolygok
$case_str = $szimClass->getBadgeCaseSTR(array(36,37,38));
	$r64 = $gs->pdo->prepare('insert ignore into user_badge (user_id,badge_id,szin)
select user_id,badge_id,case badge_id ? end
from (

select b.tulaj as user_id
,if(count(1)<5,36
,if(count(1)<10,37,38
)) as badge_id
from bolygok b
where b.tulaj>0 and b.osztaly=5
group by b.tulaj
having count(1)>=3

) t');
	$r64->execute(array($case_str));


//tul sok aranyat es ezustot bronzositani
	$r65 = $gs->pdo->prepare('select badge_id,sum(szin=1),sum(szin=2) from user_badge group by badge_id having sum(szin=1)>10 or sum(szin=2)>50');
	$r65->execute();
	foreach($r65->fetchAll(PDO::FETCH_BOTH) as $aux){
	if ($aux[1]>10) {
		$r66 = $gs->pdo->prepare('UPDATE user_badge SET szin=3 WHERE badge_id=? AND szin=1');
		$r66->execute(array($aux[0]));
	}
	elseif ($aux[2]>50) {
		$r67 = $gs->pdo->prepare('update user_badge set szin=3 where badge_id='.$aux[0].' and szin=2');
		$r67->execute(array($aux[0]));
	}
}

//ertesites es publikalas
//1-es badge (POP-100k) ne legyen, mert a TL-ek mellett folosleges
	$r68 = $gs->pdo->prepare('select ub.*,us.badge_pub,b.cim,b.alcim,b.leiras_hu,b.leiras_en
from user_badge ub, user_beallitasok us, badgek b
where ub.user_id=us.user_id and ub.bejelentett=0 and ub.badge_id!=1 and ub.badge_id=b.id');
	$r68->execute();
	foreach($r68->fetchAll(PDO::FETCH_BOTH) as $aux){
	if ($aux['szin']==1) {$szin_hu='arany';$szin_en='gold';}
	elseif ($aux['szin']==2) {$szin_hu='ezüst';$szin_en='silver';}
	else {$szin_hu='bronz';$szin_en='bronze';}
		$szimClass->systemMessage($aux['user_id']
		,'Új '.$szin_hu.' plecsnit kaptál: '.$aux['leiras_hu'],'<a href="#" onclick="return user_katt('.$aux['user_id'].')"><div title="'.$aux['leiras_hu'].'" style="position:relative;display:inline-block;width:64px;height:64px;background:transparent url(img/ikonok/zanda_badge_'.$aux['szin'].'.png)"><div style="text-align:center;font-size:14pt;font-weight:bold;color:rgb(42,43,45);margin-top:20px">'.$aux['cim'].'</div><div style="text-align:center;font-size:8pt;font-weight:bold;color:rgb(42,43,45);margin-top:0px">'.$aux['alcim'].'</div></div></a>'
		,'You got a new '.$szin_en.' badge: '.$aux['leiras_en'],'<a href="#" onclick="return user_katt('.$aux['user_id'].')"><div title="'.$aux['leiras_en'].'" style="position:relative;display:inline-block;width:64px;height:64px;background:transparent url(img/ikonok/zanda_badge_'.$aux['szin'].'.png)"><div style="text-align:center;font-size:14pt;font-weight:bold;color:rgb(42,43,45);margin-top:20px">'.$aux['cim'].'</div><div style="text-align:center;font-size:8pt;font-weight:bold;color:rgb(42,43,45);margin-top:0px">'.$aux['alcim'].'</div></div></a>'
	);
		$r69 = $gs->pdo->prepare('update user_badge set bejelentett=1, publikus=? where user_id=? and badge_id=?');
		$r69->execute(array($aux['badge_pub'], $aux['user_id'], $aux['badge_id']));
	}

/************************************** BADGE-EK VEGE *****************************************************************/



/*******************************************************************************************************/
$szimlog_hossz=round(1000*(microtime(true)-$mikor_indul));
	$res = $gs->pdo->prepare('do release_lock(?)');
	$res->execute(array($szimlock_name));

	$r70 = $ls->pdo->prepare('insert into szim_log (idopont,hossz_npc,hossz_monetaris,hossz_termeles,hossz_felderites,hossz_flottamoral,hossz_flottak,hossz_csatak,hossz,hossz_debug_elott,hossz_debug_utan,hossz_ostromok,hossz_fog)
values(?,?,?,?,?,?,?,?,?,?,?,?,?)');
	$r70->execute(array($idopont,$szimlog_hossz_npc, $szimlog_hossz_monetaris, $szimlog_hossz_termeles, $szimlog_hossz_felderites, $szimlog_hossz_flottamoral,
			$szimlog_hossz_flottak, $szimlog_hossz_csatak, $szimlog_hossz, $szimlog_hossz_debug_elott, $szimlog_hossz_debug_utan, $szimlog_hossz_ostromok, $szimlog_hossz_fog));

$lock_rendben=1;
} else $lock_rendben=0;//sikeres lock vege
$mikor_vegzodik=microtime(true);




echo ' '.round(1000*($mikor_vegzodik-$mikor_indul)).($lock_rendben?'':' LOCK');
}

echo date("Y.m.d H:i:s");
?>