# MZ SMS API

Drupal custom module that provides a JSON API to manage `sms` nodes (create, read, update, delete, list).

## Features

- Creates content type: `sms`
- Ensures fields:
  - `body` (Text formatted, long, with summary)
  - `field_content` (Text plain, long)
  - `field_date` (Date)
  - `field_numero_destinataire` (Text plain)
  - `field_numero_de_l_expediteur` (Text plain)
- Provides REST-like endpoints:
  - `POST /api/mz_sms/login` (login user)
  - `GET /api/mz_sms/sms` (list)
  - `GET /api/mz_sms/sms/{nid}` (view)
  - `POST /api/mz_sms/sms` (create)
  - `PUT|PATCH /api/mz_sms/sms/{nid}` (update)
  - `DELETE /api/mz_sms/sms/{nid}` (delete)

## Installation

```bash
drush en mz_sms_api -y
drush updb -y
drush cr
```

## API

### 0) Login user

`POST /api/mz_sms/login`

```json
{
  "name": "admin",
  "password": "your-password"
}
```

Response includes `token` when available. Use it as:

`Authorization: Bearer <token>`

Fallbacks accepted by SMS endpoints:
- cookie `auth_token`
- request `token` (POST/query/body JSON)

### 1) Create SMS

`POST /api/mz_sms/sms`

```json
{
  "title": "SMS test",
  "body": {
    "value": "Texte formate",
    "summary": "Resume",
    "format": "basic_html"
  },
  "field_content": "Texte brut long",
  "field_date": "2026-04-13",
  "field_numero_destinataire": "0340000000",
  "field_numero_de_l_expediteur": "0320000000"
}
```

### 2) List SMS

`GET /api/mz_sms/sms?limit=50&page=0`

### 3) View SMS

`GET /api/mz_sms/sms/{nid}`

### 4) Update SMS

`PUT /api/mz_sms/sms/{nid}` or `PATCH /api/mz_sms/sms/{nid}`

```json
{
  "field_content": "Nouveau contenu",
  "field_date": "2026-05-01"
}
```

### 5) Delete SMS

`DELETE /api/mz_sms/sms/{nid}`

## Notes

- Login route is open (`_access: TRUE`), but SMS CRUD routes require a valid token.
- If bearer header and cookie both fail, send `token` in request payload/query.
- If all token methods fail, API returns `Not allowed`.
