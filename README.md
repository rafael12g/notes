Voici un fichier `README.md` complet, professionnel et prêt à l'emploi pour ton projet. Il explique comment installer, configurer et utiliser l'outil.

Tu peux copier-coller ce contenu dans un fichier nommé **`README.md`** à la racine de ton projet.

-----

# 📝 CollabDocs - Wiki, Notes & Cahiers des charges (PHP)

**CollabDocs** est une solution ultra-légère et open-source pour créer des **wikis**, **prises de notes**, **cours** et **cahiers des charges** collaboratifs en temps réel (style Google Docs ou Notion).

Il fonctionne avec **un seul fichier PHP** et une base de données **MySQL**. Pas de Node.js, pas de build complexe, pas d'installation lourde. Disponible aussi en **Docker**.

## ✨ Fonctionnalités

  * **📚 Multi-documents :** Wikis, notes, cours, cahiers des charges.
  * **🧩 Modèles prêts à l'emploi :** Cahier des charges, cours, wiki, note.
  * **📄 Éditeur de Texte Riche :** Mise en forme (Gras, Italique, Titres, Couleurs) via *Quill.js*.
  * **📊 Tableau Flexible :** Tableaux personnalisables, ajout de lignes/colonnes.
  * **✅ To‑Do :** Blocs de tâches interactifs.
  * **🔄 Collaboration semi‑temps réel :** Synchronisation automatique (polling).
  * **💾 Sauvegarde automatique :** Tout est stocké instantanément.
  * **🐳 Docker prêt :** Démarrage en une commande.

-----

## 🛠️ Prérequis (hors Docker)

  * Un serveur web (Apache, Nginx) ou local (WAMP, XAMPP, MAMP).
  * **PHP 7.4** ou supérieur.
  * **MySQL** ou MariaDB.

-----

## 🚀 Installation classique

### 1\. Préparer les fichiers

Créez un dossier sur votre serveur (ex: `mon-doc`) et placez-y le fichier `take.php` (ou renommez-le en `index.php`).

### 2\. Créer la Base de Données

Ouvrez votre gestionnaire de base de données (ex: phpMyAdmin) et exécutez la requête SQL suivante pour créer la table nécessaire :

```sql
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

### 3\. Configurer la connexion

Ouvrez le fichier `take.php` et modifiez les lignes de configuration DB :

```php
// --- CONFIGURATION BDD ---
$host = 'localhost';      // Adresse du serveur
$db   = 'collab_notes';   // Nom de la base créée
$user = 'root';           // Votre utilisateur SQL
$pass = '';               // Votre mot de passe SQL
```

### 4\. C'est prêt \!

Ouvrez votre navigateur et allez sur l'adresse de votre site (ex: `http://localhost/mon-doc`).
Vous pouvez maintenant partager cette URL avec vos collègues.

-----

## 🐳 Installation Docker (recommandé)

```bash
docker compose up --build
```

Puis ouvrir : http://localhost:8080

## 📖 Guide d'utilisation

L'interface est conçue pour être intuitive :

1.  **Créer un document :**

  * Cliquez sur **"Nouveau document"** dans la barre latérale.
  * Choisissez le type (Wiki, Note, Cours, Cahier des charges).

2.  **Ajouter du contenu :**

      * Cliquez sur le bouton **"+"** flottant en bas à droite.
      * Choisissez **Texte**, **Tableur Excel** ou **Tableau Flexible**.
      * *Astuce :* Si le document est vide, cliquez simplement au milieu de la page pour commencer à écrire.

3.  **Le Tableau Flexible :**

      * Cliquez sur les en-têtes gris pour renommer les colonnes.
      * Passez la souris sur le tableau pour voir apparaître les boutons **"+ Colonne"** et **"+ Ligne"** en bas.

4.  **Supprimer un bloc :**

      * Passez la souris sur un bloc.
      * Une icône **Poubelle rouge** 🗑️ apparaît à gauche du bloc. Cliquez pour supprimer.

-----

## 📦 Technologies utilisées

Ce projet utilise des librairies Open Source puissantes via CDN (pas de téléchargement requis) :

  * **Backend :** PHP (Natif) + MySQL (PDO).
  * **Frontend :** HTML5, CSS3, JavaScript (Vanilla).
  * **Éditeur Texte :** [Quill.js](https://quilljs.com/)
  * **Tableur :** [Jspreadsheet CE](https://bossanova.uk/jspreadsheet/)
  * **Icônes :** [FontAwesome](https://fontawesome.com/)

-----

## ⚠️ Limitations & Notes

  * **Conflits d'édition :** La synchronisation utilise un système de polling (toutes les 2,5 secondes). Si deux personnes modifient *exactement le même paragraphe* à la *même seconde*, la dernière sauvegarde l'emporte.
  * **Sécurité :** Pour une mise en production publique, il est recommandé d'ajouter un système d'authentification (login/mot de passe).

-----

**Développé avec ❤️ pour simplifier la collaboration.**
