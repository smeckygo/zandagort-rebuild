<?
include('../csatlak.php');
$specko_szovetsegek_listaja_str=implode(',',$specko_szovetsegek_listaja);


$egy_hete=date('Y-m-d H:i:s',time()-604800);;
$stat='';
$stat.="\n".'<table class="stat_table">';

$stat.="\n".'<tr class="cimsor"><th colspan="2">number of players</th></tr>';
$stat.="\n".'<tr><th>signed up</th><td>'.number_format(mysql2num('select count(1) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>active in the last 7 days</th><td>'.number_format(mysql2num('select count(1) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.') and coalesce(timestampdiff(minute,uccso_akt,now()),coalesce(1440-timestampdiff(minute,now(),session_ervenyesseg),coalesce(timestampdiff(minute,uccso_login,now()),timestampdiff(minute,mikortol,now()))))<=10080'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>active in the last 24 hours</th><td>'.number_format(mysql2num('select count(1) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.') and coalesce(timestampdiff(minute,uccso_akt,now()),coalesce(1440-timestampdiff(minute,now(),session_ervenyesseg),coalesce(timestampdiff(minute,uccso_login,now()),timestampdiff(minute,mikortol,now()))))<=1440'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>active in the last 15 minutes (online)</th><td>'.number_format(mysql2num('select count(1) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.') and coalesce(timestampdiff(minute,uccso_akt,now()),coalesce(1440-timestampdiff(minute,now(),session_ervenyesseg),coalesce(timestampdiff(minute,uccso_login,now()),timestampdiff(minute,mikortol,now()))))<=15'),0,',',' ').'</td></tr>';

$stat.="\n".'<tr class="cimsor"><th colspan="2">development of players</th></tr>';
$stat.="\n".'<tr><th>average score</th><td>'.number_format(mysql2num('select avg(pontszam_exp_atlag) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>average number of planets</th><td>'.number_format(mysql2num('select count(1) from bolygok where tulaj>0 and tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')')/mysql2num('select count(1) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.')'),2,'.',' ').'</td></tr>';
$stat.="\n".'<tr><th>average tech level</th><td>'.number_format(mysql2num('select avg(techszint) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.')'),1,'.',' ').'</td></tr>';
$stat.="\n".'<tr><th>effective number of players</th><td>'.number_format(mysql2num('select round(pow(sum(pontszam_exp_atlag),2)/sum(pow(pontszam_exp_atlag,2))) from userek where szovetseg not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>players with more than one planet</th><td>'.number_format(mysql2num('select count(1) from (select tulaj,count(1) from bolygok where tulaj>0 and tulaj_szov not in ('.$specko_szovetsegek_listaja_str.') group by tulaj having count(1)>1) t'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>players with fleet</th><td>'.number_format(mysql2num('select count(distinct tulaj) from flottak where tulaj>0 and tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';

$bolygok_szama_ossz=mysql2num('select count(1) from bolygok where letezik=1');
$foglalt_bolygok_szama=mysql2num('select count(1) from bolygok where letezik=1 and tulaj!=0 and tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')');
$regisztralhato_bolygok_szama=mysql2num('select count(1) from bolygok where letezik=1 and tulaj=0 and alapbol_regisztralhato=1');
$stat.="\n".'<tr class="cimsor"><th colspan="2">planets</th></tr>';
$stat.="\n".'<tr><th>average population</th><td>'.number_format(mysql2num('select avg(pop) from bolygo_ember be,bolygok b where b.id=be.bolygo_id and b.tulaj>0 and b.tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')'),0,'.',' ').'</td></tr>';
$stat.="\n".'<tr><th>average ratio of built-in area</th><td>'.number_format(mysql2num('select (1-avg(terulet_szabad)/avg(terulet))*100 from bolygok where tulaj>0 and tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')'),1,'.',' ').'%</td></tr>';
$stat.="\n".'<tr><th>average ratio of urbanization</th><td>'.number_format(mysql2num('select sum(30000*bgy.db) from bolygo_gyar bgy, bolygok b where bgy.gyar_id=78 and bgy.bolygo_id=b.id and b.tulaj>0 and b.tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')')/mysql2num('select sum(if(bgy.gyar_id=77,500*bgy.db,30000*bgy.db)) from bolygo_gyar bgy, bolygok b where bgy.gyar_id in (77,78) and bgy.bolygo_id=b.id and b.tulaj>0 and b.tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')')*100,1,'.',' ').'%</td></tr>';
$stat.="\n".'<tr><th>number (ratio) of occupied planets</th><td>'.number_format($foglalt_bolygok_szama,0,',',' ').' ('.number_format($foglalt_bolygok_szama/$bolygok_szama_ossz*100,0,'.',' ').'%)</td></tr>';
$stat.="\n".'<tr><th>number (ratio) of planets available at registration</th><td>'.number_format($regisztralhato_bolygok_szama,0,',',' ').' ('.number_format($regisztralhato_bolygok_szama/$bolygok_szama_ossz*100,0,'.',' ').'%)</td></tr>';

$stat.="\n".'<tr class="cimsor"><th colspan="2">alliances</th></tr>';
$stat.="\n".'<tr><th>number of alliances</th><td>'.number_format(mysql2num('select count(1) from szovetsegek where id not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>number of alliances with at least 10 members</th><td>'.number_format(mysql2num('select count(1) from szovetsegek where tagletszam>=10 and id not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>effective number of alliances</th><td>'.number_format(mysql2num('select round(pow(sum(pontszam_exp_atlag),2)/sum(pow(pontszam_exp_atlag,2))) from (select sz.id,sum(u.pontszam_exp_atlag) as pontszam_exp_atlag from szovetsegek sz, userek u where u.szovetseg=sz.id and sz.id not in ('.$specko_szovetsegek_listaja_str.') group by sz.id) t'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>average score</th><td>'.number_format(mysql2num('select sum(pontszam_exp_atlag) from userek where szovetseg>0 and szovetseg not in ('.$specko_szovetsegek_listaja_str.')')/mysql2num('select count(1) from szovetsegek where id not in ('.$specko_szovetsegek_listaja_str.')'),0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>average number of planets</th><td>'.number_format(mysql2num('select count(1) from bolygok where tulaj_szov>0 and tulaj_szov not in ('.$specko_szovetsegek_listaja_str.')')/mysql2num('select count(1) from szovetsegek where id not in ('.$specko_szovetsegek_listaja_str.')'),0,'.',' ').'</td></tr>';
$stat.="\n".'<tr><th>number of lone wolves</th><td>'.number_format(mysql2num('select count(1) from userek where szovetseg=0'),0,',',' ').'</td></tr>';

/*
$stat.="\n".'<tr class="cimsor"><th colspan="2">research</th></tr>';
$er=mysql_query('select lower(kt.nev_en),avg(szint),max(szint) from kutatasi_temak kt,user_kutatasi_szint uksz,userek u where kt.id=uksz.kf_id and kt.id in (1,2,3,4,5) and uksz.user_id=u.id and u.szovetseg not in ('.$specko_szovetsegek_listaja_str.') group by kt.id');
while($aux=mysql_fetch_array($er)) $stat.="\n".'<tr><th>average (max) level of '.$aux[0].'</th><td>'.number_format($aux[1],1,'.',' ').' ('.number_format($aux[2],0,'.',' ').')</td></tr>';
*/

/*
$stat.="\n".'<tr class="cimsor"><th colspan="2">technologies</th></tr>';
$stat.="\n".'<tr class="cimsor"><td colspan="2">how many players have developed each technology</td></tr>';
*/

$teljes_egyenertek=mysql2num('select coalesce(sum(fh.ossz_hp*h.ar)/100/100,0) from flotta_hajo fh, flottak f, hajok h, userek u where fh.flotta_id=f.id and fh.hajo_id=h.id and f.tulaj=u.id and u.szovetseg not in ('.$specko_szovetsegek_listaja_str.')');
$ossz_hp=mysql2num('select coalesce(sum(fh.ossz_hp),0) from flotta_hajo fh, flottak f, hajok h, userek u where fh.flotta_id=f.id and fh.hajo_id=h.id and f.tulaj=u.id and u.szovetseg not in ('.$specko_szovetsegek_listaja_str.')');
$flottak_szama=mysql2num('select count(1) from flottak f,userek u where f.tulaj=u.id and u.szovetseg not in ('.$specko_szovetsegek_listaja_str.')');
$stat.="\n".'<tr class="cimsor"><th colspan="2">fleets</th></tr>';
$stat.="\n".'<tr><th>number of fleets</th><td>'.number_format($flottak_szama,0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>total equivalent</th><td>'.number_format($teljes_egyenertek,0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>average equivalent</th><td>'.($flottak_szama>0?number_format($teljes_egyenertek/$flottak_szama,1,'.',' '):'-').'</td></tr>';
$er=mysql_query('
select h.generacio,sum(fh.ossz_hp*h.ar)/10000 as egyenertek,sum(fh.ossz_hp) as osszhp
from flotta_hajo fh, hajok h, flottak f
where fh.hajo_id=h.id
and fh.flotta_id=f.id and f.tulaj_szov not in ('.$specko_szovetsegek_listaja_str.') and f.tulaj>0 and h.generacio>0
group by h.generacio
');
$stat.="\n".'<tr><th>distribution of generations (equivalent, HP)</th><td style="text-align: left">';
while($aux=mysql_fetch_array($er)) $stat.=$aux[0].'. gen: '.round($aux[1]/$teljes_egyenertek*100).'% ('.round($aux[2]/$ossz_hp*100).'%)<br />';
$stat.='</td></tr>';
$er=mysql_query('
select h.nev_en,sum(fh.ossz_hp*h.ar)/10000 as egyenertek,sum(fh.ossz_hp) as osszhp
from flotta_hajo fh, hajok h, flottak f
where fh.hajo_id=h.id
and fh.flotta_id=f.id and f.tulaj_szov not in ('.$specko_szovetsegek_listaja_str.') and f.tulaj>0
group by h.id
having osszhp>0
');
$stat.="\n".'<tr><th>distribution of types (equivalent, HP)</th><td style="text-align: left">';
while($aux=mysql_fetch_array($er)) $stat.=$aux[0].': '.number_format($aux[1]/$teljes_egyenertek*100,1,'.',' ').'% ('.number_format($aux[2]/$ossz_hp*100,1,'.',' ').'%)<br />';
$stat.='</td></tr>';



$megsemmisult_mindig=mysql2num('select sum(megsemmisult_egyenertek)/100 from '.$database_mmog_nemlog.'.hist_csatak');
$megsemmisult_egy_het=mysql2num('select sum(megsemmisult_egyenertek)/100 from '.$database_mmog_nemlog.'.hist_csatak where timestampdiff(hour,mikor,now())<168');
$stat.="\n".'<tr class="cimsor"><th colspan="2">battles</th></tr>';
$stat.="\n".'<tr><th>equivalent destroyed during the server</th><td>'.number_format($megsemmisult_mindig,0,'.',' ').'</td></tr>';
$stat.="\n".'<tr><th>equivalent destroyed during the last 7 days</th><td>'.number_format($megsemmisult_egy_het,0,'.',' ').'</td></tr>';





$tozsdei_forgalom=mysql2row('select sum(tgy.forgalom/e.savszel_igeny),sum(tgy.forgalom*tgy.zaro_ar)
from '.$database_mmog_nemlog.'.tozsdei_gyertyak tgy, eroforrasok e
where tgy.felbontas=1 and tgy.mikor>"'.$egy_hete.'" and tgy.termek_id=e.id');

$top_tozsdei_eroforras_shyban=mysql2row('select lower(e.nev_en),sum(tgy.forgalom*tgy.zaro_ar)
from '.$database_mmog_nemlog.'.tozsdei_gyertyak tgy, eroforrasok e
where tgy.felbontas=1 and tgy.mikor>"'.$egy_hete.'" and tgy.termek_id=e.id
group by e.id
order by sum(tgy.forgalom*tgy.zaro_ar) desc limit 1');

$stat.="\n".'<tr class="cimsor"><th colspan="2">market</th></tr>';
$stat.="\n".'<tr><th>weekly volume (TT)</th><td>'.number_format($tozsdei_forgalom[0],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>weekly volume (SHY)</th><td>'.number_format($tozsdei_forgalom[1],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>resource with the most volume</th><td>'.$top_tozsdei_eroforras_shyban[0].' ('.round($top_tozsdei_eroforras_shyban[1]/$tozsdei_forgalom[1]*100).'%)</td></tr>';

$stat.="\n".'<tr class="cimsor"><th colspan="2">top</th></tr>';
$stat.="\n".'<tr><th>top 10 players</th><td style="text-align: left">'.mysql2num('select group_concat(if(length(szovi)>0,concat(nev," (",szovi,")"),nev) order by pontszam_exp_atlag desc separator "<br />") from (select u.nev,coalesce(sz.nev,"") as szovi,u.pontszam_exp_atlag from userek u left join szovetsegek sz on u.szovetseg=sz.id where (u.karrier!=3 or u.speci!=3) and u.szovetseg not in ('.$specko_szovetsegek_listaja_str.') order by u.pontszam_exp_atlag desc limit 10) t').'</td></tr>';
$stat.="\n".'<tr><th>top lone wolf</th><td>'.mysql2num('select nev from userek where (karrier!=3 or speci!=3) and szovetseg=0 order by pontszam_exp_atlag desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>top noob (younger than 7 days)</th><td>'.mysql2num('select if(u.szovetseg>0,concat(u.nev," (",sz.nev,")"),u.nev) from userek u left join szovetsegek sz on u.szovetseg=sz.id where (u.karrier!=3 or u.speci!=3) and u.szovetseg not in ('.$specko_szovetsegek_listaja_str.') and timestampdiff(minute,u.mikortol,now())<7*1440 order by u.pontszam_exp_atlag desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (score)</th><td>'.mysql2num('select sz.nev from szovetsegek sz, userek u where sz.id not in ('.$specko_szovetsegek_listaja_str.') and sz.id=u.szovetseg group by sz.id order by sum(u.pontszam_exp_atlag) desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (number of planets)</th><td>'.mysql2num('select sz.nev from szovetsegek sz, userek u, bolygok b where sz.id not in ('.$specko_szovetsegek_listaja_str.') and sz.id=u.szovetseg and u.id=b.tulaj group by sz.id order by count(1) desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (number of members)</th><td>'.mysql2num('select nev from szovetsegek where id not in ('.$specko_szovetsegek_listaja_str.') order by tagletszam desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (score/member), min 10 members</th><td>'.mysql2num('select sz.nev from szovetsegek sz, userek u where sz.id not in ('.$specko_szovetsegek_listaja_str.') and sz.id=u.szovetseg and sz.tagletszam>=10 group by sz.id order by sum(u.pontszam_exp_atlag)/sz.tagletszam desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (score/member), min 5 members</th><td>'.mysql2num('select sz.nev from szovetsegek sz, userek u where sz.id not in ('.$specko_szovetsegek_listaja_str.') and sz.id=u.szovetseg and sz.tagletszam>=5 group by sz.id order by sum(u.pontszam_exp_atlag)/sz.tagletszam desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (number of planets/member), min 10 members</th><td>'.mysql2num('select sz.nev from szovetsegek sz, userek u, bolygok b where sz.id not in ('.$specko_szovetsegek_listaja_str.') and sz.id=u.szovetseg and u.id=b.tulaj and sz.tagletszam>=10 group by sz.id order by count(1)/sz.tagletszam desc limit 1').'</td></tr>';
$stat.="\n".'<tr><th>alliance (number of planets/member), min 5 members</th><td>'.mysql2num('select sz.nev from szovetsegek sz, userek u, bolygok b where sz.id not in ('.$specko_szovetsegek_listaja_str.') and sz.id=u.szovetseg and u.id=b.tulaj and sz.tagletszam>=5 group by sz.id order by count(1)/sz.tagletszam desc limit 1').'</td></tr>';




if ($vegjatek_stat) {

$stat.="\n".'<tr class="cimsor"><th colspan="2">endgame</th></tr>';

$nemletezo_bolygok_szama_ossz=mysql2num('select count(1) from bolygok where letezik=0');//bolygok_szama_ossz=letezok szama
$stat.="\n".'<tr><th>number (ratio) of annihilated planets</th><td>'.number_format($nemletezo_bolygok_szama_ossz,0,',',' ').' ('.number_format($nemletezo_bolygok_szama_ossz/($bolygok_szama_ossz+$nemletezo_bolygok_szama_ossz)*100,1,'.',' ').'%)</td></tr>';

$r=mysql_query('select if(csf.tulaj=-1,"Z",if(csf.tulaj=0,"K","E")) as tul,sum(csf.egyenertek_elotte-csf.egyenertek_utana)/100
from '.$database_mmog_nemlog.'.hist_csatak cs, '.$database_mmog_nemlog.'.hist_csata_flotta csf
where csf.csata_id=cs.id and cs.zanda=1
group by tul');
while($aux=mysql_fetch_array($r)) $vegjatek_megsemmisult_mindig[$aux[0]]=$aux[1];

$r=mysql_query('select if(csf.tulaj=-1,"Z",if(csf.tulaj=0,"K","E")) as tul,sum(csf.egyenertek_elotte-csf.egyenertek_utana)/100
from '.$database_mmog_nemlog.'.hist_csatak cs, '.$database_mmog_nemlog.'.hist_csata_flotta csf
where csf.csata_id=cs.id and cs.zanda=1 and timestampdiff(hour,cs.mikor,now())<168
group by tul');
while($aux=mysql_fetch_array($r)) $vegjatek_megsemmisult_egy_het[$aux[0]]=$aux[1];

$stat.="\n".'<tr><th>equivalent destroyed during the server</th><td>'.number_format($vegjatek_megsemmisult_mindig['Z']+$vegjatek_megsemmisult_mindig['E']+$vegjatek_megsemmisult_mindig['K'],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>alien</th><td>'.number_format($vegjatek_megsemmisult_mindig['Z'],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>human</th><td>'.number_format($vegjatek_megsemmisult_mindig['E'],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>pirate</th><td>'.number_format($vegjatek_megsemmisult_mindig['K'],0,',',' ').'</td></tr>';

$stat.="\n".'<tr><th>equivalent destroyed during the last 7 days</th><td>'.number_format($vegjatek_megsemmisult_egy_het['Z']+$vegjatek_megsemmisult_egy_het['E']+$vegjatek_megsemmisult_egy_het['K'],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>alien</th><td>'.number_format($vegjatek_megsemmisult_egy_het['Z'],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>human</th><td>'.number_format($vegjatek_megsemmisult_egy_het['E'],0,',',' ').'</td></tr>';
$stat.="\n".'<tr><th>pirate</th><td>'.number_format($vegjatek_megsemmisult_egy_het['K'],0,',',' ').'</td></tr>';

$stat.="\n".'<tr><th>top Zanda-killers during the server</th><td style="text-align: left">';
$r=mysql_query('select u.eredeti_id,u.nev,round(ttt.megsemmisitett_zanda_egyenertek) as megsemmisitett_zanda_egyenertek, coalesce(sz.nev,"") as szov_nev
from '.$database_mmog_nemlog.'.userek_ossz u
inner join (select if(csf.iranyito>0,csf.iranyito,csf.tulaj) as ki_kapja,sum(t.megsemmisult_zanda*csf.egyenertek_elotte/t.emberi_egyenertek) as megsemmisitett_zanda_egyenertek
from '.$database_mmog_nemlog.'.hist_csata_flotta csf
,(select cs.id,sum(if(csf.tulaj=-1,csf.egyenertek_elotte-csf.egyenertek_utana,0)) as megsemmisult_zanda,sum(if(csf.tulaj>=0,csf.egyenertek_elotte,0)) as emberi_egyenertek from '.$database_mmog_nemlog.'.hist_csatak cs, '.$database_mmog_nemlog.'.hist_csata_flotta csf where csf.csata_id=cs.id and cs.zanda=1 group by cs.id) t
where csf.csata_id=t.id and csf.tulaj>0
group by ki_kapja
order by megsemmisitett_zanda_egyenertek desc) ttt on u.eredeti_id=ttt.ki_kapja
left join userek uu on uu.id=u.eredeti_id
left join szovetsegek sz on sz.id=uu.szovetseg
order by ttt.megsemmisitett_zanda_egyenertek desc
limit 10');
while($aux=mysql_fetch_array($r)) $stat.=$aux[1].($aux['szov_nev']==''?'':(' ('.$aux['szov_nev'].')')).'<br />';
$stat.='</td></tr>';

$stat.="\n".'<tr><th>top Zanda-killers during the last 7 days</th><td style="text-align: left">';
$r=mysql_query('select u.eredeti_id,u.nev,round(ttt.megsemmisitett_zanda_egyenertek) as megsemmisitett_zanda_egyenertek, coalesce(sz.nev,"") as szov_nev
from '.$database_mmog_nemlog.'.userek_ossz u
inner join (select if(csf.iranyito>0,csf.iranyito,csf.tulaj) as ki_kapja,sum(t.megsemmisult_zanda*csf.egyenertek_elotte/t.emberi_egyenertek) as megsemmisitett_zanda_egyenertek
from '.$database_mmog_nemlog.'.hist_csata_flotta csf
,(select cs.id,sum(if(csf.tulaj=-1,csf.egyenertek_elotte-csf.egyenertek_utana,0)) as megsemmisult_zanda,sum(if(csf.tulaj>=0,csf.egyenertek_elotte,0)) as emberi_egyenertek from '.$database_mmog_nemlog.'.hist_csatak cs, '.$database_mmog_nemlog.'.hist_csata_flotta csf where csf.csata_id=cs.id and cs.zanda=1 and timestampdiff(hour,cs.mikor,now())<168 group by cs.id) t
where csf.csata_id=t.id and csf.tulaj>0
group by ki_kapja
order by megsemmisitett_zanda_egyenertek desc) ttt on u.eredeti_id=ttt.ki_kapja
left join userek uu on uu.id=u.eredeti_id
left join szovetsegek sz on sz.id=uu.szovetseg
order by ttt.megsemmisitett_zanda_egyenertek desc
limit 10');
while($aux=mysql_fetch_array($r)) $stat.=$aux[1].($aux['szov_nev']==''?'':(' ('.$aux['szov_nev'].')')).'<br />';
$stat.='</td></tr>';

}//vegjatek statok




$stat.="\n".'</table>';


header('Content-type: text/html;charset=utf-8');
echo $stat;

mysql_close($mysql_csatlakozas);
?>