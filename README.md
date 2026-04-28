# MZ SMS API

Module Drupal personnalisé : API JSON pour le type de contenu **`sms`** (création, lecture, mise à jour, suppression, liste, derniers par date métier).

**Authentification** : le compte Drupal doit avoir le rôle **`sms_manager`** et une valeur secrète dans le champ utilisateur **`field_api_token`**. Le client envoie ce secret en paramètre **`token`** (query, formulaire ou JSON) — pas de cookie ni d’en-tête `Authorization: Bearer` géré par ce module.

---

## Prérequis

- Drupal **9** ou **10**
- Dépendances : `node`, `user`, `field`, `text`, `datetime`

Après activation / mises à jour, le module assure notamment :

- le type **`sms`** et ses champs (voir ci-dessous) ;
- le champ utilisateur **`field_api_token`** (`string_long`, sensible à la casse) ;
- le rôle **`sms_manager`**.

1. Attribuer le rôle **SMS manager** aux comptes API.  
2. Renseigner **`field_api_token`** sur chaque compte (interface Drupal ou autre). La valeur n’est **pas** générée automatiquement à la connexion : le login la **renvoie** telle qu’enregistrée (ou `null` si vide).

```bash
drush en mz_sms_api -y
drush updb -y
drush cr
```

Les mises à jour de base (ex. `field_date` en datetime date+heure, suppression de `field_heure`) s’appliquent via **`drush updb`**.

---

## Champs du type `sms`

| Nom | Type | Description |
|-----|------|-------------|
| `body` | Texte formaté (long) | Corps avec résumé optionnel |
| `field_content` | Texte brut (long) | Contenu texte |
| `field_date` | **Datetime** | Définie **manuellement** dans le JSON. Aucune normalisation automatique côté module : utiliser un format valide pour le champ datetime Drupal (souvent `Y-m-d\TH:i:s`). Anti-doublon par **égalité exacte** ; tri **`GET …/last`** |
| `field_numero_destinataire` | Texte | Numéro destinataire |
| `field_numero_de_l_expediteur` | Texte | Numéro expéditeur |
| `field_raison` | Texte long | Raison / détail |
| `field_nom` | Référence → bundle **`client`** | Client |
| `field_user` | Référence → **user** | Utilisateur Drupal lié |
| `field_type_action` | Liste | `transfer`, `recu`, `depot` |
| `field_current_solde` | Décimal | Solde |

Le champ **`field_heure`** a été retiré : date et heure sont **unifiées** dans **`field_date`**.

---

## Points de terminaison

| Méthode | Chemin | Rôle |
|---------|--------|------|
| `POST` | `/api/mz_sms/login` | Connexion ; réponse inclut `field_api_token` si renseigné |
| `GET` | `/api/mz_sms/sms` | Liste paginée (tri par **date de création** Drupal, `created` DESC) |
| `GET` | `/api/mz_sms/sms/last` | Derniers SMS **par `field_date`** (instant métier) DESC, puis `nid` DESC ; uniquement les nœuds **publiés** avec `field_date` non vide |
| `GET` | `/api/mz_sms/sms/{nid}` | Détail d’un SMS |
| `POST` | `/api/mz_sms/sms` | Création |
| `PUT` / `PATCH` | `/api/mz_sms/sms/{nid}` | Mise à jour |
| `DELETE` | `/api/mz_sms/sms/{nid}` | Suppression |

La route **`/api/mz_sms/sms/last`** est déclarée **avant** `/api/mz_sms/sms/{nid}` pour que `last` ne soit pas interprété comme un `nid`.

---

## Authentification et rôle `sms_manager`

Toutes les actions API (y compris le login) exigent le rôle **`sms_manager`**.

- Login incorrect → **401**. Compte sans ce rôle après mot de passe valide → **403**, message du type `SMS manager role required`.
- Autres routes : utiliser **`token`** égal à la valeur de **`field_api_token`** du compte (utilisateur actif + `sms_manager`). Sinon → **401** avec `{ "status": false, "message": "Not allowed" }`.

**Où passer `token` (ordre de lecture côté serveur)** :

| Source | Détail |
|--------|--------|
| Query | `?token=…` |
| Corps formulaire | paramètre `token` |
| JSON | propriété `"token"` |

Non pris en charge : `Authorization: Bearer`, cookie `auth_token`, clé JSON `auth_token`.

---

## Login — `POST /api/mz_sms/login`

```json
{ "name": "sms_operator", "password": "your-password" }
```

Réponse réussie (**200**) :

```json
{
  "status": true,
  "message": "Login successful",
  "field_api_token": "secret-or-null",
  "user": {
    "uid": 5,
    "name": "sms_operator",
    "mail": "operator@example.com",
    "roles": ["authenticated", "sms_manager"]
  }
}
```

Réutiliser **`field_api_token`** comme **`token`** sur les autres appels.

---

## Champ `field_date` (API)

- Envoyer **`field_date`** dans le corps JSON avec la chaîne exacte à enregistrer (espaces de tête/queue supprimés par le module, rien d’autre).
- Aucun **formatage automatique** (pas de normalisation ISO, pas de date déduite depuis **`field_content`**). Le client est responsable du format compatible avec le stockage Drupal datetime.
- Sans **`field_date`** dans la requête de création, le nœud peut être créé sans cette valeur ; l’**anti-doublon** ne s’applique alors pas (pas de comparaison).

---

## Création — `POST /api/mz_sms/sms`

### Anti-doublon

Pour un même auteur (`uid`), si un autre nœud **`sms`** existe déjà avec **exactement la même valeur `field_date`** (après trim) que dans la requête de création, la réponse est **200** (pas 201) :

```json
{
  "status": true,
  "duplicate": true,
  "message": "SMS already recorded",
  "item": { … }
}
```

Sinon création normale **201** :

```json
{
  "status": true,
  "message": "SMS created",
  "duplicate": false,
  "item": { … }
}
```

La comparaison utilise uniquement **`field_date`** présent dans le JSON de création (pas le texte de **`field_content`**).

---

## Liste — `GET /api/mz_sms/sms`

Paramètres query : `limit` (défaut 50, max 200), `page` (défaut 0), `token`.

Réponse : `status`, `total`, `page`, `limit`, **`items`** (pas `data`). Tri interne : **`created` DESC** (ordre d’insertion Drupal).

---

## Derniers par date métier — `GET /api/mz_sms/sms/last`

Paramètres : `limit` (défaut 1, max 50), `token`.

- Nœuds **`status = 1`** (publiés).
- **`field_date` IS NOT NULL** (les SMS sans date métier sont exclus).
- Tri : **`field_date` DESC**, puis **`nid` DESC**.

Réponse : `count`, **`data`** (tableau d’objets sérialisés comme ailleurs, avec `created_formatted` et `uid` pour compatibilité).

---

## Exemples rapides

```bash
BASE="https://example.com"
TOKEN="YOUR_SECRET"

curl -s "$BASE/api/mz_sms/login" -H "Content-Type: application/json" \
  -d '{"name":"sms_operator","password":"***"}'

# Liste (tri created)
curl -s "$BASE/api/mz_sms/sms?limit=10&page=0&token=$TOKEN"

# Derniers par field_date (pas par created)
curl -s "$BASE/api/mz_sms/sms/last?limit=3&token=$TOKEN"

curl -s -X POST "$BASE/api/mz_sms/sms?token=$TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"token\":\"$TOKEN\",\"field_date\":\"2026-04-28T11:07:00\",\"field_content\":\"…\",\"field_type_action\":\"recu\"}"
```

---

## Réponses JSON (rappel)

Les lectures renvoient des objets **`sms`** avec notamment : `nid`, `title`, `body`, `field_content`, `field_date`, champs numériques / références (`field_nom`, `field_user` avec titres résolus), `created`, `changed`.

---

## Notes

- **`field_type_action`** : seules les valeurs listées sont acceptées ; les autres sont ignorées à l’écriture.
- **`field_nom`** / **`field_user`** : en lecture, titres et noms d’utilisateur sont enrichis dans la réponse.
- **`field_api_token`** : rotation et confidentialité gérées côté Drupal ; le module ne régénère pas le secret au login.
