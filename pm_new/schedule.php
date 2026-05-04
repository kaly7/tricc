<?php
// Adatbázis kapcsolódási adatok
$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";
$route_Index_ = $_GET['id'];

//echo $route_Index_;
//GOALS beolvasasa


$sql = "select * from Goals order by Index_";
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query($sql);
$goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
//    echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $goal_name[$goals_number] = $row["Goal_name"];
    $goal_name_megjegyzes[$goals_number] = $row["Megjegyzes"];
    $goal_Index_[$goals_number] = $row["Index_"];
    $goals_number++;
  }
} else {
  echo "0 results";
}
$conn->close();

$sql = "select * from Route_adatok where Route_index=".$route_Index_."";
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query($sql);
$route_goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
//    echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_index"]. "<br>";
    $route_goal_index[$route_goals_number] = $row["Goal_index"];
    for ($i=0;$i<$goals_number;$i++) {
	if ($route_goal_index[$route_goals_number] == $goal_Index_[$i]) {
	    $route_goal_Name[$route_goals_number] = $goal_name_megjegyzes[$i];
	}
    }
    $route_goals_number++;
  }
} else {
  echo "0 results";
}
$conn->close();




// Kapcsolódás az adatbázishoz
$conn = new mysqli($servername, $username, $password, $dbname);

// Kapcsolódás ellenőrzése
if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

// Adatok lekérdezése
$sql = "SELECT * FROM records where Route_id =".$route_Index_;
$result = $conn->query($sql);

// Adatok betöltése PHP változóba
$rows = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Útvonal időzítése</title>
    <link rel="stylesheet" href="styles.css">

</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>
<a href="schedule_add.php"> <button type=button class="button_x" value="Főmenü">Vissza</button></a><br>
    <h2>Útvonal időzítése</h2>
<?php
    // echo "route id:".$route_Index_."<hr>"; 
?>

<?php
//Goals kiiratasa
    for ($i=0;$i<$route_goals_number;$i++) {
	echo(($i+1)."-".$route_goal_Name[$i]."&nbsp;&nbsp;");
    }
?>

    <form id="dynamicForm" action="save_data.php" method="POST">
        <button type="button" class="btn btn-add" onclick="addRow()">Sor hozzáadása</button>
        <table id="dynamicTable">
            <thead>
                <tr>
                    <th>Napok</th>
                    <th>Időpont</th>
                    <th>Aktív</th>
                    <th>Inaktív dátumig</th>
                    <th>Kihagyás</th>
                    <th>Csak egyszer</th>
                    <th>Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <!-- Dinamikus sorok ide kerülnek PHP-val -->
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td>
                            <div class="day-container">
                                <?php
                                $days = ["H", "K", "Sze", "Cs", "P", "Szo", "V"];
                                $selectedDays = explode(', ', $row['days']);
                                foreach ($days as $day):
                                ?>
                                    <div>
                                        <input type="checkbox" name="rows[<?php echo $index; ?>][days][]" value="<?php echo $day; ?>" <?php echo in_array($day, $selectedDays) ? 'checked' : ''; ?>>
                                        <label><?php echo $day; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <input type="time" class="time-input" name="rows[<?php echo $index; ?>][time]" value="<?php echo $row['time']; ?>">
                        </td>
                        <td>
                            <input type="checkbox" name="rows[<?php echo $index; ?>][active]" <?php echo $row['active'] ? 'checked' : ''; ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="rows[<?php echo $index; ?>][inactiveUntil]" <?php echo $row['inactiveUntil'] ? 'checked' : ''; ?>>
                            <input type="date" class="date-input" name="rows[<?php echo $index; ?>][inactiveDate]" value="<?php echo $row['inactiveDate']; ?>">
                        </td>
                        <td>
                            <input type="checkbox" name="rows[<?php echo $index; ?>][skipNext]" <?php echo $row['skipNext'] ? 'checked' : ''; ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="rows[<?php echo $index; ?>][onceOnly]" <?php echo $row['onceOnly'] ? 'checked' : ''; ?>>
                        </td>
                        <td>
                            <button type="button" class="button_delete" onclick="removeRow(this)">Törlés</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
<?php
    echo "<input type=\"hidden\" name=\"id\" value=\"".$route_Index_."\">";
?>
        <button type="submit" class="button_mentes">Mentés</button>
    </form>

    <script>
        let rowCount = <?php echo count($rows); ?>;

        function addRow() {
            const table = document.getElementById("dynamicTable").getElementsByTagName("tbody")[0];
            const newRow = table.insertRow();

            // Napok checkboxok
            const daysCell = newRow.insertCell(0);
            const daysContainer = document.createElement("div");
            daysContainer.className = "day-container";

            const days = ["H", "K", "Sze", "Cs", "P", "Szo", "V"];
            days.forEach(day => {
                const dayDiv = document.createElement("div");

                const checkbox = document.createElement("input");
                checkbox.type = "checkbox";
                checkbox.name = `rows[${rowCount}][days][]`;
                checkbox.value = day;

                const label = document.createElement("label");
                label.textContent = day;

                dayDiv.appendChild(checkbox);
                dayDiv.appendChild(label);
                daysContainer.appendChild(dayDiv);
            });

            daysCell.appendChild(daysContainer);

            // Idő kiválasztó
            const timeCell = newRow.insertCell(1);
            const timeInput = document.createElement("input");
            timeInput.type = "time";
            timeInput.className = "time-input";
	    timeInput.required = true;
            timeInput.name = `rows[${rowCount}][time]`;
            timeCell.appendChild(timeInput);

            // Aktív checkbox
            const activeCell = newRow.insertCell(2);
            const activeCheckbox = document.createElement("input");
            activeCheckbox.type = "checkbox";
            activeCheckbox.name = `rows[${rowCount}][active]`;
            activeCell.appendChild(activeCheckbox);

            // Inaktív dátumig checkbox és dátum beviteli mező
            const inactiveUntilCell = newRow.insertCell(3);
            const inactiveCheckbox = document.createElement("input");
            inactiveCheckbox.type = "checkbox";
            inactiveCheckbox.name = `rows[${rowCount}][inactiveUntil]`;
            const dateInput = document.createElement("input");
            dateInput.type = "date";
            dateInput.className = "date-input";
	    dateInput.value = "1970-01-01";
            dateInput.name = `rows[${rowCount}][inactiveDate]`;
            inactiveUntilCell.appendChild(inactiveCheckbox);
            inactiveUntilCell.appendChild(dateInput);

            // Kihagyás checkbox
            const skipNextCell = newRow.insertCell(4);
            const skipCheckbox = document.createElement("input");
            skipCheckbox.type = "checkbox";
            skipCheckbox.name = `rows[${rowCount}][skipNext]`;
            skipNextCell.appendChild(skipCheckbox);

            // Csak egyszer checkbox
            const onceOnlyCell = newRow.insertCell(5);
            const onceCheckbox = document.createElement("input");
            onceCheckbox.type = "checkbox";
            onceCheckbox.name = `rows[${rowCount}][onceOnly]`;
            onceOnlyCell.appendChild(onceCheckbox);

            // Törlés gomb
            const actionCell = newRow.insertCell(6);
            const removeButton = document.createElement("button");
            removeButton.type = "button";
            removeButton.className = "btn btn-remove";
            removeButton.textContent = "Törlés";
            removeButton.onclick = function () { removeRow(this); };
            actionCell.appendChild(removeButton);

            rowCount++;
        }

        function removeRow(button) {
            const row = button.closest("tr");
            row.remove();
        }
    </script>
</center>
</div>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>
