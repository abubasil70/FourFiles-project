<?php
/**
 * SQLite Admin Interface
 * مشابه لـ Adminer للتعامل مع قاعدة بيانات SQLite واحدة
 */

// تحديد ملف قاعدة البيانات
$database_file = 'mydb.sqlite';
$error_message = '';
$success_message = '';

// إنشاء اتصال بقاعدة البيانات
try {
    $db = new PDO('sqlite:' . $database_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error_message = 'خطأ في الاتصال بقاعدة البيانات: ' . $e->getMessage();
}

// وظيفة لجلب قائمة الجداول
function getTables($db) {
    $tables = [];
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $tables[] = $row['name'];
    }
    return $tables;
}

// وظيفة لجلب معلومات الأعمدة لجدول معين
function getTableColumns($db, $tableName) {
    $columns = [];
    $result = $db->query("PRAGMA table_info(" . $db->quote($tableName) . ")");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row;
    }
    return $columns;
}

// وظيفة لإنشاء جدول جديد
function createTable($db, $tableName, $columns) {
    $sql = "CREATE TABLE " . $tableName . " (";
    $columnDefs = [];
    
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
    }
    
    $sql .= implode(", ", $columnDefs) . ")";
    
    $db->exec($sql);
    return "تم إنشاء الجدول " . $tableName . " بنجاح";
}

// وظيفة لحذف جدول
function dropTable($db, $tableName) {
    $db->exec("DROP TABLE " . $db->quote($tableName));
    return "تم حذف الجدول " . $tableName . " بنجاح";
}

// وظيفة لجلب بيانات الجدول
function getTableData($db, $tableName, $page = 1, $limit = 10) {
    $offset = ($page - 1) * $limit;
    $result = $db->query("SELECT * FROM " . $db->quote($tableName) . " LIMIT $limit OFFSET $offset");
    return $result->fetchAll(PDO::FETCH_ASSOC);
}

// وظيفة لجلب بيانات الجدول مع الترتيب
function getTableDataSorted($db, $tableName, $page, $limit, $sortBy, $sortOrder) {
    $offset = ($page - 1) * $limit;
    $query = "SELECT * FROM " . $db->quote($tableName) . " ORDER BY " . $sortBy . " " . $sortOrder . " LIMIT $limit OFFSET $offset";
    $result = $db->query($query);
    return $result->fetchAll(PDO::FETCH_ASSOC);
}

// وظيفة لحساب عدد الصفوف في جدول
function countRows($db, $tableName) {
    $result = $db->query("SELECT COUNT(*) as count FROM " . $db->quote($tableName));
    $row = $result->fetch(PDO::FETCH_ASSOC);
    return $row['count'];
}

// وظيفة لإدراج صف جديد
function insertRow($db, $tableName, $data) {
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    
    $sql = "INSERT INTO " . $tableName . " (" . implode(", ", $columns) . ") 
            VALUES (" . implode(", ", $placeholders) . ")";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($data));
    return "تم إدراج البيانات بنجاح";
}

// تعديل وظيفة updateRow لتحسين معالجة القيم
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
    return "تم تحديث البيانات بنجاح";
}

// وظيفة لحذف صف
function deleteRow($db, $tableName, $whereCondition) {
    $sql = "DELETE FROM " . $tableName . " WHERE " . $whereCondition;
    $db->exec($sql);
    return "تم حذف البيانات بنجاح";
}

// وظيفة لتنفيذ استعلام SQL مخصص
function executeCustomQuery($db, $query) {
    $result = $db->query($query);
    
    // إذا كان الاستعلام هو SELECT أو إرجاع نتائج
    if ($result !== false && strpos(strtolower($query), 'select') === 0) {
        return $result->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return "تم تنفيذ الاستعلام بنجاح";
}

// معالجة الطلبات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // إنشاء جدول جديد
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
                        'default' => $_POST['column_default'][$i]
                    ];
                }
            }
            
            $success_message = createTable($db, $tableName, $columns);
        } catch (PDOException $e) {
            $error_message = 'خطأ عند إنشاء الجدول: ' . $e->getMessage();
        }
    }
    
    // حذف جدول
    if (isset($_POST['action']) && $_POST['action'] === 'drop_table') {
        try {
            $tableName = $_POST['table_name'];
            $success_message = dropTable($db, $tableName);
        } catch (PDOException $e) {
            $error_message = 'خطأ عند حذف الجدول: ' . $e->getMessage();
        }
    }
    
    // إدراج صف جديد
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
            $error_message = 'خطأ عند إدراج البيانات: ' . $e->getMessage();
        }
    }
    
    // تحديث صف
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
            $error_message = 'خطأ عند تحديث البيانات: ' . $e->getMessage();
        }
    }
    
    // حذف صف
    if (isset($_POST['action']) && $_POST['action'] === 'delete_row') {
        try {
            $tableName = $_POST['table_name'];
            $whereCondition = $_POST['where_condition'];
            
            $success_message = deleteRow($db, $tableName, $whereCondition);
        } catch (PDOException $e) {
            $error_message = 'خطأ عند حذف البيانات: ' . $e->getMessage();
        }
    }
    
    // تنفيذ استعلام مخصص
    if (isset($_POST['action']) && $_POST['action'] === 'custom_query') {
        try {
            $query = $_POST['query'];
            $queryResult = executeCustomQuery($db, $query);
            
            if (is_array($queryResult)) {
                $customQueryResult = $queryResult;
                $success_message = "تم تنفيذ الاستعلام بنجاح. تم استرجاع " . count($queryResult) . " صفوف.";
            } else {
                $success_message = $queryResult;
            }
        } catch (PDOException $e) {
            $error_message = 'خطأ في الاستعلام: ' . $e->getMessage();
        }
    }
}

// جلب المتغيرات المختلفة للعرض
$tables = isset($db) ? getTables($db) : [];
$selectedTable = isset($_GET['table']) ? $_GET['table'] : (count($tables) > 0 ? $tables[0] : '');
$tableColumns = !empty($selectedTable) ? getTableColumns($db, $selectedTable) : [];
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
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>واجهة إدارة SQLite</title>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: 'Tajawal', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            background-color: #fff;
            padding: 30px 25px 25px 25px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            border-radius: 12px;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        header h1 {
            font-size: 2.2em;
            color: #4a69bd;
            margin: 0;
        }
        nav {
            background: #f8f9fa;
            padding: 12px 0 12px 0;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
        }
        nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-wrap: wrap;
        }
        nav li {
            margin-left: 18px;
        }
        nav a {
            text-decoration: none;
            color: #222f3e;
            font-weight: 500;
            transition: color 0.2s;
        }
        nav a:hover {
            color: #3867d6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        table, th, td {
            border: none;
        }
        th, td {
            padding: 12px 10px;
            text-align: right;
        }
        th {
            background-color: #f1f2f6;
            color: #222f3e;
            font-weight: bold;
            cursor: pointer;
            border-bottom: 2px solid #d1d8e0;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .alert {
            padding: 12px 18px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 1.1em;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn-link {
            background: none;
            border: none;
            color: #3867d6;
            cursor: pointer;
            padding: 0;
            font-size: 1em;
            text-decoration: underline;
        }
        .btn-link:hover {
            color: #e55039;
        }
        .btn-warning, .btn-danger {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.1em;
            padding: 0 6px;
            color: #222f3e;
        }
        .btn-warning:hover {
            color: #f6b93b;
        }
        .btn-danger:hover {
            color: #e55039;
        }
        .fa-trash {
            color: #e55039;
        }
        .fa-pen {
            color: #f6b93b;
        }
        .fa-plus {
            color: #4a69bd;
        }
        .fa-database {
            color: #3867d6;
        }
        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
        }
        .pagination li {
            margin-right: 5px;
        }
        .pagination a {
            display: block;
            padding: 5px 12px;
            text-decoration: none;
            border-radius: 4px;
            border: 1px solid #d1d8e0;
            color: #222f3e;
            background: #f1f2f6;
        }
        .pagination a.active {
            background-color: #3867d6;
            color: white;
            border: 1px solid #3867d6;
        }
        .tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 18px;
            cursor: pointer;
            margin-right: 5px;
            background-color: #f1f2f6;
            border: 1px solid #e0e0e0;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            font-weight: 500;
        }
        .tab.active {
            background-color: #fff;
            border-bottom: 1px solid #fff;
            margin-bottom: -1px;
            color: #3867d6;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .column-row {
            display: flex;
            margin-bottom: 5px;
        }
        .column-row input, .column-row select {
            margin-right: 10px;
        }
        #edit_form_container {
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
        }
        #edit_form_container input[type="text"] {
            width: 100%;
            padding: 8px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        #edit_form_container label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        #edit_form_container input[type="submit"],
        #edit_form_container button {
            margin-right: 10px;
        }
        .icon-action {
            font-size: 1.2em;
            margin-left: 6px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>واجهة إدارة SQLite</h1>
            <div>
                <span>قاعدة البيانات: <?php echo $database_file; ?></span>
            </div>
        </header>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <nav>
            <ul>
                <li><a href="?">الرئيسية</a></li>
                <?php foreach ($tables as $table): ?>
                    <li><a href="?table=<?php echo $table; ?>"><?php echo $table; ?></a></li>
                <?php endforeach; ?>
                <li><a href="?action=create_table">إنشاء جدول جديد</a></li>
                <li><a href="?action=custom_query">استعلام مخصص</a></li>
            </ul>
        </nav>
        
        <?php if (isset($_GET['action']) && $_GET['action'] === 'create_table'): ?>
            <!-- نموذج إنشاء جدول جديد -->
            <h2>إنشاء جدول جديد</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="create_table">
                
                <div>
                    <label for="table_name">اسم الجدول:</label>
                    <input type="text" id="table_name" name="table_name" required>
                </div>
                
                <h3>الأعمدة:</h3>
                <div id="columns_container">
                    <div class="column-row">
                        <input type="text" name="column_name[]" placeholder="اسم العمود" required>
                        <select name="column_type[]">
                            <option value="INTEGER">INTEGER</option>
                            <option value="TEXT">TEXT</option>
                            <option value="REAL">REAL</option>
                            <option value="BLOB">BLOB</option>
                            <option value="NUMERIC">NUMERIC</option>
                        </select>
                        <label><input type="checkbox" name="column_pk[]"> مفتاح أساسي</label>
                        <label><input type="checkbox" name="column_nn[]"> غير فارغ</label>
                        <input type="text" name="column_default[]" placeholder="القيمة الافتراضية">
                    </div>
                </div>
                
                <button type="button" onclick="addColumn()">إضافة عمود</button>
                <input type="submit" value="إنشاء الجدول">
            </form>
            
            <script>
                function addColumn() {
                    const container = document.getElementById('columns_container');
                    const newColumn = document.createElement('div');
                    newColumn.className = 'column-row';
                    newColumn.innerHTML = `
                        <input type="text" name="column_name[]" placeholder="اسم العمود">
                        <select name="column_type[]">
                            <option value="INTEGER">INTEGER</option>
                            <option value="TEXT">TEXT</option>
                            <option value="REAL">REAL</option>
                            <option value="BLOB">BLOB</option>
                            <option value="NUMERIC">NUMERIC</option>
                        </select>
                        <label><input type="checkbox" name="column_pk[]"> مفتاح أساسي</label>
                        <label><input type="checkbox" name="column_nn[]"> غير فارغ</label>
                        <input type="text" name="column_default[]" placeholder="القيمة الافتراضية">
                    `;
                    container.appendChild(newColumn);
                }
            </script>
        
        <?php elseif (isset($_GET['action']) && $_GET['action'] === 'custom_query'): ?>
            <!-- نموذج استعلام مخصص -->
            <h2>استعلام SQL مخصص</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="custom_query">
                
                <div>
                    <label for="query">استعلام SQL:</label>
                    <textarea id="query" name="query" rows="5" required></textarea>
                </div>
                
                <input type="submit" value="تنفيذ">
            </form>
            
            <?php if (isset($customQueryResult) && is_array($customQueryResult)): ?>
                <h3>نتائج الاستعلام:</h3>
                <?php if (count($customQueryResult) > 0): ?>
                    <table>
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
                    <p>لا توجد نتائج للعرض.</p>
                <?php endif; ?>
            <?php endif; ?>
        
        <?php elseif (!empty($selectedTable)): ?>
            <!-- عرض بيانات الجدول -->
            <div class="tabs">
                <div class="tab active" onclick="openTab(event, 'browse')">استعراض</div>
                <div class="tab" onclick="openTab(event, 'structure')">الهيكل</div>
                <div class="tab" onclick="openTab(event, 'sql')">SQL</div>
            </div>
            
            <div id="browse" class="tab-content active">
                <h2>استعراض الجدول: <?php echo $selectedTable; ?></h2>
                <button onclick="showInsertForm()" style="margin-bottom:15px; background: #4a69bd; color: #fff; border: none; border-radius: 6px; padding: 10px 18px; font-size: 1.1em; cursor: pointer;">
                    <i class="fa fa-plus"></i> إضافة سجل جديد
                </button>
                
                <?php if (count($tableData) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>العمليات</th>
                                <?php foreach ($tableColumns as $column): ?>
                                    <th>
                                        <a href="?table=<?php echo $selectedTable; ?>&sort_by=<?php echo $column['name']; ?>&sort_order=<?php echo isset($_GET['sort_order']) && $_GET['sort_order'] === 'asc' ? 'desc' : 'asc'; ?>">
                                            <?php echo $column['name']; ?>
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
                                        <a href="#" class="btn-link" title="تعديل" onclick="populateEditForm('<?php echo addslashes($whereCondition); ?>', <?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>); return false;">
                                            <i class="fa fa-pen icon-action"></i>
                                        </a>
                                        <a href="#" class="btn-link" title="حذف" onclick="if(confirm('هل أنت متأكد من حذف هذا الصف؟')) { document.getElementById('delete_form_<?php echo md5($whereCondition); ?>').submit(); } return false;">
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
                    
                    <!-- التصفح بين الصفحات -->
                    <?php if ($pageCount > 1): ?>
                        <ul class="pagination">
                            <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                                <li>
                                    <a href="?table=<?php echo $selectedTable; ?>&page=<?php echo $i; ?>&limit=<?php echo $limit; ?>" <?php echo $i === $page ? 'class="active"' : ''; ?>>
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    <?php endif; ?>
                    
                    <!-- نموذج التعديل -->
                    <div id="edit_form_container">
                        <h3>تعديل السجل</h3>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="update_row">
                            <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                            <input type="hidden" name="where_condition" id="edit_where_condition">
                            
                            <div id="edit_fields_container">
                            <?php foreach ($tableColumns as $column): ?>
                                <?php if ($column['pk'] && strtoupper($column['type']) === 'INTEGER'): ?>
                                    <div style="display:none;">
                                        <label for="edit_<?php echo $column['name']; ?>"><?php echo $column['name']; ?>:</label>
                                        <input type="text" 
                                               id="edit_<?php echo $column['name']; ?>" 
                                               name="data[<?php echo $column['name']; ?>]" 
                                               class="edit-field">
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <label for="edit_<?php echo $column['name']; ?>"><?php echo $column['name']; ?>:</label>
                                        <input type="text" 
                                               id="edit_<?php echo $column['name']; ?>" 
                                               name="data[<?php echo $column['name']; ?>]" 
                                               class="edit-field">
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </div>
                            
                            <div style="margin-top: 10px;">
                                <input type="submit" value="حفظ التعديلات">
                                <button type="button" onclick="hideEditForm()">إلغاء</button>
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
                    <p>لا توجد بيانات في هذا الجدول.</p>
                <?php endif; ?>
                
                <!-- نموذج الإدراج المنبثق -->
                <div id="insert_form_container" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border: 1px solid #ddd; box-shadow: 0 0 10px rgba(0,0,0,0.1); z-index: 1000; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; border-radius: 12px;">
                    <h3 style="color:#4a69bd; margin-top:0;">إدراج بيانات في الجدول: <?php echo $selectedTable; ?></h3>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="insert_row">
                        <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                        <?php foreach ($tableColumns as $column): ?>
                            <?php if ($column['pk'] && strtoupper($column['type']) === 'INTEGER'): ?>
                                <div style="display:none;">
                                    <label for="insert_<?php echo $column['name']; ?>"><?php echo $column['name']; ?> (<?php echo $column['type']; ?>):</label>
                                    <input type="text" id="insert_<?php echo $column['name']; ?>" name="data[<?php echo $column['name']; ?>]" placeholder="سيتم توليده تلقائيًا" class="edit-field" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                                </div>
                            <?php else: ?>
                                <div style="margin-bottom: 12px;">
                                    <label for="insert_<?php echo $column['name']; ?>" style="display:block; font-weight:bold; margin-bottom:4px; color:#3867d6;">
                                        <?php echo $column['name']; ?> (<?php echo $column['type']; ?>):
                                    </label>
                                    <input type="text" id="insert_<?php echo $column['name']; ?>" name="data[<?php echo $column['name']; ?>]" class="edit-field" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div style="margin-top: 10px; text-align:left;">
                            <input type="submit" value="إدراج" style="background:#4a69bd; color:#fff; border:none; border-radius:6px; padding:8px 18px; font-size:1em; cursor:pointer;">
                            <button type="button" onclick="hideInsertForm()" style="background:#e55039; color:#fff; border:none; border-radius:6px; padding:8px 18px; font-size:1em; cursor:pointer;">إلغاء</button>
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
                </script>
            </div>
            
            <div id="structure" class="tab-content">
                <h2>هيكل الجدول: <?php echo $selectedTable; ?></h2>
                
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم العمود</th>
                            <th>النوع</th>
                            <th>فارغ</th>
                            <th>افتراضي</th>
                            <th>مفتاح أساسي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableColumns as $i => $column): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo $column['name']; ?></td>
                                <td><?php echo $column['type']; ?></td>
                                <td><?php echo $column['notnull'] ? 'لا' : 'نعم'; ?></td>
                                <td><?php echo $column['dflt_value']; ?></td>
                                <td><?php echo $column['pk'] ? 'نعم' : 'لا'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا الجدول؟');">
                    <input type="hidden" name="action" value="drop_table">
                    <input type="hidden" name="table_name" value="<?php echo $selectedTable; ?>">
                    <input type="submit" value="حذف الجدول" class="btn-danger">
                </form>
            </div>
            
            <div id="sql" class="tab-content">
                <h2>استعلام SQL للجدول: <?php echo $selectedTable; ?></h2>
                
                <?php
                $tableInfoQuery = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='" . $selectedTable . "'");
                $tableInfoRow = $tableInfoQuery->fetch(PDO::FETCH_ASSOC);
                ?>
                
                <pre><?php echo $tableInfoRow['sql']; ?></pre>
            </div>
            
            <script>
                function openTab(evt, tabName) {
                    const tabContents = document.getElementsByClassName('tab-content');
                    for (let i = 0; i < tabContents.length; i++) {
                        tabContents[i].classList.remove('active');
                    }

                    const tabs = document.getElementsByClassName('tab');
                    for (let i = 0; i < tabs.length; i++) {
                        tabs[i].classList.remove('active');
                    }

                    document.getElementById(tabName).classList.add('active');
                    evt.currentTarget.classList.add('active');
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>