# 📝 CollabDocs - Wiki, Notes & Cahiers des charges (PHP)

**CollabDocs** est une solution ultra‑légère et open‑source pour créer des **wikis**, **prises de notes**, **cours** et **cahiers des charges** collaboratifs (style Google Docs / Notion). Tout reste en **PHP** avec **MySQL**, sans Node.js ni build complexe.

---

## ✨ Fonctionnalités

- **Multi‑documents** : Wiki, Notes, Cours, Cahier des charges.
- **Modèles prêts à l’emploi** (génération automatique de sections).
- **Éditeur riche** (Quill.js) : titres, styles, couleurs, alignements.
- **Blocs** : texte, tableau, to‑do, image, YouTube.
- **Sauvegarde auto** + synchronisation **semi‑temps réel** (polling).
- **Authentification + rôles** : admin / éditeur / lecteur.
- **Liens wiki + backlinks** : navigation entre pages via `[[Titre]]`.
- **Bibliothèques** : organisation des documents par collections.
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
- `ADMIN_USER`
- `ADMIN_PASS`

Si `ADMIN_USER` / `ADMIN_PASS` ne sont pas définis, le compte par défaut est **admin / admin** (à changer immédiatement).

---

## 📦 Déploiement Portainer (conseillé)

1. **Stack** → **Add stack** → coller le contenu de [docker-compose.yml](docker-compose.yml).
2. **Deploy the stack**.
3. Ouvrir : `http://IP:8080`

✅ Le montage de volume côté app a été retiré pour éviter les erreurs **403 / Forbidden** dues aux permissions de fichiers sur l’hôte.

⚠️ **Important** : un compte admin **admin/admin** est disponible pour le bootstrap, et un **changement obligatoire** est demandé à la première connexion.
Pour les nouveaux utilisateurs, l’admin attribue l’accès par document.

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
6. **Liens wiki** : écrire `[[Titre d'une page]]` pour créer un lien
    - Les **liens sortants** et **backlinks** s’affichent dans la barre latérale
7. **Gestion des utilisateurs** (admin) : bouton *Utilisateurs* dans la barre latérale
8. **Accès par document** (admin) : bouton *Accès* dans l’en‑tête
9. **Bibliothèques** : gestion et filtre depuis la barre latérale
10. **Création** : un éditeur a accès automatiquement aux pages qu’il crée

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
- **Authentification** : sessions sécurisées + mots de passe hashés (bcrypt)
- **CSRF** : protection sur toutes les actions sensibles
- **Rôles** : admin / éditeur / lecteur
- **Lecteur** : accès en lecture seule (UI verrouillée)
- **Accès par document** : l’admin choisit qui peut lire/éditer chaque page

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
