<?
include('csatlak.php');
include('ujkuki.php');
header('Cache-Control: no-cache');
header('Expires: -1');
header('Content-type: text/javascript; charset=utf-8');
if ($ismert) {
?>
/*{"nevek":<?
echo mysql2jsonarray('select concat(nev," (",if(y>0,concat("'.$lang[$lang_lang]['kisphpk']['D'].' ",round(y/2)),if(y<0,concat("'.$lang[$lang_lang]['kisphpk']['É'].' ",round(-y/2)),0)),", ",if(x>0,concat("'.$lang[$lang_lang]['kisphpk']['K'].' ",round(x/2)),if(x<0,concat("'.$lang[$lang_lang]['kisphpk']['Ny'].' ",round(-x/2)),0)),")") from bolygok where letezik=1 and tulaj='.$uid.' and nev like "'.sanitstr($_REQUEST['x']).'%" order by nev limit 10');
?>}*/
<?
}
?>
<? mysql_close($mysql_csatlakozas);?>