<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="styles.css">

    <title>Szerver Időbeállítás</title>
    <script>
        // JavaScript függvény az aktuális idő megjelenítéséhez
        function displayServerTime(serverTime) {
            // JavaScript időobjektum létrehozása a szerver időből
            var serverDate = new Date(serverTime);
            setInterval(function() {
                // Frissítés másodpercenként
                serverDate.setSeconds(serverDate.getSeconds() + 1);

                var hours = serverDate.getHours();
                var minutes = serverDate.getMinutes();
                var seconds = serverDate.getSeconds();
                var day = serverDate.getDate();
                var month = serverDate.getMonth() + 1; // Január a 0. hónap
                var year = serverDate.getFullYear();

                // Formázás kétjegyű számokra (pl. 01, 02)
                hours = hours < 10 ? '0' + hours : hours;
                minutes = minutes < 10 ? '0' + minutes : minutes;
                seconds = seconds < 10 ? '0' + seconds : seconds;
                day = day < 10 ? '0' + day : day;
                month = month < 10 ? '0' + month : month;

                var currentTimeString = year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
                document.getElementById('current-time').innerText = currentTimeString;
            }, 1000);
        }
    </script>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<center>
<a href="index.php"> <button type=button class="btn btn-add" value="Főmenü">Vissza</button></a><br>
    <h1>Szerver Időbeállítás</h1>

    <p>Jelenlegi idő: <strong id="current-time"></strong></p>

    <table>
        <tr>
            <th>Dátum és idő megadása</th>
        </tr>
        <tr>
            <td>
                <form id="timeForm" method="post">
                    <label for="date">Válassza ki a dátumot:</label>
                    <input type="date" id="date" name="date" required>
                    <br><br>
                    <label for="time">Válassza ki az időt:</label>
                    <input type="time" id="time" name="time" required>
                    <br><br>
                    <input type="submit" value="Idő Beállítása">
                </form>
            </td>
        </tr>
    </table>

    <div id="message" class="message">
        <?php
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $date = $_POST["date"];
            $time = $_POST["time"];

            // Formázza az időt a `date` parancshoz
            $datetime = $date . " " . $time;
            $command = "sudo /bin/date -s \"$datetime\"";
	    //echo $command;
            // Futtassa a parancsot
            $output = shell_exec($command);

            if ($output === null) {
                echo "Hiba történt az idő beállítása közben.";
            } else {
                echo "Az idő sikeresen beállítva: " . $datetime;
            }
        } else {
            // Amikor az oldal betöltődik, jelenítse meg a szerver aktuális idejét
            date_default_timezone_set('Europe/Budapest'); // Állítsd be a megfelelő időzónát
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i');
            $currentDateTime = date('Y-m-d H:i:s'); // Szerver aktuális ideje teljes formátumban

            echo "<script>displayServerTime('$currentDateTime');</script>";
        }
        ?>
    </div>
</center>
</body>
</html>
