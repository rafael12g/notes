<?php
/* =========================================================================
   SETUP SQL
   CREATE TABLE IF NOT EXISTS collab_docs (
       id INT AUTO_INCREMENT PRIMARY KEY,
       title VARCHAR(255) NOT NULL,
       type VARCHAR(30) DEFAULT 'note',
       tags VARCHAR(255) DEFAULT '',
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
========================================================================= */

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

// --- MIGRATION SIMPLE ---
$pdo->exec("CREATE TABLE IF NOT EXISTS collab_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(30) DEFAULT 'note',
    tags VARCHAR(255) DEFAULT '',
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

try { $pdo->exec("ALTER TABLE collab_blocks ADD COLUMN doc_id INT NOT NULL DEFAULT 1"); } catch (PDOException $e) { /* ignore */ }
try { $pdo->exec("CREATE INDEX idx_blocks_doc_id ON collab_blocks (doc_id)"); } catch (PDOException $e) { /* ignore */ }

$defaultDocId = $pdo->query("SELECT id FROM collab_docs ORDER BY id ASC LIMIT 1")->fetchColumn();
if (!$defaultDocId) {
    $stmt = $pdo->prepare("INSERT INTO collab_docs (title, type, tags) VALUES (?, ?, ?)");
    $stmt->execute(['Mon Wiki', 'wiki', '']);
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
// API BACKEND
// =========================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($_GET['action'] === 'docs_list') {
        $stmt = $pdo->query("SELECT id, title, type, tags, created_at, updated_at FROM collab_docs ORDER BY updated_at DESC, id DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['action'] === 'docs_create') {
        $title = trim($input['title'] ?? 'Nouveau document');
        $type = $input['type'] ?? 'note';
        $tags = trim($input['tags'] ?? '');
        $template = $input['template'] ?? $type;

        $stmt = $pdo->prepare("INSERT INTO collab_docs (title, type, tags) VALUES (?, ?, ?)");
        $stmt->execute([$title, $type, $tags]);
        $docId = (int)$pdo->lastInsertId();

        $blocks = templateBlocks($template, $title);
        foreach ($blocks as $index => $content) {
            $stmt = $pdo->prepare("INSERT INTO collab_blocks (doc_id, type, content, position) VALUES (?, 'text', ?, ?)");
            $stmt->execute([$docId, $content, $index]);
        }

        echo json_encode(["status" => "ok", "id" => $docId]);
        exit;
    }

    if ($_GET['action'] === 'docs_update') {
        $docId = (int)($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $type = $input['type'] ?? 'note';
        $tags = trim($input['tags'] ?? '');
        $stmt = $pdo->prepare("UPDATE collab_docs SET title = ?, type = ?, tags = ? WHERE id = ?");
        $stmt->execute([$title, $type, $tags, $docId]);
        echo json_encode(["status" => "updated"]);
        exit;
    }

    if ($_GET['action'] === 'docs_delete') {
        $docId = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM collab_blocks WHERE doc_id = ?");
        $stmt->execute([$docId]);
        $stmt = $pdo->prepare("DELETE FROM collab_docs WHERE id = ?");
        $stmt->execute([$docId]);
        echo json_encode(["status" => "deleted"]);
        exit;
    }

    if ($_GET['action'] === 'fetch') {
        $docId = (int)($input['doc_id'] ?? ($_GET['doc_id'] ?? $defaultDocId));
        $stmt = $pdo->prepare("SELECT * FROM collab_blocks WHERE doc_id = ? ORDER BY position ASC");
        $stmt->execute([$docId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['action'] === 'add') {
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
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
        echo json_encode(["status" => "ok", "id" => $pdo->lastInsertId()]);
        exit;
    }

    if ($_GET['action'] === 'update') {
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        $stmt = $pdo->prepare("UPDATE collab_blocks SET content = ? WHERE id = ?");
        $stmt->execute([$input['content'], $input['id']]);
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        echo json_encode(["status" => "saved"]);
        exit;
    }

    if ($_GET['action'] === 'reorder') {
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        foreach ($input['order'] as $index => $id) {
            $stmt = $pdo->prepare("UPDATE collab_blocks SET position = ? WHERE id = ? AND doc_id = ?");
            $stmt->execute([$index, $id, $docId]);
        }
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        echo json_encode(["status" => "reordered"]);
        exit;
    }

    if ($_GET['action'] === 'delete') {
        $docId = (int)($input['doc_id'] ?? $defaultDocId);
        $stmt = $pdo->prepare("DELETE FROM collab_blocks WHERE id = ? AND doc_id = ?");
        $stmt->execute([$input['id'], $docId]);
        $pdo->prepare("UPDATE collab_docs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$docId]);
        echo json_encode(["status" => "deleted"]);
        exit;
    }
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
        }
        .logo { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .logo i { color: #a5b4fc; }
        .doc-actions { display: flex; flex-direction: column; gap: 10px; }
        .btn-primary { background: var(--primary); border: none; color: white; padding: 10px 12px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn-primary:hover { filter: brightness(1.05); }
        .search { background: #111827; border: 1px solid #1f2937; color: #e2e8f0; padding: 10px 12px; border-radius: 8px; }

        #doc-list { display: flex; flex-direction: column; gap: 8px; overflow-y: auto; padding-right: 6px; }
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
        .modal-card input, .modal-card select { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-ghost { background: #f3f4f6; border: none; padding: 10px 12px; border-radius: 8px; cursor: pointer; }

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
                <input type="text" id="doc-tags" class="doc-tags" placeholder="Tags (ex: produit, sprint)">
            </div>
            <div class="status" id="doc-status">Prêt</div>
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

<div id="status">Synchronisé</div>

<script>
    const container = document.getElementById('viewport');
    const docList = document.getElementById('doc-list');
    const docTitle = document.getElementById('doc-title');
    const docType = document.getElementById('doc-type');
    const docTags = document.getElementById('doc-tags');
    const docStatus = document.getElementById('doc-status');
    const docSearch = document.getElementById('doc-search');

    let activeBlockId = null;
    let saveTimer = {};
    let docSaveTimer = null;
    let lastFocusedCell = null;
    let currentDocId = null;
    let docs = [];

    function setStatus(text) {
        document.getElementById('status').innerText = text;
        docStatus.innerText = text;
    }

    function toggleMenu() {
        document.getElementById('menu').classList.toggle('show');
        document.getElementById('btn-plus').classList.toggle('open');
    }
    function addItem(type) { addBlock(type); toggleMenu(); }

    async function loadDocs() {
        const res = await fetch('?action=docs_list');
        docs = await res.json();
        renderDocList();

        const saved = localStorage.getItem('docId');
        currentDocId = docs.find(d => String(d.id) === String(saved))?.id || docs[0]?.id;
        if (currentDocId) selectDoc(currentDocId, true);
    }

    function renderDocList() {
        const filter = docSearch.value.toLowerCase();
        docList.innerHTML = '';
        docs
            .filter(d => d.title.toLowerCase().includes(filter) || (d.tags || '').toLowerCase().includes(filter))
            .forEach(doc => {
                const item = document.createElement('div');
                item.className = 'doc-item' + (String(doc.id) === String(currentDocId) ? ' active' : '');
                item.innerHTML = `
                    <div class="doc-meta">
                        <div class="doc-title-sm">${doc.title}</div>
                        <div class="doc-type">${doc.type}</div>
                    </div>
                    <i class="fa-solid fa-trash doc-delete" title="Supprimer"></i>
                `;
                item.onclick = (e) => {
                    if (e.target.classList.contains('doc-delete')) return;
                    selectDoc(doc.id);
                };
                item.querySelector('.doc-delete').onclick = async () => {
                    if (!confirm('Supprimer ce document et tous ses blocs ?')) return;
                    await fetch('?action=docs_delete', { method: 'POST', body: JSON.stringify({ id: doc.id }) });
                    await loadDocs();
                };
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
        renderDocList();
        if (!skipRefresh) container.innerHTML = '';
        refresh();
    }

    function openModal() { document.getElementById('doc-modal').classList.add('show'); }
    function closeModal() { document.getElementById('doc-modal').classList.remove('show'); }

    document.getElementById('btn-new-doc').onclick = () => {
        document.getElementById('new-title').value = '';
        document.getElementById('new-type').value = 'wiki';
        document.getElementById('new-tags').value = '';
        document.getElementById('new-template').value = 'wiki';
        openModal();
    };
    document.getElementById('btn-cancel').onclick = closeModal;
    document.getElementById('btn-create').onclick = async () => {
        const title = document.getElementById('new-title').value || 'Nouveau document';
        const type = document.getElementById('new-type').value;
        const tags = document.getElementById('new-tags').value;
        const template = document.getElementById('new-template').value;
        const res = await fetch('?action=docs_create', { method: 'POST', body: JSON.stringify({ title, type, tags, template }) });
        const data = await res.json();
        closeModal();
        await loadDocs();
        selectDoc(data.id);
    };

    docSearch.oninput = () => renderDocList();

    function scheduleDocMetaSave() {
        clearTimeout(docSaveTimer);
        docSaveTimer = setTimeout(async () => {
            await fetch('?action=docs_update', {
                method: 'POST',
                body: JSON.stringify({ id: currentDocId, title: docTitle.value, type: docType.value, tags: docTags.value })
            });
            await loadDocs();
        }, 600);
    }
    docTitle.oninput = scheduleDocMetaSave;
    docType.onchange = scheduleDocMetaSave;
    docTags.oninput = scheduleDocMetaSave;

    document.addEventListener('DOMContentLoaded', () => {
        loadDocs();
        setInterval(refresh, 2500);
        new Sortable(container, {
            animation: 150, handle: '.drag-handle',
            onEnd: () => {
                const order = Array.from(container.children).map(el => el.dataset.id);
                fetch('?action=reorder', { method: 'POST', body: JSON.stringify({ order, doc_id: currentDocId }) });
            }
        });
        document.getElementById('img-up').addEventListener('change', function() {
            if (this.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { addBlock('image', e.target.result); toggleMenu(); };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    async function refresh() {
        if (!currentDocId || activeBlockId) return;
        try {
            const res = await fetch(`?action=fetch&doc_id=${currentDocId}`);
            const blocks = await res.json();
            if (blocks.length === 0 && container.children.length === 0) { addBlock('text'); return; }
            blocks.forEach(block => {
                let el = document.getElementById(`blk-${block.id}`);
                if (!el) renderBlock(block);
                else if (parseInt(activeBlockId) !== parseInt(block.id)) updateDOM(el, block);
            });
            const ids = blocks.map(b => parseInt(b.id));
            Array.from(container.children).forEach(child => { if (!ids.includes(parseInt(child.dataset.id))) child.remove(); });
        } catch (e) {}
    }

    async function addBlock(type, content = null) {
        if (!currentDocId) return;
        await fetch('?action=add', { method: 'POST', body: JSON.stringify({ type, content, doc_id: currentDocId }) });
        refresh();
        setTimeout(() => window.scrollTo(0, document.body.scrollHeight), 100);
    }

    async function save(id, content) {
        setStatus('Sauvegarde...');
        clearTimeout(saveTimer[id]);
        saveTimer[id] = setTimeout(async () => {
            await fetch('?action=update', { method: 'POST', body: JSON.stringify({ id, content, doc_id: currentDocId }) });
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
                td.contentEditable = true;
                td.innerHTML = cellHTML;
                td.onfocus = () => { activeBlockId = id; lastFocusedCell = td; };
                td.onblur = () => { activeBlockId = null; };
                td.oninput = () => {
                    div.tableData[rI][cI] = td.innerHTML;
                    save(id, JSON.stringify(div.tableData));
                };
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    window.tblAdd = (id, type) => {
        const el = document.getElementById(`blk-${id}`);
        if (type === 'row') el.tableData.push(new Array(el.tableData[0].length).fill(''));
        else el.tableData.forEach(row => row.push(''));
        save(id, JSON.stringify(el.tableData));
        renderTable(el, id);
    };

    window.tblColor = (id, color, type) => {
        if (!lastFocusedCell) return;
        if (type === 'fore') lastFocusedCell.style.color = color;
        else lastFocusedCell.style.backgroundColor = color;
        lastFocusedCell.dispatchEvent(new Event('input'));
    };

    window.tblCmd = (id, cmd) => {
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
        if (!confirm('Supprimer ?')) return;
        document.getElementById(`blk-${id}`).remove();
        await fetch('?action=delete', { method: 'POST', body: JSON.stringify({ id, doc_id: currentDocId }) });
    }
</script>
</body>
</html>
