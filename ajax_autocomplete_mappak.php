<?
include('csatlak.php');
include('ujkuki.php');
header('Cache-Control: no-cache');
header('Expires: -1');
header('Content-type: text/javascript; charset=utf-8');
?>
/*{"nevek":<?
echo mysql2jsonarray('select distinct mappa from levelek where tulaj='.$uid.' order by mappa limit 10');
?>}*/
<?

?>
<? mysql_close($mysql_csatlakozas);?>