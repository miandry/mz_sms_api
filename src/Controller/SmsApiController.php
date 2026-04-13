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

    // If api_solutions is present, also generate compatible bearer cookie.
    if (\Drupal::getContainer()->has('api_solutions.api_crud')) {
      $token_service = \Drupal::service('api_solutions.api_crud');
      $token = $token_service->generateBearerToken($user);
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

}

