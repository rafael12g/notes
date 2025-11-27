Voici un fichier `README.md` complet, professionnel et prêt à l'emploi pour ton projet. Il explique comment installer, configurer et utiliser l'outil.

Tu peux copier-coller ce contenu dans un fichier nommé **`README.md`** à la racine de ton projet.

-----

# 📝 CollabDocs V4 - Éditeur Collaboratif PHP

**CollabDocs** est une solution ultra-légère et open-source pour créer des documents collaboratifs en temps réel (style Google Docs ou Notion).

Il fonctionne avec **un seul fichier PHP** et une base de données **MySQL**. Pas de Node.js, pas de build complexe, pas d'installation lourde. Idéal pour les serveurs partagés ou les réseaux locaux.

## ✨ Fonctionnalités

  * **📄 Éditeur de Texte Riche :** Mise en forme complète (Gras, Italique, Titres, Listes, Couleurs) via *Quill.js*.
  * **📊 Tableur "Excel" :** Calculs, formules, redimensionnement et copier-coller via *Jspreadsheet*.
  * **📅 Tableau Flexible :** Créez vos propres tableaux avec colonnes personnalisables (idéal pour les plannings ou todo-lists).
  * **🔄 Collaboration Semi-Temps Réel :** Synchronisation automatique entre les utilisateurs (Polling intelligent).
  * **💾 Sauvegarde Automatique :** Plus besoin de bouton "Enregistrer", tout est stocké instantanément.
  * **🚀 Zéro Installation Client :** Tout se passe dans le navigateur.

-----

## 🛠️ Prérequis

  * Un serveur web (Apache, Nginx) ou local (WAMP, XAMPP, MAMP).
  * **PHP 7.4** ou supérieur.
  * **MySQL** ou MariaDB.

-----

## 🚀 Installation en 2 minutes

### 1\. Préparer les fichiers

Créez un dossier sur votre serveur (ex: `mon-doc`) et créez un fichier nommé `index.php` à l'intérieur. Collez-y tout le code source du projet.

### 2\. Créer la Base de Données

Ouvrez votre gestionnaire de base de données (ex: phpMyAdmin) et exécutez la requête SQL suivante pour créer la table nécessaire :

```sql
CREATE DATABASE IF NOT EXISTS collab_notes;
USE collab_notes;

CREATE TABLE IF NOT EXISTS collab_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL, -- Types: 'text', 'sheet', 'custom_table'
    content LONGTEXT,
    position INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 3\. Configurer la connexion

Ouvrez le fichier `index.php` et modifiez les lignes 15 à 20 avec vos propres informations :

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

## 📖 Guide d'utilisation

L'interface est conçue pour être intuitive :

1.  **Ajouter du contenu :**

      * Cliquez sur le bouton **"+"** flottant en bas à droite.
      * Choisissez **Texte**, **Tableur Excel** ou **Tableau Flexible**.
      * *Astuce :* Si le document est vide, cliquez simplement au milieu de la page pour commencer à écrire.

2.  **Le Tableau Flexible :**

      * Cliquez sur les en-têtes gris pour renommer les colonnes.
      * Passez la souris sur le tableau pour voir apparaître les boutons **"+ Colonne"** et **"+ Ligne"** en bas.

3.  **Supprimer un bloc :**

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

  * **Conflits d'édition :** La synchronisation utilise un système de "Polling" (vérification toutes les 2 secondes). Si deux personnes modifient *exactement le même paragraphe* à la *même seconde*, la dernière sauvegarde l'emporte.
  * **Sécurité :** Ce code est un prototype fonctionnel. Pour une mise en production publique, il est recommandé d'ajouter un système d'authentification (Login/Mot de passe).

-----

**Développé avec ❤️ pour simplifier la collaboration.**
