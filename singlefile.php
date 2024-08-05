<?php
// Include environment loading and error reporting setup
include_once("loadEnv.php");

error_reporting(E_ALL);
ini_set('display_errors', 'on');

// Directory where backups are stored
$backupDir = 'bkups';

// Ensure the backup directory exists
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// Fetch database credentials from environment variables
$host = getenv('DB_SERVER');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$database = getenv('DB_NAME');
$port = getenv('DB_PORT');

// Establish database connection
$conn = new mysqli($host, $username, $password, $database, $port);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch all tables from the database
$tablesResult = $conn->query("SHOW TABLES");
if (!$tablesResult) {
    die("Error fetching tables: " . $conn->error);
}

$tables = [];
while ($row = $tablesResult->fetch_array()) {
    $table = $row[0];
    // Get the row count for each table
    $countResult = $conn->query("SELECT COUNT(*) FROM `$table`");
    if ($countResult) {
        $countRow = $countResult->fetch_array();
        $rowCount = $countRow[0];
    } else {
        $rowCount = 0;
    }
    $tables[] = ['name' => $table, 'rows' => $rowCount];
}

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tables'])) {
    $backupName = trim($_POST['backup_name']);
    $includeCreateTable = isset($_POST['include_create_table']);

    // Default backup file name if not provided
    if (empty($backupName)) {
        $backupName = 'bkup-database';
    }

    // Remove any illegal characters from the file name
    $backupName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $backupName);
    $backupName = basename($backupName); // Prevent directory traversal

    // Define the output file for SQL in the backup directory
    $sqlFile = $backupDir . "/backup-" . $backupName . "-" . date('Y-m-d_H-i-s') . ".sql";
    
    // Open file for writing
    $fileHandle = fopen($sqlFile, 'w');
    if (!$fileHandle) {
        die("Unable to open file for writing: $sqlFile");
    }
    
    // Iterate through selected tables
    foreach ($_POST['tables'] as $table) {
        if ($includeCreateTable) {
            // Fetch the CREATE TABLE statement
            $createTableResult = $conn->query("SHOW CREATE TABLE `$table`");
            if ($createTableResult) {
                $createTableRow = $createTableResult->fetch_assoc();
                fwrite($fileHandle, $createTableRow['Create Table'] . ";\n\n");
            }
        }

        fwrite($fileHandle, "-- Table: $table\n");
        
        // Fetch all rows from the table
        $rowsResult = $conn->query("SELECT * FROM `$table`");
        if (!$rowsResult) {
            fwrite($fileHandle, "Error fetching rows from table $table: " . $conn->error . "\n");
            continue;
        }
        
        // Generate INSERT statements for each row
        while ($row = $rowsResult->fetch_assoc()) {
            // Begin the INSERT statement
            $sql = "INSERT INTO `$table` (";
            
            // Add column names
            $columns = array_keys($row);
            $sql .= implode(", ", array_map(function($col) { return "`$col`"; }, $columns));
            $sql .= ") VALUES (";
            
            // Add values, ensuring proper escaping and quoting
            $values = array_values($row);
            $escapedValues = array_map(function($value) use ($conn) {
                if (is_null($value)) {
                    return 'NULL';
                }
                return "'" . $conn->real_escape_string($value) . "'";
            }, $values);
            $sql .= implode(", ", $escapedValues);
            $sql .= ");\n";
            
            // Write the generated INSERT statement to the file
            fwrite($fileHandle, $sql);
        }
        
        fwrite($fileHandle, "\n");
    }
    
    // Close the file handle
    fclose($fileHandle);
    
    echo "Backup SQL file created successfully: $sqlFile\n";
}

// Handle AJAX request for table data
if (isset($_GET['table'])) {
    $table = $conn->real_escape_string($_GET['table']); // Escape input
    $rowsResult = $conn->query("SELECT * FROM `$table` LIMIT 100");
    
    $data = [];
    if ($rowsResult) {
        while ($row = $rowsResult->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Handle AJAX request to display backup file content
if (isset($_GET['backup_file'])) {
    $backupFile = basename($_GET['backup_file']); // Prevent directory traversal
    $filePath = $backupDir . '/' . $backupFile;

    if (file_exists($filePath)) {
        echo nl2br(file_get_contents($filePath));
    } else {
        echo "File not found.";
    }
    exit;
}

// Fetch the list of backup files in the backup directory
$backupFiles = array_diff(scandir($backupDir), ['..', '.']);

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Backup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .container {
            display: flex;
        }
        .form-container {
            width: 20%;
            padding-right: 20px;
        }
        .results-container {
            width: 80%;
            padding-left: 20px;
            border-left: 1px solid #ccc;
            max-height: 80vh; /* Set a maximum height */
            overflow-y: auto; /* Enable vertical scrolling */
        }
        .table-preview table, .backup-content pre {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
        }
        .table-preview th, .table-preview td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        .table-preview th {
            background-color: #f2f2f2;
            text-align: left;
        }
    </style>
    <script>
        function fetchTableData(checkbox, table) {
            var tablePreviewDiv = document.getElementById('table-preview');
            var tableDiv = document.getElementById('table_' + table);

            if (checkbox.checked) {
                // Create a new div for this table's data if it doesn't exist
                if (!tableDiv) {
                    tableDiv = document.createElement('div');
                    tableDiv.setAttribute('id', 'table_' + table);
                    tablePreviewDiv.appendChild(tableDiv);

                    // Fetch data using AJAX
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '?table=' + table, true);
                    xhr.onload = function () {
                        if (xhr.status === 200) {
                            var data = JSON.parse(xhr.responseText);
                            
                            // If data exists, create a table
                            if (data.length > 0) {
                                var tableElement = document.createElement('table');
                                var headerRow = document.createElement('tr');
                                
                                // Create table headers
                                for (var key in data[0]) {
                                    if (data[0].hasOwnProperty(key)) {
                                        var th = document.createElement('th');
                                        th.textContent = key;
                                        headerRow.appendChild(th);
                                    }
                                }
                                tableElement.appendChild(headerRow);
                                
                                // Create table rows
                                data.forEach(function(row) {
                                    var tr = document.createElement('tr');
                                    for (var key in row) {
                                        if (row.hasOwnProperty(key)) {
                                            var td = document.createElement('td');
                                            td.textContent = row[key];
                                            tr.appendChild(td);
                                        }
                                    }
                                    tableElement.appendChild(tr);
                                });
                                
                                tableDiv.appendChild(tableElement);
                            } else {
                                tableDiv.innerHTML = 'No data available.';
                            }
                        } else {
                            console.error('Failed to fetch table data.');
                        }
                    };
                    xhr.send();
                }
            } else {
                // Remove the div containing this table's data
                if (tableDiv) {
                    tablePreviewDiv.removeChild(tableDiv);
                }
            }
        }

        function showBackupContent(radio) {
            var file = radio.value;
            var backupContentDiv = document.getElementById('backup-content-body');

            // Fetch backup file content using AJAX
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?backup_file=' + encodeURIComponent(file), true);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    var content = xhr.responseText;
                    document.getElementById('backup-content-body').innerHTML = content;
                } else {
                    console.error('Failed to fetch backup content.');
                }
            };
            xhr.send();
        }
    </script>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <form method="POST" action="">
                <h2>Backup Database</h2>
                <label for="backup_name">Backup Name:</label>
                <input type="text" id="backup_name" name="backup_name" required>
                <br>
                <label><input type="checkbox" name="include_create_table"> Include CREATE TABLE statements</label>
                <br>
                <h3>Select Tables</h3>
                <?php foreach ($tables as $table): ?>
                    <label>
                        <input type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($table['name']); ?>" onclick="document.getElementById('backup-content').style.display = 'none';" onchange="fetchTableData(this, '<?php echo htmlspecialchars($table['name']); ?>')">
                        <?php echo htmlspecialchars($table['name']); ?> (<?php echo htmlspecialchars($table['rows']); ?> rows)
                    </label><br>
                <?php endforeach; ?>
                <br>
                <input type="submit" value="Create Backup">
            </form>
            <h2>Backups</h2>
            <form>
                <?php foreach ($backupFiles as $file): ?>
                    <label>
                        <input type="radio" name="backup_file" value="<?php echo htmlspecialchars($file); ?>" onclick="document.getElementById('results-container').style.display = 'none';">
                        <?php echo htmlspecialchars($file); ?>
                    </label><br>
                <?php endforeach; ?>
            </form>
        </div>
        <div class="results-container">
            <div id="table-preview">
                <h3>Table Contents</h3>
                <!-- Table data will be dynamically inserted here -->
            </div>
            <div id="backup-content">
                <h3>Backup File Content</h3>
                <pre id="backup-content-body"></pre>
            </div>
        </div>
    </div>
</body>
</html>
