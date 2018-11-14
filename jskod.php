<?
include('csatlak.php');include('ujkuki.php');
include_once('langjs_s.php');

header('Cache-Control: no-cache');header('Expires: -1');//az �lland� fejlesztget�sek miatt van �gy be�ll�tva; lehetne helyette az index_belso.php-ban ?v=1,2,...-t is megadni belinkel�skor
header('Content-type: text/javascript; charset=utf-8');

//kv�zi titkos�t�s, ha nem akarjuk, hogy valaki el tudja �rni az unpack-elt k�dot
//b�r a pack l�nyege f�leg ink�bb a t�m�rs�g
//ennek a szkriptnek pedig a legl�nyege, hogy a nyelvf�gg� r�szeket a lang_js.php ($langjs) alapj�n behelyettes�tse
$konyvtar='jskod_vustygbkforjfdjp';

//alap�rtelmezett az �les verzi�
$verzio=1;

//admin-nak fejleszt�i verzi�t betenni
if ($ismert) if ($uid==1) $verzio=2;

//ez esetben m�sik f�jlokkal dolgozunk
if ($verzio==2) $konyvtar.='_v2';

//ezek a js f�jlok csomagol�dnak egybe
$lista=array('jskod.js','jskod_terkep.js','jskod_akciok.js','jskod_aux.js');
$cel_lista=array('jskod'.$lang__lang.'.js','jskod_terkep'.$lang__lang.'.js','jskod_akciok'.$lang__lang.'.js','jskod_aux'.$lang__lang.'.js');

clearstatcache();
$packed='';
for($i=0;$i<count($lista);$i++) {
	$src=$konyvtar.'/'.$lista[$i];
	$dest=$konyvtar.'/'.$cel_lista[$i].'_packed';
	if (!file_exists($dest) || filemtime($src)>filemtime($dest)) {//ha v�ltozott a js f�jl, �jragener�lni
		if (isset($langjs[$lang_lang][$lista[$i]])) $script=strtr(file_get_contents($src),$langjs[$lang_lang][$lista[$i]]);
		else $script=file_get_contents($src);
		if ($verzio==2) {//fejleszt�i verzi�: egyr�szt nincs pack-elve, �gy k�nnyebb debug-olni, m�sr�szt elt�rhet az �lest�l, �gy �les�t�s el�tt mindig itt lehet tesztelni
			$x=$script;
		} else {//�les verzi�
			require_once $konyvtar.'/class.JavaScriptPacker.php';
			$packer=new JavaScriptPacker($script,'Normal',true,false);
			$x=$packer->pack();
		}
		file_put_contents($dest,$x);
		$packed.=$x;
	} else {//ha nem, akkor mehet a r�gi
		$packed.=file_get_contents($dest);
	}
}


echo $packed;

insert_into_php_debug_log(round(1000*(microtime(true)-$szkript_mikor_indul)));mysql_close($mysql_csatlakozas);
?>