<?php
/* =========================================================================
   SETUP SQL
   CREATE TABLE IF NOT EXISTS collab_docs (
       id INT AUTO_INCREMENT PRIMARY KEY,
       title VARCHAR(255) NOT NULL,
       slug VARCHAR(255) DEFAULT NULL,
       type VARCHAR(30) DEFAULT 'note',
       tags VARCHAR(255) DEFAULT '',
       library_id INT DEFAULT NULL,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );

   CREATE TABLE IF NOT EXISTS collab_blocks (
       id INT AUTO_INCREMENT PRIMARY KEY,
       doc_id INT NOT NULL DEFAULT 1,
       type VARCHAR(20) NOT NULL,
       content LONGTEXT,
       position INT DEFAULT 0,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );

   CREATE TABLE IF NOT EXISTS collab_users (
       id INT AUTO_INCREMENT PRIMARY KEY,
       username VARCHAR(50) UNIQUE NOT NULL,
       password_hash VARCHAR(255) NOT NULL,
       role ENUM('admin','editor','reader') NOT NULL DEFAULT 'editor',
       must_change_password TINYINT(1) NOT NULL DEFAULT 0,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       last_login TIMESTAMP NULL DEFAULT NULL
   );

   CREATE TABLE IF NOT EXISTS collab_libraries (
       id INT AUTO_INCREMENT PRIMARY KEY,
       name VARCHAR(120) UNIQUE NOT NULL,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE TABLE IF NOT EXISTS collab_doc_access (
       user_id INT NOT NULL,
       doc_id INT NOT NULL,
       role ENUM('editor','reader') NOT NULL DEFAULT 'reader',
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (user_id, doc_id)
   );
========================================================================= */

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function slugify($text) {
    $text = trim($text);
    if ($text === '') return 'doc';
    $text = mb_strtolower($text, 'UTF-8');
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'doc';
}

function ensure_unique_slug($pdo, $baseSlug, $excludeId = null) {
    $slug = $baseSlug;
    $i = 1;
    while (true) {
        if ($excludeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM collab_docs WHERE slug = ? AND id <> ?");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM collab_docs WHERE slug = ?");
            $stmt->execute([$slug]);
        }
        if ($stmt->fetchColumn() == 0) return $slug;
        $slug = $baseSlug . '-' . $i;
        $i++;
    }
}

function esc_like($value) {
    return str_replace(['%', '_'], ['\\%', '\\_'], $value);
}

function require_auth() {
    if (empty($_SESSION['user'])) {
        json_response(['error' => 'Unauthorized'], 401);
    }
}

function require_role($roles) {
    $user = $_SESSION['user'] ?? null;
    if (!$user || !in_array($user['role'], (array)$roles, true)) {
        json_response(['error' => 'Forbidden'], 403);
    }
}

function get_doc_access_role($pdo, $userId, $docId) {
    $stmt = $pdo->prepare("SELECT role FROM collab_doc_access WHERE user_id = ? AND doc_id = ?");
    $stmt->execute([$userId, $docId]);
    return $stmt->fetchColumn() ?: null;
}

function require_doc_access($pdo, $user, $docId, $needWrite = false) {
    if ($user['role'] === 'admin') return;
    $role = get_doc_access_role($pdo, $user['id'], $docId);
    if (!$role) json_response(['error' => 'Forbidden'], 403);
    if ($needWrite && $role !== 'editor') json_response(['error' => 'Forbidden'], 403);
}

// --- CONFIGURATION (ENV READY) ---
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'collab_notes';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) { die("Erreur DB : Vérifiez vos identifiants."); }

// --- MIGRATIONS ---
$pdo->exec("CREATE TABLE IF NOT EXISTS collab_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) DEFAULT NULL,
    type VARCHAR(30) DEFAULT 'note',
    tags VARCHAR(255) DEFAULT '',
    library_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS collab_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_id INT NOT NULL DEFAULT 1,
    type VARCHAR(20) NOT NULL,
    content LONGTEXT,
    position INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS collab_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','editor','reader') NOT NULL DEFAULT 'editor',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS collab_libraries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS collab_doc_access (
    user_id INT NOT NULL,
    doc_id INT NOT NULL,
    role ENUM('editor','reader') NOT NULL DEFAULT 'reader',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, doc_id)
)");

try { $pdo->exec("ALTER TABLE collab_blocks ADD COLUMN doc_id INT NOT NULL DEFAULT 1"); } catch (PDOException $e) { /* ignore */ }
try { $pdo->exec("CREATE INDEX idx_blocks_doc_id ON collab_blocks (doc_id)"); } catch (PDOException $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE collab_docs ADD COLUMN slug VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) { /* ignore */ }
try { $pdo->exec("CREATE UNIQUE INDEX uniq_docs_slug ON collab_docs (slug)"); } catch (PDOException $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE collab_docs ADD COLUMN library_id INT DEFAULT NULL"); } catch (PDOException $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE collab_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) { /* ignore */ }

$bootstrapWarning = false;
$userCount = $pdo->query("SELECT COUNT(*) FROM collab_users")->fetchColumn();
if ((int)$userCount === 0) {
    $adminUser = getenv('ADMIN_USER') ?: 'admin';
    $adminPass = getenv('ADMIN_PASS') ?: 'admin';
    if (!getenv('ADMIN_USER') || !getenv('ADMIN_PASS')) {
        $bootstrapWarning = true;
    }
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO collab_users (username, password_hash, role, must_change_password) VALUES (?, ?, 'admin', 1)");
    $stmt->execute([$adminUser, $hash]);
}

$defaultLibraryId = $pdo->query("SELECT id FROM collab_libraries ORDER BY id ASC LIMIT 1")->fetchColumn();
if (!$defaultLibraryId) {
    $stmt = $pdo->prepare("INSERT INTO collab_libraries (name) VALUES (?)");
    $stmt->execute(['Général']);
    $defaultLibraryId = (int)$pdo->lastInsertId();
}
$stmt = $pdo->prepare("UPDATE collab_docs SET library_id = ? WHERE library_id IS NULL OR library_id = 0");
$stmt->execute([$defaultLibraryId]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Backfill slugs
$docsWithoutSlug = $pdo->query("SELECT id, title FROM collab_docs WHERE slug IS NULL OR slug = ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($docsWithoutSlug as $doc) {
    $base = slugify($doc['title']);
    $slug = ensure_unique_slug($pdo, $base, $doc['id']);
    $stmt = $pdo->prepare("UPDATE collab_docs SET slug = ? WHERE id = ?");
    $stmt->execute([$slug, $doc['id']]);
}

$defaultDocId = $pdo->query("SELECT id FROM collab_docs ORDER BY id ASC LIMIT 1")->fetchColumn();
if (!$defaultDocId) {
    $slug = ensure_unique_slug($pdo, slugify('Mon Wiki'));
    $stmt = $pdo->prepare("INSERT INTO collab_docs (title, slug, type, tags) VALUES (?, ?, ?, ?)");
    $stmt->execute(['Mon Wiki', $slug, 'wiki', '']);
    $defaultDocId = (int)$pdo->lastInsertId();
}
$stmt = $pdo->prepare("UPDATE collab_blocks SET doc_id = ? WHERE doc_id IS NULL OR doc_id = 0");
$stmt->execute([$defaultDocId]);

function templateBlocks($type, $title) {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    switch ($type) {
        case 'spec':
            return [
                "<h1>$safeTitle</h1><p><strong>Version :</strong> 1.0</p><p><strong>Auteur :</strong> </p>",
                "<h2>1. Contexte et objectifs</h2><p>Décrivez le contexte, les objectifs et les enjeux.</p>",
                "<h2>2. Périmètre</h2><p><strong>In-scope :</strong> ...</p><p><strong>Out-of-scope :</strong> ...</p>",
                "<h2>3. Exigences fonctionnelles</h2><ol><li>...</li></ol>",
                "<h2>4. Exigences non fonctionnelles</h2><ul><li>Performance</li><li>Sécurité</li><li>Accessibilité</li></ul>",
                "<h2>5. Contraintes & hypothèses</h2><p>...</p>",
                "<h2>6. Livrables</h2><ul><li>...</li></ul>",
                "<h2>7. Planning</h2><p>...</p>",
                "<h2>8. Risques & validation</h2><p>...</p>"
            ];
        case 'course':
            return [
                "<h1>$safeTitle</h1><p><strong>Niveau :</strong> </p><p><strong>Public :</strong> </p>",
                "<h2>Objectifs pédagogiques</h2><ul><li>...</li></ul>",
                "<h2>Plan du cours</h2><ol><li>...</li></ol>",
                "<h2>Contenu</h2><h3>Section 1</h3><p>...</p>",
                "<h2>Exercices</h2><ol><li>...</li></ol>",
                "<h2>Ressources</h2><ul><li>...</li></ul>"
            ];
        case 'wiki':
            return [
                "<h1>$safeTitle</h1><p>Résumé rapide de la page.</p>",
                "<h2>Table des matières</h2><ol><li>...</li></ol>",
                "<h2>Section</h2><p>...</p>"
            ];
        case 'note':
        default:
            return ["<h1>$safeTitle</h1><p>...</p>"];
    }
}

// =========================================================================
// AUTH & API BACKEND
// =========================================================================
$action = $_GET['action'] ?? null;
$loginError = null;

if ($action === 'logout') {
    session_unset();
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $token = $payload['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            $loginError = 'Jeton CSRF invalide.';
        } else {
            $username = trim($payload['username'] ?? '');
            $password = $payload['password'] ?? '';
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role, must_change_password FROM collab_users WHERE username = ?");
            $stmt->execute([$username]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow && password_verify($password, $userRow['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int)$userRow['id'],
                    'username' => $userRow['username'],
                    'role' => $userRow['role'],
                    'must_change' => (int)($userRow['must_change_password'] ?? 0)
                ];
                $pdo->prepare("UPDATE collab_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$userRow['id']]);

                if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                    json_response(['status' => 'ok']);
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            // Bootstrap fallback: admin/admin
            if ($username === 'admin' && $password === 'admin') {
                $hash = password_hash('admin', PASSWORD_DEFAULT);
                if ($userRow) {
                    $stmt = $pdo->prepare("UPDATE collab_users SET password_hash = ?, role = 'admin', must_change_password = 1 WHERE id = ?");
                    $stmt->execute([$hash, $userRow['id']]);
                    $userId = (int)$userRow['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO collab_users (username, password_hash, role, must_change_password) VALUES ('admin', ?, 'admin', 1)");
                    $stmt->execute([$hash]);
                    $userId = (int)$pdo->lastInsertId();
                }
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => $userId,
                    'username' => 'admin',
                    'role' => 'admin',
                    'must_change' => 1
                ];
                if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                    json_response(['status' => 'ok']);
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }

            $loginError = 'Identifiants invalides.';
        }
    }
}

if (isset($_GET['action']) && !in_array($action, ['login', 'logout'], true)) {
    require_auth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            json_response(['error' => 'Invalid CSRF token'], 403);
        }
    }

    if ($action === 'docs_list') {
        $user = $_SESSION['user'];
        if ($user['role'] === 'admin') {
            $stmt = $pdo->query("SELECT id, title, slug, type, tags, library_id, created_at, updated_at FROM collab_docs ORDER BY updated_at DESC, id DESC");
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($docs as &$d) { $d['access_role'] = 'editor'; }
            json_response($docs);
        }
        $stmt = $pdo->prepare("SELECT d.id, d.title, d.slug, d.type, d.tags, d.library_id, d.created_at, d.updated_at, da.role AS access_role
            FROM collab_docs d
            INNER JOIN collab_doc_access da ON da.doc_id = d.id AND da.user_id = ?
            ORDER BY d.updated_at DESC, d.id DESC");
        $stmt->execute([$user['id']]);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action === 'docs_create') {
        require_role(['admin', 'editor']);
        $title = trim($input['title'] ?? 'Nouveau document');
        $type = $input['type'] ?? 'note';
        $tags = trim($input['tags'] ?? '');
        $template = $input['template'] ?? $type;
        $libraryId = (int)($input['library_id'] ?? $defaultLibraryId);
        $slug = ensure_unique_slug($pdo, slugify($title));

        $stmt = $pdo->prepare("INSERT INTO collab_docs (title, slug, type, tags, library_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $slug, $type, $tags, $libraryId]);
        $docId = (int)$pdo->lastInsertId();

        if ($_SESSION['user']['role'] !== 'admin') {
            $stmt = $pdo->prepare("INSERT INTO collab_doc_access (user_id, doc_id, role) VALUES (?, ?, 'editor')");
            $stmt->execute([$_SESSION['user']['id'], $docId]);
        }

        $blocks = templateBlocks($template, $title);
        foreach ($blocks as $index => $content) {
            $stmt = $pdo->prepare("INSERT INTO collab_blocks (doc_id, type, content, position) VALUES (?, 'text', ?, ?)");
            $stmt->execute([$docId, $content, $index]);
        }

        json_response(["status" => "ok", "id" => $docId]);
    }

    if ($action === 'docs_update') {
        require_role(['admin', 'editor']);
        $docId = (int)($input['id'] ?? 0);
        require_doc_access($pdo, $_SESSION['user'], $docId, true);
        $title = trim($input['title'] ?? '');
        $type = $input['type'] ?? 'note';
        $tags = trim($input['tags'] ?? '');
        $libraryId = (int)($input['library_id'] ?? $defaultLibraryId);
        if ($title === '') { json_response(['error' => 'Titre requis'], 422); }
        $stmt = $pdo->prepare("UPDATE collab_docs SET title = ?, type = ?, tags = ?, library_id = ? WHERE id = ?");
        $stmt->execute([$title, $type, $tags, $libraryId, $docId]);
        json_response(["status" => "updated"]);
    }

    if ($action === 'docs_delete') {
        require_role(['admin']);
        $docId = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM collab_blocks WHERE doc_id = ?");
        $stmt->execute([$docId]);
        $stmt = $pdo->prepare("DELETE FROM collab_doc_access WHERE doc_id = ?");
        $stmt->execute([$docId]);
        $stmt = $pdo->prepare("DELETE FROM collab_docs WHERE id = ?");
        $stmt->execute([$docId]);
        json_response(["status" => "deleted"]);
    }

    if ($action === 'fetch') {
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        require_doc_access($pdo, $_SESSION['user'], $docId, false);
        $stmt = $pdo->prepare("SELECT * FROM collab_blocks WHERE doc_id = ? ORDER BY position ASC");
        $stmt->execute([$docId]);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action === 'add') {
        require_role(['admin', 'editor']);
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        require_doc_access($pdo, $_SESSION['user'], $docId, true);
        $maxStmt = $pdo->prepare("SELECT MAX(position) FROM collab_blocks WHERE doc_id = ?");
        $maxStmt->execute([$docId]);
        $max = $maxStmt->fetchColumn();
        $pos = ($max !== false && $max !== null) ? $max + 1 : 0;

        $content = '';
        if ($input['type'] === 'table') {
            $content = json_encode([['', '', ''],['', '', ''],['', '', '']]);
        }
        if ($input['type'] === 'todo') $content = json_encode(['text' => 'Nouvelle tâche', 'checked' => false]);
        if ($input['type'] === 'image') $content = $input['content'] ?? '';
        if ($input['type'] === 'youtube') $content = $input['content'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO collab_blocks (doc_id, type, content, position) VALUES (?, ?, ?, ?)");
        $stmt->execute([$docId, $input['type'], $content, $pos]);
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        json_response(["status" => "ok", "id" => $pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        require_role(['admin', 'editor']);
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        require_doc_access($pdo, $_SESSION['user'], $docId, true);
        $stmt = $pdo->prepare("UPDATE collab_blocks SET content = ? WHERE id = ?");
        $stmt->execute([$input['content'], $input['id']]);
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        json_response(["status" => "saved"]);
    }

    if ($action === 'reorder') {
        require_role(['admin', 'editor']);
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        require_doc_access($pdo, $_SESSION['user'], $docId, true);
        foreach ($input['order'] as $index => $id) {
            $stmt = $pdo->prepare("UPDATE collab_blocks SET position = ? WHERE id = ? AND doc_id = ?");
            $stmt->execute([$index, $id, $docId]);
        }
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        json_response(["status" => "reordered"]);
    }

    if ($action === 'delete') {
        require_role(['admin', 'editor']);
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        require_doc_access($pdo, $_SESSION['user'], $docId, true);
        $stmt = $pdo->prepare("DELETE FROM collab_blocks WHERE id = ? AND doc_id = ?");
        $stmt->execute([$input['id'], $docId]);
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        json_response(["status" => "deleted"]);
    }

    if ($action === 'backlinks') {
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        require_doc_access($pdo, $_SESSION['user'], $docId, false);
        $stmt = $pdo->prepare("SELECT id, title, slug FROM collab_docs WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) json_response([]);
        $title = esc_like($doc['title']);
        $slug = esc_like($doc['slug']);

        if ($_SESSION['user']['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT DISTINCT d.id, d.title, d.slug
                FROM collab_docs d
                INNER JOIN collab_blocks b ON b.doc_id = d.id
                WHERE d.id <> ? AND (b.content LIKE ? OR b.content LIKE ?)
                ORDER BY d.updated_at DESC
            ");
            $stmt->execute([$docId, "%[[{$title}]]%", "%[[{$slug}]]%"]);
            json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $stmt = $pdo->prepare("SELECT DISTINCT d.id, d.title, d.slug
            FROM collab_docs d
            INNER JOIN collab_blocks b ON b.doc_id = d.id
            INNER JOIN collab_doc_access da ON da.doc_id = d.id AND da.user_id = ?
            WHERE d.id <> ? AND (b.content LIKE ? OR b.content LIKE ?)
            ORDER BY d.updated_at DESC
        ");
        $stmt->execute([$_SESSION['user']['id'], $docId, "%[[{$title}]]%", "%[[{$slug}]]%"]);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action === 'users_list') {
        require_role(['admin']);
        $stmt = $pdo->query("SELECT id, username, role, created_at, last_login FROM collab_users ORDER BY id ASC");
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action === 'users_create') {
        require_role(['admin']);
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'editor';
        if (strlen($username) < 3 || strlen($password) < 8) {
            json_response(['error' => 'Nom d’utilisateur (>=3) et mot de passe (>=8) requis'], 422);
        }
        if (!in_array($role, ['admin', 'editor', 'reader'], true)) {
            json_response(['error' => 'Rôle invalide'], 422);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO collab_users (username, password_hash, role) VALUES (?, ?, ?)");
            $stmt->execute([$username, $hash, $role]);
        } catch (PDOException $e) {
            json_response(['error' => 'Utilisateur déjà existant'], 409);
        }
        json_response(['status' => 'created']);
    }

    if ($action === 'users_update') {
        require_role(['admin']);
        $userId = (int)($input['id'] ?? 0);
        $role = $input['role'] ?? null;
        $password = $input['password'] ?? null;

        if ($role && !in_array($role, ['admin', 'editor', 'reader'], true)) {
            json_response(['error' => 'Rôle invalide'], 422);
        }

        if ($role) {
            $adminCount = $pdo->query("SELECT COUNT(*) FROM collab_users WHERE role = 'admin'")->fetchColumn();
            $isTargetAdmin = $pdo->prepare("SELECT role FROM collab_users WHERE id = ?");
            $isTargetAdmin->execute([$userId]);
            $currentRole = $isTargetAdmin->fetchColumn();
            if ($currentRole === 'admin' && $role !== 'admin' && (int)$adminCount <= 1) {
                json_response(['error' => 'Au moins un admin est requis'], 409);
            }
            $stmt = $pdo->prepare("UPDATE collab_users SET role = ? WHERE id = ?");
            $stmt->execute([$role, $userId]);
        }
        if ($password !== null && $password !== '') {
            if (strlen($password) < 8) json_response(['error' => 'Mot de passe trop court'], 422);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE collab_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);
        }
        json_response(['status' => 'updated']);
    }

    if ($action === 'users_delete') {
        require_role(['admin']);
        $userId = (int)($input['id'] ?? 0);
        $currentId = (int)($_SESSION['user']['id'] ?? 0);
        if ($userId === $currentId) {
            json_response(['error' => 'Impossible de supprimer votre compte'], 409);
        }
        $role = $pdo->prepare("SELECT role FROM collab_users WHERE id = ?");
        $role->execute([$userId]);
        $role = $role->fetchColumn();
        if ($role === 'admin') {
            $adminCount = $pdo->query("SELECT COUNT(*) FROM collab_users WHERE role = 'admin'")->fetchColumn();
            if ((int)$adminCount <= 1) {
                json_response(['error' => 'Au moins un admin est requis'], 409);
            }
        }
        $stmt = $pdo->prepare("DELETE FROM collab_users WHERE id = ?");
        $stmt->execute([$userId]);
        json_response(['status' => 'deleted']);
    }

    if ($action === 'libraries_list') {
        $stmt = $pdo->query("SELECT id, name FROM collab_libraries ORDER BY name ASC");
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action === 'libraries_create') {
        require_role(['admin']);
        $name = trim($input['name'] ?? '');
        if ($name === '') json_response(['error' => 'Nom requis'], 422);
        try {
            $stmt = $pdo->prepare("INSERT INTO collab_libraries (name) VALUES (?)");
            $stmt->execute([$name]);
        } catch (PDOException $e) {
            json_response(['error' => 'Bibliothèque déjà existante'], 409);
        }
        json_response(['status' => 'created']);
    }

    if ($action === 'libraries_delete') {
        require_role(['admin']);
        $libraryId = (int)($input['id'] ?? 0);
        if ($libraryId === (int)$defaultLibraryId) {
            json_response(['error' => 'Impossible de supprimer la bibliothèque par défaut'], 409);
        }
        $stmt = $pdo->prepare("UPDATE collab_docs SET library_id = ? WHERE library_id = ?");
        $stmt->execute([$defaultLibraryId, $libraryId]);
        $stmt = $pdo->prepare("DELETE FROM collab_libraries WHERE id = ?");
        $stmt->execute([$libraryId]);
        json_response(['status' => 'deleted']);
    }

    if ($action === 'doc_access_list') {
        require_role(['admin']);
        $docId = (int)($input['doc_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT u.id, u.username, u.role AS global_role, da.role AS doc_role
            FROM collab_users u
            LEFT JOIN collab_doc_access da ON da.user_id = u.id AND da.doc_id = ?
            ORDER BY u.username ASC");
        $stmt->execute([$docId]);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action === 'doc_access_update') {
        require_role(['admin']);
        $docId = (int)($input['doc_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);
        $role = $input['role'] ?? 'none';
        if (!in_array($role, ['editor', 'reader', 'none'], true)) {
            json_response(['error' => 'Rôle invalide'], 422);
        }
        if ($role === 'none') {
            $stmt = $pdo->prepare("DELETE FROM collab_doc_access WHERE user_id = ? AND doc_id = ?");
            $stmt->execute([$userId, $docId]);
            json_response(['status' => 'deleted']);
        }
        $stmt = $pdo->prepare("INSERT INTO collab_doc_access (user_id, doc_id, role) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role)");
        $stmt->execute([$userId, $docId, $role]);
        json_response(['status' => 'updated']);
    }

    if ($action === 'change_credentials') {
        require_auth();
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        $newUsername = trim($input['new_username'] ?? '');
        if (strlen($newPassword) < 8) json_response(['error' => 'Mot de passe trop court'], 422);
        if (strlen($newUsername) < 3) json_response(['error' => 'Nom d’utilisateur trop court'], 422);

        $stmt = $pdo->prepare("SELECT password_hash FROM collab_users WHERE id = ?");
        $stmt->execute([$_SESSION['user']['id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, $hash)) {
            json_response(['error' => 'Mot de passe actuel invalide'], 401);
        }
        try {
            $stmt = $pdo->prepare("UPDATE collab_users SET username = ?, password_hash = ?, must_change_password = 0 WHERE id = ?");
            $stmt->execute([$newUsername, password_hash($newPassword, PASSWORD_DEFAULT), $_SESSION['user']['id']]);
        } catch (PDOException $e) {
            json_response(['error' => 'Nom d’utilisateur déjà utilisé'], 409);
        }
        $_SESSION['user']['username'] = $newUsername;
        $_SESSION['user']['must_change'] = 0;
        json_response(['status' => 'updated']);
    }

    if ($action === 'change_password') {
        require_auth();
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        if (strlen($newPassword) < 8) json_response(['error' => 'Mot de passe trop court'], 422);

        $stmt = $pdo->prepare("SELECT password_hash FROM collab_users WHERE id = ?");
        $stmt->execute([$_SESSION['user']['id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, $hash)) {
            json_response(['error' => 'Mot de passe actuel invalide'], 401);
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE collab_users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $_SESSION['user']['id']]);
        $_SESSION['user']['must_change'] = 0;
        json_response(['status' => 'updated']);
    }

    json_response(['error' => 'Action inconnue'], 404);
}

$currentUser = $_SESSION['user'] ?? null;

if (!$currentUser) {
    $csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $errorMsg = $loginError ? '<div class="login-error">' . htmlspecialchars($loginError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '';
    $bootstrapMsg = $bootstrapWarning ? '<div class="login-warning">⚠️ Compte admin par défaut actif. Définissez ADMIN_USER et ADMIN_PASS.</div>' : '';
    echo "<!DOCTYPE html><html lang=\"fr\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>Connexion - CollabDocs</title><style>
        body{margin:0;font-family:'Segoe UI',Helvetica,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh}
        .login-card{background:#111827;padding:28px;border-radius:16px;box-shadow:0 20px 40px rgba(0,0,0,.4);width:360px;display:flex;flex-direction:column;gap:12px}
        .login-card h1{font-size:18px;margin:0;color:#fff}
        .login-card input{padding:10px 12px;border-radius:8px;border:1px solid #1f2937;background:#0b1220;color:#e2e8f0}
        .login-card button{background:#4f46e5;border:none;color:#fff;padding:10px 12px;border-radius:8px;font-weight:600;cursor:pointer}
        .login-error{background:#7f1d1d;color:#fecaca;padding:8px;border-radius:8px;font-size:12px}
        .login-warning{background:#78350f;color:#fde68a;padding:8px;border-radius:8px;font-size:12px}
        .login-foot{font-size:12px;color:#94a3b8}
    </style></head><body>
        <form class=\"login-card\" method=\"POST\" action=\"?action=login\">
            <h1>Connexion</h1>
            $bootstrapMsg
            $errorMsg
            <input name=\"username\" placeholder=\"Nom d'utilisateur\" required>
            <input type=\"password\" name=\"password\" placeholder=\"Mot de passe\" required>
            <input type=\"hidden\" name=\"csrf\" value=\"$csrf\">
            <button type=\"submit\">Se connecter</button>
            <div class=\"login-foot\">Accès protégé · CollabDocs</div>
        </form>
    </body></html>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollabDocs - Wiki & Notes</title>

    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

    <style>
        :root { --bg: #f3f4f6; --paper: #ffffff; --primary: #4f46e5; --text: #111827; --sidebar: #0f172a; }
        * { box-sizing: border-box; }
        body { background: var(--bg); margin: 0; font-family: 'Segoe UI', Helvetica, sans-serif; color: var(--text); }

        #app { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        #sidebar {
            width: 280px; background: var(--sidebar); color: #e2e8f0; padding: 20px; position: fixed; top: 0; bottom: 0; left: 0;
            display: flex; flex-direction: column; gap: 16px; box-shadow: 10px 0 30px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .logo { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .logo i { color: #a5b4fc; }
        .doc-actions { display: flex; flex-direction: column; gap: 10px; }
        .btn-primary { background: var(--primary); border: none; color: white; padding: 10px 12px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn-primary:hover { filter: brightness(1.05); }
        .search { background: #111827; border: 1px solid #1f2937; color: #e2e8f0; padding: 10px 12px; border-radius: 8px; }

        #doc-list { display: flex; flex-direction: column; gap: 8px; overflow-y: auto; padding-right: 6px; flex: 1; min-height: 120px; }
        .doc-item {
            background: rgba(255,255,255,0.04); padding: 10px 12px; border-radius: 10px; cursor: pointer; display: flex; justify-content: space-between; gap: 8px; align-items: center;
            border: 1px solid transparent; transition: 0.2s;
        }
        .doc-item:hover { border-color: rgba(255,255,255,0.15); }
        .doc-item.active { background: rgba(99,102,241,0.2); border-color: rgba(99,102,241,0.6); }
        .doc-meta { display: flex; flex-direction: column; gap: 4px; }
        .doc-title-sm { font-size: 14px; font-weight: 600; color: #f8fafc; }
        .doc-type { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #1f2937; color: #c7d2fe; display: inline-block; }
        .doc-delete { color: #fca5a5; font-size: 12px; opacity: 0.7; }
        .doc-delete:hover { opacity: 1; }

        /* MAIN */
        #main { margin-left: 280px; width: calc(100% - 280px); }

        header {
            position: sticky; top: 0; height: 70px; background: rgba(255,255,255,0.92); backdrop-filter: blur(8px);
            border-bottom: 1px solid #e5e7eb; z-index: 100; display: flex; align-items: center; justify-content: space-between;
            padding: 0 30px; gap: 20px;
        }
        .title-group { display: flex; align-items: center; gap: 12px; flex: 1; }
        .doc-title { font-size: 18px; font-weight: 700; border: none; background: transparent; outline: none; width: 420px; color: #111827; }
        .doc-title:focus { border-bottom: 2px solid var(--primary); }
        .doc-type-select, .doc-tags {
            border: 1px solid #e5e7eb; padding: 6px 8px; border-radius: 8px; background: #fff; font-size: 12px;
        }
        .status { font-size: 12px; color: #6b7280; }
        .doc-slug { font-size: 11px; color: #94a3b8; background: #f8fafc; padding: 4px 8px; border-radius: 999px; }

        #viewport {
            width: min(980px, 100%); min-height: 100vh; margin: 30px auto 80px;
            background: var(--paper); box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            padding: 60px; border-radius: 12px;
        }
        @media (max-width: 1100px) { #viewport { padding: 30px; } .doc-title { width: 240px; } }
        @media (max-width: 900px) { #sidebar { position: static; width: 100%; height: auto; } #main { margin-left: 0; width: 100%; } header { position: static; } }

        /* BLOCS */
        .block-wrapper { position: relative; margin-bottom: 15px; padding-left: 35px; border-radius: 6px; border: 1px solid transparent; transition: 0.2s; }
        .block-wrapper:hover { border-color: #e5e7eb; }

        .block-controls {
            position: absolute; left: 5px; top: 5px; display: flex; flex-direction: column; gap: 6px;
            opacity: 0; transition: 0.2s;
        }
        .block-wrapper:hover .block-controls { opacity: 1; }
        .icon-btn { cursor: pointer; color: #9ca3af; font-size: 14px; padding: 4px; border-radius: 4px; }
        .icon-btn:hover { color: var(--primary); background: #eff6ff; }
        .icon-trash:hover { color: #ef4444; background: #fef2f2; }
        .drag-handle { cursor: grab; }

        .ql-toolbar.ql-snow { border: none; background: #f8fafc; border-radius: 8px; margin-bottom: 5px; display: none; }
        .ql-container.ql-snow { border: none; font-size: 16px; font-family: inherit; }
        .block-wrapper:focus-within .ql-toolbar.ql-snow { display: block; animation: fadeIn 0.3s; }

        .table-container { overflow-x: auto; padding: 5px 0; }
        .native-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .native-table td {
            border: 1px solid #d1d5db; min-width: 60px; padding: 8px;
            outline: none; transition: 0.2s; vertical-align: top;
        }
        .native-table td:focus { border: 2px solid var(--primary); z-index: 10; position: relative; }

        .table-toolbar {
            background: #1f2937; color: white; padding: 8px 12px; border-radius: 30px;
            position: absolute; top: -50px; left: 0; z-index: 50; display: none;
            gap: 10px; align-items: center; box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .block-wrapper:focus-within .table-toolbar { display: flex; animation: fadeIn 0.3s; }

        .tbl-btn { background: rgba(255,255,255,0.1); border: none; color: white; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .tbl-btn:hover { background: rgba(255,255,255,0.2); }
        .color-picker-wrapper { position: relative; display: flex; align-items: center; gap: 5px; font-size: 12px; }
        input[type="color"] { width: 25px; height: 25px; border: none; background: none; cursor: pointer; padding: 0; }

        .img-block img { max-width: 100%; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .youtube-block iframe { border-radius: 8px; }
        .todo-block { display: flex; align-items: center; gap: 12px; padding: 8px 0; font-size: 16px; }
        .todo-check { width: 20px; height: 20px; cursor: pointer; accent-color: var(--primary); }
        .todo-input { border: none; background: transparent; flex: 1; font-size: 16px; outline: none; font-family: inherit; }
        .todo-done { text-decoration: line-through; color: #9ca3af; }

        /* MENU FAB */
        .fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 200; display: flex; flex-direction: column; align-items: flex-end; gap: 12px; }
        .fab-main {
            width: 60px; height: 60px; border-radius: 50%; background: var(--primary);
            color: white; border: none; font-size: 24px; cursor: pointer;
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex; align-items: center; justify-content: center;
        }
        .fab-main.open { transform: rotate(45deg); background: #ef4444; }

        .fab-menu { display: none; flex-direction: column; gap: 8px; align-items: flex-end; }
        .fab-menu.show { display: flex; animation: slideUp 0.3s; }
        .fab-item {
            background: white; padding: 10px 18px; border-radius: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1); cursor: pointer; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; gap: 10px; transition: 0.2s;
        }
        .fab-item:hover { transform: translateX(-5px); color: var(--primary); }

        #status { position: fixed; bottom: 20px; left: 300px; font-size: 12px; color: #9ca3af; font-weight: 500; }

        /* MODAL */
        .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 300; }
        .modal.show { display: flex; }
        .modal-card { background: #fff; padding: 24px; border-radius: 14px; width: 420px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 12px; }
        .modal-card.large { width: 560px; max-height: 80vh; overflow-y: auto; }
        .modal-card input, .modal-card select { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-ghost { background: #f3f4f6; border: none; padding: 10px 12px; border-radius: 8px; cursor: pointer; }
        .btn-secondary { background: #111827; color: #e2e8f0; border: 1px solid #1f2937; padding: 8px 10px; border-radius: 8px; cursor: pointer; font-size: 12px; }
        .btn-secondary:hover { filter: brightness(1.05); }

        .sidebar-section { display: flex; flex-direction: column; gap: 8px; }
        .sidebar-title { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; }
        .link-list { display: flex; flex-direction: column; gap: 6px; max-height: 140px; overflow-y: auto; padding-right: 6px; }
        .link-item { background: rgba(255,255,255,0.05); border: 1px solid transparent; padding: 6px 10px; border-radius: 8px; cursor: pointer; font-size: 12px; color: #e2e8f0; }
        .link-item:hover { border-color: rgba(255,255,255,0.2); }
        .link-empty { font-size: 12px; color: #64748b; }
        .user-panel { display: flex; flex-direction: column; gap: 8px; border-top: 1px solid rgba(148,163,184,0.2); padding-top: 12px; }
        .user-info { font-size: 12px; color: #cbd5f5; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .role-pill { font-size: 10px; background: rgba(99,102,241,0.2); color: #c7d2fe; padding: 3px 8px; border-radius: 999px; }
        .logout-link { color: #fca5a5; font-size: 12px; text-decoration: none; }
        .logout-link:hover { text-decoration: underline; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
<div id="app">
    <aside id="sidebar">
        <div class="logo"><i class="fa-solid fa-book"></i> CollabDocs</div>
        <div class="doc-actions">
            <button class="btn-primary" id="btn-new-doc">Nouveau document</button>
            <input id="doc-search" class="search" placeholder="Rechercher...">
        </div>
        <div id="doc-list"></div>

        <div class="sidebar-section">
            <div class="sidebar-title">Bibliothèques</div>
            <div id="library-list" class="link-list"></div>
            <?php if ($currentUser['role'] === 'admin') { ?>
                <button class="btn-secondary" id="btn-libraries">Gérer les bibliothèques</button>
            <?php } ?>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-title">Liens wiki</div>
            <div id="out-links" class="link-list"></div>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-title">Backlinks</div>
            <div id="back-links" class="link-list"></div>
        </div>

        <div class="user-panel">
            <div class="user-info">
                <div>
                    <strong><?php echo htmlspecialchars($currentUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                    <div class="role-pill"><?php echo htmlspecialchars($currentUser['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                </div>
                <a class="logout-link" href="?action=logout">Déconnexion</a>
            </div>
            <div style="display:flex; gap:8px; flex-wrap: wrap;">
                <button class="btn-secondary" id="btn-account">Mon compte</button>
                <?php if ($currentUser['role'] === 'admin') { ?>
                    <button class="btn-secondary" id="btn-users">Utilisateurs</button>
                <?php } ?>
            </div>
        </div>
    </aside>

    <div id="main">
        <header>
            <div class="title-group">
                <input type="text" id="doc-title" class="doc-title" value="">
                <select id="doc-type" class="doc-type-select">
                    <option value="wiki">Wiki</option>
                    <option value="note">Note</option>
                    <option value="course">Cours</option>
                    <option value="spec">Cahier des charges</option>
                </select>
                <select id="doc-library" class="doc-type-select"></select>
                <input type="text" id="doc-tags" class="doc-tags" placeholder="Tags (ex: produit, sprint)">
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <?php if ($currentUser['role'] === 'admin') { ?>
                    <button class="btn-secondary" id="btn-access">Accès</button>
                <?php } ?>
                <div class="doc-slug" id="doc-slug">slug</div>
                <div class="status" id="doc-status">Prêt</div>
            </div>
        </header>

        <div id="viewport"></div>
    </div>
</div>

<div class="fab-container">
    <div class="fab-menu" id="menu">
        <div class="fab-item" onclick="addItem('text')">Texte <i class="fa-solid fa-paragraph"></i></div>
        <div class="fab-item" onclick="addItem('table')">Tableau <i class="fa-solid fa-table"></i></div>
        <div class="fab-item" onclick="addItem('todo')">To-Do <i class="fa-solid fa-check-square"></i></div>
        <div class="fab-item" onclick="document.getElementById('img-up').click()">Image <i class="fa-regular fa-image"></i></div>
        <div class="fab-item" onclick="addItem('youtube')">YouTube <i class="fa-brands fa-youtube"></i></div>
    </div>
    <button class="fab-main" onclick="toggleMenu()" id="btn-plus"><i class="fa-solid fa-plus"></i></button>
</div>

<input type="file" id="img-up" hidden accept="image/*">

<div class="modal" id="doc-modal">
    <div class="modal-card">
        <h3>Nouveau document</h3>
        <input type="text" id="new-title" placeholder="Titre du document">
        <select id="new-type">
            <option value="wiki">Wiki</option>
            <option value="note">Note</option>
            <option value="course">Cours</option>
            <option value="spec">Cahier des charges</option>
        </select>
        <input type="text" id="new-tags" placeholder="Tags (optionnel)">
        <select id="new-library"></select>
        <select id="new-template">
            <option value="note">Modèle : Note</option>
            <option value="wiki">Modèle : Wiki</option>
            <option value="course">Modèle : Cours</option>
            <option value="spec">Modèle : Cahier des charges</option>
        </select>
        <div class="modal-actions">
            <button class="btn-ghost" id="btn-cancel">Annuler</button>
            <button class="btn-primary" id="btn-create">Créer</button>
        </div>
    </div>
</div>

<div class="modal" id="account-modal">
    <div class="modal-card">
        <h3>Mon compte</h3>
        <input type="password" id="current-password" placeholder="Mot de passe actuel">
        <input type="password" id="new-password" placeholder="Nouveau mot de passe (min 8)">
        <div class="modal-actions">
            <button class="btn-ghost" id="btn-account-cancel">Annuler</button>
            <button class="btn-primary" id="btn-account-save">Mettre à jour</button>
        </div>
    </div>
</div>

<?php if ($currentUser['role'] === 'admin') { ?>
<div class="modal" id="access-modal">
    <div class="modal-card large">
        <h3>Accès au document</h3>
        <div id="access-list" style="display:flex; flex-direction:column; gap:8px;"></div>
        <div class="modal-actions">
            <button class="btn-ghost" id="btn-access-close">Fermer</button>
        </div>
    </div>
</div>

<div class="modal" id="libraries-modal">
    <div class="modal-card large">
        <h3>Bibliothèques</h3>
        <div id="libraries-list" style="display:flex; flex-direction:column; gap:8px;"></div>
        <div style="border-top:1px solid #e5e7eb; padding-top:12px; display:flex; flex-direction:column; gap:10px;">
            <strong>Ajouter une bibliothèque</strong>
            <input type="text" id="new-library-name" placeholder="Nom">
            <button class="btn-primary" id="btn-library-create">Créer</button>
        </div>
        <div class="modal-actions">
            <button class="btn-ghost" id="btn-libraries-close">Fermer</button>
        </div>
    </div>
</div>
<?php } ?>

<div class="modal" id="force-credentials-modal">
    <div class="modal-card">
        <h3>Changement obligatoire</h3>
        <div style="font-size:12px;color:#6b7280;">Veuillez modifier votre identifiant et votre mot de passe.</div>
        <input type="text" id="force-username" placeholder="Nouveau nom d'utilisateur">
        <input type="password" id="force-current" placeholder="Mot de passe actuel">
        <input type="password" id="force-password" placeholder="Nouveau mot de passe (min 8)">
        <div class="modal-actions">
            <button class="btn-primary" id="btn-force-save">Mettre à jour</button>
        </div>
    </div>
</div>

<?php if ($currentUser['role'] === 'admin') { ?>
<div class="modal" id="users-modal">
    <div class="modal-card large">
        <h3>Gestion des utilisateurs</h3>
        <div id="users-list" style="display:flex; flex-direction:column; gap:8px;"></div>
        <div style="border-top:1px solid #e5e7eb; padding-top:12px; display:flex; flex-direction:column; gap:10px;">
            <strong>Ajouter un utilisateur</strong>
            <input type="text" id="new-user" placeholder="Nom d'utilisateur">
            <input type="password" id="new-user-pass" placeholder="Mot de passe (min 8)">
            <select id="new-user-role">
                <option value="editor">Éditeur</option>
                <option value="reader">Lecteur</option>
                <option value="admin">Admin</option>
            </select>
            <button class="btn-primary" id="btn-user-create">Créer</button>
        </div>
        <div class="modal-actions">
            <button class="btn-ghost" id="btn-users-close">Fermer</button>
        </div>
    </div>
</div>
<?php } ?>

<div id="status">Synchronisé</div>

<script>
    const csrfToken = "<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
    const userRole = "<?php echo htmlspecialchars($currentUser['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
    const userName = "<?php echo htmlspecialchars($currentUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
    const mustChange = <?php echo !empty($currentUser['must_change']) ? 'true' : 'false'; ?>;
    const isAdmin = userRole === 'admin';
    const canCreate = isAdmin || userRole === 'editor';
    let canEdit = isAdmin;

    const container = document.getElementById('viewport');
    const docList = document.getElementById('doc-list');
    const docTitle = document.getElementById('doc-title');
    const docType = document.getElementById('doc-type');
    const docLibrary = document.getElementById('doc-library');
    const docTags = document.getElementById('doc-tags');
    const docSlug = document.getElementById('doc-slug');
    const docStatus = document.getElementById('doc-status');
    const docSearch = document.getElementById('doc-search');
    const outLinks = document.getElementById('out-links');
    const backLinks = document.getElementById('back-links');
    const libraryList = document.getElementById('library-list');
    const newLibrarySelect = document.getElementById('new-library');

    let activeBlockId = null;
    let saveTimer = {};
    let docSaveTimer = null;
    let lastFocusedCell = null;
    let currentDocId = null;
    let currentDocRole = null;
    let currentLibraryId = null;
    let docs = [];
    let libraries = [];

    function setStatus(text) {
        document.getElementById('status').innerText = text;
        docStatus.innerText = text;
    }

    async function api(action, data = {}) {
        const res = await fetch(`?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(data)
        });
        if (res.status === 401) { location.reload(); }
        return res;
    }

    function toggleMenu() {
        document.getElementById('menu').classList.toggle('show');
        document.getElementById('btn-plus').classList.toggle('open');
    }
    function addItem(type) { addBlock(type); toggleMenu(); }

    function applyRoleUI() {
        if (!canEdit) {
            document.getElementById('btn-plus').style.display = 'none';
            document.getElementById('menu').style.display = 'none';
            if (!canCreate) document.getElementById('btn-new-doc').style.display = 'none';
            docTitle.setAttribute('readonly', 'readonly');
            docTags.setAttribute('readonly', 'readonly');
            docType.setAttribute('disabled', 'disabled');
            docLibrary.setAttribute('disabled', 'disabled');
        } else {
            document.getElementById('btn-plus').style.display = '';
            if (canCreate) document.getElementById('btn-new-doc').style.display = '';
            docTitle.removeAttribute('readonly');
            docTags.removeAttribute('readonly');
            docType.removeAttribute('disabled');
            docLibrary.removeAttribute('disabled');
        }
    }

    async function loadDocs() {
        const res = await api('docs_list');
        docs = await res.json();
        renderDocList();

        const saved = localStorage.getItem('docId');
        currentDocId = docs.find(d => String(d.id) === String(saved))?.id || docs[0]?.id;
        if (currentDocId) selectDoc(currentDocId, true);
    }

    async function loadLibraries() {
        const res = await api('libraries_list');
        libraries = await res.json();
        renderLibraries();
        fillLibrarySelects();
        if (!currentLibraryId && libraries.length) currentLibraryId = libraries[0].id;
    }

    function renderLibraries() {
        libraryList.innerHTML = '';
        const allItem = document.createElement('div');
        allItem.className = 'link-item';
        allItem.textContent = 'Toutes';
        allItem.onclick = () => { currentLibraryId = null; renderDocList(); };
        libraryList.appendChild(allItem);

        libraries.forEach(lib => {
            const el = document.createElement('div');
            el.className = 'link-item';
            el.textContent = lib.name;
            el.onclick = () => { currentLibraryId = lib.id; renderDocList(); };
            libraryList.appendChild(el);
        });
    }

    function fillLibrarySelects() {
        const selects = [docLibrary, newLibrarySelect];
        selects.forEach(sel => { sel.innerHTML = ''; });
        libraries.forEach(lib => {
            selects.forEach(sel => {
                const opt = document.createElement('option');
                opt.value = lib.id;
                opt.textContent = lib.name;
                sel.appendChild(opt);
            });
        });
    }

    function renderDocList() {
        const filter = docSearch.value.toLowerCase();
        docList.innerHTML = '';
        docs
            .filter(d => {
                const matchesText = d.title.toLowerCase().includes(filter) || (d.tags || '').toLowerCase().includes(filter) || (d.slug || '').toLowerCase().includes(filter);
                const matchesLibrary = currentLibraryId ? String(d.library_id) === String(currentLibraryId) : true;
                return matchesText && matchesLibrary;
            })
            .forEach(doc => {
                const item = document.createElement('div');
                item.className = 'doc-item' + (String(doc.id) === String(currentDocId) ? ' active' : '');
                const deleteIcon = isAdmin ? '<i class="fa-solid fa-trash doc-delete" title="Supprimer"></i>' : '';
                item.innerHTML = `
                    <div class="doc-meta">
                        <div class="doc-title-sm">${doc.title}</div>
                        <div class="doc-type">${doc.type}</div>
                    </div>
                    ${deleteIcon}
                `;
                item.onclick = (e) => {
                    if (e.target.classList.contains('doc-delete')) return;
                    selectDoc(doc.id);
                };
                if (isAdmin) {
                    item.querySelector('.doc-delete').onclick = async () => {
                        if (!confirm('Supprimer ce document et tous ses blocs ?')) return;
                        await api('docs_delete', { id: doc.id });
                        await loadDocs();
                    };
                }
                docList.appendChild(item);
            });
    }

    function selectDoc(id, skipRefresh = false) {
        currentDocId = id;
        localStorage.setItem('docId', id);
        const doc = docs.find(d => String(d.id) === String(id));
        if (!doc) return;
        docTitle.value = doc.title;
        docType.value = doc.type;
        docTags.value = doc.tags || '';
        docLibrary.value = doc.library_id || '';
        docSlug.innerText = doc.slug ? `slug: ${doc.slug}` : 'slug: -';
        currentDocRole = doc.access_role || (isAdmin ? 'editor' : 'reader');
        canEdit = isAdmin || currentDocRole === 'editor';
        applyRoleUI();
        if (sortableInstance) sortableInstance.option('disabled', !canEdit);
        renderDocList();
        if (!skipRefresh) container.innerHTML = '';
        refresh();
        loadBacklinks();
    }

    function openModal() { document.getElementById('doc-modal').classList.add('show'); }
    function closeModal() { document.getElementById('doc-modal').classList.remove('show'); }

    document.getElementById('btn-new-doc').onclick = () => {
        document.getElementById('new-title').value = '';
        document.getElementById('new-type').value = 'wiki';
        document.getElementById('new-tags').value = '';
        if (currentLibraryId) document.getElementById('new-library').value = currentLibraryId;
        document.getElementById('new-template').value = 'wiki';
        openModal();
    };
    document.getElementById('btn-cancel').onclick = closeModal;
    document.getElementById('btn-create').onclick = async () => {
        const title = document.getElementById('new-title').value || 'Nouveau document';
        const type = document.getElementById('new-type').value;
        const tags = document.getElementById('new-tags').value;
        const template = document.getElementById('new-template').value;
        const library_id = document.getElementById('new-library').value;
        const res = await api('docs_create', { title, type, tags, template, library_id });
        const data = await res.json();
        closeModal();
        await loadDocs();
        selectDoc(data.id);
    };

    docSearch.oninput = () => renderDocList();

    // Account modal
    const accountModal = document.getElementById('account-modal');
    document.getElementById('btn-account').onclick = () => accountModal.classList.add('show');
    document.getElementById('btn-account-cancel').onclick = () => accountModal.classList.remove('show');
    document.getElementById('btn-account-save').onclick = async () => {
        const currentPassword = document.getElementById('current-password').value;
        const newPassword = document.getElementById('new-password').value;
        const res = await api('change_password', { current_password: currentPassword, new_password: newPassword });
        const data = await res.json();
        if (data.error) { alert(data.error); return; }
        alert('Mot de passe mis à jour.');
        accountModal.classList.remove('show');
        document.getElementById('current-password').value = '';
        document.getElementById('new-password').value = '';
    };

    // Forced credentials change
    document.getElementById('btn-force-save').onclick = async () => {
        const new_username = document.getElementById('force-username').value.trim();
        const current_password = document.getElementById('force-current').value;
        const new_password = document.getElementById('force-password').value;
        const res = await api('change_credentials', { new_username, current_password, new_password });
        const data = await res.json();
        if (data.error) { alert(data.error); return; }
        alert('Identifiants mis à jour.');
        document.getElementById('force-credentials-modal').classList.remove('show');
        location.reload();
    };

    // Users modal (admin)
    const usersModal = document.getElementById('users-modal');
    const usersList = document.getElementById('users-list');
    if (isAdmin) {
        document.getElementById('btn-users').onclick = async () => {
            usersModal.classList.add('show');
            await loadUsers();
        };
        document.getElementById('btn-users-close').onclick = () => usersModal.classList.remove('show');
        document.getElementById('btn-user-create').onclick = async () => {
            const username = document.getElementById('new-user').value.trim();
            const password = document.getElementById('new-user-pass').value;
            const role = document.getElementById('new-user-role').value;
            const res = await api('users_create', { username, password, role });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            document.getElementById('new-user').value = '';
            document.getElementById('new-user-pass').value = '';
            await loadUsers();
        };
    }

    // Access modal (admin)
    const accessModal = document.getElementById('access-modal');
    const accessList = document.getElementById('access-list');
    if (isAdmin) {
        document.getElementById('btn-access').onclick = async () => {
            accessModal.classList.add('show');
            await loadDocAccess();
        };
        document.getElementById('btn-access-close').onclick = () => accessModal.classList.remove('show');
    }

    async function loadDocAccess() {
        if (!isAdmin || !currentDocId) return;
        const res = await api('doc_access_list', { doc_id: currentDocId });
        const data = await res.json();
        accessList.innerHTML = '';
        data.forEach(user => {
            const row = document.createElement('div');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '1fr 120px 90px';
            row.style.gap = '8px';
            row.style.alignItems = 'center';
            row.innerHTML = `
                <div><strong>${user.username}</strong><div style="font-size:11px;color:#6b7280;">${user.global_role}</div></div>
                <select class="doc-access">
                    <option value="none" ${!user.doc_role ? 'selected' : ''}>Aucun</option>
                    <option value="reader" ${user.doc_role === 'reader' ? 'selected' : ''}>Lecteur</option>
                    <option value="editor" ${user.doc_role === 'editor' ? 'selected' : ''}>Éditeur</option>
                </select>
                <button class="btn-secondary btn-save">Sauver</button>
            `;
            row.querySelector('.btn-save').onclick = async () => {
                const role = row.querySelector('.doc-access').value;
                const res = await api('doc_access_update', { doc_id: currentDocId, user_id: user.id, role });
                const resp = await res.json();
                if (resp.error) { alert(resp.error); return; }
                await loadDocAccess();
                await loadDocs();
            };
            accessList.appendChild(row);
        });
    }

    // Libraries modal (admin)
    const librariesModal = document.getElementById('libraries-modal');
    const librariesList = document.getElementById('libraries-list');
    if (isAdmin) {
        document.getElementById('btn-libraries').onclick = async () => {
            librariesModal.classList.add('show');
            await loadLibrariesAdmin();
        };
        document.getElementById('btn-libraries-close').onclick = () => librariesModal.classList.remove('show');
        document.getElementById('btn-library-create').onclick = async () => {
            const name = document.getElementById('new-library-name').value.trim();
            const res = await api('libraries_create', { name });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            document.getElementById('new-library-name').value = '';
            await loadLibraries();
            await loadLibrariesAdmin();
        };
    }

    async function loadLibrariesAdmin() {
        if (!isAdmin) return;
        const res = await api('libraries_list');
        const libs = await res.json();
        librariesList.innerHTML = '';
        libs.forEach(lib => {
            const row = document.createElement('div');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '1fr 90px';
            row.style.gap = '8px';
            row.style.alignItems = 'center';
            row.innerHTML = `
                <div><strong>${lib.name}</strong></div>
                <button class="btn-secondary btn-delete">Suppr</button>
            `;
            row.querySelector('.btn-delete').onclick = async () => {
                if (!confirm('Supprimer cette bibliothèque ?')) return;
                const res = await api('libraries_delete', { id: lib.id });
                const data = await res.json();
                if (data.error) { alert(data.error); return; }
                await loadLibraries();
                await loadLibrariesAdmin();
            };
            librariesList.appendChild(row);
        });
    }

    async function loadUsers() {
        if (!isAdmin) return;
        const res = await api('users_list');
        const users = await res.json();
        usersList.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('div');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '1fr 120px 1fr 90px 80px';
            row.style.gap = '8px';
            row.style.alignItems = 'center';
            row.innerHTML = `
                <div><strong>${user.username}</strong><div style="font-size:11px;color:#6b7280;">${user.role}</div></div>
                <select class="user-role">
                    <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Admin</option>
                    <option value="editor" ${user.role === 'editor' ? 'selected' : ''}>Éditeur</option>
                    <option value="reader" ${user.role === 'reader' ? 'selected' : ''}>Lecteur</option>
                </select>
                <input class="user-pass" type="password" placeholder="Nouveau mdp">
                <button class="btn-secondary btn-update">Maj</button>
                <button class="btn-secondary btn-delete">Suppr</button>
            `;
            row.querySelector('.btn-update').onclick = async () => {
                const role = row.querySelector('.user-role').value;
                const password = row.querySelector('.user-pass').value;
                const res = await api('users_update', { id: user.id, role, password });
                const data = await res.json();
                if (data.error) { alert(data.error); return; }
                await loadUsers();
            };
            row.querySelector('.btn-delete').onclick = async () => {
                if (!confirm('Supprimer cet utilisateur ?')) return;
                const res = await api('users_delete', { id: user.id });
                const data = await res.json();
                if (data.error) { alert(data.error); return; }
                await loadUsers();
            };
            usersList.appendChild(row);
        });
    }

    function scheduleDocMetaSave() {
        clearTimeout(docSaveTimer);
        if (!canEdit) return;
        docSaveTimer = setTimeout(async () => {
            await api('docs_update', { id: currentDocId, title: docTitle.value, type: docType.value, tags: docTags.value, library_id: docLibrary.value });
            await loadDocs();
        }, 600);
    }
    docTitle.oninput = scheduleDocMetaSave;
    docType.onchange = scheduleDocMetaSave;
    docTags.oninput = scheduleDocMetaSave;
    docLibrary.onchange = scheduleDocMetaSave;

    let sortableInstance = null;
    document.addEventListener('DOMContentLoaded', () => {
        applyRoleUI();
        loadLibraries();
        loadDocs();
        setInterval(refresh, 2500);
        sortableInstance = new Sortable(container, {
            animation: 150, handle: '.drag-handle',
            onEnd: () => {
                if (!canEdit) return;
                const order = Array.from(container.children).map(el => el.dataset.id);
                api('reorder', { order, doc_id: currentDocId });
            }
        });
        sortableInstance.option('disabled', !canEdit);

        document.getElementById('img-up').addEventListener('change', function() {
            if (this.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { addBlock('image', e.target.result); toggleMenu(); };
                reader.readAsDataURL(this.files[0]);
            }
        });

        if (mustChange) {
            document.getElementById('force-credentials-modal').classList.add('show');
        }
    });

    async function refresh() {
        if (!currentDocId || activeBlockId) return;
        try {
            const res = await api('fetch', { doc_id: currentDocId });
            const blocks = await res.json();
            if (blocks.length === 0 && container.children.length === 0) { if (canEdit) addBlock('text'); return; }
            blocks.forEach(block => {
                let el = document.getElementById(`blk-${block.id}`);
                if (!el) renderBlock(block);
                else if (parseInt(activeBlockId) !== parseInt(block.id)) updateDOM(el, block);
            });
            const ids = blocks.map(b => parseInt(b.id));
            Array.from(container.children).forEach(child => { if (!ids.includes(parseInt(child.dataset.id))) child.remove(); });
            updateLinkPanels(blocks);
        } catch (e) {}
    }

    function extractWikiLinksFromBlocks(blocks) {
        const found = new Set();
        blocks.forEach(block => {
            if (block.type !== 'text' || !block.content) return;
            const div = document.createElement('div');
            div.innerHTML = block.content;
            const text = div.textContent || '';
            const regex = /\[\[([^\]]+)\]\]/g;
            let match;
            while ((match = regex.exec(text)) !== null) {
                const link = match[1].trim();
                if (link) found.add(link);
            }
        });
        return Array.from(found);
    }

    function resolveDocByLink(linkText) {
        const norm = linkText.toLowerCase();
        return docs.find(d => (d.title || '').toLowerCase() === norm || (d.slug || '').toLowerCase() === norm);
    }

    function renderLinkList(containerEl, links, emptyText, clickHandler) {
        containerEl.innerHTML = '';
        if (!links.length) {
            containerEl.innerHTML = `<div class="link-empty">${emptyText}</div>`;
            return;
        }
        links.forEach(link => {
            const el = document.createElement('div');
            el.className = 'link-item';
            el.textContent = link.label;
            el.onclick = () => clickHandler(link);
            containerEl.appendChild(el);
        });
    }

    function updateLinkPanels(blocks) {
        const rawLinks = extractWikiLinksFromBlocks(blocks);
        const mapped = rawLinks.map(l => {
            const doc = resolveDocByLink(l);
            return { label: doc ? doc.title : l, doc, raw: l };
        });
        renderLinkList(outLinks, mapped, 'Aucun lien', (link) => openWikiLink(link));
    }

    async function loadBacklinks() {
        if (!currentDocId) return;
        try {
            const res = await api('backlinks', { doc_id: currentDocId });
            const data = await res.json();
            const mapped = data.map(d => ({ label: d.title, doc: d }));
            renderLinkList(backLinks, mapped, 'Aucun backlink', (link) => selectDoc(link.doc.id));
        } catch (e) {}
    }

    async function openWikiLink(link) {
        if (link.doc) {
            selectDoc(link.doc.id);
            return;
        }
        if (!canEdit) {
            alert('Lien introuvable. Demandez à un éditeur de créer la page.');
            return;
        }
        if (!confirm(`Créer la page "${link.raw}" ?`)) return;
        const res = await api('docs_create', { title: link.raw, type: 'wiki', tags: '', template: 'wiki' });
        const data = await res.json();
        await loadDocs();
        selectDoc(data.id);
    }

    async function addBlock(type, content = null) {
        if (!currentDocId || !canEdit) return;
        await api('add', { type, content, doc_id: currentDocId });
        refresh();
        setTimeout(() => window.scrollTo(0, document.body.scrollHeight), 100);
    }

    async function save(id, content) {
        if (!canEdit) return;
        setStatus('Sauvegarde...');
        clearTimeout(saveTimer[id]);
        saveTimer[id] = setTimeout(async () => {
            await api('update', { id, content, doc_id: currentDocId });
            setStatus('Synchronisé');
        }, 800);
    }

    function renderBlock(block) {
        const div = document.createElement('div');
        div.className = 'block-wrapper';
        div.id = `blk-${block.id}`;
        div.dataset.id = block.id;
        div.dataset.type = block.type;
        div.innerHTML = `
            <div class="block-controls">
                <i class="fa-solid fa-grip-vertical icon-btn drag-handle"></i>
                <i class="fa-solid fa-trash icon-btn icon-trash" onclick="deleteBlock(${block.id})"></i>
            </div>
            <div class="block-content"></div>
        `;
        const contentDiv = div.querySelector('.block-content');
        if (!canEdit) {
            div.querySelector('.block-controls').style.display = 'none';
        }
        container.appendChild(div);

        if (block.type === 'text') {
            const toolbarOptions = [
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'header': [1, 2, 3, false] }],
                [{ 'align': [] }],
                ['clean']
            ];
            const quill = new Quill(contentDiv, { theme: 'snow', placeholder: 'Texte...', modules: { toolbar: toolbarOptions } });
            div.quill = quill;
            quill.root.innerHTML = block.content || '';
            quill.on('selection-change', r => activeBlockId = r ? block.id : null);
            quill.on('text-change', (d,o,s) => { if (s === 'user') save(block.id, quill.root.innerHTML); });
            if (!canEdit) quill.enable(false);
        }
        else if (block.type === 'table') {
            const toolbar = document.createElement('div');
            toolbar.className = 'table-toolbar';
            toolbar.innerHTML = `
                <button class="tbl-btn" onclick="tblCmd(${block.id}, 'bold')"><i class="fa-solid fa-bold"></i></button>
                <div class="color-picker-wrapper"><i class="fa-solid fa-palette"></i> <input type="color" onchange="tblColor(${block.id}, this.value, 'fore')"></div>
                <div class="color-picker-wrapper"><i class="fa-solid fa-fill-drip"></i> <input type="color" onchange="tblColor(${block.id}, this.value, 'back')"></div>
                <div style="width:1px; height:20px; background:#555; margin:0 5px;"></div>
                <button class="tbl-btn" onclick="tblAdd(${block.id}, 'row')">+ Ligne</button>
                <button class="tbl-btn" onclick="tblAdd(${block.id}, 'col')">+ Col</button>
            `;
            div.appendChild(toolbar);
            if (!canEdit) toolbar.style.display = 'none';
            contentDiv.innerHTML = `<div class="table-container"><table class="native-table"><tbody></tbody></table></div>`;

            div.tableData = JSON.parse(block.content);
            renderTable(div, block.id);
        }
        else if (block.type === 'todo') {
            const d = JSON.parse(block.content || '{"text":"","checked":false}');
            contentDiv.innerHTML = `<div class="todo-block"><input type="checkbox" class="todo-check" ${d.checked?'checked':''}><input class="todo-input ${d.checked?'todo-done':''}" value="${d.text}"></div>`;
            const chk = contentDiv.querySelector('.todo-check');
            const inp = contentDiv.querySelector('.todo-input');
            const doSave = () => { save(block.id, JSON.stringify({text: inp.value, checked: chk.checked})); inp.classList.toggle('todo-done', chk.checked); };
            chk.onchange = doSave; inp.oninput = doSave; inp.onfocus = () => activeBlockId = block.id; inp.onblur = () => activeBlockId = null;
            if (!canEdit) { chk.disabled = true; inp.readOnly = true; }
        }
        else if (block.type === 'image') { contentDiv.className = 'img-block'; contentDiv.innerHTML = `<img src="${block.content}">`; }
        else if (block.type === 'youtube') {
            let vid = block.content;
            if (!vid) vid = prompt("ID Youtube (ex: dQw4w9WgXcQ)") || '';
            const finalId = vid.includes('v=') ? vid.split('v=')[1].split('&')[0] : vid;
            if (finalId && finalId !== block.content) save(block.id, finalId);
            contentDiv.innerHTML = `<iframe width="100%" height="400" src="https://www.youtube.com/embed/${finalId}" frameborder="0" allowfullscreen></iframe>`;
        }
    }

    function renderTable(div, id) {
        const tbody = div.querySelector('tbody');
        tbody.innerHTML = '';
        div.tableData.forEach((row, rI) => {
            const tr = document.createElement('tr');
            row.forEach((cellHTML, cI) => {
                const td = document.createElement('td');
                td.contentEditable = canEdit ? 'true' : 'false';
                td.innerHTML = cellHTML;
                if (canEdit) {
                    td.onfocus = () => { activeBlockId = id; lastFocusedCell = td; };
                    td.onblur = () => { activeBlockId = null; };
                    td.oninput = () => {
                        div.tableData[rI][cI] = td.innerHTML;
                        save(id, JSON.stringify(div.tableData));
                    };
                }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    window.tblAdd = (id, type) => {
        if (!canEdit) return;
        const el = document.getElementById(`blk-${id}`);
        if (type === 'row') el.tableData.push(new Array(el.tableData[0].length).fill(''));
        else el.tableData.forEach(row => row.push(''));
        save(id, JSON.stringify(el.tableData));
        renderTable(el, id);
    };

    window.tblColor = (id, color, type) => {
        if (!canEdit) return;
        if (!lastFocusedCell) return;
        if (type === 'fore') lastFocusedCell.style.color = color;
        else lastFocusedCell.style.backgroundColor = color;
        lastFocusedCell.dispatchEvent(new Event('input'));
    };

    window.tblCmd = (id, cmd) => {
        if (!canEdit) return;
        if (!lastFocusedCell) return;
        if (cmd === 'bold') lastFocusedCell.style.fontWeight = lastFocusedCell.style.fontWeight === 'bold' ? 'normal' : 'bold';
        lastFocusedCell.dispatchEvent(new Event('input'));
    }

    function updateDOM(el, block) {
        if (block.type === 'text') {
            if (el.quill.root.innerHTML !== block.content) el.quill.root.innerHTML = block.content;
        }
        else if (block.type === 'table') {
            el.tableData = JSON.parse(block.content);
            renderTable(el, block.id);
        }
        else if (block.type === 'todo') {
            const d = JSON.parse(block.content);
            el.querySelector('.todo-check').checked = d.checked;
            el.querySelector('.todo-input').value = d.text;
            el.querySelector('.todo-input').classList.toggle('todo-done', d.checked);
        }
    }

    async function deleteBlock(id) {
        if (!canEdit) return;
        if (!confirm('Supprimer ?')) return;
        document.getElementById(`blk-${id}`).remove();
        await api('delete', { id, doc_id: currentDocId });
    }
</script>
</body>
</html>
