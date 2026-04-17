# MZ SMS API

Drupal custom module providing a JSON API to manage `sms` nodes (create, read, update, delete, list, last).

---

## Content Type: `sms`

### Fields

| Field name | Type | Description |
|---|---|---|
| `body` | Text formatted, long | Message body (with summary) |
| `field_content` | Text plain, long | Plain text content |
| `field_date` | Date | Date of the SMS |
| `field_numero_destinataire` | Text plain | Recipient phone number |
| `field_numero_de_l_expediteur` | Text plain | Sender phone number |
| `field_raison` | Text plain, long | Reason / description |
| `field_nom` | Entity reference → `client` | Referenced client node |
| `field_type_action` | List (string) | Action type: `transfer`, `recu`, `depot` |
| `field_reference` | Text plain | Reference code |
| `field_current_solde` | Decimal (18,2) | Current balance |

---

## Endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/mz_sms/login` | Login user, returns bearer token |
| `GET` | `/api/mz_sms/sms` | List SMS (paginated) |
| `GET` | `/api/mz_sms/sms/last` | Last SMS insertion(s) |
| `GET` | `/api/mz_sms/sms/{nid}` | View one SMS |
| `POST` | `/api/mz_sms/sms` | Create SMS |
| `PUT\|PATCH` | `/api/mz_sms/sms/{nid}` | Update SMS |
| `DELETE` | `/api/mz_sms/sms/{nid}` | Delete SMS |

---

## Installation

```bash
drush en mz_sms_api -y
drush updb -y
drush cr
```

---

## Authentication

All SMS endpoints (except login) require a valid bearer token.

### Login and get token

`POST /api/mz_sms/login`

```json
{
  "name": "admin",
  "password": "your-password"
}
```

**Response:**

```json
{
  "status": true,
  "message": "Login successful",
  "token": "abc123...",
  "user": {
    "uid": 1,
    "name": "admin",
    "mail": "admin@example.com",
    "roles": ["administrator"]
  }
}
```

### Token delivery — 3 accepted methods

#### A) Authorization header (recommended)

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" ...
```

#### B) HTTP-only cookie `auth_token`

Set automatically by the login response. Works transparently with `withCredentials: true` in Axios.

#### C) `token` in query string or JSON body

```
GET /api/mz_sms/sms?token=YOUR_TOKEN
```

```json
{ "token": "YOUR_TOKEN", "field_content": "..." }
```

If all three methods fail the endpoint returns:

```json
{ "status": false, "message": "Not allowed" }
```

---

## API Reference

### 1) Create SMS

`POST /api/mz_sms/sms`

All fields are optional except `title` (auto-generated as `SMS YYYY-MM-DD HH:MM:SS` if omitted).

```bash
curl -X POST http://YOUR-BASE/api/mz_sms/sms \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
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
    "field_type_action": "transfer",
    "field_reference": "REF-20260414-001",
    "field_current_solde": "125000.50"
  }'
```

> `field_nom` accepts a plain node ID (`42`) or an object (`{"target_id": 42}`).  
> `field_type_action` allowed values: `transfer`, `recu`, `depot`.

**Response:**

```json
{
  "status": true,
  "message": "SMS created",
  "item": { "nid": 55, "title": "Transfert client Dupont", "..." }
}
```

---

### 2) List SMS

`GET /api/mz_sms/sms`

| Query param | Type | Default | Description |
|---|---|---|---|
| `limit` | int | 50 | Items per page |
| `page` | int | 0 | Page index (0-based) |
| `token` | string | — | Token fallback |

```bash
curl "http://YOUR-BASE/api/mz_sms/sms?limit=20&page=0" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "status": true,
  "total": 120,
  "page": 0,
  "limit": 20,
  "data": [
    {
      "nid": 55,
      "title": "Transfert client Dupont",
      "field_type_action": "transfer",
      "field_current_solde": "125000.50",
      "field_nom": { "target_id": 42, "title": "Dupont Jean" },
      "..."
    }
  ]
}
```

---

### 3) Last SMS insertion(s)

`GET /api/mz_sms/sms/last`

Returns the most recently created `sms` node(s), sorted by creation date descending.

| Query param | Type | Default | Description |
|---|---|---|---|
| `limit` | int | 1 | Number of latest records (max 50) |
| `token` | string | — | Token fallback |

```bash
# Last single SMS
curl "http://YOUR-BASE/api/mz_sms/sms/last" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Last 5 SMS
curl "http://YOUR-BASE/api/mz_sms/sms/last?limit=5" \
  -H "Authorization: Bearer YOUR_TOKEN"
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
      "body": { "value": "...", "summary": "...", "format": "basic_html" },
      "field_content": "Montant transféré : 50 000 Ar",
      "field_date": "2026-04-14",
      "field_numero_destinataire": "0340000000",
      "field_numero_de_l_expediteur": "0320000000",
      "field_raison": "Remboursement facture #1042",
      "field_reference": "REF-20260414-001",
      "field_current_solde": "125000.50",
      "field_type_action": "transfer",
      "field_nom": { "target_id": 42, "title": "Dupont Jean" },
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
curl "http://YOUR-BASE/api/mz_sms/sms/55" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### 5) Update SMS

`PUT /api/mz_sms/sms/{nid}` or `PATCH /api/mz_sms/sms/{nid}`

Only include the fields you want to update.

```bash
curl -X PATCH http://YOUR-BASE/api/mz_sms/sms/55 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "field_type_action": "recu",
    "field_current_solde": "75000.00",
    "field_raison": "Paiement reçu le 14/04/2026"
  }'
```

**Response:**

```json
{ "status": true, "message": "SMS updated", "item": { "..." } }
```

---

### 6) Delete SMS

`DELETE /api/mz_sms/sms/{nid}`

```bash
curl -X DELETE http://YOUR-BASE/api/mz_sms/sms/55 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{ "status": true, "message": "SMS deleted" }
```

---

## Notes

- The `/api/mz_sms/sms/last` route is registered **before** `{nid}` routes so it is never mistaken for a node ID.
- Login route is open (`_access: TRUE`); all other routes require a valid token.
- Token is stored in `field_api_token` on the Drupal user entity. Each login regenerates it, invalidating previous sessions for the same user.
- `field_nom` resolves the referenced client node title in all read responses (`view`, `list`, `last`).
- `field_type_action` is validated server-side; invalid values are silently ignored.
