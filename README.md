# 📝 CollabDocs - Wiki, Notes & Cahiers des charges (PHP)

**CollabDocs** est une solution ultra‑légère et open‑source pour créer des **wikis**, **prises de notes**, **cours** et **cahiers des charges** collaboratifs (style Google Docs / Notion). Tout reste en **PHP** avec **MySQL**, sans Node.js ni build complexe.

---

## ✨ Fonctionnalités

- **Multi‑documents** : Wiki, Notes, Cours, Cahier des charges.
- **Modèles prêts à l’emploi** (génération automatique de sections).
- **Éditeur riche** (Quill.js) : titres, styles, couleurs, alignements.
- **Blocs** : texte, tableau, to‑do, image, YouTube.
- **Sauvegarde auto** + synchronisation **semi‑temps réel** (polling).
- **Docker prêt** (image Apache + PHP + MySQL).

---

## 🧩 Architecture (simple et robuste)

- **Frontend** : HTML/CSS/JS (vanilla)
- **Backend** : PHP natif + PDO
- **DB** : MySQL/MariaDB
- **Conteneur** : Apache + PHP 8.2

---

## ✅ Prérequis (hors Docker)

- PHP 7.4+ (recommandé : 8.1+)
- Apache ou Nginx
- MySQL/MariaDB

---

## 🚀 Installation Docker (recommandée)

Dans ce dossier :

```
docker compose up --build
```

Puis ouvrir : http://localhost:8080

Variables d’environnement gérées par Docker :

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

---

## 📦 Déploiement Portainer (conseillé)

1. **Stack** → **Add stack** → coller le contenu de [docker-compose.yml](docker-compose.yml).
2. **Deploy the stack**.
3. Ouvrir : `http://IP:8080`

✅ Le montage de volume côté app a été retiré pour éviter les erreurs **403 / Forbidden** dues aux permissions de fichiers sur l’hôte.

---

## 🛠️ Installation classique (sans Docker)

### 1) Fichiers

Copier [take.php](take.php) et [index.php](index.php) dans le dossier de votre site.

### 2) Base de données

```
CREATE DATABASE IF NOT EXISTS collab_notes;
USE collab_notes;

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
```

### 3) Configuration DB

Dans [take.php](take.php), modifiez :

```
$host = 'localhost';
$db   = 'collab_notes';
$user = 'root';
$pass = '';
```

### 4) C’est prêt

Ouvrir l’URL de votre site.

---

## 📖 Guide d’utilisation

1. **Créer un document** : bouton *Nouveau document* (sidebar)
2. **Choisir le type** : Wiki, Note, Cours, Cahier des charges
3. **Ajouter des blocs** : bouton **+** flottant (texte, tableau, to‑do, image, YouTube)
4. **Réorganiser** : glisser‑déposer
5. **Collaborer** : ouverture simultanée possible, sync automatique

---

## 🧱 Modèles intégrés

- **Cahier des charges** : sections prêtes (objectifs, périmètre, exigences, planning, risques)
- **Cours** : objectifs, plan, contenu, exercices
- **Wiki** : structure rapide
- **Note** : page simple

---

## 🔒 Sécurité & collaboration

- **Collaboration** via polling (2,5s)
- **Conflit d’édition** : le dernier enregistrement l’emporte si édition concurrente
- **Conseillé** : ajouter un système d’authentification si exposition publique

---

## 🧪 Dépannage

### Erreur 403 “Forbidden” (Apache)

**Cause fréquente** : permissions insuffisantes quand un dossier hôte est monté dans le conteneur.

✅ Solution :
- utiliser l’image sans volume (déjà configurée dans [docker-compose.yml](docker-compose.yml))
- ou donner les droits de lecture à l’utilisateur Apache (`www-data`) sur l’hôte

---

## 📚 Technologies

- **PHP** + **PDO**
- **MySQL**
- **Quill.js**
- **SortableJS**
- **FontAwesome**

---

## ✅ Roadmap

**Court terme**
- Authentification + rôles (admin/éditeur/lecteur)
- Pages liées (liens wiki + backlinks)
- Amélioration de la collaboration (verrou léger par bloc)

**Moyen terme**
- Historique des versions par document
- Export PDF / DOCX / Markdown
- Import Markdown / HTML

**Long terme**
- API REST publique
- Notifications (mentions, tâches)
- SSO (OIDC) pour usage entreprise

---

**CollabDocs** — rapide, simple, et 100% PHP. 🧠
