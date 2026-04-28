<?php

namespace Drupal\mz_sms_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * CRUD API for sms content type.
 */
class SmsApiController extends ControllerBase {

  /**
   * Login API for Drupal users.
   *
   * Payload:
   * - name: username
   * - password: plain password
   */
  public function loginUser(Request $request) {
    $data = $this->decodeJson($request);
    if ($data === NULL) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Invalid JSON body',
      ], 400);
    }

    $name = trim((string) ($data['name'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    if ($name === '' || $password === '') {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'name and password are required',
      ], 400);
    }

    $user = user_load_by_name($name);
    if (!$user || !$user->isActive()) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Invalid credentials',
      ], 401);
    }

    $password_hasher = \Drupal::service('password');
    if (!$password_hasher->check($password, $user->getPassword())) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Invalid credentials',
      ], 401);
    }

    user_login_finalize($user);

    $response_data = [
      'status' => TRUE,
      'message' => 'Login successful',
      'user' => [
        'uid' => (int) $user->id(),
        'name' => $user->getAccountName(),
        'mail' => $user->getEmail(),
        'roles' => $user->getRoles(),
      ],
    ];
    $response = new JsonResponse($response_data);

    // Stable permanent token for cookie, auth_token, and token (KeyValue-backed).
    $permanent = $this->getOrCreatePermanentAuthToken($user);
    if (is_string($permanent) && $permanent !== '') {
      $response_data['auth_token'] = $permanent;
      $response_data['token'] = $permanent;
      $response->setData($response_data);
      // Long-lived cookie (10 years); value does not rotate unless KeyValue entry is cleared.
      $response->headers->setCookie(new Cookie(
        'auth_token',
        $permanent,
        time() + (10 * 365 * 24 * 3600),
        '/',
        NULL,
        FALSE,
        TRUE,
        FALSE,
        'Lax'
      ));
    }

    return $response;
  }

  /**
   * List sms nodes with optional pagination.
   */
  public function listSms(Request $request) {
    if (!$this->getAuthenticatedUserFromRequest($request)) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Not allowed',
      ], 401);
    }

    $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
    $page = max(0, (int) $request->query->get('page', 0));
    $offset = $page * $limit;

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'sms')
      ->accessCheck(FALSE)
      ->sort('created', 'DESC');
    $total = (clone $query)->count()->execute();
    $nids = $query->range($offset, $limit)->execute();

    $items = [];
    if (!empty($nids)) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $items[] = $this->serializeSmsNode($node);
      }
    }

    return new JsonResponse([
      'status' => TRUE,
      'page' => $page,
      'limit' => $limit,
      'total' => (int) $total,
      'items' => $items,
    ]);
  }

  /**
   * View one sms node.
   */
  public function viewSms($nid) {
    if (!$this->getAuthenticatedUserFromRequest(\Drupal::request())) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Not allowed',
      ], 401);
    }

    $node = Node::load((int) $nid);
    if (!$this->isSmsNode($node)) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'SMS not found',
      ], 404);
    }

    return new JsonResponse([
      'status' => TRUE,
      'item' => $this->serializeSmsNode($node),
    ]);
  }

  /**
   * Create sms node.
   */
  public function createSms(Request $request) {
    $auth_user = $this->getAuthenticatedUserFromRequest($request);
    if (!$auth_user) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Not allowed',
      ], 401);
    }

    $data = $this->decodeJson($request);
    if ($data === NULL) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Invalid JSON body',
      ], 400);
    }

    try {
      $node = Node::create([
        'type' => 'sms',
        'title' => trim((string) ($data['title'] ?? 'SMS ' . date('Y-m-d H:i:s'))),
        'uid' => (int) $auth_user->id(),
      ]);

      $this->applyPayload($node, $data);
      $node->save();

      return new JsonResponse([
        'status' => TRUE,
        'message' => 'SMS created',
        'item' => $this->serializeSmsNode($node),
      ], 201);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Create failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Update sms node.
   */
  public function updateSms($nid, Request $request) {
    if (!$this->getAuthenticatedUserFromRequest($request)) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Not allowed',
      ], 401);
    }

    $node = Node::load((int) $nid);
    if (!$this->isSmsNode($node)) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'SMS not found',
      ], 404);
    }

    $data = $this->decodeJson($request);
    if ($data === NULL) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Invalid JSON body',
      ], 400);
    }

    try {
      $this->applyPayload($node, $data);
      $node->save();

      return new JsonResponse([
        'status' => TRUE,
        'message' => 'SMS updated',
        'item' => $this->serializeSmsNode($node),
      ]);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Update failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Delete sms node.
   */
  public function deleteSms($nid) {
    if (!$this->getAuthenticatedUserFromRequest(\Drupal::request())) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Not allowed',
      ], 401);
    }

    $node = Node::load((int) $nid);
    if (!$this->isSmsNode($node)) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'SMS not found',
      ], 404);
    }

    try {
      $node->delete();
      return new JsonResponse([
        'status' => TRUE,
        'message' => 'SMS deleted',
      ]);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Delete failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Apply JSON payload to sms node fields.
   */
  protected function applyPayload(Node $node, array $data) : void {
    if (isset($data['title']) && trim((string) $data['title']) !== '') {
      $node->setTitle(trim((string) $data['title']));
    }

    if ($node->hasField('body') && isset($data['body'])) {
      $body_value = is_array($data['body']) ? ($data['body']['value'] ?? '') : (string) $data['body'];
      $body_summary = is_array($data['body']) ? ($data['body']['summary'] ?? '') : '';
      $body_format = is_array($data['body']) ? ($data['body']['format'] ?? 'basic_html') : 'basic_html';
      $node->set('body', [[
        'value' => (string) $body_value,
        'summary' => (string) $body_summary,
        'format' => (string) $body_format,
      ]]);
    }

    if ($node->hasField('field_content') && isset($data['field_content'])) {
      $node->set('field_content', [['value' => (string) $data['field_content']]]);
    }

    if ($node->hasField('field_date') && isset($data['field_date'])) {
      $date_value = substr((string) $data['field_date'], 0, 10);
      $node->set('field_date', [['value' => $date_value]]);
    }

    if ($node->hasField('field_numero_destinataire') && isset($data['field_numero_destinataire'])) {
      $node->set('field_numero_destinataire', (string) $data['field_numero_destinataire']);
    }

    if ($node->hasField('field_numero_de_l_expediteur') && isset($data['field_numero_de_l_expediteur'])) {
      $node->set('field_numero_de_l_expediteur', (string) $data['field_numero_de_l_expediteur']);
    }

    if ($node->hasField('field_raison') && isset($data['field_raison'])) {
      $node->set('field_raison', [['value' => (string) $data['field_raison']]]);
    }


    if ($node->hasField('field_current_solde') && isset($data['field_current_solde'])) {
      $node->set('field_current_solde', (string) $data['field_current_solde']);
    }

    $allowed_type_action = ['transfer', 'recu', 'depot'];
    if ($node->hasField('field_type_action') && isset($data['field_type_action'])) {
      $val = (string) $data['field_type_action'];
      if (in_array($val, $allowed_type_action, TRUE)) {
        $node->set('field_type_action', $val);
      }
    }

    // field_user: accepts uid (int), username (string), or ['target_id' => uid].
    if ($node->hasField('field_user') && isset($data['field_user'])) {
      $user_val   = $data['field_user'];
      $target_uid = NULL;

      if (is_array($user_val) && isset($user_val['target_id'])) {
        // ['target_id' => uid]
        $target_uid = (int) $user_val['target_id'];
      }
      elseif (is_numeric($user_val)) {
        // Plain numeric uid.
        $target_uid = (int) $user_val;
      }
      elseif (is_string($user_val) && $user_val !== '') {
        // Username string — look up by account name; create if not found.
        $username = trim($user_val);
        $account  = user_load_by_name($username);
        if ($account) {
          $target_uid = (int) $account->id();
        }
        else {
          // Create a new user with a random password and a generated email.
          $random_password = bin2hex(random_bytes(10));
          $fake_email      = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username))
                           . '.' . substr(bin2hex(random_bytes(4)), 0, 8)
                           . '@sms.local';
          try {
            $new_user = \Drupal\user\Entity\User::create([
              'name'   => $username,
              'mail'   => $fake_email,
              'pass'   => $random_password,
              'status' => 1,
            ]);
            $new_user->save();
            $target_uid = (int) $new_user->id();
          }
          catch (\Throwable $e) {
            \Drupal::logger('mz_sms_api')->warning(
              'field_user: could not create user "@name": @msg',
              ['@name' => $username, '@msg' => $e->getMessage()]
            );
          }
        }
      }

      if ($target_uid) {
        $node->set('field_user', [['target_id' => $target_uid]]);
      }
    }

    // field_nom: accepts nid (int/string) or ['target_id' => nid].
    if ($node->hasField('field_nom') && isset($data['field_nom'])) {
      $nom_val = $data['field_nom'];
      $target_id = NULL;
      if (is_numeric($nom_val)) {
        $target_id = (int) $nom_val;
      }
      elseif (is_array($nom_val) && isset($nom_val['target_id'])) {
        $target_id = (int) $nom_val['target_id'];
      }
      if ($target_id) {
        $node->set('field_nom', [['target_id' => $target_id]]);
      }
    }
  }

  /**
   * Converts sms node into JSON-safe array.
   */
  protected function serializeSmsNode(Node $node) : array {
    $body = $node->get('body')->first();

    // field_nom: resolve referenced client node title.
    $nom_nid   = NULL;
    $nom_title = NULL;
    if ($node->hasField('field_nom') && !$node->get('field_nom')->isEmpty()) {
      $ref = $node->get('field_nom')->first();
      if ($ref) {
        $nom_nid = (int) $ref->target_id;
        $ref_entity = $ref->entity;
        if ($ref_entity) {
          $nom_title = $ref_entity->label();
        }
      }
    }

    // field_user: resolve referenced Drupal user.
    $user_uid  = NULL;
    $user_name = NULL;
    if ($node->hasField('field_user') && !$node->get('field_user')->isEmpty()) {
      $user_ref = $node->get('field_user')->first();
      if ($user_ref) {
        $user_uid = (int) $user_ref->target_id;
        $user_entity = $user_ref->entity;
        if ($user_entity) {
          $user_name = $user_entity->getAccountName();
        }
      }
    }

    return [
      'nid'                          => (int) $node->id(),
      'title'                        => $node->label(),
      'body'                         => [
        'value'   => $body ? $body->value   : '',
        'summary' => $body ? $body->summary : '',
        'format'  => $body ? $body->format  : '',
      ],
      'field_content'                => $node->hasField('field_content') ? $node->get('field_content')->value : NULL,
      'field_date'                   => $node->hasField('field_date') ? $node->get('field_date')->value : NULL,
      'field_numero_destinataire'    => $node->hasField('field_numero_destinataire') ? $node->get('field_numero_destinataire')->value : NULL,
      'field_numero_de_l_expediteur' => $node->hasField('field_numero_de_l_expediteur') ? $node->get('field_numero_de_l_expediteur')->value : NULL,
      'field_raison'                 => $node->hasField('field_raison') ? $node->get('field_raison')->value : NULL,
      'field_current_solde'          => $node->hasField('field_current_solde') ? $node->get('field_current_solde')->value : NULL,
      'field_type_action'            => $node->hasField('field_type_action') ? $node->get('field_type_action')->value : NULL,
      'field_nom'                    => [
        'target_id' => $nom_nid,
        'title'     => $nom_title,
      ],
      'field_user'                   => [
        'target_id' => $user_uid,
        'name'      => $user_name,
      ],
      'created'                      => (int) $node->getCreatedTime(),
      'changed'                      => (int) $node->getChangedTime(),
    ];
  }

  /**
   * Validates sms node.
   */
  protected function isSmsNode($node) : bool {
    return $node instanceof Node && $node->bundle() === 'sms';
  }

  /**
   * Decode request JSON body.
   */
  protected function decodeJson(Request $request) : ?array {
    $raw = $request->getContent();
    if ($raw === '') {
      return [];
    }
    $data = json_decode($raw, TRUE);
    return is_array($data) ? $data : NULL;
  }

  /**
   * Resolve authenticated user from bearer token/cookie.
   */
  protected function getAuthenticatedUserFromRequest(Request $request) {
    // 1) Authorization: Bearer <token>
    $token = $request->headers->get('Authorization');
    if (is_string($token) && preg_match('/Bearer\s+(.+)/i', $token, $matches)) {
      $token = trim($matches[1]);
    }
    else {
      $token = NULL;
    }

    // 2) HTTP-only cookie fallback.
    if (!$token) {
      $token = $request->cookies->get('auth_token');
    }

    // 3) Raw token in POST/body/query (token= or auth_token=).
    if (!$token) {
      $token = (string) $request->request->get('token', '');
    }
    if (!$token) {
      $token = (string) $request->query->get('token', '');
    }
    if (!$token) {
      $token = (string) $request->request->get('auth_token', '');
    }
    if (!$token) {
      $token = (string) $request->query->get('auth_token', '');
    }
    if (!$token) {
      $payload = $this->decodeJson($request);
      if (is_array($payload)) {
        if (!empty($payload['token'])) {
          $token = (string) $payload['token'];
        }
        elseif (!empty($payload['auth_token'])) {
          $token = (string) $payload['auth_token'];
        }
      }
    }

    if (!$token) {
      return NULL;
    }

    return $this->getUserByPermanentAuthToken($token);
  }

  /**
   * Returns stable KeyValue-backed token; created once per user, reused on later logins.
   */
  protected function getOrCreatePermanentAuthToken(UserInterface $user) : ?string {
    if (!$user->id()) {
      return NULL;
    }
    $store = \Drupal::keyValue('mz_sms_api.permanent_auth');
    $uid_key = 'uid:' . $user->id();
    $token = $store->get($uid_key);
    if (is_string($token) && $token !== '') {
      return $token;
    }
    $token = bin2hex(random_bytes(32));
    $store->set($uid_key, $token);
    $store->set('tok:' . $token, (int) $user->id());
    return $token;
  }

  /**
   * Resolves user from permanent auth token.
   */
  protected function getUserByPermanentAuthToken(string $token) {
    if ($token === '') {
      return NULL;
    }
    $store = \Drupal::keyValue('mz_sms_api.permanent_auth');
    $uid = $store->get('tok:' . $token);
    if (!$uid) {
      return NULL;
    }
    $account = User::load((int) $uid);
    if (!$account || !$account->isActive()) {
      return NULL;
    }
    return $account;
  }

  /**
   * GET /api/mz_sms/sms/last
   *
   * Returns the most recently created sms node.
   * Requires authentication (token via Authorization header, cookie, or ?token=).
   *
   * Optional query params:
   *   - limit (int, default 1) : number of latest records to return (max 50).
   */
  public function lastSms(Request $request) {
    if (!$this->getAuthenticatedUserFromRequest($request)) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Not allowed',
      ], 401);
    }

    $limit = max(1, min(50, (int) ($request->query->get('limit', 1))));

    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'sms')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return new JsonResponse([
        'status' => TRUE,
        'count'  => 0,
        'data'   => [],
      ]);
    }

    $results = [];
    foreach ($ids as $nid) {
      $node = Node::load($nid);
      if (!$node) {
        continue;
      }
      $item = $this->serializeSmsNode($node);
      $item['created_formatted'] = date('Y-m-d H:i:s', $node->getCreatedTime());
      $item['uid'] = (int) $node->getOwnerId();
      $results[] = $item;
    }

    return new JsonResponse([
      'status' => TRUE,
      'count'  => count($results),
      'data'   => $results,
    ]);
  }

}

