<?php
/* =========================================================================
   SETUP SQL (À exécuter dans phpMyAdmin)
   
   CREATE TABLE IF NOT EXISTS collab_blocks (
       id INT AUTO_INCREMENT PRIMARY KEY,
       type VARCHAR(20) NOT NULL, 
       content LONGTEXT,
       position INT DEFAULT 0,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );
========================================================================= */

// --- CONFIGURATION BDD ---
$host = 'localhost';
$db   = 'collab_notes';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { die("Erreur connexion DB."); }

// =========================================================================
// API BACKEND
// =========================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // 1. CHARGER
    if ($_GET['action'] === 'fetch') {
        $stmt = $pdo->query("SELECT * FROM collab_blocks ORDER BY position ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // 2. AJOUTER
    if ($_GET['action'] === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        $max = $pdo->query("SELECT MAX(position) FROM collab_blocks")->fetchColumn();
        $pos = ($max !== false) ? $max + 1 : 0;
        
        // CONTENU PAR DÉFAUT SELON LE TYPE
        $content = '';
        if ($in['type'] === 'sheet') {
            $content = '[]'; // Tableur vide
        } elseif ($in['type'] === 'custom_table') {
            // Structure JSON pour le tableau flexible : Headers + Rows
            $content = json_encode([
                "headers" => ["Colonne 1", "Colonne 2"],
                "rows" => [ ["Donnée A", "Donnée B"] ]
            ]);
        }
        
        $stmt = $pdo->prepare("INSERT INTO collab_blocks (type, content, position) VALUES (?, ?, ?)");
        $stmt->execute([$in['type'], $content, $pos]);
        echo json_encode(["status" => "ok", "id" => $pdo->lastInsertId()]);
        exit;
    }

    // 3. SAUVEGARDER
    if ($_GET['action'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE collab_blocks SET content = ? WHERE id = ?");
        $stmt->execute([$in['content'], $in['id']]);
        echo json_encode(["status" => "saved"]);
        exit;
    }

    // 4. SUPPRIMER
    if ($_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("DELETE FROM collab_blocks WHERE id = ?");
        $stmt->execute([$in['id']]);
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
    <title>Suite Collaborative V4</title>
    
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    
    <script src="https://bossanova.uk/jspreadsheet/v4/jexcel.js"></script>
    <link rel="stylesheet" href="https://bossanova.uk/jspreadsheet/v4/jexcel.css" type="text/css" />
    <script src="https://jsuites.net/v4/jsuites.js"></script>
    <link rel="stylesheet" href="https://jsuites.net/v4/jsuites.css" type="text/css" />

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        :root { --primary: #2979ff; --bg: #f8f9fa; --paper: #ffffff; }
        body { background: var(--bg); margin: 0; font-family: 'Segoe UI', sans-serif; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        
        /* HEADER */
        header { 
            position: fixed; top: 0; width: 100%; height: 60px; background: white; 
            border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; 
            align-items: center; padding: 0 20px; z-index: 100; box-sizing: border-box;
        }
        .brand { font-weight: 700; color: #444; display: flex; align-items: center; gap: 10px; font-size: 18px; }
        .badge { background: var(--primary); color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; }
        #status { font-size: 13px; color: #888; display: flex; align-items: center; gap: 5px; }

        /* PAGE */
        #page-viewport {
            margin-top: 90px; margin-bottom: 120px;
            width: 21cm; min-height: 29.7cm; 
            background: var(--paper); 
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2.5cm; box-sizing: border-box;
            display: flex; flex-direction: column; gap: 10px;
        }

        /* BLOCS GÉNÉRAUX */
        .block-wrapper { position: relative; border-radius: 4px; border: 1px solid transparent; transition: 0.2s; }
        .block-wrapper:hover { border-color: #eee; }
        .trash-btn {
            position: absolute; left: -30px; top: 0; color: #ff5252; cursor: pointer;
            opacity: 0; transition: opacity 0.2s; padding: 5px;
        }
        .block-wrapper:hover .trash-btn { opacity: 1; }

        /* TEXTE */
        .ql-toolbar { display: none; position: absolute; top: -35px; left: 0; z-index: 50; background: #333; border: none !important; border-radius: 4px; padding: 5px !important; }
        .block-wrapper:focus-within .ql-toolbar { display: block; }
        .ql-container.ql-snow { border: none !important; }
        .ql-editor { padding: 5px 0; font-size: 16px; line-height: 1.6; }

        /* TABLEAU FLEXIBLE (NOUVEAU) */
        .custom-table-container { width: 100%; overflow-x: auto; margin: 10px 0; }
        .custom-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .custom-table th, .custom-table td { border: 1px solid #e0e0e0; padding: 0; position: relative; }
        .custom-table input { width: 100%; border: none; padding: 8px; box-sizing: border-box; outline: none; font-family: inherit; background: transparent; }
        .custom-table th { background: #f1f3f4; font-weight: 600; }
        .custom-table input:focus { background: #e8f0fe; }
        
        /* Boutons Tableau Flexible */
        .tbl-controls { display: flex; gap: 5px; margin-top: 5px; opacity: 0; transition: 0.2s; }
        .block-wrapper:hover .tbl-controls { opacity: 1; }
        .btn-mini { border: 1px solid #ddd; background: white; cursor: pointer; padding: 4px 8px; font-size: 11px; border-radius: 3px; color: #555; }
        .btn-mini:hover { background: #f0f0f0; color: var(--primary); }

        /* TABLEUR EXCEL */
        .sheet-wrapper { margin: 15px 0; }

        /* MENU FLOTTANT */
        .fab-menu { position: fixed; bottom: 30px; right: 30px; display: flex; flex-direction: column; gap: 10px; align-items: flex-end; }
        .fab-btn {
            width: 50px; height: 50px; border-radius: 50%; border: none; 
            background: var(--primary); color: white; font-size: 20px; 
            box-shadow: 0 4px 10px rgba(41, 121, 255, 0.4); cursor: pointer; 
            display: flex; align-items: center; justify-content: center; transition: 0.2s;
        }
        .fab-btn:hover { transform: scale(1.1); }
        .fab-options { display: flex; gap: 10px; opacity: 0; pointer-events: none; transition: 0.2s; transform: translateY(10px); }
        .fab-menu:hover .fab-options { opacity: 1; pointer-events: all; transform: translateY(0); }
        .mini-fab { width: 40px; height: 40px; font-size: 16px; background: white; color: #555; }
        .mini-fab:hover { color: var(--primary); }

        .empty-msg { color: #ccc; text-align: center; margin-top: 40%; pointer-events: none; }
    </style>
</head>
<body>

    <header>
        <div class="brand"><span class="badge">V4</span> Éditeur Collaboratif</div>
        <div id="status"><i class="fa-solid fa-check"></i> Prêt</div>
    </header>

    <div id="page-viewport">
        </div>

    <div class="fab-menu">
        <div class="fab-options">
            <button class="fab-btn mini-fab" onclick="addBlock('sheet')" title="Tableur Excel"><i class="fa-solid fa-file-excel"></i></button>
            <button class="fab-btn mini-fab" onclick="addBlock('custom_table')" title="Tableau Flexible"><i class="fa-solid fa-table"></i></button>
            <button class="fab-btn mini-fab" onclick="addBlock('text')" title="Texte"><i class="fa-solid fa-font"></i></button>
        </div>
        <button class="fab-btn"><i class="fa-solid fa-plus"></i></button>
    </div>

<script>
    const container = document.getElementById('page-viewport');
    const statusEl = document.getElementById('status');
    let activeBlockId = null;
    let saveTimeouts = {};

    // --- INIT ---
    document.addEventListener('DOMContentLoaded', () => {
        refresh();
        setInterval(refresh, 2000);
        
        // Clic global pour créer du texte si page vide
        container.addEventListener('click', (e) => {
            if (e.target === container) {
                if(container.children.length === 0) addBlock('text');
                else {
                    const last = container.lastElementChild;
                    if(last.dataset.type === 'text' && last.quill) last.quill.focus();
                }
            }
        });
    });

    // --- REFRESH / SYNC ---
    async function refresh() {
        if(activeBlockId) return; 

        try {
            const res = await fetch('?action=fetch');
            const blocks = await res.json();

            if(blocks.length === 0 && container.children.length === 0) {
                container.innerHTML = '<div class="empty-msg">Cliquez pour écrire...</div>';
                return;
            }
            if(blocks.length > 0 && container.querySelector('.empty-msg')) container.innerHTML = '';

            blocks.forEach(block => {
                let el = document.getElementById(`blk-${block.id}`);
                if (!el) renderBlock(block);
                else if (parseInt(activeBlockId) !== parseInt(block.id)) updateBlockData(el, block);
            });

            const ids = blocks.map(b => parseInt(b.id));
            Array.from(container.children).forEach(child => {
                if (child.id && !ids.includes(parseInt(child.dataset.id))) child.remove();
            });
        } catch(e) {}
    }

    // --- RENDU DES BLOCS ---
    function renderBlock(block, focusNow = false) {
        const wrapper = document.createElement('div');
        wrapper.className = 'block-wrapper';
        wrapper.id = `blk-${block.id}`;
        wrapper.dataset.id = block.id;
        wrapper.dataset.type = block.type;

        // Poubelle
        const trash = document.createElement('i');
        trash.className = 'fa-solid fa-trash trash-btn';
        trash.onclick = (e) => { e.stopPropagation(); deleteBlock(block.id); };
        wrapper.appendChild(trash);

        const contentDiv = document.createElement('div');
        wrapper.appendChild(contentDiv);
        container.appendChild(wrapper);

        // 1. TEXTE (Quill)
        if (block.type === 'text') {
            const quill = new Quill(contentDiv, {
                theme: 'snow',
                modules: { toolbar: [['bold', 'italic', 'underline'], [{'header':1}, {'header':2}], [{'list':'ordered'},{'list':'bullet'}], [{'color':[]}]] }
            });
            wrapper.quill = quill;
            quill.root.innerHTML = block.content || '';
            quill.on('selection-change', (r) => { activeBlockId = r ? block.id : null; });
            quill.on('text-change', (d, o, s) => { if(s === 'user') scheduleSave(block.id, quill.root.innerHTML); });
            if(focusNow) quill.focus();
        }

        // 2. TABLEUR EXCEL (Jspreadsheet)
        else if (block.type === 'sheet') {
            contentDiv.className = 'sheet-wrapper';
            preventClicks(contentDiv); // Stop propagation
            
            let data = tryParse(block.content, [[]]);
            if(data.length === 0) data = [[]];
            
            const sheet = jexcel(contentDiv, {
                data: data, minDimensions:[5,4], defaultColWidth: 80,
                onchange: (inst) => scheduleSave(block.id, JSON.stringify(inst.jexcel.getData())),
                onselection: () => { activeBlockId = block.id; },
                onblur: () => { activeBlockId = null; }
            });
            wrapper.sheet = sheet;
        }

        // 3. TABLEAU FLEXIBLE (Custom)
        else if (block.type === 'custom_table') {
            contentDiv.className = 'custom-table-container';
            preventClicks(contentDiv); // Stop propagation
            
            let data = tryParse(block.content, { headers: ['Titre'], rows: [['Valeur']] });
            wrapper.tableData = data; // Stockage local des données
            
            renderCustomTableUI(contentDiv, wrapper, block.id);
        }
    }

    // --- LOGIQUE DU TABLEAU FLEXIBLE ---
    function renderCustomTableUI(containerDiv, wrapper, id) {
        containerDiv.innerHTML = '';
        const data = wrapper.tableData;

        // Création table HTML
        const table = document.createElement('table');
        table.className = 'custom-table';

        // HEADERS
        const thead = document.createElement('thead');
        const trHead = document.createElement('tr');
        data.headers.forEach((h, index) => {
            const th = document.createElement('th');
            const input = document.createElement('input');
            input.value = h;
            input.onfocus = () => activeBlockId = id;
            input.onblur = () => activeBlockId = null;
            input.oninput = (e) => { data.headers[index] = e.target.value; scheduleSave(id, JSON.stringify(data)); };
            th.appendChild(input);
            trHead.appendChild(th);
        });
        thead.appendChild(trHead);
        table.appendChild(thead);

        // ROWS
        const tbody = document.createElement('tbody');
        data.rows.forEach((row, rIndex) => {
            const tr = document.createElement('tr');
            row.forEach((cell, cIndex) => {
                const td = document.createElement('td');
                const input = document.createElement('input');
                input.value = cell;
                input.onfocus = () => activeBlockId = id;
                input.onblur = () => activeBlockId = null;
                input.oninput = (e) => { data.rows[rIndex][cIndex] = e.target.value; scheduleSave(id, JSON.stringify(data)); };
                td.appendChild(input);
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        containerDiv.appendChild(table);

        // CONTROLES (Ajouter Ligne/Col)
        const controls = document.createElement('div');
        controls.className = 'tbl-controls';
        controls.innerHTML = `
            <button class="btn-mini" onclick="addCol(${id})">+ Colonne</button>
            <button class="btn-mini" onclick="addRow(${id})">+ Ligne</button>
        `;
        containerDiv.appendChild(controls);
    }

    // --- FONCTIONS TABLEAU FLEXIBLE ---
    function addCol(id) {
        const el = document.getElementById(`blk-${id}`);
        const data = el.tableData;
        data.headers.push("Nouv. Col");
        data.rows.forEach(row => row.push(""));
        scheduleSave(id, JSON.stringify(data));
        renderCustomTableUI(el.querySelector('.custom-table-container'), el, id);
    }

    function addRow(id) {
        const el = document.getElementById(`blk-${id}`);
        const data = el.tableData;
        const newRow = new Array(data.headers.length).fill("");
        data.rows.push(newRow);
        scheduleSave(id, JSON.stringify(data));
        renderCustomTableUI(el.querySelector('.custom-table-container'), el, id);
    }

    // --- UTILITAIRES ---
    function tryParse(str, fallback) {
        try { return JSON.parse(str); } catch(e) { return fallback; }
    }
    
    function preventClicks(div) {
        div.addEventListener('click', e => e.stopPropagation());
        div.addEventListener('mousedown', e => e.stopPropagation());
    }

    function updateBlockData(el, block) {
        if(block.type === 'text') {
            if(el.quill.root.innerHTML !== block.content) el.quill.root.innerHTML = block.content;
        } 
        // Note: Pour Sheet et Custom Table, on évite le refresh auto brutal quand on est pas focus,
        // mais pour une vraie collab parfaite, il faudrait re-rendre le tableau.
        else if (block.type === 'custom_table') {
             el.tableData = tryParse(block.content, el.tableData);
             renderCustomTableUI(el.querySelector('.custom-table-container'), el, block.id);
        }
    }

    function scheduleSave(id, content) {
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sauvegarde...';
        clearTimeout(saveTimeouts[id]);
        saveTimeouts[id] = setTimeout(async () => {
            await fetch('?action=update', { method: 'POST', body: JSON.stringify({ id, content }) });
            statusEl.innerHTML = '<i class="fa-solid fa-check"></i> Enregistré';
            setTimeout(() => { statusEl.innerHTML = '<i class="fa-solid fa-check"></i> Prêt'; }, 2000);
        }, 800);
    }

    async function addBlock(type) {
        if(container.querySelector('.empty-msg')) container.innerHTML = '';
        const res = await fetch('?action=add', { method: 'POST', body: JSON.stringify({ type }) });
        const data = await res.json();
        
        let initialContent = '';
        if(type === 'custom_table') initialContent = JSON.stringify({ headers:["Titre"], rows:[[""]] });
        else if(type === 'sheet') initialContent = '[]';

        renderBlock({ id: data.id, type: type, content: initialContent }, true);
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    }

    async function deleteBlock(id) {
        if(!confirm("Supprimer ?")) return;
        document.getElementById(`blk-${id}`).remove();
        await fetch('?action=delete', { method: 'POST', body: JSON.stringify({ id }) });
    }
</script>
</body>
</html>