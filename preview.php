<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit();
}

// Vérifier si un ID de fichier est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid file ID']);
    exit();
}

$fileId = intval($_GET['id']);
$userId = $_SESSION['user_id'];

// Connexion à la base de données
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Récupérer les informations du fichier
$stmt = $conn->prepare("SELECT f.filename, f.file_type, fc.content 
                        FROM files f 
                        JOIN file_content fc ON f.id = fc.file_id 
                        WHERE f.id = ? AND f.user_id = ?");
$stmt->bind_param("ii", $fileId, $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File not found or access denied']);
    exit();
}

$file = $result->fetch_assoc();

// Vérifier si le fichier est prévisualisable
$previewableTypes = [
    'text/plain', 'text/html', 'text/css', 'text/javascript',
    'application/json', 'application/xml', 'application/javascript',
    'text/markdown', 'text/x-python', 'text/x-php'
];

$isPreviewable = false;
foreach ($previewableTypes as $type) {
    if (strpos($file['file_type'], $type) === 0) {
        $isPreviewable = true;
        break;
    }
}

if (!$isPreviewable) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'This file type cannot be previewed']);
    exit();
}

// Limiter la taille du contenu prévisualisé (pour éviter de surcharger le navigateur)
$maxPreviewSize = 100 * 1024; // 100 KB
$content = $file['content'];
$isTruncated = false;

if (strlen($content) > $maxPreviewSize) {
    $content = substr($content, 0, $maxPreviewSize);
    $isTruncated = true;
}

// Encoder le contenu pour éviter les problèmes de caractères spéciaux
$content = htmlspecialchars($content);

// Ajouter un message si le contenu a été tronqué
if ($isTruncated) {
    $content .= "\n\n[File content truncated. Download the full file to view the complete content.]";
}

// Renvoyer les données au format JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'filename' => $file['filename'],
    'file_type' => $file['file_type'],
    'content' => $content,
    'truncated' => $isTruncated
]);

$stmt->close();
$conn->close();
?>