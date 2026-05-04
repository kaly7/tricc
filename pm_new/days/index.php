<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Naptár Kezelő</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <h1>Naptár Kezelő</h1>
    </header>
    <main>
        <section id="days-list">
            <h2>Napok Listája</h2>
            <!-- A napok listája itt jelenik meg -->
            <div id="days-container"></div>
        </section>
        <section id="add-day-form">
            <h2>Új Nap Hozzáadása</h2>
            <form id="day-form" action="add_day.php" method="POST">
                <label for="date">Dátum:</label>
                <input type="date" id="date" name="date" required>
                
                <label for="day_type">Nap Típusa:</label>
                <select id="day_type" name="day_type" required>
                    <option value="workday">Munkanap</option>
                    <option value="holiday">Ünnepnap</option>
                    <option value="non-working">Munkaszüneti Nap</option>
                </select>
                
                <label for="description">Leírás:</label>
                <input type="text" id="description" name="description">
                
                <button type="submit">Hozzáadás</button>
            </form>
        </section>
    </main>
    <footer>
        <p>&copy; 2024 Naptár Kezelő</p>
    </footer>
    <script src="scripts.js"></script>
</body>
</html>
