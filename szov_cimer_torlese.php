<?
include('csatlak.php');
include('ujkuki.php');

if ($adataim['szovetseg']==0) kilep($lang[$lang_lang]['kisphpk']['Nem vagy tagja sz�vets�gnek.']);

$res=mysql_query('select * from szovetsegek where id='.$adataim['szovetseg']) or hiba(__FILE__,__LINE__,mysql_error());
$szovetseg=mysql_fetch_array($res);

if ($szovetseg['alapito']!=$uid) kilep($lang[$lang_lang]['kisphpk']['Nem te vagy az alap�t�.']);

mysql_query('update szovetsegek set cimer_ext="" where id='.$szovetseg['id']);

kilep();
?>