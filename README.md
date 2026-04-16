# Bible Master - Plateforme de Gestion des Matchs

## 📋 Description

**Bible Master** est une application web de gestion et de suivi des matchs de Bible Quiz en temps réel. Elle permet aux administrateurs de créer, gérer et scorer des matchs, tandis que les utilisateurs peuvent consulter les scores en direct et les résultats des compétitions.

**Objectif:** Centraliser la gestion des compétitions bibliques avec suivi en temps réel des matchs et des scores pour les équipes en compétition.

---

## ✨ Fonctionnalités

### 👨‍💼 Espace Administrateur

| Fonctionnalité | Description |
|---|---|
| **Connexion Admin** | Authentification sécurisée avec username/password |
| **Dashboard** | Vue d'ensemble des statistiques (nombre de matchs, en cours, programmés, terminés) |
| **Création de Matchs** | Formulaire pour créer des matchs : sélection équipes, date, heure, statut |
| **Gestion des Scores** | Interface interactive pour entrer les scores et le statut des matchs |
| **Scoring en Temps Réel** | Page avec scoreboard en direct, 4 épreuves, boutons ± pour chaque équipe |
| **Journal des Scores** | Log de tous les évènements durant le match |
| **Publication des Matchs** | Contrôle de la visibilité des matchs côté utilisateur |
| **Gestion des Équipes** | 5 équipes bibliques prédéfinies |
| **Déconnexion Sécurisée** | Destruction de la session et redirection vers login |

**Pages Admin:**
- `/admin/login.php` - Connexion
- `/admin/dashboard.php` - Aperçu général
- `/admin/create_match.php` - Créer un match
- `/admin/set_score.php` - Gérer les scores (scoreboard temps réel)

### 👥 Espace Utilisateur

| Fonctionnalité | Description |
|---|---|
| **Affichage des Matchs en direct** | Section "Match en cours" avec statut EN COURS |
| **Matchs à venir** | Liste des matchs programmés |
| **Résultats antérieurs** | Affichage des matchs terminés avec scores finaux |
| **Affichage des Scores** | Scores formatés (X - Y) |
| **Mode Nuit** | Toggle pour activer/désactiver le mode sombre |
| **Leaderboard** | Espace réservé pour classement des équipes (extensible) |
| **Affichage Responsive** | Interface adaptée desktop/mobile |

**Pages User:**
- `/user/index.php` - Page d'accueil (matchs publics)

---

## 🗄️ Architecture Base de Données

### Tables

#### `admins`
```sql
- id: INT (clé primaire)
- username: VARCHAR(50) (unique)
- password_hash: VARCHAR(255)
- created_at: TIMESTAMP
```

#### `teams`
```sql
- id: INT (clé primaire)
- name: VARCHAR(100) (unique)
- created_at: TIMESTAMP
```

**Équipes prédéfinies:**
- Flammes de Jérusalem
- Harpe de David
- Étoiles de Bethléem
- Guerriers de Capharnaum
- Lumière de Galilée

#### `matches`
```sql
- id: INT (clé primaire)
- team1_id: INT (FK → teams)
- team2_id: INT (FK → teams)
- match_date: DATE
- match_time: TIME
- status: ENUM('Programme', 'En cours', 'Termine')
- score_team1: INT (NULL)
- score_team2: INT (NULL)
- published: TINYINT(1) [0|1]
- created_at: TIMESTAMP
- updated_at: TIMESTAMP (auto-update)
```

**Contraintes:**
- Team1 ≠ Team2 (pas de match contre soi-même)
- Clés étrangères avec CASCADE on UPDATE et RESTRICT on DELETE

---

## 🛡️ Système d'Authentification et Sécurité

### Authentification Admin
- **Système:** Session PHP + Cookies secure
- **Hash des mots de passe:** PASSWORD_DEFAULT (bcrypt)
- **Admin par défaut:**
  - Username: `admin`
  - Password: `admin123`
  - **⚠️ À modifier après installation en production**

### Protections
- ✅ **Session Hijacking:** session_regenerate_id() lors du login
- ✅ **CSRF Protection:** Tokens CSRF sur tous les formulaires
- ✅ **SQL Injection:** Prepared statements (PDO)
- ✅ **XSS Protection:** htmlspecialchars() sur tous les outputs
- ✅ **Type Safety:** declare(strict_types=1) sur tous les fichiers PHP

### Fonctions de sécurité
- `requireAdminAuth()` - Protège les pages admin
- `isAdminAuthenticated()` - Vérifie l'authentification
- `loginAdmin()` - Crée une session sécurisée
- `logoutAdmin()` - Détruit complètement la session

---

## 🚀 Installation et Configuration

### Configuration Locale (XAMPP)

#### 1. Prérequis
- XAMPP (PHP 7.4+ avec MySQL)
- Apache démarré
- MySQL démarré

#### 2. Base de Données

**Option A: Via phpMyAdmin**
1. Ouvrir http://localhost/phpmyadmin
2. Créer une base nommée `bible_master`
3. Importer le fichier `database/schema.sql`

**Option B: Via ligne de commande**
```bash
mysql -u root -p < database/schema.sql
```

#### 3. Configuration
La configuration locale est **automatique** :
- **Host:** 127.0.0.1
- **Port:** 3306
- **Base:** bible_master
- **User:** root
- **Password:** (vide par défaut XAMPP)

Le fichier `config/database.php` détecte automatiquement l'environnement local.

#### 4. Premier Accès
```
URL: http://localhost/Bible_Master/admin/login.php
Login: admin / admin123
```

### Configuration Production (InfinityFree)

#### 1. Variables d'Environnement
Créer un fichier `.env` ou configurer les variables système:
```
APP_ENV=production
DB_HOST=sql211.infinityfree.com
DB_NAME=if0_41644137_biblemaster
DB_USER=if0_41644137
DB_PASS=your_password_here
DB_PORT=3306
```

#### 2. Upload de la Base
1. Accédez à phpMyAdmin InfinityFree
2. Importer `database/schema.sql`

#### 3. Fichiers à Mettre à Jour
- Remplacer le mot de passe admin par défaut
- Vérifier les permissions des dossiers (uploads, sessions)

---

## 📁 Structure du Projet

```
Bible_Master/
├── admin/                          # Espace administrateur
│   ├── login.php                  # Page de connexion
│   ├── logout.php                 # Déconnexion
│   ├── dashboard.php              # Tableau de bord
│   ├── create_match.php           # Création de matchs
│   ├── set_score.php              # Gestion des scores (scoreboard temps réel)
│   ├── includes/
│   │   ├── auth.php               # Fonctions d'authentification
│   │   └── csrf.php               # Protection CSRF
│   ├── *.css                      # Styles spécifiques admin
│   └── *.html                     # Prototypes (référence)
│
├── user/
│   └── index.php                  # Page publique des matchs
│
├── config/
│   ├── database.php               # Connexion BD (local/prod automatique)
│   └── repositories.php           # Fonctions de requêtes BD
│
├── database/
│   └── schema.sql                 # Schéma SQL initial
│
├── css/
│   └── style.css                  # Styles globaux
│
└── README.md                       # Cette documentation
```

---

## 🎯 Pages et Fonctionnalités Détaillées

### Admin

#### 1. Login (`/admin/login.php`)
- Forme élégante avec gradient teal/blanc
- Validation des identifiants
- Message d'erreur attractif
- Redirection vers dashboard si authentifié

#### 2. Dashboard (`/admin/dashboard.php`)
- **Statistiques live:** Nombre de matchs (total, en cours, programmés, terminés)
- **Raccourcis rapides:** Boutons vers création de matchs et gestion des scores
- **Derniers matchs:** Liste les 8 derniers matchs avec statut et visibilité
- **Navigation:** Lien vers gestion des scores ou déconnexion

#### 3. Create Match (`/admin/create_match.php`)
- **Formulaire:**
  - Sélection équipe 1
  - Sélection équipe 2
  - Date du match
  - Heure du match
  - Statut (radio buttons: Programme, En cours, Terminé)
- **Validation:** Vérification des équipes différentes
- **Résultat:** Affichage du match créé
- **CSRF Protection:** Token sur le formulaire

#### 4. Set Score (`/admin/set_score.php`) - ⭐ Scoreboard Temps Réel
**Interface principale de scoring interactive:**
- **Topbar:** Affichage du statut (LIVE MATCH / MATCH TERMINÉ)
- **Scoreboard:**
  - Affichage des noms des deux équipes
  - Score global (0 — 0)
  - Barres colorées (teal/bleu)
- **4 Épreuves:**
  - Tirée l'épée
  - Question Éclair
  - Identification
  - Question relais
- **Pour chaque épreuve:**
  - Boutons +/- pour team A
  - Boutons +/- pour team B
  - Affichage du score de l'épreuve
  - Bouton "Valider" pour enregistrer
- **Boutons principaux:**
  - "Start" - Démarrer le match (active les contrôles)
  - "Terminer" - Arrêter le match (confirmation, affiche le résultat final)
- **Journal (Log):**
  - Enregistrement automatique de tous les scores
  - Logs système (démarrage, arrêt, résultat)
- **Toast:** Notification "Score enregistré" (auto-hide 2s)
- **Modes desactivation:** Contrôles désactivés avant le démarrage

### User

#### Page d'Accueil (`/user/index.php`)
- **Header:** Logo, titre, toggle mode nuit
- **Match en cours:** Section dynamique des matchs EN COURS
- **À venir:** Grille des matchs PROGRAMME
- **Résultats antérieurs:** Matchs TERMINE
- **Chaque match affiche:**
  - Noms des deux équipes
  - Date et heure
  - Score actuel (ou "Score non disponible")
  - Statut (badge couleur: live, upcoming, done)

---

## 🔧 Intégration des Composants

### Flow de Données

```
User Visit /user/index.php
    ↓
fetchMatches($pdo, null, true) [only published=1]
    ↓
Filter by Status (En cours, Programme, Termine)
    ↓
Display with Scores & Status Badges
    ↓
Output HTML + CSS

Admin Login → Dashboard → Create Match OR Set Score
    ↓
Database Updates
    ↓
Changes reflected on User Side (next page load)
```

### Fonctions Principales

**Authentification:**
- `requireAdminAuth()` - Protège l'accès admin
- `isAdminAuthenticated()` - Vérifie la session
- `ensureDefaultAdmin($pdo)` - Crée l'admin par défaut si vide

**Base de Données:**
- `fetchMatches($pdo, $status, $onlyPublished)` - Récupère matchs filtrés
- `fetchTeams($pdo)` - Liste toutes les équipes
- `countMatchesByStatus($pdo)` - Stats par statut
- `fetchMatchById($pdo, $id)` - Récupère un match spécifique

**Sécurité:**
- `csrfToken()` - Génère/récupère le token CSRF
- `validateCsrfOrFail($token)` - Valide le token

---

## ⚙️ Environnements

### Local (XAMPP)
- **Activé par défaut** si APP_ENV n'est pas défini
- **Valeurs par défaut:**
  - Host: 127.0.0.1
  - User: root
  - Password: (vide)
  - Base: bible_master

### Production (InfinityFree)
- **Activé avec:** `APP_ENV=production`
- **Nécessite:** Toutes les variables DB_ renseignées
- **Domaine:** sql211.infinityfree.com

---

## 📝 Workflow Type

### Pour un Admin

1. **Première visite:** Se connecter avec admin / admin123
2. **Création match:** Dashboard → Créer → Remplir formulaire → Valider
3. **Scoring:** Dashboard → Gérer → Start match → Modifier scores → Terminer
4. **Publication:** Les matchs sont visibles user automatiquement (published=1 par défaut)

### Pour un User

1. Accès direct à `/user/index.php` (pas d'auth)
2. Voir les matchs en direct
3. Consulter les scores
4. Activer mode nuit si souhaité

---

## 🚨 Notes de Sécurité

- ⚠️ **Admin par défaut:** Changer `admin123` immédiatement avant production
- ⚠️ **Mots de passe:** Utiliser des mots de passe forts (12+ caractères, mixtes)
- ⚠️ **HTTPS:** En production, utiliser un certificat SSL (obligatoire)
- ⚠️ **CORS:** Configurer correctement les headers si API externe
- ✅ **Sessions:** Timeout automatique après inactivité (configurable)
- ✅ **DB:** Sauvegardes régulières recommandées

---

## 🐛 Système d'Erreurs

| Erreur | Cause | Solution |
|---|---|---|
| "Connexion impossible à la BD" | MySQL non démarré ou identifiants erronés | Vérifier XAMPP MySQL, logs DB |
| "Identifiants invalides" | Username/password incorrect | Vérifier les valeurs, réinitialiser si nécessaire |
| "Session invalide" | CSRF token expiré ou données corrompues | Rafraîchir la page et retenter |
| Scores ne s'affichent pas côté user | Match non published ou page en cache | Vérifier flag published=1, vider cache |

---

## 🎨 Styles et Thème

**Palette couleur:**
- Teal: #1D9E75 (actions principales)
- Blue: #378ADD (accents)
- Red: #E24B4A (statuts urgents)
- Gradient: #6f7793 → #ffffff (background)
- Dark: #16181f, #1e2028 (mode nuit)
- Blanc: #e8e9ee (texte clair)

**Typographies:**
- `Sora` (sans-serif) - Corps et titres
- `DM Mono` (monospace) - Scores et données techniques

---

## 📞 Support et Maintenance

**Logs d'erreur:** Voir `/error_log` côté serveur
**Base de données:** Vérifier les indices (idx_match_status, idx_match_datetime)
**Performance:** Les fichiers statiques (CSS/JS) sont cachés par le navigateur

---

## 📄 Licence

Projet Bible Master © 2026. Tous droits réservés.

---

**Version:** 1.0.0  
**Dernière mise à jour:** 13 Avril 2026  
**Auteur:** Bible Master Dev Team
