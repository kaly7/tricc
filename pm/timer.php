<?php
$dsn = 'mysql:host=localhost;dbname=Robot';
$username = 'robot';
$password = 'abrakadabra';

$days_of_week = [
    'Sun' => ['english' => 'Sunday', 'hungarian' => 'Vasárnap', 'rovid'=> "V"],
    'Mon' => ['english' => 'Monday', 'hungarian' => 'Hétfő', 'rovid'=> "H"],
    'Tue' => ['english' => 'Tuesday', 'hungarian' => 'Kedd', 'rovid'=> "K"],
    'Wed' => ['english' => 'Wednesday', 'hungarian' => 'Szerda', 'rovid'=> "Sze"],
    'Thu' => ['english' => 'Thursday', 'hungarian' => 'Csütörtök', 'rovid'=> "Cs"],
    'Fri' => ['english' => 'Friday', 'hungarian' => 'Péntek', 'rovid'=> "P"],
    'Sat' => ['english' => 'Saturday', 'hungarian' => 'Szombat', 'rovid'=> "Szo"]
];

// Példa a nap nevének lekérdezésére
$current_day_short = date('D'); // Lekéri az aktuális nap rövidített nevét, pl. "Mon"
$current_day = $days_of_week[$current_day_short];

$nap_rovid = $current_day['rovid'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}

$datum = date('Y-m-d H:i');

$datum_nap= date('Y-m-d');
$sql0 = 'SELECT * FROM nap_tipusok where datum="'.$datum_nap.'" and (tipus="Munkaszüneti nap" or tipus="Ünnepnap") order by id';
$stmt0 = $pdo->prepare($sql0);
$stmt0->execute();
//echo date('H:i:00');

$results0 = $stmt0->fetchAll(PDO::FETCH_ASSOC);
foreach ($results0 as $row0) {
//    echo("NEM FUTTATUNK");
    exit(0);
}

$counter = 0;
$old_Route_id = 0;
$sql = 'SELECT * FROM records where active=1 order by id';
$stmt = $pdo->prepare($sql);
$stmt->execute();
//echo date('H:i:00');

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    $id = $row['id'];
    $pos = strpos($row['days'], $nap_rovid);

    // NAP stimmel?
    if ($pos !== false ) {
//	echo "OK\n";
	$nap = true;
    } else {
//	echo "NINCS\n";
	$nap = false;
    }
    

    //inactive ??
    $ido = false;
    if ( ($row['inactiveUntil'] == 1 ) and ($datum < $row['inactiveDate']) ) {
	$inactive = false;
    } else {
	if ($row['time'] == date('H:i:00') ) {
	    $inactive = true;
	    $ido = true;
	} 
    }

    
    //skipNext - kihagyas
    $skipNext = true;
    if ( ($row['skipNext'] == 1 ) and ($nap) and ($inactive) and ($ido) ) {
	// ezt modt akkor nem hajtjuk végre
	$skipNext = false;
	// vissza kell írni az adatbázisba a skipNext-t 0-ra
	// SQL UPDATE utasítás
	    $sql = 'UPDATE records SET skipNext = :skipNext WHERE id = :id';
//	    echo "skipNEXT\n";
	    // Előkészített utasítás végrehajtása
	    $stmt1 = $pdo->prepare($sql);
	    $x = 0;
	    $stmt1->bindParam(':skipNext', $x, PDO::PARAM_INT);
	    $stmt1->bindParam(':id', $row['id'], PDO::PARAM_INT);

	    if ($stmt1->execute()) {
//	        echo "A rekord sikeresen frissítve lett.";
	    } else {
//	        echo "Hiba történt a rekord frissítése során.";
	    }
    }


    //onceOnly
    if ( ($row['onceOnly'] == 1) and ($ido) and ($nap) and ($inactive)  ){
	// vissza kell írni az adatbázisba az active-t 0-ra
	// SQL UPDATE utasítás
	    $sql = 'UPDATE records SET active = :active, onceOnly= :onceOnly WHERE id = :id';
//	    echo "skipNEXT\n";
	    // Előkészített utasítás végrehajtása
	    $stmt2 = $pdo->prepare($sql);
	    $x = 0;
	    $stmt2->bindParam(':active', $x, PDO::PARAM_INT);
	    $stmt2->bindParam(':onceOnly', $x, PDO::PARAM_INT);
	    $stmt2->bindParam(':id', $row['id'], PDO::PARAM_INT);

	    if ($stmt2->execute()) {
//	        echo "A rekord sikeresen frissítve lett.";
	    } else {
//	        echo "Hiba történt a rekord frissítése során.";
	    }	
    }


    
    //MEHET?
    if ( ($nap) and ($ido) and ($inactive) and ($skipNext) ) {
//	echo "TALT TO FLEET...\n";
    //induljon a rabszolga! Utvonal összeállitas
	if ($old_Route_id != $row['Route_id']) {
	    $old_Route_id = $row['Route_id'];
	    $counter=0;
	};
	$sql = 'SELECT ra.Route_index, g.Goal_name FROM Route_adatok ra JOIN Goals g ON ra.Goal_index = g.Index_ where Route_index='.$row['Route_id'];
//	echo $sql."\n";
	$stmt3 = $pdo->prepare($sql);
	$stmt3->execute();
	$results3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);

	$datum2 = date('Y_m_d_H_i_s');
	$filename = '/var/www/html/pm/tmp/timer_'.$row['Route_id'].'_'.$counter."_".$datum2.".fleet";
	$file = fopen($filename,'w');
	$string = "";
	$cc = 0;
	foreach ($results3 as $row3) {
	    $string = $string." ".$row3['Goal_name']." pickup 10 ";    
//	    echo "GOAL\n";
	    $cc++;
	}
	$string = "queuemulti ".$cc." 2 ".$string." ".$datum2."_TIMER";
	fwrite($file,$string);
	fclose($file);
	$counter++;
	
    }
    
}



// Egyszeri időzített pont-pont útvonalak feldolgozása
$sql_pp = "SELECT e.*,
               gi.Goal_name AS indulo_name,
               gk.Goal_name AS kozbenso_name,
               gc.Goal_name AS cel_name
           FROM egyedi_utemezesek e
           JOIN Goals gi ON e.indulo_goal_index  = gi.Index_
           JOIN Goals gk ON e.kozbenso_goal_index = gk.Index_
           JOIN Goals gc ON e.cel_goal_index      = gc.Index_
           WHERE e.active = 1 AND e.idopont <= NOW()";
$stmt_pp = $pdo->prepare($sql_pp);
$stmt_pp->execute();
$results_pp = $stmt_pp->fetchAll(PDO::FETCH_ASSOC);

foreach ($results_pp as $re) {
    $datum2   = date('Y_m_d_H_i_s');
    $filename = '/var/www/html/pm/tmp/timer_pp_' . $re['id'] . '_' . $datum2 . '.fleet';
    $string   = "queuemulti 3 2 " . $re['indulo_name'] . " pickup 10 " . $re['kozbenso_name'] . " pickup 10 " . $re['cel_name'] . " pickup 10 " . $datum2 . "_PP";
    file_put_contents($filename, $string);

    $upd = $pdo->prepare("UPDATE egyedi_utemezesek SET active = 0 WHERE id = :id");
    $upd->execute([':id' => $re['id']]);
}

?>
