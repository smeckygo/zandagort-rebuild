<?
include('csatlak.php');
include('ujkuki.php');
header('Cache-Control: no-cache');
header('Expires: -1');
header('Content-type: text/javascript; charset=utf-8');
?>
/*{"nevek":<?
echo mysql2jsonarray('select nev from userek where szovetseg='.$adataim['szovetseg'].' and nev like "'.sanitstr($_REQUEST['x']).'%" order by nev limit 10');
?>}*/
<?

?>
<? mysql_close($mysql_csatlakozas);?>