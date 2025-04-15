<?php
/**
 * SQLite Admin Interface
 * Similar to Adminer for managing a single SQLite database
 */

// Define the database file
$database_file = 'mydb.sqlite';
$error_message = '';
$success_message = '';

// Create a connection to the database
try {
    $db = new PDO('sqlite:' . $database_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error_message = 'Error connecting to the database: ' . $e->getMessage();
}

// Create a table to store column comments if it doesn't exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS table_column_comments (
        table_name TEXT NOT NULL,
        column_name TEXT NOT NULL,
        comment TEXT,
        PRIMARY KEY (table_name, column_name)
    )");
} catch (PDOException $e) {
    // Ignore the error if the table already exists
}

// Function to fetch the list of tables
function getTables($db) {
    $tables = [];
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'table_column_comments'");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $tables[] = $row['name'];
    }
    return $tables;
}

// Function to fetch column information for a specific table
function getTableColumns($db, $tableName) {
    $columns = [];
    $result = $db->query("PRAGMA table_info(" . $db->quote($tableName) . ")");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row;
    }
    return $columns;
}

// Function to fetch column comments for a specific table
function getColumnComments($db, $tableName) {
    $comments = [];
    $stmt = $db->prepare("SELECT column_name, comment FROM table_column_comments WHERE table_name = ?");
    $stmt->execute([$tableName]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $comments[$row['column_name']] = $row['comment'];
    }
    return $comments;
}

// Function to create a new table
function createTable($db, $tableName, $columns) {
    $sql = "CREATE TABLE " . $tableName . " (";
    $columnDefs = [];
    $commentsToInsert = [];
    
    foreach ($columns as $column) {
        $def = $column['name'] . " " . $column['type'];
        if (!empty($column['pk'])) {
            $def .= " PRIMARY KEY";
        }
        if (!empty($column['nn'])) {
            $def .= " NOT NULL";
        }
        if (isset($column['default']) && $column['default'] !== '') {
            $def .= " DEFAULT " . $db->quote($column['default']);
        }
        $columnDefs[] = $def;
        // Save the comment if it exists
        if (isset($column['comment']) && $column['comment'] !== '') {
            $commentsToInsert[] = [
                'table' => $tableName,
                'column' => $column['name'],
                'comment' => $column['comment']
            ];
        }
    }
    
    $sql .= implode(", ", $columnDefs) . ")";
    
    $db->exec($sql);
    // Insert comments into the comments table
    if (!empty($commentsToInsert)) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO table_column_comments (table_name, column_name, comment) VALUES (?, ?, ?)");
        foreach ($commentsToInsert as $c) {
            $stmt->execute([$c['table'], $c['column'], $c['comment']]);
        }
    }
    return "Table " . $tableName . " created successfully";
}

// Function to drop a table
function dropTable($db, $tableName) {
    $db->exec('DROP TABLE "' . $tableName . '"');
    return "Table " . $tableName . " deleted successfully";
}

// Function to fetch table data
function getTableData($db, $tableName, $page = 1, $limit = 10) {
    $offset = ($page - 1) * $limit;
    $result = $db->query("SELECT * FROM " . $db->quote($tableName) . " LIMIT $limit OFFSET $offset");
    return $result->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch sorted table data
function getTableDataSorted($db, $tableName, $page, $limit, $sortBy, $sortOrder) {
    $offset = ($page - 1) * $limit;
    $query = "SELECT * FROM " . $db->quote($tableName) . " ORDER BY " . $sortBy . " " . $sortOrder . " LIMIT $limit OFFSET $offset";
    $result = $db->query($query);
    return $result->fetchAll(PDO::FETCH_ASSOC);
}

// Function to count rows in a table
function countRows($db, $tableName) {
    $result = $db->query("SELECT COUNT(*) as count FROM " . $db->quote($tableName));
    $row = $result->fetch(PDO::FETCH_ASSOC);
    return $row['count'];
}

// Function to insert a new row
function insertRow($db, $tableName, $data) {
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    
    $sql = "INSERT INTO " . $tableName . " (" . implode(", ", $columns) . ") 
            VALUES (" . implode(", ", $placeholders) . ")";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($data));
    return "Data inserted successfully";
}

// Update the updateRow function to improve value handling
function updateRow($db, $tableName, $data, $whereCondition) {
    $setClauses = [];
    $values = [];
    foreach ($data as $column => $value) {
        $setClauses[] = "$column = ?";
        $values[] = $value;
    }
    
    $sql = "UPDATE " . $tableName . " SET " . implode(", ", $setClauses) . " WHERE " . $whereCondition;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
    return "Data updated successfully";
}

// Function to delete a row
function deleteRow($db, $tableName, $whereCondition) {
    $sql = "DELETE FROM " . $tableName . " WHERE " . $whereCondition;
    $db->exec($sql);
    return "Data deleted successfully";
}

// Function to execute a custom SQL query
function executeCustomQuery($db, $query) {
    $result = $db->query($query);
    
    // If the query is SELECT or returns results
    if ($result !== false && strpos(strtolower($query), 'select') === 0) {
        return $result->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return "Query executed successfully";
}

// Export table data to a CSV file
if (isset($_GET['table'], $_GET['export_xls'])) {
    $exportTable = $_GET['table'];
    $exportColumns = getTableColumns($db, $exportTable);
    $exportData = getTableData($db, $exportTable, 1, 1000000); // Export all data
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $exportTable . '-' . date('Ymd_His') . '.csv"');
    // BOM for UTF-8 Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // Column headers
    $header = [];
    foreach ($exportColumns as $col) {
        $header[] = isset($columnComments[$col['name']]) && $columnComments[$col['name']] !== '' ? $columnComments[$col['name']] : $col['name'];
    }
    fputcsv($out, $header);
    // Data
    foreach ($exportData as $row) {
        $line = [];
        foreach ($exportColumns as $col) {
            $line[] = $row[$col['name']];
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// Handle requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create a new table
    if (isset($_POST['action']) && $_POST['action'] === 'create_table') {
        try {
            $tableName = $_POST['table_name'];
            $columns = [];
            
            for ($i = 0; $i < count($_POST['column_name']); $i++) {
                if (!empty($_POST['column_name'][$i])) {
                    $columns[] = [
                        'name' => $_POST['column_name'][$i],
                        'type' => $_POST['column_type'][$i],
                        'pk' => isset($_POST['column_pk'][$i]) ? 1 : 0,
                        'nn' => isset($_POST['column_nn'][$i]) ? 1 : 0,
                        'default' => $_POST['column_default'][$i],
                        'comment' => $_POST['column_comment'][$i]
                    ];
                }
            }
            
            $success_message = createTable($db, $tableName, $columns);
        } catch (PDOException $e) {
            $error_message = 'Error creating table: ' . $e->getMessage();
        }
    }
    
    // Drop a table
    if (isset($_POST['action']) && $_POST['action'] === 'drop_table') {
        try {
            $tableName = $_POST['table_name'];
            $success_message = dropTable($db, $tableName);
			header('Location: ?');
        } catch (PDOException $e) {
            $error_message = 'Error deleting table: ' . $e->getMessage();
        }
    }
    
    // Insert a new row
    if (isset($_POST['action']) && $_POST['action'] === 'insert_row') {
        try {
            $tableName = $_POST['table_name'];
            $data = [];
            
            foreach ($_POST['data'] as $column => $value) {
                if ($value !== '') {
                    $data[$column] = $value;
                }
            }
            
            $success_message = insertRow($db, $tableName, $data);
        } catch (PDOException $e) {
            $error_message = 'Error inserting data: ' . $e->getMessage();
        }
    }
    
    // Update a row
    if (isset($_POST['action']) && $_POST['action'] === 'update_row') {
        try {
            $tableName = $_POST['table_name'];
            $data = [];
            $whereCondition = $_POST['where_condition'];
            
            foreach ($_POST['data'] as $column => $value) {
                $data[$column] = $value;
            }
            
            $success_message = updateRow($db, $tableName, $data, $whereCondition);
        } catch (PDOException $e) {
            $error_message = 'Error updating data: ' . $e->getMessage();
        }
    }
    
    // Delete a row
    if (isset($_POST['action']) && $_POST['action'] === 'delete_row') {
        try {
            $tableName = $_POST['table_name'];
            $whereCondition = $_POST['where_condition'];
            
            $success_message = deleteRow($db, $tableName, $whereCondition);
        } catch (PDOException $e) {
            $error_message = 'Error deleting data: ' . $e->getMessage();
        }
    }
    
    // Execute a custom query
    if (isset($_POST['action']) && $_POST['action'] === 'custom_query') {
        try {
            $query = $_POST['query'];
            $queryResult = executeCustomQuery($db, $query);
            
            if (is_array($queryResult)) {
                $customQueryResult = $queryResult;
                $success_message = "Query executed successfully. Retrieved " . count($queryResult) . " rows.";
            } else {
                $success_message = $queryResult;
            }
        } catch (PDOException $e) {
            $error_message = 'Error in query: ' . $e->getMessage();
        }
    }
}

// Fetch variables for display
$tables = isset($db) ? getTables($db) : [];
$selectedTable = isset($_GET['table']) ? $_GET['table'] : (count($tables) > 0 ? $tables[0] : '');
$tableColumns = !empty($selectedTable) ? getTableColumns($db, $selectedTable) : [];
$columnComments = !empty($selectedTable) ? getColumnComments($db, $selectedTable) : [];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

if (isset($_GET['sort_by'])) {
    $sortBy = $_GET['sort_by'];
    $sortOrder = isset($_GET['sort_order']) && $_GET['sort_order'] === 'desc' ? 'DESC' : 'ASC';
    $tableData = !empty($selectedTable) ? getTableDataSorted($db, $selectedTable, $page, $limit, $sortBy, $sortOrder) : [];
} else {
    $tableData = !empty($selectedTable) ? getTableData($db, $selectedTable, $page, $limit) : [];
}

$rowCount = !empty($selectedTable) ? countRows($db, $selectedTable) : 0;
$pageCount = ceil($rowCount / $limit);
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQLite Admin Interface</title>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e9ecf3 100%);
            margin: 0;
            padding: 0;
        }
        .apex-header {
            background: linear-gradient(90deg, #3a539b 0%, #218c74 100%);
            color: #fff;
            padding: 18px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }
        .apex-header .app-title {
            font-size: 2em;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .apex-header .user-info {
            font-size: 1em;
            display: flex;
            align-items: center;
        }
        .apex-header .user-info i {
            margin-left: 8px;
        }
        .apex-nav {
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
            padding: 0 0 0 0;
        }
        .apex-nav ul {
            list-style: none;
            margin: 0;
            padding: 0 30px;
            display: flex;
            align-items: center;
        }
        .apex-nav li {
            margin-left: 22px;
        }
        .apex-nav a {
            color: #3a539b;
            text-decoration: none;
            font-weight: 500;
            font-size: 1.1em;
            padding: 12px 0;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        .apex-nav a:hover {
            color: #218c74;
        }
        .apex-nav i {
            margin-left: 6px;
        }
        .apex-container {
            max-width: 1200px;
            margin: 30px auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 35px 30px 30px 30px;
        }
        .apex-btn {
            background: linear-gradient(90deg, #3a539b 0%, #218c74 100%);
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 22px;
            font-size: 1.1em;
            font-weight: 500;
            cursor: pointer;
            margin-bottom: 10px;
            margin-left: 8px;
            transition: background 0.2s;
        }
        .apex-btn:hover {
            background: linear-gradient(90deg, #218c74 0%, #3a539b 100%);
        }
        .apex-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .apex-table th, .apex-table td {
            padding: 13px 10px;
            text-align: left;
        }
        .apex-table th {
            background: #f1f2f6;
            color: #3a539b;
            font-weight: bold;
            border-bottom: 2px solid #d1d8e0;
        }
        .apex-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .apex-table tr:hover {
            background: #eaf0fb;
        }
        .apex-tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        .apex-tab {
            padding: 10px 22px;
            cursor: pointer;
            margin-right: 5px;
            background: #f1f2f6;
            border: 1px solid #e0e0e0;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            font-weight: 500;
            font-size: 1.1em;
        }
        .apex-tab.active {
            background: #fff;
            border-bottom: 1px solid #fff;
            margin-bottom: -1px;
            color: #218c74;
        }
        .apex-tab-content {
            display: none;
        }
        .apex-tab-content.active {
            display: block;
        }
        .apex-alert {
            padding: 12px 18px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 1.1em;
        }
        .apex-alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .apex-alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .apex-pagination {
            display: flex;
            list-style: none;
            padding: 0;
        }
        .apex-pagination li {
            margin-right: 5px;
        }
        .apex-pagination a {
            display: block;
            padding: 6px 14px;
            text-decoration: none;
            border-radius: 4px;
            border: 1px solid #d1d8e0;
            color: #3a539b;
            background: #f1f2f6;
            font-weight: 500;
        }
        .apex-pagination a.active {
            background-color: #218c74;
            color: white;
            border: 1px solid #218c74;
        }
        /* Modal/Popup style */
        #edit_form_container, #insert_form_container {
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            padding: 25px 20px 20px 20px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 0 18px rgba(58,83,155,0.13);
            z-index: 1000;
            border-radius: 14px;
        }
		
        #edit_form_container input[type="text"], #insert_form_container input[type="text"], #edit_form_container input[type="number"], #insert_form_container input[type="number"], #edit_form_container input[type="date"], #insert_form_container input[type="date"] {
            width: 100%;
            padding: 8px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }
        #edit_form_container label, #insert_form_container label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
            color: #3a539b;
        }
        #edit_form_container input[type="submit"], #edit_form_container button, #insert_form_container input[type="submit"], #insert_form_container button {
            margin-right: 10px;
            margin-top: 10px;
        }
        .icon-action {
            font-size: 1.2em;
            margin-left: 6px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="apex-header">
        <span class="app-title"><i class="fa fa-database"></i> SQLite Database Management</span>
        <span class="user-info"><i class="fa fa-user-circle"></i> User</span>
    </div>
    <nav class="apex-nav">
        <ul>
            <li><a href="?"><i class="fa fa-home"></i> Home</a></li>
            <?php foreach ($tables as $table): ?>
                <li><a href="?table=<?php echo $table; ?>"><i class="fa fa-table"></i> <?php echo $table; ?></a></li>
            <?php endforeach; ?>
            <li><a href="?action=create_table"><i class="fa fa-plus"></i> Create New Table</a></li>
            <li><a href="?action=custom_query"><i class="fa fa-terminal"></i> Custom Query</a></li>
        </ul>
    </nav>
    <div class="apex-container">
        <?php if (!empty($error_message)): ?>
            <div class="apex-alert apex-alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="apex-alert apex-alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['action']) && $_GET['action'] === 'create_table'): ?>
            <!-- Create New Table Form -->
            <h2>Create New Table</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="create_table">
                
                <div>
                    <label for="table_name">Table Name:</label>
                    <input type="text" id="table_name" name="table_name" required>
                </div>
                
                <h3>Columns:</h3>
                <div id="columns_container">
                    <div class="column-row">
                        <input type="text" name="column_name[]" placeholder="Column Name" required>
                        <select name="column_type[]">
                            <option value="INTEGER">INTEGER</option>
                            <option value="TEXT">TEXT</option>
                            <option value="DATE">DATE</option>
                            <option value="EMAIL">EMAIL</option>
                        </select>
                        <label><input type="checkbox" name="column_pk[]"> Primary Key</label>
                        <label><input type="checkbox" name="column_nn[]"> Not Null</label>
                        <input type="text" name="column_default[]" placeholder="Default Value">
                        <input type="text" name="column_comment[]" placeholder="Column Comment/Title">
                    </div>
                </div>
                
                <button type="button" onclick="addColumn()">Add Column</button>
                <input type="submit" value="Create Table">
            </form>
            
            <script>
                function addColumn() {
                    const container = document.getElementById('columns_container');
                    const newColumn = document.createElement('div');
                    newColumn.className = 'column-row';
                    newColumn.innerHTML = `
                        <input type="text" name="column_name[]" placeholder="Column Name">
                        <select name="column_type[]">
                            <option value="INTEGER">INTEGER</option>
                            <option value="TEXT">TEXT</option>
                            <option value="DATE">DATE</option>
                            <option value="EMAIL">EMAIL</option>
                        </select>
                        <label><input type="checkbox" name="column_pk[]"> Primary Key</label>
                        <label><input type="checkbox" name="column_nn[]"> Not Null</label>
                        <input type="text" name="column_default[]" placeholder="Default Value">
                        <input type="text" name="column_comment[]" placeholder="Column Comment/Title">
                    `;
                    container.appendChild(newColumn);
                }
            </script>
        
        <?php elseif (isset($_GET['action']) && $_GET['action'] === 'custom_query'): ?>
            <!-- Custom Query Form -->
            <h2>Custom SQL Query</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="custom_query">
                
                <div>
                    <label for="query">SQL Query:</label>
                    <textarea id="query" name="query" rows="5" required></textarea>
                </div>
                
                <input type="submit" value="Execute">
            </form>
            
            <?php if (isset($customQueryResult) && is_array($customQueryResult)): ?>
                <h3>Query Results:</h3>
                <?php if (count($customQueryResult) > 0): ?>
                    <table class="apex-table">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($customQueryResult[0]) as $column): ?>
                                    <th><?php echo $column; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customQueryResult as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?php echo $value; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No results to display.</p>
                <?php endif; ?>
            <?php endif; ?>
        
        <?php elseif (!empty($selectedTable)): ?>
            <!-- Display Table Data -->
            <div class="apex-tabs">
                <div class="apex-tab active" onclick="openTab(event, 'browse')">Browse</div>
            </div>
            
            <div id="browse" class="apex-tab-content active">
                <h2>Browse Table: <?php echo $selectedTable; ?></h2>
                <form method="post" action="" onsubmit="return confirm('Are you sure you want to delete this table?');" style="display:inline-block; margin-bottom:15px;">
                    <input type="hidden" name="action" value="drop_table">
                    <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                    <input type="submit" value="Delete Table" class="apex-btn" style="background:#e55039;">
                </form>
                <a href="?table=<?php echo $selectedTable; ?>&export_xls=1" class="apex-btn" style="background: #218c74;">
                    <i class="fa fa-file-excel"></i> Export to Excel
                </a>
                <button onclick="showInsertForm()" class="apex-btn">
                    <i class="fa fa-plus"></i> Add New Record
                </button>
                
                <?php if (count($tableData) > 0): ?>
                    <table class="apex-table">
                        <thead>
                            <tr>
                                <th>Actions</th>
                                <?php foreach ($tableColumns as $column): ?>
                                    <th>
                                        <a href="?table=<?php echo $selectedTable; ?>&sort_by=<?php echo $column['name']; ?>&sort_order=<?php echo isset($_GET['sort_order']) && $_GET['sort_order'] === 'asc' ? 'desc' : 'asc'; ?>">
                                            <?php echo isset($columnComments[$column['name']]) && $columnComments[$column['name']] !== '' ? $columnComments[$column['name']] : $column['name']; ?>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableData as $row): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $whereCondition = "";
                                        foreach ($tableColumns as $column) {
                                            if ($column['pk']) {
                                                $whereCondition = $column['name'] . " = '" . $row[$column['name']] . "'";
                                                break;
                                            }
                                        }
                                        if (empty($whereCondition) && count($tableColumns) > 0) {
                                            $firstCol = $tableColumns[0]['name'];
                                            $whereCondition = $firstCol . " = '" . $row[$firstCol] . "'";
                                        }
                                        ?>
                                        <a href="#" class="btn-link" title="Edit" onclick="populateEditForm('<?php echo addslashes($whereCondition); ?>', <?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>); return false;">
                                            <i class="fa fa-pen icon-action"></i>
                                        </a>
                                        <a href="#" class="btn-link" title="Delete" onclick="if(confirm('Are you sure you want to delete this row?')) { document.getElementById('delete_form_<?php echo md5($whereCondition); ?>').submit(); } return false;">
                                            <i class="fa fa-trash icon-action"></i>
                                        </a>
                                        <form id="delete_form_<?php echo md5($whereCondition); ?>" method="post" action="" style="display:none;">
                                            <input type="hidden" name="action" value="delete_row">
                                            <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                                            <input type="hidden" name="where_condition" value="<?php echo $whereCondition; ?>">
                                        </form>
                                    </td>
                                    <?php foreach ($tableColumns as $column): ?>
                                        <td><?php echo $row[$column['name']]; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($pageCount > 1): ?>
                        <ul class="apex-pagination">
                            <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                                <li>
                                    <a href="?table=<?php echo $selectedTable; ?>&page=<?php echo $i; ?>&limit=<?php echo $limit; ?>" <?php echo $i === $page ? 'class="active"' : ''; ?>>
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    <?php endif; ?>
                    
                    <!-- Edit Form -->
                    <div id="edit_form_container">
                        <h3>Edit Record</h3>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="update_row">
                            <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                            <input type="hidden" name="where_condition" id="edit_where_condition">
                            
                            <div id="edit_fields_container">
                            <?php foreach ($tableColumns as $column): ?>
                                <?php
                                $type = strtoupper($column['type']);
                                $inputType = 'text';
                                if (strpos($type, 'INT') !== false || strpos($type, 'REAL') !== false || strpos($type, 'NUMERIC') !== false) {
                                    $inputType = 'number';
                                } elseif (strpos($type, 'DATE') !== false) {
                                    $inputType = 'date';
                                }
                                ?>
                                <?php if ($column['pk'] && $type === 'INTEGER'): ?>
                                    <div style="display:none;">
                                        <label for="edit_<?php echo $column['name']; ?>">
                                            <?php echo isset($columnComments[$column['name']]) && $columnComments[$column['name']] !== '' ? $columnComments[$column['name']] : $column['name']; ?>:
                                        </label>
                                        <input type="<?php echo $inputType; ?>" 
                                               id="edit_<?php echo $column['name']; ?>" 
                                               name="data[<?php echo $column['name']; ?>]" 
                                               class="edit-field">
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <label for="edit_<?php echo $column['name']; ?>">
                                            <?php echo isset($columnComments[$column['name']]) && $columnComments[$column['name']] !== '' ? $columnComments[$column['name']] : $column['name']; ?>:
                                        </label>
                                        <input type="<?php echo $inputType; ?>" 
                                               id="edit_<?php echo $column['name']; ?>" 
                                               name="data[<?php echo $column['name']; ?>]" 
                                               class="edit-field">
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </div>
                            
                            <div style="margin-top: 10px;">
                                <input type="submit" value="Save Changes">
                                <button type="button" onclick="hideEditForm()">Cancel</button>
                            </div>
                        </form>
                    </div>
                    
                    <script>
                        function populateEditForm(whereCondition, rowData) {
                            document.getElementById('edit_where_condition').value = whereCondition;
                            <?php foreach ($tableColumns as $column): ?>
                                if (typeof rowData['<?php echo $column['name']; ?>'] !== 'undefined') {
                                    document.getElementById('edit_<?php echo $column['name']; ?>').value = rowData['<?php echo $column['name']; ?>'] !== null ? rowData['<?php echo $column['name']; ?>'] : '';
                                }
                            <?php endforeach; ?>
                            document.getElementById('edit_form_container').style.display = 'block';
                            window.scrollTo({top: 0, behavior: 'smooth'});
                        }
                        
                        function hideEditForm() {
                            document.getElementById('edit_form_container').style.display = 'none';
                        }
                    </script>
                <?php else: ?>
                    <p>No data in this table.</p>
                <?php endif; ?>
                
                <!-- Insert Form Popup -->
                <div id="insert_form_container" style="display: none;">
                    <h3 style="color:#3a539b; margin-top:0;">Insert Data into Table: <?php echo $selectedTable; ?></h3>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="insert_row">
                        <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                        <?php foreach ($tableColumns as $column): ?>
                            <?php
                            $type = strtoupper($column['type']);
                            $inputType = 'text';
                            if (strpos($type, 'INT') !== false || strpos($type, 'REAL') !== false || strpos($type, 'NUMERIC') !== false) {
                                $inputType = 'number';
                            } elseif (strpos($type, 'DATE') !== false) {
                                $inputType = 'date';
                            }
                            ?>
                            <?php if ($column['pk'] && $type === 'INTEGER'): ?>
                                <div style="display:none;">
                                    <label for="insert_<?php echo $column['name']; ?>">
                                        <?php echo isset($columnComments[$column['name']]) && $columnComments[$column['name']] !== '' ? $columnComments[$column['name']] : $column['name']; ?>:
                                    </label>
                                    <input type="<?php echo $inputType; ?>" id="insert_<?php echo $column['name']; ?>" name="data[<?php echo $column['name']; ?>]" placeholder="Will be auto-generated" class="edit-field">
                                </div>
                            <?php else: ?>
                                <div style="margin-bottom: 12px;">
                                    <label for="insert_<?php echo $column['name']; ?>" style="display:block; font-weight:bold; margin-bottom:4px; color:#3a539b;">
                                        <?php echo isset($columnComments[$column['name']]) && $columnComments[$column['name']] !== '' ? $columnComments[$column['name']] : $column['name']; ?>:
                                    </label>
                                    <input type="<?php echo $inputType; ?>" id="insert_<?php echo $column['name']; ?>" name="data[<?php echo $column['name']; ?>]" class="edit-field">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div style="margin-top: 10px; text-align:left;">
                            <input type="submit" value="Insert" class="apex-btn">
                            <button type="button" onclick="hideInsertForm()" class="apex-btn" style="background:#e55039;">Cancel</button>
                        </div>
                    </form>
                </div>
                <script>
                    function showInsertForm() {
                        document.getElementById('insert_form_container').style.display = 'block';
                        window.scrollTo({top: 0, behavior: 'smooth'});
                    }
                    function hideInsertForm() {
                        document.getElementById('insert_form_container').style.display = 'none';
                    }
                    // Ensure the form is always hidden on page load
                    window.addEventListener('DOMContentLoaded', function() {
                        var insertForm = document.getElementById('insert_form_container');
                        if (insertForm) {
                            insertForm.style.visible = false;
                        }
                    });
                </script>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
