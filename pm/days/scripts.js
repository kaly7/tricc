document.addEventListener('DOMContentLoaded', function() {
    const daysContainer = document.getElementById('days-container');
    const dayForm = document.getElementById('day-form');

    // Adatok betöltése
    fetch('get_days.php')
        .then(response => response.json())
        .then(days => {
            days.forEach(day => {
                const dayElement = document.createElement('div');
                dayElement.className = 'day-item';
                
                dayElement.innerHTML = `
                    <div class="day-info">
                        <strong>${day.date}</strong>: ${day.day_type} (${day.description || 'Nincs leírás'})
                    </div>
                    <button onclick="deleteDay(${day.id})">Törlés</button>
                    <button onclick="editDay(${day.id}, '${day.date}', '${day.day_type}', '${day.description}')">Módosítás</button>
                `;
                daysContainer.appendChild(dayElement);
            });
        });

    // Új nap hozzáadása
    dayForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const date = document.getElementById('date').value;
        const dayType = document.getElementById('day_type').value;
        const description = document.getElementById('description').value;
        
        fetch('add_day.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                date: date,
                day_type: dayType,
                description: description
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Hiba történt: ' + data.error);
            } else if (data.success) {
                alert(data.success);
                window.location.href = 'index.php';
            }
        })
        .catch(error => alert('Hiba történt: ' + error));
    });
});

// Nap törlése
function deleteDay(id) {
    if (confirm('Biztosan törölni szeretnéd ezt a napot?')) {
        fetch('delete_day.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({ id: id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Hiba történt: ' + data.error);
            } else {
                window.location.href = 'index.php';
            }
        })
        .catch(error => alert('Hiba történt: ' + error));
    }
}

// Nap módosítása
function editDay(id, date, dayType, description) {
    const newDate = prompt('Dátum:', date);
    const newDayType = prompt('Nap típusa (workday, holiday, non-working):', dayType);
    const newDescription = prompt('Leírás:', description);

    if (newDate && newDayType) {
        fetch('update_day.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                id: id,
                date: newDate,
                day_type: newDayType,
                description: newDescription
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Hiba történt: ' + data.error);
            } else {
                window.location.href = 'index.php';
            }
        })
        .catch(error => alert('Hiba történt: ' + error));
    }
}
