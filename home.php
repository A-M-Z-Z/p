<?php
session_start();

// Verify user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");
    exit();
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = $_SESSION['username'];
$userid = $_SESSION['user_id'];
$messages = [];

// Calculate current storage usage
$storageQuery = $conn->prepare("SELECT SUM(file_size) as total_used FROM files WHERE user_id = ?");
$storageQuery->bind_param("i", $userid);
$storageQuery->execute();
$result = $storageQuery->get_result();
$row = $result->fetch_assoc();
$currentUsage = $row['total_used'] ?: 0;

// Get user's quota
$quotaQuery = $conn->prepare("SELECT storage_quota FROM users WHERE id = ?");
$quotaQuery->bind_param("i", $userid);
$quotaQuery->execute();
$quotaResult = $quotaQuery->get_result();
$quotaRow = $quotaResult->fetch_assoc();
$userQuota = $quotaRow['storage_quota'] ?: 104857600; // Default 100MB

// Current folder ID
$current_folder_id = isset($_GET['folder_id']) ? intval($_GET['folder_id']) : null;

// Create folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder_name'])) {
    $folder_name = $conn->real_escape_string(trim($_POST['new_folder_name']));
    
    if (!empty($folder_name)) {
        // Create folder
        $query = "INSERT INTO folders (user_id, folder_name, parent_folder_id) VALUES ($userid, '$folder_name', ";
        $query .= $current_folder_id ? $current_folder_id : "NULL";
        $query .= ")";
        
        if ($conn->query($query)) {
            $messages[] = "<div class='alert alert-success'>Folder created successfully.</div>";
        } else {
            $messages[] = "<div class='alert alert-danger'>Error creating folder: " . $conn->error . "</div>";
        }
    }
}

// Upload multiple files
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
    $uploadedFiles = $_FILES['files'];
    $fileCount = count($uploadedFiles['name']);
    $success = 0;
    $errors = 0;
    
    // Process each file
    for ($i = 0; $i < $fileCount; $i++) {
        if ($uploadedFiles['error'][$i] != 0) {
            $errors++;
            continue;
        }
        
        $fileName = $conn->real_escape_string($uploadedFiles['name'][$i]);
        $fileSize = $uploadedFiles['size'][$i];
        $fileTmpPath = $uploadedFiles['tmp_name'][$i];
        $fileType = $conn->real_escape_string($uploadedFiles['type'][$i]);
        
        // Check if this file would exceed quota
        if (($currentUsage + $fileSize) > $userQuota) {
            $errors++;
            $messages[] = "<div class='alert alert-danger'>Cannot upload file '{$fileName}': Storage quota exceeded. Your quota is " . 
                number_format($userQuota / 1048576, 2) . " MB and you're using " . 
                number_format($currentUsage / 1048576, 2) . " MB.</div>";
            continue; // Skip this file
        }
        
        // Check if file already exists
        $check_query = "SELECT id FROM files WHERE user_id = $userid AND filename = '$fileName'";
        if ($current_folder_id) {
            $check_query .= " AND folder_id = $current_folder_id";
        } else {
            $check_query .= " AND folder_id IS NULL";
        }
        
        $check = $conn->query($check_query);
        
        if ($check->num_rows > 0) {
            $errors++;
            $messages[] = "<div class='alert alert-danger'>File '{$fileName}' already exists in this location.</div>";
            continue;
        }
        
        // Read file content
        $file_content = file_get_contents($fileTmpPath);
        
        // Insert file metadata
        $insert_query = "INSERT INTO files (user_id, filename, file_size, file_type";
        $insert_query .= ", folder_id) VALUES ($userid, '$fileName', $fileSize, '$fileType'";
        $insert_query .= ", " . ($current_folder_id ? $current_folder_id : "NULL") . ")";
        
        if ($conn->query($insert_query)) {
            $file_id = $conn->insert_id;
            
            // Insert file content
            $content_insert = $conn->query("INSERT INTO file_content (file_id, content) VALUES ($file_id, '" . $conn->real_escape_string($file_content) . "')");
            
            if ($content_insert) {
                $success++;
                $currentUsage += $fileSize; // Update usage for next file check
            } else {
                $errors++;
                $messages[] = "<div class='alert alert-danger'>Error saving content for file '{$fileName}'.</div>";
            }
        } else {
            $errors++;
            $messages[] = "<div class='alert alert-danger'>Error saving metadata for file '{$fileName}'.</div>";
        }
    }
    
    if ($success > 0) {
        $messages[] = "<div class='alert alert-success'>Successfully uploaded $success files.</div>";
    }
    if ($errors > 0) {
        $messages[] = "<div class='alert alert-danger'>Failed to upload $errors files.</div>";
    }
}

// Delete folder
if (isset($_GET['delete_folder']) && is_numeric($_GET['delete_folder'])) {
    $folder_id = intval($_GET['delete_folder']);
    
    // Check if folder belongs to user
    $check = $conn->query("SELECT id FROM folders WHERE id = $folder_id AND user_id = $userid");
    if ($check->num_rows > 0) {
        if ($conn->query("DELETE FROM folders WHERE id = $folder_id")) {
            $messages[] = "<div class='alert alert-success'>Folder deleted successfully.</div>";
            
            // Redirect if current folder was deleted
            if ($folder_id == $current_folder_id) {
                $parent = $conn->query("SELECT parent_folder_id FROM folders WHERE id = $folder_id")->fetch_assoc();
                $parent_id = $parent ? $parent['parent_folder_id'] : null;
                
                header("Location: home.php" . ($parent_id ? "?folder_id=$parent_id" : ""));
                exit();
            }
        } else {
            $messages[] = "<div class='alert alert-danger'>Error deleting folder: " . $conn->error . "</div>";
        }
    }
}

// Delete file
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $file_id = intval($_GET['delete_id']);
    
    // Get file size before deleting for quota update
    $sizeQuery = $conn->prepare("SELECT file_size FROM files WHERE id = ? AND user_id = ?");
    $sizeQuery->bind_param("ii", $file_id, $userid);
    $sizeQuery->execute();
    $sizeResult = $sizeQuery->get_result();
    if ($sizeResult->num_rows > 0) {
        $sizeRow = $sizeResult->fetch_assoc();
        $fileSize = $sizeRow['file_size'];
    }
    
    // Check if file belongs to user
    $check = $conn->query("SELECT id FROM files WHERE id = $file_id AND user_id = $userid");
    if ($check->num_rows > 0) {
        if ($conn->query("DELETE FROM files WHERE id = $file_id")) {
            $messages[] = "<div class='alert alert-success'>File deleted successfully.</div>";
            
            // Update current usage after deleting
            if (isset($fileSize)) {
                $currentUsage = max(0, $currentUsage - $fileSize);
            }
        } else {
            $messages[] = "<div class='alert alert-danger'>Error deleting file: " . $conn->error . "</div>";
        }
    }
}

// Get current folder info
$current_folder_name = "Root";
$parent_folder_id = null;

if ($current_folder_id) {
    $folder_info = $conn->query("SELECT folder_name, parent_folder_id FROM folders WHERE id = $current_folder_id AND user_id = $userid");
    if ($folder_info->num_rows > 0) {
        $folder = $folder_info->fetch_assoc();
        $current_folder_name = $folder['folder_name'];
        $parent_folder_id = $folder['parent_folder_id'];
    } else {
        // Invalid folder ID, redirect to root
        header("Location: home.php");
        exit();
    }
}

// Get subfolders
$folders = [];
$query = "SELECT id, folder_name FROM folders WHERE user_id = $userid AND ";
$query .= $current_folder_id ? "parent_folder_id = $current_folder_id" : "parent_folder_id IS NULL";
$query .= " ORDER BY folder_name";

$result = $conn->query($query);
while ($folder = $result->fetch_assoc()) {
    $folders[] = $folder;
}

// Get files in current folder
$files = [];
$query = "SELECT id, filename, file_size, file_type FROM files WHERE user_id = $userid AND ";
$query .= $current_folder_id ? "folder_id = $current_folder_id" : "folder_id IS NULL";
$query .= " ORDER BY filename";

$result = $conn->query($query);
while ($file = $result->fetch_assoc()) {
    $files[] = $file;
}

// Get breadcrumb
function getBreadcrumb($conn, $folder_id, $userid) {
    $path = [];
    $current = $folder_id;
    
    while ($current) {
        $result = $conn->query("SELECT id, folder_name, parent_folder_id FROM folders WHERE id = $current AND user_id = $userid");
        if ($result->num_rows > 0) {
            $folder = $result->fetch_assoc();
            array_unshift($path, ['id' => $folder['id'], 'name' => $folder['folder_name']]);
            $current = $folder['parent_folder_id'];
        } else {
            break;
        }
    }
    
    return $path;
}

$breadcrumb = $current_folder_id ? getBreadcrumb($conn, $current_folder_id, $userid) : [];

// Calculate usage percentage for the storage bar
$usagePercentage = ($userQuota > 0) ? ($currentUsage / $userQuota) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudBOX - Files and Folders</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .top-bar {
            background-color: #4f46e5;
            padding: 15px;
            display: flex;
            align-items: center;
            color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .logo {
            margin-right: 15px;
        }
        
        .top-bar h1 {
            margin: 0;
            font-size: 22px;
        }
        
        .search-bar {
            margin-left: auto;
        }
        
        .search-bar input {
            border-radius: 20px;
            padding: 8px 15px;
            border: none;
            width: 250px;
        }
        
        .dashboard-nav {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .dashboard-nav a {
            color: #4b5563;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .dashboard-nav a:hover {
            background-color: #f3f4f6;
            color: #4f46e5;
        }
        
        main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .container-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .item {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .folder-icon {
            color: #4f46e5;
        }
        
        .file-icon {
            color: #60a5fa;
        }
        
        .name {
            text-align: center;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            width: 100%;
            margin-bottom: 10px;
        }
        
        .actions {
            display: flex;
            margin-top: 10px;
            gap: 10px;
            width: 100%;
            justify-content: center;
        }
        
        .file-details {
            font-size: 13px;
            color: #6b7280;
            text-align: center;
            margin-top: 5px;
        }
        
        .drag-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 30px 20px;
            text-align: center;
            transition: border-color 0.3s;
            margin-bottom: 15px;
            position: relative;
            cursor: pointer;
        }
        
        .drag-area.active {
            border-color: #4f46e5;
            background-color: rgba(79, 70, 229, 0.05);
        }
        
        .drag-area i {
            font-size: 48px;
            color: #9ca3af;
            margin-bottom: 15px;
        }
        
        .storage-card {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .storage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .storage-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        .storage-status {
            font-size: 14px;
            color: <?= $usagePercentage > 90 ? '#dc3545' : ($usagePercentage > 70 ? '#fd7e14' : '#198754') ?>;
            font-weight: 500;
        }
        
        .storage-progress-container {
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            margin-bottom: 10px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .storage-progress {
            height: 100%;
            border-radius: 10px;
            background: <?= $usagePercentage > 90 ? 
                        'linear-gradient(90deg, #dc3545 0%, #f44336 100%)' : 
                        ($usagePercentage > 70 ? 
                            'linear-gradient(90deg, #fd7e14 0%, #ffb74d 100%)' : 
                            'linear-gradient(90deg, #198754 0%, #20c997 100%)') ?>;
            width: <?= min(100, $usagePercentage) ?>%;
            transition: width 1s ease;
            position: relative;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .storage-progress-text {
            position: absolute;
            color: <?= $usagePercentage > 50 ? 'white' : '#212529' ?>;
            font-weight: 600;
            font-size: 12px;
            text-shadow: 0 1px 1px rgba(0,0,0,0.2);
            width: 100%;
            text-align: center;
        }
        
        .storage-details {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: #6c757d;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .section-header i {
            font-size: 24px;
            margin-right: 10px;
            color: #4f46e5;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: #343a40;
        }
        
        .btn-action {
            padding: 6px 12px;
            font-size: 14px;
            border-radius: 6px;
        }
        
        /* Bootstrap adjustments */
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 10px;
        }
        
        .form-control {
            border-radius: 6px;
            padding: 10px 15px;
        }
        
        .btn-primary {
            background-color: #4f46e5;
            border-color: #4f46e5;
        }
        
        .btn-primary:hover {
            background-color: #4338ca;
            border-color: #4338ca;
        }
        
        .btn-success {
            background-color: #059669;
            border-color: #059669;
        }
        
        .btn-success:hover {
            background-color: #047857;
            border-color: #047857;
        }
        
        .btn-danger {
            background-color: #ef4444;
            border-color: #ef4444;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
            border-color: #dc2626;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
            
            .search-bar input {
                width: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="logo">
            <img src="logo.png" alt="CloudBOX Logo" height="40">
        </div>
        <h1>CloudBOX</h1>
        <div class="search-bar">
            <input type="text" placeholder="Search files and folders..." class="form-control">
        </div>
    </div>
    
    <nav class="dashboard-nav">
        <a href="home"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="drive"><i class="fas fa-folder"></i> My Drive</a>
        <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
        <a href="admin"><i class="fas fa-crown"></i> Admin Panel</a>
        <?php endif; ?>
        <a href="shared"><i class="fas fa-share-alt"></i> Shared Files</a>
        <a href="monitoring"><i class="fas fa-chart-line"></i> Monitoring</a>
        <a href="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <main>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">Welcome, <?= htmlspecialchars($username) ?>!</h1>
        </div>
        
        <!-- Improved Storage Usage Display -->
        <div class="storage-card">
            <div class="storage-header">
                <h2 class="storage-title"><i class="fas fa-hdd me-2"></i> Storage Usage</h2>
                <div class="storage-status">
                    <?php if($usagePercentage > 90): ?>
                        <i class="fas fa-exclamation-triangle me-1"></i> Critical
                    <?php elseif($usagePercentage > 70): ?>
                        <i class="fas fa-exclamation-circle me-1"></i> High
                    <?php else: ?>
                        <i class="fas fa-check-circle me-1"></i> Good
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="storage-progress-container">
                <div class="storage-progress">
                    <div class="storage-progress-text"><?= number_format($usagePercentage, 1) ?>%</div>
                </div>
            </div>
            
            <div class="storage-details">
                <span><i class="fas fa-database me-1"></i> <?= number_format($currentUsage / 1048576, 2) ?> MB used</span>
                <span><i class="fas fa-server me-1"></i> <?= number_format($userQuota / 1048576, 2) ?> MB total</span>
                <span><i class="fas fa-hard-drive me-1"></i> <?= number_format(($userQuota - $currentUsage) / 1048576, 2) ?> MB free</span>
            </div>
        </div>
        
        <!-- Display messages -->
        <?php foreach ($messages as $message): ?>
            <?= $message ?>
        <?php endforeach; ?>
        
        <!-- Breadcrumb navigation -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-white p-3 rounded shadow-sm">
                <li class="breadcrumb-item"><a href="home.php"><i class="fas fa-home"></i> Root</a></li>
                <?php foreach ($breadcrumb as $folder): ?>
                    <li class="breadcrumb-item">
                        <a href="home.php?folder_id=<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></a>
                    </li>
                <?php endforeach; ?>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($current_folder_name) ?></li>
            </ol>
        </nav>
        
        <div class="row mb-4">
            <!-- Create Folder Form -->
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-folder-plus me-2"></i>Create New Folder</h5>
                        <form method="POST">
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" name="new_folder_name" placeholder="Folder name" required>
                                <button class="btn btn-primary" type="submit">Create</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Upload Files Form -->
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-cloud-upload-alt me-2"></i>Upload Files</h5>
                        
                        <div class="drag-area" id="drag-area">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Drag & drop files here or <strong>click to browse</strong></p>
                            <form method="POST" enctype="multipart/form-data" id="upload-form">
                                <input type="file" name="files[]" id="file-input" multiple class="d-none">
                            </form>
                        </div>
                        
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-primary w-50" onclick="document.getElementById('file-input').click()">
                                <i class="fas fa-file me-1"></i> Select Files
                            </button>
                            <button class="btn btn-success w-50" onclick="selectFolder()">
                                <i class="fas fa-folder me-1"></i> Select Folder
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Folders section -->
        <?php if (!empty($folders)): ?>
        <div class="section-header">
            <i class="fas fa-folder"></i>
            <h2 class="section-title">Folders</h2>
        </div>
        <div class="container-grid">
            <?php foreach ($folders as $folder): ?>
                <div class="item">
                    <div class="icon folder-icon">
                        <i class="fas fa-folder fa-3x"></i>
                    </div>
                    <div class="name"><?= htmlspecialchars($folder['folder_name']) ?></div>
                    <div class="actions">
                        <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-sm btn-primary btn-action">
                            <i class="fas fa-download me-1"></i> Download
                        </a>
                        <?php if(strpos($file['file_type'], 'text/') === 0 || 
                                 strpos($file['file_type'], 'application/json') === 0 ||
                                 strpos($file['file_type'], 'application/xml') === 0 ||
                                 strpos($file['file_type'], 'application/javascript') === 0): ?>
                        <a href="#" class="btn btn-sm btn-info btn-action" onclick="previewFile(<?= $file['id'] ?>)">
                            <i class="fas fa-eye me-1"></i> Preview
                        </a>
                        <?php endif; ?>
                        <a href="home.php?folder_id=<?= $folder['id'] ?>" class="btn btn-sm btn-primary btn-action">
                            <i class="fas fa-folder-open me-1"></i> Open
                        </a>
                        <a href="download_folder.php?folder_id=<?= $folder['id'] ?>" class="btn btn-sm btn-secondary btn-action" title="Download as ZIP">
                            <i class="fas fa-download"></i>
                        </a>
                        <a href="home.php?delete_folder=<?= $folder['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" 
                           class="btn btn-sm btn-danger btn-action" 
                           onclick="return confirm('Are you sure you want to delete this folder?');">
                            <i class="fas fa-trash"></i>
                        </a>
                        <a href="home.php?delete_id=<?= $file['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" 
                           class="btn btn-sm btn-danger btn-action" 
                           onclick="return confirm('Are you sure you want to delete this file?');">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Files section -->
        <?php if (!empty($files)): ?>
        <div class="section-header">
            <i class="fas fa-file"></i>
            <h2 class="section-title">Files</h2>
        </div>
        <div class="container-grid">
            <?php foreach ($files as $file): ?>
                <div class="item">
                    <?php
                    // Determine file icon based on type
                    $iconClass = 'fa-file';
                    if (strpos($file['file_type'], 'image/') === 0) {
                        $iconClass = 'fa-file-image';
                    } elseif (strpos($file['file_type'], 'video/') === 0) {
                        $iconClass = 'fa-file-video';
                    } elseif (strpos($file['file_type'], 'audio/') === 0) {
                        $iconClass = 'fa-file-audio';
                    } elseif (strpos($file['file_type'], 'application/pdf') === 0) {
                        $iconClass = 'fa-file-pdf';
                    } elseif (strpos($file['file_type'], 'text/') === 0) {
                        $iconClass = 'fa-file-alt';
                    } elseif (strpos($file['file_type'], 'application/zip') === 0 || 
                             strpos($file['file_type'], 'application/x-rar') === 0) {
                        $iconClass = 'fa-file-archive';
                    }
                    ?>
                    <div class="icon file-icon">
                        <i class="fas <?= $iconClass ?> fa-3x"></i>
                    </div>
                    <div class="name"><?= preg_replace('/^(\d+_)+/', '', $file['filename']) ?></div>
                    <div class="file-details"><?= number_format($file['file_size'] / 1024, 2) ?> KB</div>
                    <div class="actions">
                        