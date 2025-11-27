<?php
/* =========================================================================
   SETUP SQL (Identique)
   CREATE TABLE IF NOT EXISTS collab_blocks (
       id INT AUTO_INCREMENT PRIMARY KEY,
       type VARCHAR(20) NOT NULL, 
       content LONGTEXT,
       position INT DEFAULT 0,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );
========================================================================= */

// --- CONFIGURATION ---
$host = 'localhost';
$db   = 'collab_notes';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) { die("Erreur DB : Vérifiez vos identifiants."); }

// =========================================================================
// API BACKEND
// =========================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if ($_GET['action'] === 'fetch') {
        $stmt = $pdo->query("SELECT * FROM collab_blocks ORDER BY position ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['action'] === 'add') {
        $max = $pdo->query("SELECT MAX(position) FROM collab_blocks")->fetchColumn();
        $pos = ($max !== false) ? $max + 1 : 0;
        
        $content = '';
        if ($input['type'] === 'table') {
            // Tableau 3x3 vide
            $content = json_encode([['', '', ''],['', '', ''],['', '', '']]);
        }
        if ($input['type'] === 'todo') $content = json_encode(['text' => 'Nouvelle tâche', 'checked' => false]);
        if ($input['type'] === 'image') $content = $input['content'];
        if ($input['type'] === 'youtube') $content = $input['content'];

        $stmt = $pdo->prepare("INSERT INTO collab_blocks (type, content, position) VALUES (?, ?, ?)");
        $stmt->execute([$input['type'], $content, $pos]);
        echo json_encode(["status" => "ok", "id" => $pdo->lastInsertId()]);
        exit;
    }

    if ($_GET['action'] === 'update') {
        $stmt = $pdo->prepare("UPDATE collab_blocks SET content = ? WHERE id = ?");
        $stmt->execute([$input['content'], $input['id']]);
        echo json_encode(["status" => "saved"]);
        exit;
    }

    if ($_GET['action'] === 'reorder') {
        foreach ($input['order'] as $index => $id) {
            $stmt = $pdo->prepare("UPDATE collab_blocks SET position = ? WHERE id = ?");
            $stmt->execute([$index, $id]);
        }
        echo json_encode(["status" => "reordered"]);
        exit;
    }

    if ($_GET['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM collab_blocks WHERE id = ?");
        $stmt->execute([$input['id']]);
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
    <title>Doc V7 - Custom Edition</title>
    
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

    <style>
        :root { --bg: #f3f4f6; --paper: #ffffff; --primary: #4f46e5; --text: #111827; }
        body { background: var(--bg); margin: 0; font-family: 'Segoe UI', Helvetica, sans-serif; color: var(--text); padding-bottom: 100px; }

        /* HEADER */
        header {
            position: fixed; top: 0; width: 100%; height: 60px; background: rgba(255,255,255,0.85);
            backdrop-filter: blur(8px); border-bottom: 1px solid #e5e7eb; z-index: 100;
            display: flex; align-items: center; padding: 0 20px; box-sizing: border-box; justify-content: space-between;
        }
        .doc-title { font-size: 18px; font-weight: 700; border: none; background: transparent; outline: none; width: 400px; color: #333; }
        .doc-title:focus { border-bottom: 2px solid var(--primary); }

        /* PAGE */
        #viewport {
            width: 850px; min-height: 100vh; margin: 90px auto 50px;
            background: var(--paper); box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            padding: 60px; box-sizing: border-box; border-radius: 12px;
        }
        @media (max-width: 900px) { #viewport { width: 95%; padding: 20px; } }

        /* BLOCS */
        .block-wrapper { position: relative; margin-bottom: 15px; padding-left: 35px; border-radius: 6px; border: 1px solid transparent; transition: 0.2s; }
        .block-wrapper:hover { border-color: #e5e7eb; }

        /* CONTROLS */
        .block-controls {
            position: absolute; left: 5px; top: 5px; display: flex; flex-direction: column; gap: 6px;
            opacity: 0; transition: 0.2s;
        }
        .block-wrapper:hover .block-controls { opacity: 1; }
        .icon-btn { cursor: pointer; color: #9ca3af; font-size: 14px; padding: 4px; border-radius: 4px; }
        .icon-btn:hover { color: var(--primary); background: #eff6ff; }
        .icon-trash:hover { color: #ef4444; background: #fef2f2; }
        .drag-handle { cursor: grab; }

        /* --- QUILL CUSTOM (TEXTE) --- */
        /* On surcharge Quill pour qu'il soit joli et s'intègre bien */
        .ql-toolbar.ql-snow { border: none; background: #f8fafc; border-radius: 8px; margin-bottom: 5px; display: none; }
        .ql-container.ql-snow { border: none; font-size: 16px; font-family: inherit; }
        .block-wrapper:focus-within .ql-toolbar.ql-snow { display: block; animation: fadeIn 0.3s; }

        /* --- TABLEAU CUSTOM --- */
        .table-container { overflow-x: auto; padding: 5px 0; }
        .native-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .native-table td {
            border: 1px solid #d1d5db; min-width: 60px; padding: 8px;
            outline: none; transition: 0.2s; vertical-align: top;
        }
        .native-table td:focus { border: 2px solid var(--primary); z-index: 10; position: relative; }
        
        /* Barre d'outils Tableau */
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

        /* TYPES SPECIFIQUES */
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

        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        #status { position: fixed; bottom: 20px; left: 20px; font-size: 12px; color: #9ca3af; font-weight: 500; }
    </style>
</head>
<body>

    <header>
        <input type="text" id="doc-title" class="doc-title" value="Mon Document (Cliquez pour renommer)" oninput="saveTitle()">
        <div style="font-size: 12px; color: #6b7280;">V7 Custom Edition</div>
    </header>

    <div id="viewport"></div>
    <div id="status">Prêt</div>

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

<script>
    const container = document.getElementById('viewport');
    let activeBlockId = null;
    let saveTimer = {};
    let lastFocusedCell = null; // Pour le tableau

    // INIT & TITRE
    document.getElementById('doc-title').value = localStorage.getItem('docTitle') || 'Mon Projet Créatif';
    function saveTitle() { localStorage.setItem('docTitle', document.getElementById('doc-title').value); }

    function toggleMenu() {
        document.getElementById('menu').classList.toggle('show');
        document.getElementById('btn-plus').classList.toggle('open');
    }
    function addItem(type) { addBlock(type); toggleMenu(); }

    document.addEventListener('DOMContentLoaded', () => {
        refresh(); setInterval(refresh, 2500);
        new Sortable(container, {
            animation: 150, handle: '.drag-handle',
            onEnd: () => {
                const order = Array.from(container.children).map(el => el.dataset.id);
                fetch('?action=reorder', { method: 'POST', body: JSON.stringify({ order }) });
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

    // --- LOGIQUE ---
    async function refresh() {
        if(activeBlockId) return;
        try {
            const res = await fetch('?action=fetch');
            const blocks = await res.json();
            if(blocks.length === 0 && container.children.length === 0) { addItem('text'); return; }
            blocks.forEach(block => {
                let el = document.getElementById(`blk-${block.id}`);
                if (!el) renderBlock(block);
                else if (parseInt(activeBlockId) !== parseInt(block.id)) updateDOM(el, block);
            });
            const ids = blocks.map(b => parseInt(b.id));
            Array.from(container.children).forEach(child => { if (!ids.includes(parseInt(child.dataset.id))) child.remove(); });
        } catch(e) {}
    }

    async function addBlock(type, content = null) {
        const res = await fetch('?action=add', { method: 'POST', body: JSON.stringify({ type, content }) });
        const data = await res.json();
        refresh(); setTimeout(() => window.scrollTo(0, document.body.scrollHeight), 100);
    }

    async function save(id, content) {
        document.getElementById('status').innerText = 'Sauvegarde...';
        clearTimeout(saveTimer[id]);
        saveTimer[id] = setTimeout(async () => {
            await fetch('?action=update', { method: 'POST', body: JSON.stringify({ id, content }) });
            document.getElementById('status').innerText = 'Synchronisé';
        }, 800);
    }

    // --- RENDU ---
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

        // 1. TEXTE (AVEC TOOLBAR COMPLETE)
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
            quill.on('text-change', (d,o,s) => { if(s==='user') save(block.id, quill.root.innerHTML); });
        }
        
        // 2. TABLEAU CUSTOM
        else if (block.type === 'table') {
            // Barre d'outils flottante
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
            const chk = contentDiv.querySelector('.todo-check'), inp = contentDiv.querySelector('.todo-input');
            const doSave = () => { save(block.id, JSON.stringify({text:inp.value, checked:chk.checked})); inp.classList.toggle('todo-done', chk.checked); };
            chk.onchange = doSave; inp.oninput = doSave; inp.onfocus = () => activeBlockId = block.id; inp.onblur = () => activeBlockId = null;
        }
        else if (block.type === 'image') { contentDiv.className='img-block'; contentDiv.innerHTML = `<img src="${block.content}">`; }
        else if (block.type === 'youtube') {
            const vid = prompt("ID Youtube (ex: dQw4w9WgXcQ)") || block.content;
            const finalId = vid.includes('v=') ? vid.split('v=')[1].split('&')[0] : vid;
            if(finalId && finalId !== block.content) save(block.id, finalId);
            contentDiv.innerHTML = `<iframe width="100%" height="400" src="https://www.youtube.com/embed/${finalId || block.content}" frameborder="0" allowfullscreen></iframe>`;
        }
    }

    // --- FONCTIONS TABLEAU ---
    function renderTable(div, id) {
        const tbody = div.querySelector('tbody');
        tbody.innerHTML = '';
        div.tableData.forEach((row, rI) => {
            const tr = document.createElement('tr');
            row.forEach((cellHTML, cI) => {
                const td = document.createElement('td');
                td.contentEditable = true;
                td.innerHTML = cellHTML; // Utilise innerHTML pour le style
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
        if(type === 'row') el.tableData.push(new Array(el.tableData[0].length).fill(''));
        else el.tableData.forEach(row => row.push(''));
        save(id, JSON.stringify(el.tableData)); renderTable(el, id);
    };

    window.tblColor = (id, color, type) => {
        if(!lastFocusedCell) return;
        if(type === 'fore') lastFocusedCell.style.color = color;
        else lastFocusedCell.style.backgroundColor = color;
        // Trigger input event manually to save
        lastFocusedCell.dispatchEvent(new Event('input'));
    };

    window.tblCmd = (id, cmd) => {
        if(!lastFocusedCell) return;
        if(cmd === 'bold') lastFocusedCell.style.fontWeight = lastFocusedCell.style.fontWeight === 'bold' ? 'normal' : 'bold';
        lastFocusedCell.dispatchEvent(new Event('input'));
    }

    function updateDOM(el, block) {
        if (block.type === 'text') { if (el.quill.root.innerHTML !== block.content) el.quill.root.innerHTML = block.content; }
        else if (block.type === 'table') { el.tableData = JSON.parse(block.content); renderTable(el, block.id); }
        else if (block.type === 'todo') { const d = JSON.parse(block.content); el.querySelector('.todo-check').checked = d.checked; el.querySelector('.todo-input').value = d.text; }
    }

    async function deleteBlock(id) { if(confirm('Supprimer ?')) { document.getElementById(`blk-${id}`).remove(); await fetch('?action=delete', { method: 'POST', body: JSON.stringify({ id }) }); } }
</script>
</body>
</html>
