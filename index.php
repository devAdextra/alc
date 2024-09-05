<?php

// Caricamento delle variabili di ambiente
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("Il file .env non esiste.");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, "\"' ");
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Funzione per ottenere le credenziali da Supabase
function takeCred() {
    $envFilePath = __DIR__ . '/.env';
    loadEnv($envFilePath);

    $url = $_ENV['SUPABASE_URL'];
    $supa_key = $_ENV['SUPABASE_KEY'];

    return compact('url', 'supa_key');
}

// Funzione per ottenere le colonne della tabella Supabase
function prendiColonne($table) {
    $cred = takeCred();
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "{$cred['url']}/rest/v1/$table?select=*&limit=1",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: {$cred['supa_key']}",
            "Authorization: Bearer {$cred['supa_key']}",
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (!empty($data)) {
            return array_keys($data[0]);
        }
    }

    return [];
}

// Funzione per inserire i dati in Supabase
function inserisci_dati($table, $data) {
    $cred = takeCred();
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "{$cred['url']}/rest/v1/$table",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            "apikey: {$cred['supa_key']}",
            "Authorization: Bearer {$cred['supa_key']}",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ],
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);

    curl_close($curl);

    if ($error) {
        return [
            "successo" => false,
            "messaggio" => "Errore cURL: $error",
            "codice_http" => $http_code
        ];
    } elseif ($http_code >= 200 && $http_code < 300) {
        return [
            "successo" => true,
            "messaggio" => "Dati inseriti con successo.",
            "risposta" => json_decode($response, true),
            "codice_http" => $http_code
        ];
    } else {
        return [
            "successo" => false,
            "messaggio" => "Errore durante l'inserimento dei dati.",
            "risposta" => json_decode($response, true),
            "codice_http" => $http_code
        ];
    }
}

// Funzione per leggere il file CSV e restituire i dati
function leggiCSV($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }

    $data = [];
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        while (($row = fgetcsv($handle)) !== FALSE) {
            $data[] = $row;
        }
        fclose($handle);
    }
    return $data;
}

// Gestione del form di caricamento
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["csv_file"])) {
    $uploadDir = __DIR__ . '/uploads/';
    $uploadFile = $uploadDir . basename($_FILES['csv_file']['name']);

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $uploadFile)) {
        $csvData = leggiCSV($uploadFile);
        $colonneTabella = prendiColonne('utenti');

        if (!empty($csvData)) {
            $headerCSV = array_shift($csvData); // Rimuove e ottiene l'intestazione del CSV

            // Seleziona solo le righe che hanno almeno una colonna
            $csvData = array_filter($csvData, function ($row) {
                return count($row) > 0;
            });

            // Visualizza il nome del file caricato
            echo "<h2>File Caricato: " . htmlspecialchars(basename($uploadFile)) . "</h2>";
			
            // Visualizza la tabella e il form di invio
            echo "<h2>Dati del file CSV:</h2>";
            echo "<form method='post' action='' id='form_invio'>";
            echo "<table border='1' cellspacing='0' cellpadding='5'>";
            echo "<thead><tr>";
            echo "<th>Seleziona</th>";

            // Visualizza intestazioni CSV
            foreach ($headerCSV as $header) {
                echo "<th>" . htmlspecialchars($header) . "</th>";
            }
            echo "</tr></thead><tbody>";

            // Visualizza i dati del CSV
            foreach ($csvData as $index => $row) {
                echo "<tr>";
                echo "<td><input type='checkbox' name='seleziona[]' value='$index' checked></td>";
                foreach ($row as $cell) {
                    echo "<td>" . htmlspecialchars($cell) . "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";

            // Mostra il form per la mappatura delle colonne
            echo "<h2>Mappe le colonne CSV ai nomi della tabella:</h2>";
            echo "<form method='post' action=''>";
            echo "<table border='1' cellspacing='0' cellpadding='5'>";
            echo "<thead><tr>";
            echo "<th>Colonna CSV</th>";
            echo "<th>Colonna Supabase</th>";
            echo "</tr></thead><tbody>";

            foreach ($headerCSV as $index => $header) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($header) . "</td>";
                echo "<td><select name='mappa_supabase[" . htmlspecialchars($header) . "]'>";
                echo "<option value=''>Seleziona colonna</option>";

                foreach (array_slice($colonneTabella, 1) as $colonna) {
                    $selected = $header === $colonna ? "selected" : "";
                    echo "<option value='" . htmlspecialchars($colonna) . "' $selected>" . htmlspecialchars($colonna) . "</option>";
                }
                
                echo "</select></td>";
                echo "</tr>";
            }

            echo "</tbody></table>";

            // Passa l'intestazione del CSV al form
            echo "<input type='hidden' name='header' value='" . htmlspecialchars(serialize($headerCSV)) . "'>";
            echo "<input type='hidden' name='csv_file' value='" . htmlspecialchars(basename($uploadFile)) . "'>";
            echo "<input type='submit' name='invia_dati' value='Invia'>";
            echo "</form>";
        } else {
            echo "<div class='errore'>Il file CSV è vuoto.</div>";
        }
    } else {
        echo "<div class='errore'>Errore durante il caricamento del file.</div>";
    }
}

// Gestione dell'invio dei dati al database
if (isset($_POST['invia_dati'])) {
    $seleziona = $_POST['seleziona'] ?? [];
    $mappaSupabase = $_POST['mappa_supabase'] ?? [];
    $header = unserialize($_POST['header']);
    $csvFile = $_POST['csv_file'];
    $uploadDir = __DIR__ . '/uploads/';
    $uploadFile = $uploadDir . basename($csvFile);

    if (!empty($seleziona)) {
        $csvData = leggiCSV($uploadFile);
        array_shift($csvData); // Rimuove l'intestazione

        foreach ($seleziona as $index) {
            if (isset($csvData[$index])) {
                $row = $csvData[$index];
                $dati = [];

                foreach ($header as $key => $col) {
                    $colonnaSupabase = $mappaSupabase[$col] ?? null;
                    if ($colonnaSupabase) {
                        $dati[$colonnaSupabase] = $row[$key] ?? null;
                    }
                }

                if (!empty($dati)) {
                    $risposta = inserisci_dati('utenti', $dati);
                    if ($risposta['successo']) {
                        echo "<div class='successo'>Dati inviati con successo!</div>";
                    } else {
                        echo "<div class='errore'>Errore: {$risposta['messaggio']} (Codice HTTP: {$risposta['codice_http']})</div>";
                        if (!empty($risposta['risposta'])) {
                            echo "<pre>" . print_r($risposta['risposta'], true) . "</pre>";
                        }
                    }
                } else {
                    echo "<div class='errore'>Nessun dato valido da inviare.</div>";
                }
            }
        }
    } else {
        echo "<div class='errore'>Nessuna riga selezionata per l'invio.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caricamento e Invio CSV</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            padding: 20px;
        }

        .container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: auto;
        }

        h2 {
            text-align: center;
            color: #333;
        }

        form {
            margin-bottom: 20px;
        }

        input[type="file"], select {
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        input[type="submit"] {
            background-color: #007bff;
            color: #fff;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }

        input[type="submit"]:hover {
            background-color: #0056b3;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background-color: #f8f8f8;
        }

        .errore {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }

        .successo {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Carica un file CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <input type="submit" value="Carica e Visualizza">
        </form>
    </div>
</body>
</html>
