<?php
require "engine/classes/database.class.php";
include "engine/classes/otherfunctions.class.php";
include "engine/classes/serverfunctions.class.php";
include "engine/classes/stockexchange.class.php";
include "engine/classes/ecosphere.class.php";
include "engine/classes/building.class.php";
include "engine/classes/resources.class.php";
include "engine/classes/planets.class.php";
include "engine/classes/account.class.php";



// régi faszságok
//include('tavoli_zanda_session.php');$x=tavoli_zanda_session();$zanda_session_id=$x[0];$zanda_ref=$x[1];
include('csatlak.php');
$nyilvanos_oldal=1;
include('ujkuki.php');

	if(isset($_POST['reg_reg']) && isset($_POST['reg_rulez'])){
		try{
			if(!$Account->register($_POST['reg_nev'], $_POST['reg_email_1'], array($_POST['reg_jelszo_1'], $_POST['reg_jelszo_2'], $_POST['reg_rulez']))){
				throw new Exception("Regisztrációs Hiba!");
			}
		}catch (Exception $e) {
			echo $e->getMessage();
		}
	}elseif(isset($_POST['reg_reg']) && !isset($_POST['reg_rulez'])){
		echo 'General Terms and Conditions!';
	}




/*if ($_GET['reg']=='siker') {//reg utani oldal
	if ($ismert) {
		if ($adataim['elso_belepes_betoltes']==0) {//de mar nem az elso ide lepese
			header('Location: .');exit;//akkor ne nezze konverzionak az Analytics
		}
	} else {
		header('Location: .');exit;//nincs is belepve, ez most mar csak a hibasan lementett url miatt lehet, azt meg jobb elfelejteni (es foleg nem felrevezetni a GA-t)
	}
}
*/
/*
if (strlen($_POST['akt'])==64) {//aktivalas
	$er=mysql_query('select * from userek where aktivalo_kulcs="'.sanitstr($_REQUEST['akt']).'"') or hiba(__FILE__,__LINE__,mysql_error());
	$aux=mysql_fetch_array($er);
	if ($aux) {
		mysql_query('update userek set aktivalo_kulcs="" where id='.$aux['id']);
		//
		$to=$aux['email'];
		if ($aux['nyelv']=='hu') {
			rendszeruzenet_a_kozponti_szolgaltatohaztol($aux['id'],'Regisztráció aktiválva!',"Kedves ".$aux['nev']."!\n\nSikeresen aktiváltad regisztrációdat. További jó szórakozást a játékhoz!\n\n\nZandagort és népe",'hu');
			$subject='Zandagort regisztráció aktiválva';
			$message_html="<p>Kedves ".$aux['nev']."!</p><p>Sikeresen aktiváltad regisztrációdat. További jó szórakozást a játékhoz!</p>";
			$message_plain="Kedves ".$aux['nev']."!\n\nSikeresen aktiváltad regisztrációdat. További jó szórakozást a játékhoz!\n";
		} else {
			rendszeruzenet_a_kozponti_szolgaltatohaztol($aux['id'],'Registration activated!',"Dear ".$aux['nev']."!\n\nYou have successfully activated your registration. Have fun with the game!\n\n\nZandagort and his people",'en');
			$subject='Zandagort registration activated';
			$message_html="<p>Dear ".$aux['nev']."!</p><p>You have successfully activated your registration. Have fun with the game!</p>";
			$message_plain="Dear ".$aux['nev']."!\n\nYou have successfully activated your registration. Have fun with the game!\n";
		}
		$x=zandamail($aux['nyelv'],array(
			'email'	=>	$to,
			'name'	=>	$aux['nev'],
			'subject'	=>	$subject,
			'html'	=>	$message_html,
			'plain'	=>	$message_plain
		));
		//
		//ZandaNet aktivalas
		/*
		$mysql_csatlakozas_zandanet=mysql_connect('HOST','USER','PASSWORD');
		mysql_select_db('mmog',$mysql_csatlakozas_zandanet) or die();
		mysql_query('set names "utf8"',$mysql_csatlakozas_zandanet);
		mysql_query('update zandanet_users set activated=1 where id='.$aux['zanda_id'],$mysql_csatlakozas_zandanet);
		//mysql_connect('localhost',$mysql_username,$mysql_password);//hogy ujra a helyi szerver legyen a default
		//nem csatlakozik vissza, mert ugyis vege a futasnak
		*/
		/*if ($ismert) header('Location: .');
		else header('Location: .?sikeres_akt=1');
		exit;
	}
	header('Location: .');exit;
}
*/
if (!$ismert) if (isset($_REQUEST['reg_reg'])) if (!isset($_REQUEST['reg_hiba'])) if ($sf->serverStarted) {

	//egy fordulos kitiltasok
	$er=mysql_query('select count(1) from '.$database_mmog_nemlog.'.email_kitiltasok where email="'.sanitstr($_REQUEST['reg_email_1']).'"') or hiba(__FILE__,__LINE__,mysql_error());
	$aux=mysql_fetch_array($er);

	//osztaly szuro
	$_REQUEST['reg_osztaly_valaszto']=(int)$_REQUEST['reg_osztaly_valaszto'];
	$osztaly_szuro='';if ($_REQUEST['reg_osztaly_valaszto']>=1) if ($_REQUEST['reg_osztaly_valaszto']<=5) $osztaly_szuro=' and osztaly='.$_REQUEST['reg_osztaly_valaszto'];
	//koordinata stimmel-e

	if ($_REQUEST['reg_koord']=='') {//uresen hagyva -> random
		//nyitott szovik
		$hova_szovi=0;
		$nyitott_angol_szovik='';//ismert, nagyobb, nyitott angol szovik listaja (kezzel karbantartva)
		if ($nyitott_angol_szovik=='') {//ha nincs ismert angol szovi, akkor marad az osszes mindenkinek, nyelvtol fuggetlenul
			if ($lang_lang=='hu') {
				$nyitott_magyar_szovik_szama=(int)mysql2num('select count(1) from szovetsegek where zart=0');
				if ($nyitott_magyar_szovik_szama>1) $hova_szovi=(int)mysql2num('select id from szovetsegek where zart=0 order by tagletszam desc limit '.mt_rand(0,min($nyitott_magyar_szovik_szama-1,9)).',1');
			} else {
				$hova_szovi=(int)mysql2num('select id from szovetsegek where zart=0 order by rand() limit 1');
			}
		} else {//ha van, akkor magyarokat magyarokhoz, angolokat angolokhoz
			if ($lang_lang=='hu') {
				$nyitott_magyar_szovik_szama=(int)mysql2num('select count(1) from szovetsegek where zart=0 and id not in ('.$nyitott_angol_szovik.')');
				if ($nyitott_magyar_szovik_szama>1) $hova_szovi=(int)mysql2num('select id from szovetsegek where zart=0 and id not in ('.$nyitott_angol_szovik.') order by tagletszam desc limit '.mt_rand(0,min($nyitott_magyar_szovik_szama-1,9)).',1');
			} else {
				$hova_szovi=(int)mysql2num('select id from szovetsegek where zart=0 and id in ('.$nyitott_angol_szovik.') order by rand() limit 1');
			}
		}
		if ($hova_szovi>0) {
			$hova_szovi_bolygok=mysql2row('select avg(x),avg(y) from bolygok where tulaj_szov='.$hova_szovi);
			$er=mysql_query("select * from bolygok where letezik=1 and tulaj=0 and moral=100 and alapbol_regisztralhato=1$osztaly_szuro order by pow(".$hova_szovi_bolygok[0]."-x,2)+pow(".$hova_szovi_bolygok[1]."-y,2) limit 1");
			$bolygo=mysql_fetch_array($er);
		} else {
			$er=mysql_query("select * from bolygok where letezik=1 and tulaj=0 and moral=100 and alapbol_regisztralhato=1$osztaly_szuro order by rand() limit 1");
			$bolygo=mysql_fetch_array($er);
		}
	} else {
		$koord=explode(',',$_REQUEST['reg_koord']);
		if (count($koord)!=2) {header('Location: .?reg_hiba=rossz_koord'.$adatok);exit;}
		$y_str=trim($koord[0]);
		$x_str=trim($koord[1]);
		if ($lang_lang=='hu') {
			$k=mb_substr($y_str,0,1,'UTF-8');$y_num=(int)strtr(trim(mb_substr($y_str,1,1000,'UTF-8')),array(' '=>''));
			if ($k=='É' || $k=='E' || $k=='é' || $k=='e') $y=-2*$y_num;
			elseif ($k=='D' || $k=='d') $y=2*$y_num;
			else $y=2*((int)$y_str);
			$k=mb_substr($x_str,0,1,'UTF-8');$x_num=(int)strtr(trim(mb_substr($x_str,1,1000,'UTF-8')),array(' '=>''));
			if ($k=='N' || $k=='n') $x=-2*(int)strtr(trim(mb_substr($x_str,2,1000,'UTF-8')),array(' '=>''));
			elseif ($k=='K' || $k=='k') $x=2*$x_num;
			else $x=2*((int)$x_str);
		} else {
			$k=mb_substr($y_str,0,1,'UTF-8');$y_num=(int)strtr(trim(mb_substr($y_str,1,1000,'UTF-8')),array(' '=>''));
			if ($k=='N' || $k=='n') $y=-2*$y_num;
			elseif ($k=='S' || $k=='s') $y=2*$y_num;
			else $y=2*((int)$y_str);
			$k=mb_substr($x_str,0,1,'UTF-8');$x_num=(int)strtr(trim(mb_substr($x_str,1,1000,'UTF-8')),array(' '=>''));
			if ($k=='W' || $k=='w') $x=-2*$x_num;
			elseif ($k=='E' || $k=='e') $x=2*$x_num;
			else $x=2*((int)$x_str);
		}
		$er=mysql_query("select * from bolygok where letezik=1 and tulaj=0 and moral=100 and alapbol_regisztralhato=1$osztaly_szuro order by pow($x-x,2)+pow($y-y,2) limit 1") or hiba(__FILE__,__LINE__,mysql_error());
		$bolygo=mysql_fetch_array($er);
	}

	if (!$bolygo) {header('Location: .?reg_hiba=nincs_bolygo'.$adatok);exit;}
	//captcha
	$er=mysql_query('select kapcsa from '.$database_mmog_nemlog.'.kapcsak where id='.((int)$_REQUEST['reg_kapcsa_id']));
	$aux=mysql_fetch_array($er);

	if ($aux[0]!=trim(strtoupper($_REQUEST['reg_kapcsa_szo']))) {header('Location: .?reg_hiba=captcha&reg_captcha_hiba=kapcsa'.$adatok);exit;}
	//rulez

	if (!isset($_POST['reg_rulez'])) {header('Location: .?reg_hiba=rulez'.$adatok);exit;}
	//ZandaNet email
	$aktivalva_van_e_mar=1;
	$pub_key=randomgen(32);
	$zanda_id=0;
	/*
	$mysql_csatlakozas_zandanet=mysql_connect('HOST','USER','PASSWORD');
	mysql_select_db('mmog',$mysql_csatlakozas_zandanet) or die();
	mysql_query('set names "utf8"',$mysql_csatlakozas_zandanet);
	$er=mysql_query('select * from zandanet_users where email="'.sanitstr($_REQUEST['reg_email_1']).'"',$mysql_csatlakozas_zandanet);
	$aux=mysql_fetch_array($er);
	if ($aux) {//van mar ZN account
		$zanda_id=$aux['id'];
		if ($aux['activated']) $aktivalva_van_e_mar=1;
		$er2=mysql_query('select count(1) from zandanet_server_accounts where zanda_id='.$zanda_id.' and server_prefix="'.$szerver_prefix.'" and deleted=0',$mysql_csatlakozas_zandanet);
		$aux2=mysql_fetch_array($er2);
		if ($_REQUEST['reg_email_1']!=$zanda_test_user_email) if ($aux2[0]>0) {
			//ezen a szerveren mar van regisztraciod
			//mivel ez csak hiba miatt lehet, deleted=1-re allitsa be, azt mehet tovabb
			//header('Location: .?reg_hiba=foglalt_email'.$adatok);exit;
			//
			mysql_query('update zandanet_server_accounts set deleted=1 where zanda_id='.$zanda_id.' and server_prefix="'.$szerver_prefix.'" and deleted=0',$mysql_csatlakozas_zandanet);
		}
	} else {//nincs -> ZN-reg
		$zandanet_jelszo=$_REQUEST['reg_jelszo_1'];
		$zandanet_jelszo_so=randomgen(32);
		$zandanet_jelszo_hash=hash('whirlpool',$zandanet_jelszo.$zandanet_jelszo_so.$zandanet_rendszer_so);
		mysql_query('insert into zandanet_users (name,email,pw_salt,pw_hash,newsletter_'.$lang_lang.',ip,dn,ua,ref,session_id,pub_key) values("'.sanitstr($_REQUEST['reg_nev']).'","'.sanitstr($_REQUEST['reg_email_1']).'","'.$zandanet_jelszo_so.'","'.$zandanet_jelszo_hash.'",1,"'.sanitstr($_SERVER['REMOTE_ADDR']).'","'.sanitstr(gethostbyaddr($_SERVER['REMOTE_ADDR'])).'","'.sanitstr($_SERVER['HTTP_USER_AGENT']).'","'.sanitstr($zanda_ref).'",'.$zanda_session_id.',"'.$pub_key.'")',$mysql_csatlakozas_zandanet);
		$er2=mysql_query('select last_insert_id() from zandanet_users',$mysql_csatlakozas_zandanet);
		$aux2=mysql_fetch_array($er2);$zanda_id=$aux2[0];
	}
	mysql_connect('localhost',$mysql_username,$mysql_password);//hogy ujra a helyi szerver legyen a default
	*/
	//mehet!
	$jelszo=$_REQUEST['reg_jelszo_1'];
	$jelszo_so=randomgen(32);
	$jelszo_hash=hash('whirlpool',$jelszo.$jelszo_so.$rendszer_so);


	$kozos_jelszo_hash=hash('whirlpool',$jelszo.$rendszer_so);
	$datum=date('Y-m-d H:i:s');
	//
	$premium_alap=date('Y-m-d H:i:s',time()+3600*24*10);//alap 10 napig
	$premium_emelt=date('Y-m-d H:i:s',time()-3600*24*10);//emelt _nem_
	$premium_szint=1;//mert itt alapbol ne legyen upgrade-es
	$premium_a_vegeig=0;//mert nem kapnak ilyet, csak 10 napot
	if ($datum>=$szerver_amikortol_mindenki_premium) {//a legvegen mindenki emelt premium lesz
		$premium_szint=2;
		$premium_a_vegeig=2;
	}
	//vege a zandanak, bucsuajandek:
	$premium_szint=2;
	$premium_a_vegeig=2;
	//
	if ($aktivalva_van_e_mar) $aktivalo_kulcs='';else $aktivalo_kulcs=randomgen(64);
	//penzlimitet normalisra resetelni
	//

	//
	$to=$_REQUEST['reg_email_1'];

	$x = true;


	if ($x) {
		bolygo_reset($bolygo['id'],$bolygo['osztaly'],$bolygo['terulet']);






		//kezdo anyahajo, csak regisztraciokor (es bolygo_reset.php-ben)
		mysql_query('update bolygo_eroforras set db=100 where bolygo_id='.$bolygo['id'].' and eroforras_id='.HAJO_TIPUS_ANYA);
		//regio beallitasa
		mysql_query('update userek set tobbsegi_regio='.$bolygo['regio'].',valasztott_regio='.$bolygo['regio'].',valasztott_regio2='.$bolygo['regio'].',aktualis_regio='.$bolygo['regio'].',aktualis_regio2='.$bolygo['regio'].' where id='.$user_id);
		//
		$x=$bolygo['x'];$y=$bolygo['y'];
		mysql_query('insert into user_beallitasok (user_id,chat_hu,chat_en) values('.$user_id.','.($lang_lang=='hu'?1:0).','.($lang_lang=='en'?1:0).')');
		mysql_query('insert into user_kutatasi_szint (user_id,kf_id) select '.$user_id.',id from kutatasi_temak');
		mysql_query('insert into user_veteli_limit (user_id,termek_id) select '.$user_id.',id from eroforrasok where tozsdezheto=1');
		mysql_query('update bolygok set nev="'.sanitstr($_REQUEST['reg_nev']).(($lang_lang=='hu')?' bolygója':'\'s planet').'",kulso_nev=nev,tulaj='.$user_id.',eredeti_tulaj='.$user_id.',vedelem=2,anyabolygo=0,fobolygo=1,uccso_foglalas_mikor="'.$datum.'",kezelo=0,tulaj_szov=-'.$user_id.' where id='.$bolygo['id']);

		if ($aktivalva_van_e_mar==0) {
			if ($lang_lang=='hu') {
				$uzi="Kedves ".$_REQUEST['reg_nev']."!\n\nAhhoz, hogy véglegesen használni tudd a regisztrációdat, aktiválnod kell, különben egy hét múlva ki leszel tiltva. Ha jól adtad meg az email címed (".$_REQUEST['reg_email_1']."), akkor kaptál egy levelet, benne egy aktiváló linkkel. Ha nem érkezett meg, nézd meg, hátha szpemnek nézte a levelezőrendszered. Ezt azért is fontos ellenőrizned, mert ha bármi változás történik a játékban, arról is emailben lesz értesítés. Ha egy(-két) napon belül nem kapod meg az aktiváló levelet, írj egy emailt az <a href=\"mailto:".$zanda_admin_email['hu']."?subject=aktivacio\">".$zanda_admin_email['hu']."</a> címre 'AKTIVÁCIÓ' tárggyal. Jó szórakozást a játékhoz!";
				$uzi.="\n\n\nZandagort és népe";
				$uzi.="\n\n".$zanda_ingame_msg_ps['hu'];
				rendszeruzenet_a_kozponti_szolgaltatohaztol($user_id,'Aktiváld a regisztrációdat!',$uzi,$lang_lang);
			} else {
				$uzi="Dear ".$_REQUEST['reg_nev']."!\n\nYou have to activate your registration to use it permanently, otherwise you will be banned after a week. If your email address is correct (".$_REQUEST['reg_email_1']."), you received a mail with an activating link in it. If it hasn't arrived, check your spamfilter. It is important, because changes and crucial information will be occasionally mailed to you. If you don't get the email in a few days, please write to <a href=\"mailto:".$zanda_admin_email['en']."?subject=activation\">".$zanda_admin_email['en']."</a> with the subject 'ACTIVATION'. Have fun with the game!";
				$uzi.="\n\n\nZandagort and his people";
				$uzi.="\n\n".$zanda_ingame_msg_ps['en'];
				rendszeruzenet_a_kozponti_szolgaltatohaztol($user_id,'Activate your registration!',$uzi,$lang_lang);
			}
		}
		frissit_user_vedelmi_szintek($user_id,1);
		//
		$_REQUEST['reg_kin_keresztul_uid']=(int)$_REQUEST['reg_kin_keresztul_uid'];
		if ($_REQUEST['reg_kin_keresztul_uid']>0) {
			$er=mysql_query('select id from userek where id='.$_REQUEST['reg_kin_keresztul_uid']) or hiba(__FILE__,__LINE__,mysql_error());
			$aux_ref=mysql_fetch_array($er);$kin_keresztul_uid=(int)$aux_ref[0];
		} else {
			$er=mysql_query('select id from userek where nev="'.sanitstr($_REQUEST['reg_kin_keresztul']).'"') or hiba(__FILE__,__LINE__,mysql_error());
			$aux_ref=mysql_fetch_array($er);$kin_keresztul_uid=(int)$aux_ref[0];
		}
		if ($kin_keresztul_uid>0) {
			if ($kin_keresztul_uid<$user_id) mysql_query('update userek set kin_keresztul_id='.$kin_keresztul_uid.', kin_keresztul_nev="" where id='.$user_id);
		} else {
			mysql_query('update userek set kin_keresztul_id=0, kin_keresztul_nev="'.sanitstr($_REQUEST['reg_kin_keresztul']).'" where id='.$user_id);
		}
		//
		mysql_query('insert into '.$database_mmog_nemlog.'.userek_ossz (nev,email,mikortol,uccso_akt,regip,jelszo_so,jelszo_hash,kitiltva,vagyon,avatar_crc,premium_alap,nyelv,eredeti_id,zanda_ref,zanda_session_id,zanda_id) select nev,email,mikortol,uccso_akt,regip,jelszo_so,jelszo_hash,kitiltva,vagyon,avatar_crc,premium_alap,nyelv,id,zanda_ref,zanda_session_id,zanda_id from userek where id='.$user_id);//innen nem fog torlodni
		//fantom
		if (isset($_POST['reg_fantom'])) {
			mysql_query('update userek set karrier=3,speci=3 where id='.$user_id);
			mysql_query('update bolygok set nev="B'.$bolygo['id'].'",kulso_nev="B'.$bolygo['id'].'" where id='.$bolygo['id']);
		}
		//
		//LOGIN
		$session_so=randomgen(32);
		$token=randomgen(32);
		$ttt=time()+$suti_hossz;
		$datum=date("Y-m-d H:i:s",$ttt);
		$kulon_ip=$_SERVER['REMOTE_ADDR'];$kulon_dn=gethostbyaddr($_SERVER['REMOTE_ADDR']);$ip_cim=$kulon_dn.' ('.$kulon_ip.')';
		mysql_query('update userek set session_so="'.$session_so.'",token="'.$token.'",session_ervenyesseg="'.$datum.'",uccso_multicsekk="'.date('Y-m-d H:i:s').'",uccso_login="'.date('Y-m-d H:i:s').'",uccso_login_ip="'.$ip_cim.'",inaktivitasi_ertesito=0 where id='.$user_id) or hiba(__FILE__,__LINE__,mysql_error());
		$r=mysql_query('select * from userek where id='.$user_id) or hiba(__FILE__,__LINE__,mysql_error());
		$adataim=mysql_fetch_array($r);
		$uid=$adataim['id'];
		setcookie('uid',$session_so.$uid,$ttt,'/');
		//
		mysql_select_db($database_mmog_nemlog);
		mysql_query('insert into loginek (uid,mikor,ip,technikai_login,kulon_ip,kulon_dn,sub_ip,sub_dn) values('.$uid.',"'.date('Y-m-d H:i:s').'","'.$ip_cim.'",2,"'.$kulon_ip.'","'.$kulon_dn.'",substring_index("'.$kulon_ip.'",".",2),substring_index("'.$kulon_dn.'",".",-2))');
		mysql_query('insert into loginek_osszes (uid,mikor,ip,technikai_login,kulon_ip,kulon_dn,sub_ip,sub_dn) values('.$uid.',"'.date('Y-m-d H:i:s').'","'.$ip_cim.'",2,"'.$kulon_ip.'","'.$kulon_dn.'",substring_index("'.$kulon_ip.'",".",2),substring_index("'.$kulon_dn.'",".",-2))');
		//multipontozas
		/*$er3=mysql_query('select * from loginek where uid!='.$uid) or hiba(__FILE__,__LINE__,mysql_error());
		$aux3=mysql_fetch_array($er3);
		if ($aux3[0]>0) if ($aux3[1]<=24) {//24 oran belul
			$bunti_pont=round(100/($aux3[1]+1));//0-1 oraig 0, 1-2 oraig 1...
			mysql_query('insert into multi_matrix (ki,kivel,pont) values('.$uid.','.$aux3[0].','.$bunti_pont.') on duplicate key update pont=pont+'.$bunti_pont) or hiba(__FILE__,__LINE__,mysql_error());
			mysql_query('insert into multi_matrix (ki,kivel,pont) values('.$aux3[0].','.$uid.','.$bunti_pont.') on duplicate key update pont=pont+'.$bunti_pont) or hiba(__FILE__,__LINE__,mysql_error());
		}*/
		/*
		//utolso lepes, ZandaNet
		mysql_query('insert into feliratkozasok_'.$lang_lang.' (nev,email,ip,dn,ua,ref,session_id,'.$szerver_prefix.'_reg,pub_key) values("'.sanitstr($_REQUEST['reg_nev']).'","'.sanitstr($_REQUEST['reg_email_1']).'","'.sanitstr($_SERVER['REMOTE_ADDR']).'","'.sanitstr(gethostbyaddr($_SERVER['REMOTE_ADDR'])).'","'.sanitstr($_SERVER['HTTP_USER_AGENT']).'","'.sanitstr($zanda_ref).'",'.$zanda_session_id.',1,"'.$pub_key.'") on duplicate key update '.$szerver_prefix.'_reg=1,pub_key="'.$pub_key.'"',$mysql_csatlakozas_zandanet);
		mysql_query('insert into zandanet_server_accounts (zanda_id,server_prefix,user_id,name) values('.$zanda_id.',"'.$szerver_prefix.'",'.$uid.',"'.sanitstr($_REQUEST['reg_nev']).'")',$mysql_csatlakozas_zandanet);
		*/
		//
		header('Location: .?reg=siker');exit;
	} else {
		mysql_query('delete from userek where id='.$user_id);
		header('Location: .?reg_hiba=sikertelen_email'.$adatok);exit;
	}
}


?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="hu-HU" lang="hu-HU">
<head>
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<title><?=$lang[$lang_lang]['index.php']['Zandagort online stratégiai játék - sN szerver prefix'];?><?=$szerver_prefix;?><?=$lang[$lang_lang]['index.php']['Zandagort online stratégiai játék - sN szerver'];?></title>
<meta name="description" content="<?=$lang[$lang_lang]['index.php']['Egy sci-fi témájú massively multiplayer online stratégiai játék.'];?>" />
<meta name="keywords" content="<?=$lang[$lang_lang]['index.php']['játék játékok game games online stratégiai űr sci-fi'];?>" />
<meta name="SKYPE_TOOLBAR" content="SKYPE_TOOLBAR_PARSER_COMPATIBLE" />
<link rel="stylesheet" type="text/css" href="stilus<?
if ($ismert) if ($user_beallitasok['css_munkahelyi']==1) echo '_min';
?>.css" />
<link rel="stylesheet" type="text/css" href="stilus_kieg_<?=$lang_lang;?><?
if ($ismert) if ($user_beallitasok['css_munkahelyi']==1) echo '_min';
?>.css" />
<link rel="shortcut icon" type="image/vnd.microsoft.icon" href="favicon.ico" />
<link rel="icon" type="image/vnd.microsoft.icon" href="favicon.ico" />
<? if ($ismert) include('index_belso.php');else include('index_kulso.php'); ?>
</body>
</html>
<? insert_into_php_debug_log(round(1000*(microtime(true)-$szkript_mikor_indul)));mysql_close($mysql_csatlakozas);?>