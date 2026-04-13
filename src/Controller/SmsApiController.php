<?php

namespace Drupal\mz_sms_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
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

    $token = $this->generateBearerTokenForUser($user);
    if (is_string($token) && $token !== '') {
      $response_data['token'] = $token;
      $response->setData($response_data);
      $response->headers->setCookie(new Cookie(
        'auth_token',
        $token,
        time() + (30 * 24 * 3600),
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
  }

  /**
   * Converts sms node into JSON-safe array.
   */
  protected function serializeSmsNode(Node $node) : array {
    $body = $node->get('body')->first();
    $content = $node->hasField('field_content') ? $node->get('field_content')->value : NULL;
    $date = $node->hasField('field_date') ? $node->get('field_date')->value : NULL;
    $dest = $node->hasField('field_numero_destinataire') ? $node->get('field_numero_destinataire')->value : NULL;
    $sender = $node->hasField('field_numero_de_l_expediteur') ? $node->get('field_numero_de_l_expediteur')->value : NULL;

    return [
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'body' => [
        'value' => $body ? $body->value : '',
        'summary' => $body ? $body->summary : '',
        'format' => $body ? $body->format : '',
      ],
      'field_content' => $content,
      'field_date' => $date,
      'field_numero_destinataire' => $dest,
      'field_numero_de_l_expediteur' => $sender,
      'created' => (int) $node->getCreatedTime(),
      'changed' => (int) $node->getChangedTime(),
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
   * Generate a bearer token using available auth services.
   */
  protected function generateBearerTokenForUser($user) : ?string {
    foreach (['api_solutions.api_crud', 'api.crud'] as $service_id) {
      if (!\Drupal::getContainer()->has($service_id)) {
        continue;
      }
      $service = \Drupal::service($service_id);
      if (method_exists($service, 'generateBearerToken')) {
        $token = $service->generateBearerToken($user);
        if (is_string($token) && $token !== '') {
          return $token;
        }
      }
      if (method_exists($service, 'generateToken')) {
        $token = $service->generateToken($user);
        if (is_string($token) && $token !== '') {
          return $token;
        }
      }
    }

    return NULL;
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

    // 3) Raw token in POST/body/query (token=...).
    if (!$token) {
      $token = (string) $request->request->get('token', '');
    }
    if (!$token) {
      $token = (string) $request->query->get('token', '');
    }
    if (!$token) {
      $payload = $this->decodeJson($request);
      if (is_array($payload) && !empty($payload['token'])) {
        $token = (string) $payload['token'];
      }
    }

    if (!$token) {
      return NULL;
    }

    foreach (['api_solutions.api_crud', 'api.crud'] as $service_id) {
      if (!\Drupal::getContainer()->has($service_id)) {
        continue;
      }
      $service = \Drupal::service($service_id);
      if (method_exists($service, 'validateBearerToken')) {
        $user = $service->validateBearerToken($token);
        if ($user) {
          return $user;
        }
      }
      if (method_exists($service, 'getUserByToken')) {
        $user = $service->getUserByToken($token);
        if ($user) {
          return $user;
        }
      }
      if (method_exists($service, 'isTokenValid') && method_exists($service, 'getUserByToken')) {
        if ($service->isTokenValid($token)) {
          return $service->getUserByToken($token);
        }
      }
    }

    return NULL;
  }

}

