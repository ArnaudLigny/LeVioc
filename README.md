# Les évaluations Steam de LeVioc

Ce site web liste les [évaluations](https://levioc.narno.com/reviews/) de jeux vidéo rédigées par [LeVioc sur Steam](https://steamcommunity.com/id/LeVioc).

Cecil est utilisé pour générer un site statique à partir de ces évaluations, qui sont extraites automatiquement via le script `extract_reviews.php`.

## Structure du projet

- `pages/reviews/` : Fichiers Markdown des évaluations (un fichier par jeu)
- `assets/images/apps/` : Images d'en-tête des jeux (téléchargées depuis Steam)
- `layouts/` : Templates Twig pour Cecil
- `extract_reviews.php` : Script d'extraction automatique des évaluations

## Extraction des évaluations

Le script `extract_reviews.php` permet d'extraire automatiquement les évaluations depuis le profil Steam de LeVioc et de générer les fichiers Markdown correspondants.

### Prérequis

- **PHP 8.0+** (avec support des fonctions `file_get_contents`, `json_decode`)
- Connexion Internet (pour accéder à Steam et l'API Steam Store)

### Utilisation

```bash
# Test sur 1 page (10 évaluations)
php extract_reviews.php test

# Extraction complète (toutes les pages, ~134 évaluations)
php extract_reviews.php all

# Extraction d'un nombre spécifique de pages (1 à 14)
php extract_reviews.php 5
```

### Fonctionnement

Le script effectue les opérations suivantes :

1. **Récupération du HTML** : Télécharge les pages du profil Steam de LeVioc
2. **Extraction des données** : Pour chaque évaluation trouvée :
   - Récupère l'**App ID** du jeu
   - Obtient le **titre** via l'API Steam Store
   - Extrait le **temps de jeu** (en heures)
   - Parse la **date de publication**
   - Détecte la **recommandation** (positive/négative)
   - Extrait le **contenu** de l'évaluation
3. **Création des fichiers** :
   - Génère un fichier Markdown `{app_id}.md` dans `pages/reviews/`
   - Télécharge l'image d'en-tête du jeu dans `assets/images/apps/`
4. **Gestion intelligente** :
   - Évite les doublons
   - Ignore les fichiers existants
   - Respecte un délai entre les requêtes (rate limiting)

### Format des fichiers générés

Chaque évaluation est un fichier Markdown avec un en-tête YAML :

```yaml
---
title: "Nom du jeu"
date: 2016-10-01
recommended: true
playtime: 11.9
image: images/apps/201810.jpg
---
Contenu de l'évaluation...
```

### Détails techniques

- **API Steam Store** : Utilisée pour récupérer les noms officiels des jeux (`https://store.steampowered.com/api/appdetails`)
- **Extraction HTML** : Utilise des expressions régulières pour parser le HTML de Steam
- **Cache** : Les titres de jeux sont mis en cache en mémoire pour éviter les requêtes répétées
- **Délai** : 0,2 seconde entre chaque requête API pour éviter le rate limiting

## Développement

Ce site est généré avec [Cecil](https://cecil.app), un générateur de site statique.

```bash
# Installation de Cecil (via Scoop sur Windows)
scoop install cecil

# Génération du site
cecil build

# Serveur de développement
cecil serve
```
