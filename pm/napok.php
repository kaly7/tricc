<?php
include 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $datum = $_POST['datum'];
    $tipus = $_POST['tipus'];

    // Ellenőrizzük, hogy a dátum már létezik-e
    $check = $conn->prepare("SELECT * FROM nap_tipusok WHERE datum = ?");
    $check->bind_param("s", $datum);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $error = "Ez a dátum már létezik!";
    } else {
        $stmt = $conn->prepare("INSERT INTO nap_tipusok (datum, tipus) VALUES (?, ?)");
        $stmt->bind_param("ss", $datum, $tipus);
        $stmt->execute();
        $stmt->close();
    }

    $check->close();
}

if (isset($_POST['delete'])) {
    $id = $_POST['id'];
    $conn->query("DELETE FROM nap_tipusok WHERE id=$id");
}

$result = $conn->query("SELECT * FROM nap_tipusok ORDER BY datum ASC");
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Napok Kezelése</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<center>
<a href="index.php"> <button type=button class="btn btn-add" value="Főmenü">Vissza</button></a><br>
    <h2>Napok Kezelése</h2>
    <div class="form-container">
        <form method="POST" action="napok.php">
            <input type="date" name="datum" required>
            <select name="tipus" required>
                <option value="Munkanap">Munkanap</option>
                <option value="Ünnepnap">Ünnepnap</option>
                <option value="Munkaszüneti nap">Munkaszüneti nap</option>
            </select>
            <button type="submit">Hozzáadás</button>
        </form>
    </div>
    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    <table>
        <thead>
            <tr>
                <th>Dátum</th>
                <th>Típus</th>
                <th>Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['datum']; ?></td>
                    <td><?php echo $row['tipus']; ?></td>
                    <td>
                        <form method="POST" action="napok.php" style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="delete" class="delete-button">Törlés</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</center>
</body>
</html>
