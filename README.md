# MZ SMS API

Drupal custom module that exposes a JSON API for **`sms`** nodes (create, read, update, delete, list, latest). Authentication uses the **`field_api_token`** value on the Drupal user account and requires the **`sms_manager`** role.

---

## Requirements

- Drupal **9** or **10**
- Module dependencies: `node`, `user`, `field`, `text`, `datetime`

On enable / updates, the module ensures:

- Content type **`sms`** and its fields (see below)
- User field **`field_api_token`** (`string_long`, case-sensitive)
- Role **`sms_manager`** (machine name: `sms_manager`)

Assign **SMS manager** to accounts that may call the API, and set a secret **API token** on each user (`field_api_token`). The token is **not** generated automatically by login; store it in the user profile (or via custom logic).

---

## Content type: `sms`

### Fields

| Field name | Type | Description |
|------------|------|-------------|
| `body` | Text (formatted, long) | Body with optional summary |
| `field_content` | Plain text, long | Plain text content |
| `field_date` | Date | Date of the SMS |
| `field_numero_destinataire` | Text | Recipient number |
| `field_numero_de_l_expediteur` | Text | Sender number |
| `field_raison` | Plain text, long | Reason / notes |
| `field_nom` | Entity reference → `client` | Client node |
| `field_user` | Entity reference → **user** | Related Drupal user |
| `field_type_action` | List (string) | One of: `transfer`, `recu`, `depot` |
| `field_current_solde` | Decimal (18,2) | Current balance |

The `field_reference` field was removed in a prior update; do not rely on it in payloads.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/mz_sms/login` | Password login (requires `sms_manager`); returns user info and `field_api_token` |
| `GET` | `/api/mz_sms/sms` | List SMS (paginated) |
| `GET` | `/api/mz_sms/sms/last` | Latest SMS node(s) by creation time |
| `GET` | `/api/mz_sms/sms/{nid}` | View one SMS |
| `POST` | `/api/mz_sms/sms` | Create SMS |
| `PUT` / `PATCH` | `/api/mz_sms/sms/{nid}` | Update SMS |
| `DELETE` | `/api/mz_sms/sms/{nid}` | Delete SMS |

Route **`/api/mz_sms/sms/last`** is registered before **`/api/mz_sms/sms/{nid}`** so `last` is never parsed as a node ID.

---

## Installation

```bash
drush en mz_sms_api -y
drush updb -y
drush cr
```

Then in the admin UI:

1. Assign the **SMS manager** role (`sms_manager`) to API users.
2. Set **`field_api_token`** on each account to a unique secret string.

---

## Authentication and roles

Every SMS API action (including login) requires the **`sms_manager`** role.

For **`POST /api/mz_sms/login`**, valid credentials without that role yield **403** with `"SMS manager role required"`.

For all other endpoints, the caller must prove possession of the API token configured on the user:

- The server looks up an **active** user whose **`field_api_token`** matches the submitted value **and** who has **`sms_manager`**.

### Sending the token

Only these are supported (checked in order until a non-empty token is found):

| Source | How |
|--------|-----|
| Query string | `?token=YOUR_SECRET` |
| Form POST | Body parameter `token` (`application/x-www-form-urlencoded` or multipart) |
| JSON body | Property `"token"` alongside your other JSON fields |

**Not** supported: `Authorization: Bearer`, cookies named `auth_token`, or a separate JSON key `auth_token`.

If authentication fails, responses typically look like:

```json
{ "status": false, "message": "Not allowed" }
```

with HTTP **401**.

---

## Login

`POST /api/mz_sms/login`

**Request body (JSON):**

```json
{
  "name": "sms_operator",
  "password": "your-password"
}
```

**Successful response (200):**

```json
{
  "status": true,
  "message": "Login successful",
  "field_api_token": "the-value-from-user-profile-or-null",
  "user": {
    "uid": 5,
    "name": "sms_operator",
    "mail": "operator@example.com",
    "roles": ["authenticated", "sms_manager"]
  }
}
```

`field_api_token` mirrors the **`field_api_token`** field on the user ( **`null`** if empty). Use that same value as **`token`** on subsequent API calls (query or JSON body).

**Typical errors:**

| HTTP | Meaning |
|------|---------|
| 400 | Invalid JSON or missing `name` / `password` |
| 401 | Wrong username/password or inactive user |
| 403 | Valid login but account lacks **`sms_manager`** |

---

## Examples

Assume the site runs at `https://drupal.local` (replace with your origin). Replace `YOUR_SECRET` with the value saved in **`field_api_token`** for your user.

### Bash: login, then call the API with `token` in the URL

```bash
BASE="https://drupal.local"

# 1) Login (JSON body is only name + password)
curl -s -X POST "$BASE/api/mz_sms/login" \
  -H "Content-Type: application/json" \
  -d '{"name":"sms_operator","password":"your-password"}'

# Response includes field_api_token — use that same string as TOKEN below.

TOKEN="YOUR_SECRET"

# 2) List SMS (token in query string — good for GET)
curl -s "$BASE/api/mz_sms/sms?limit=10&page=0&token=$TOKEN"

# 3) Latest SMS (published nodes only)
curl -s "$BASE/api/mz_sms/sms/last?limit=3&token=$TOKEN"

# 4) Create SMS (token can be in query and/or repeated inside JSON)
curl -s -X POST "$BASE/api/mz_sms/sms?token=$TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"token\":\"$TOKEN\",\"title\":\"Test API\",\"field_content\":\"Hello\",\"field_type_action\":\"transfer\"}"
```

### JavaScript (`fetch`): login then request with token

```javascript
const base = 'https://drupal.local';

// Login — store field_api_token from JSON (e.g. localStorage in a real app)
const loginRes = await fetch(`${base}/api/mz_sms/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ name: 'sms_operator', password: 'your-password' }),
});
const loginJson = await loginRes.json();
const apiToken = loginJson.field_api_token;
if (!apiToken) {
  throw new Error('Set field_api_token on the user in Drupal first.');
}

const tokenQuery = new URLSearchParams({ token: apiToken });

const listRes = await fetch(`${base}/api/mz_sms/sms?${tokenQuery}&limit=20&page=0`);
const listJson = await listRes.json();

const createRes = await fetch(`${base}/api/mz_sms/sms?${tokenQuery}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    token: apiToken,
    title: 'SMS from fetch',
    field_content: 'Body text',
    field_type_action: 'transfer',
  }),
});
const created = await createRes.json();
```

### Token only in JSON body (no query string)

For **`POST`** / **`PATCH`** you can send **`token`** inside the JSON only:

```bash
curl -s -X POST "https://drupal.local/api/mz_sms/sms" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "YOUR_SECRET",
    "title": "Sans query string",
    "field_content": "OK"
  }'
```

---

## API reference

Replace `YOUR-BASE` with your site origin. Pass **`token`** as a query parameter or inside JSON when using `curl` examples below.

### 1) Create SMS

`POST /api/mz_sms/sms`

Optional fields use defaults where documented in code (e.g. title auto-generated if omitted).

```bash
curl -X POST "http://YOUR-BASE/api/mz_sms/sms?token=YOUR_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "YOUR_SECRET",
    "title": "Transfert client Dupont",
    "body": {
      "value": "<p>Confirmation de transfert</p>",
      "summary": "Transfert",
      "format": "basic_html"
    },
    "field_content": "Montant transféré : 50 000 Ar",
    "field_date": "2026-04-14",
    "field_numero_destinataire": "0340000000",
    "field_numero_de_l_expediteur": "0320000000",
    "field_raison": "Remboursement facture #1042",
    "field_nom": 42,
    "field_user": "some_drupal_username",
    "field_type_action": "transfer",
    "field_current_solde": "125000.50"
  }'
```

`field_nom` accepts a client node ID or `{"target_id": 42}`.  
`field_user` accepts a numeric uid, username string, or `{"target_id": uid}` (username may trigger user creation in server logic—see controller).  
`field_type_action` must be one of **`transfer`**, **`recu`**, **`depot`**; invalid values are ignored.

**Response (201):**

```json
{
  "status": true,
  "message": "SMS created",
  "item": { "nid": 55, "title": "…", "field_user": { "target_id": 3, "name": "…" }, "…": "…" }
}
```

---

### 2) List SMS

`GET /api/mz_sms/sms`

| Query param | Type | Default | Description |
|-------------|------|---------|-------------|
| `limit` | int | 50 | Page size (max 200) |
| `page` | int | 0 | Zero-based page index |
| `token` | string | — | API token (if not sent elsewhere) |

```bash
curl "http://YOUR-BASE/api/mz_sms/sms?limit=20&page=0&token=YOUR_SECRET"
```

**Response:**

```json
{
  "status": true,
  "total": 120,
  "page": 0,
  "limit": 20,
  "items": [
    {
      "nid": 55,
      "title": "Transfert client Dupont",
      "field_type_action": "transfer",
      "field_current_solde": "125000.50",
      "field_nom": { "target_id": 42, "title": "Dupont Jean" },
      "field_user": { "target_id": 3, "name": "operator" },
      "created": 1744617600,
      "changed": 1744617600
    }
  ]
}
```

---

### 3) Last SMS insertion(s)

`GET /api/mz_sms/sms/last`

Returns published **`sms`** nodes only (`status = 1`), newest first.

| Query param | Type | Default | Description |
|-------------|------|---------|-------------|
| `limit` | int | 1 | Number of rows (max 50) |
| `token` | string | — | API token |

```bash
curl "http://YOUR-BASE/api/mz_sms/sms/last?limit=5&token=YOUR_SECRET"
```

**Response:**

```json
{
  "status": true,
  "count": 1,
  "data": [
    {
      "nid": 55,
      "title": "Transfert client Dupont",
      "body": { "value": "…", "summary": "…", "format": "basic_html" },
      "field_content": "…",
      "field_date": "2026-04-14",
      "field_nom": { "target_id": 42, "title": "Dupont Jean" },
      "field_user": { "target_id": 3, "name": "operator" },
      "created": 1744617600,
      "created_formatted": "2026-04-14 10:00:00",
      "uid": 1
    }
  ]
}
```

---

### 4) View SMS

`GET /api/mz_sms/sms/{nid}`

```bash
curl "http://YOUR-BASE/api/mz_sms/sms/55?token=YOUR_SECRET"
```

---

### 5) Update SMS

`PUT` or `PATCH` `/api/mz_sms/sms/{nid}`

Send only the fields to change.

```bash
curl -X PATCH "http://YOUR-BASE/api/mz_sms/sms/55?token=YOUR_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "YOUR_SECRET",
    "field_type_action": "recu",
    "field_current_solde": "75000.00",
    "field_raison": "Paiement reçu le 14/04/2026"
  }'
```

---

### 6) Delete SMS

`DELETE /api/mz_sms/sms/{nid}`

```bash
curl -X DELETE "http://YOUR-BASE/api/mz_sms/sms/55?token=YOUR_SECRET"
```

---

## Notes

- **`field_api_token`** is stored on the user and compared verbatim (case-sensitive). Manage rotation in Drupal or custom code; the module does not auto-regenerate it on login.
- **`field_nom`** and **`field_user`** are expanded with titles / usernames in read responses (`list`, `view`, `last`).
- **`field_type_action`** is validated server-side; unknown values are ignored on write.
