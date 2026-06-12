<?php

namespace Drupal\dmr_public_comment\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Handles all communication with the Pega DX API v2.
 *
 * Responsible for fetching case data, submitting public comments, uploading
 * attachments, and linking those attachments to the correct data record in
 * Pega. All API calls use OAuth 2.0 client credentials for authentication.
 */
class PegaCaseService {

  // Pega DX API v2 endpoint paths.
  private const TOKEN_ENDPOINT        = '/prweb/PRRestService/oauth2/v1/token';
  private const CASE_API_PATH         = '/prweb/app/Licensing/api/application/v2/cases/{caseId}?viewType=page&pageName=PublicCommentDrupalInfo';
  private const COMMENT_API_PATH      = '/prweb/app/Licensing/api/application/v2/data/D_PublicComment';
  private const ATTACHMENT_UPLOAD_PATH = '/prweb/app/Licensing/api/application/v2/attachments/upload';
  private const ATTACHMENT_API_PATH    = '/prweb/app/Licensing/api/application/v2/cases/{caseId}/attachments';

  // Custom REST service endpoint built in Pega to link attachments to a comment record.
  private const LINK_ATTACHMENTS_PATH = '/prweb/app/Licensing/api/drupal_public_comment/v1/comment/attachments';

  // Only attachments in this category are considered when linking to a comment.
  private const ATTACHMENT_CATEGORY  = 'PublicCommentDocuments';

  // Prefix used to construct the full Pega case handle (e.g. "SOM-DMR-BPH-WORK L-14372").
  private const CASE_HANDLE_PREFIX   = 'SOM-DMR-BPH-WORK';

  private const REQUEST_TIMEOUT      = 10;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Fetches public-facing case data from Pega for a given case ID.
   *
   * Used to pre-populate the public comment form (applicant name, location,
   * comment period dates, etc.) and to validate that the case exists.
   *
   * @param string $caseId
   *   The short case ID (e.g. "L-14372"), without the class prefix.
   *
   * @return array|null
   *   The case content array from Pega, or NULL if the request fails or
   *   credentials are not configured.
   */
  public function getCaseContent(string $caseId): ?array {
    $config = $this->configFactory->get('dmr_public_comment.settings');
    $baseUrl      = $config->get('base_url');
    $clientId     = $config->get('client_id');
    $clientSecret = $config->get('client_secret');

    if (!$baseUrl || !$clientId || !$clientSecret) {
      return NULL;
    }

    $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
      return NULL;
    }

    return $this->fetchContent($baseUrl, $caseId, $token);
  }

  /**
   * Submits a public comment to Pega as a new SOM-DMR-Data-Comment record.
   *
   * On success, returns the pzInsKey of the newly created record. This key is
   * needed afterwards to link any uploaded attachments to the correct comment.
   *
   * @param array $fields
   *   Associative array of comment field values (CaseID, FullName, Email, etc.)
   *   matching the properties expected by the D_PublicComment data page in Pega.
   *
   * @return string|null
   *   The Pega pzInsKey of the created comment record (e.g.
   *   "SOM-DMR-DATA-COMMENT ABC123"), or NULL if the submission fails.
   */
  public function submitComment(array $fields): ?string {
    $config = $this->configFactory->get('dmr_public_comment.settings');
    $baseUrl      = $config->get('base_url');
    $clientId     = $config->get('client_id');
    $clientSecret = $config->get('client_secret');

    if (!$baseUrl || !$clientId || !$clientSecret) {
      return NULL;
    }

    $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
      return NULL;
    }

    try {
      $response = $this->httpClient->post($baseUrl . self::COMMENT_API_PATH, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type'  => 'application/json',
          'Accept'        => 'application/json',
        ],
        'json'    => ['data' => $fields],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      return $data['responseData']['pzInsKey'] ?? NULL;
    }
    catch (RequestException $e) {
      $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('Pega comment submission failed (@status): @body', [
        '@status' => $e->getResponse()?->getStatusCode(),
        '@body'   => $body,
      ]);
      return NULL;
    }
  }

  /**
   * Uploads a file attachment to Pega and associates it with a case.
   *
   * Pega requires a two-step process for attachments:
   *   1. Upload the file to Pega's temporary storage via a multipart POST.
   *      Pega returns a temporary attachment ID.
   *   2. Associate that temporary ID with the case via a second POST, which
   *      moves it from temp storage into the case's attachment list.
   *
   * @param string $caseId
   *   The short case ID (e.g. "L-14372").
   * @param string $filename
   *   The original filename of the uploaded file.
   * @param string $mimeType
   *   The MIME type of the file (e.g. "application/pdf").
   * @param string $fileContents
   *   The raw binary contents of the file.
   *
   * @return bool
   *   TRUE if both steps succeed, FALSE otherwise.
   */
  public function submitAttachment(string $caseId, string $filename, string $mimeType, string $fileContents): bool {
    $config = $this->configFactory->get('dmr_public_comment.settings');
    $baseUrl      = $config->get('base_url');
    $clientId     = $config->get('client_id');
    $clientSecret = $config->get('client_secret');

    if (!$baseUrl || !$clientId || !$clientSecret) {
      return FALSE;
    }

    $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
      return FALSE;
    }

    // Step 1: upload file to Pega temp storage, get back an attachment ID.
    try {
      $uploadResponse = $this->httpClient->post($baseUrl . self::ATTACHMENT_UPLOAD_PATH, [
        'headers'   => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'multipart' => [
          ['name' => 'appendUniqueIdToFileName', 'contents' => 'true'],
          ['name' => 'file', 'contents' => $fileContents, 'filename' => $filename],
        ],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $attachmentId = json_decode((string) $uploadResponse->getBody(), TRUE)['ID'] ?? NULL;
      if (!$attachmentId) {
        $this->logger->error('Pega attachment upload returned no ID for case @id', ['@id' => $caseId]);
        return FALSE;
      }
    }
    catch (RequestException $e) {
      $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('Pega attachment upload failed for case @id (@status): @body', [
        '@id'     => $caseId,
        '@status' => $e->getResponse()?->getStatusCode(),
        '@body'   => $body,
      ]);
      return FALSE;
    }

    // Step 2: associate the uploaded attachment with the case.
    $handle = self::CASE_HANDLE_PREFIX . ' ' . $caseId;
    $url    = $baseUrl . str_replace('{caseId}', rawurlencode($handle), self::ATTACHMENT_API_PATH);

    try {
      $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type'  => 'application/json',
          'Accept'        => 'application/json',
        ],
        'json'    => [
          'attachments' => [
            ['type' => 'File', 'category' => self::ATTACHMENT_CATEGORY, 'ID' => $attachmentId],
          ],
        ],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      return TRUE;
    }
    catch (RequestException $e) {
      $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('Pega attachment association failed for case @id (@status): @body', [
        '@id'     => $caseId,
        '@status' => $e->getResponse()?->getStatusCode(),
        '@body'   => $body,
      ]);
      return FALSE;
    }
  }

  /**
   * Retrieves the attachment keys for files uploaded to a case after a given time.
   *
   * After uploading attachments, we need their Pega pzInsKey values to link
   * them to the comment record. The $since timestamp is used to filter out
   * attachments that already existed on the case before this submission, so
   * we only link the files the current user uploaded.
   *
   * @param string $caseId
   *   The short case ID (e.g. "L-14372").
   * @param float $since
   *   Unix timestamp (with microseconds from microtime(TRUE)) representing the
   *   earliest creation time to include. Attachments created before this time
   *   are excluded.
   *
   * @return array
   *   Array of LINK-ATTACHMENT pzInsKey strings for the matching attachments,
   *   or an empty array if none are found or the request fails.
   */
  public function getCaseAttachments(string $caseId, float $since): array {
    $config = $this->configFactory->get('dmr_public_comment.settings');
    $baseUrl      = $config->get('base_url');
    $clientId     = $config->get('client_id');
    $clientSecret = $config->get('client_secret');

    if (!$baseUrl || !$clientId || !$clientSecret) {
      return [];
    }

    $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
      return [];
    }

    $handle = self::CASE_HANDLE_PREFIX . ' ' . $caseId;
    $url    = $baseUrl . str_replace('{caseId}', rawurlencode($handle), self::ATTACHMENT_API_PATH);

    try {
      $response = $this->httpClient->get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);

      // Filter to attachments uploaded during this request only. The timestamp
      // is captured before uploads begin so that pre-existing attachments on
      // the case (from prior submissions or manual uploads) are excluded.
      $keys = [];
      foreach ($data['attachments'] ?? [] as $attachment) {
        if (($attachment['category'] ?? '') !== self::ATTACHMENT_CATEGORY) {
          continue;
        }
        $createTime = $attachment['createTime'] ?? '';
        if ($createTime) {
          // Pega returns createTime as a compact string (e.g. "20260611T015722.432 GMT").
          // Reformat to ISO 8601 so PHP's DateTime can parse it.
          $normalized = preg_replace('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', '$1-$2-$3T$4:$5:$6', $createTime);
          try {
            $dt = new \DateTime($normalized, new \DateTimeZone('UTC'));
            if ($dt->getTimestamp() < (int) $since) {
              continue;
            }
          }
          catch (\Exception $e) {
            // If the timestamp can't be parsed, include the attachment to be safe.
          }
        }
        $id = $attachment['ID'] ?? '';
        if ($id) {
          $keys[] = $id;
        }
      }
      return $keys;
    }
    catch (RequestException $e) {
      $this->logger->error('Pega GET case attachments failed for case @id: @msg', [
        '@id'  => $caseId,
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Calls a custom Pega REST service to link attachments to a comment record.
   *
   * The standard Pega DX API does not support linking attachments directly to
   * a data record (only to a case). This method calls a custom REST service
   * endpoint built in Pega that runs an activity (LinkCommentAttachments) to
   * copy the attachment references onto the SOM-DMR-Data-Comment record's
   * Attachments page list.
   *
   * @param string $caseId
   *   The short case ID (e.g. "L-14372"), used only for error logging.
   * @param string $pzInsKey
   *   The pzInsKey of the SOM-DMR-Data-Comment record to link attachments to
   *   (e.g. "SOM-DMR-DATA-COMMENT ABC123").
   * @param array $attachmentKeys
   *   Array of LINK-ATTACHMENT pzInsKey strings to associate with the comment.
   *
   * @return bool
   *   TRUE if the request succeeds, FALSE otherwise.
   */
  public function linkAttachmentsToComment(string $caseId, string $pzInsKey, array $attachmentKeys): bool {
    $config = $this->configFactory->get('dmr_public_comment.settings');
    $baseUrl      = $config->get('base_url');
    $clientId     = $config->get('client_id');
    $clientSecret = $config->get('client_secret');

    if (!$baseUrl || !$clientId || !$clientSecret) {
      return FALSE;
    }

    $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
      return FALSE;
    }

    // Each key is individually URL-encoded to handle spaces and special
    // characters. Keys are joined with a literal comma (not passed through
    // http_build_query) because Pega's expression parser misinterprets the
    // pipe character even when percent-encoded.
    $url = $baseUrl . self::LINK_ATTACHMENTS_PATH
      . '?pzInsKey=' . rawurlencode($pzInsKey)
      . '&attachmentKeys=' . implode(',', array_map('rawurlencode', $attachmentKeys));

    try {
      $this->httpClient->post($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      return TRUE;
    }
    catch (RequestException $e) {
      $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('Pega link attachments to comment failed for case @id (@status): @body', [
        '@id'     => $caseId,
        '@status' => $e->getResponse()?->getStatusCode(),
        '@body'   => $body,
      ]);
      return FALSE;
    }
  }

  /**
   * Obtains a Pega OAuth 2.0 access token using client credentials.
   *
   * Tokens are cached in Drupal's cache backend to avoid requesting a new
   * token on every API call. The cache entry expires 60 seconds before the
   * token's actual expiry to avoid sending a nearly-expired token.
   *
   * @param string $baseUrl
   *   The Pega base URL.
   * @param string $clientId
   *   The OAuth client ID.
   * @param string $clientSecret
   *   The OAuth client secret.
   *
   * @return string|null
   *   The access token string, or NULL if the request fails.
   */
  private function getAccessToken(string $baseUrl, string $clientId, string $clientSecret): ?string {
    $cid = 'dmr_public_comment:token:' . md5($clientId);
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    try {
      $response = $this->httpClient->post($baseUrl . self::TOKEN_ENDPOINT, [
        'form_params' => [
          'grant_type'    => 'client_credentials',
          'client_id'     => $clientId,
          'client_secret' => $clientSecret,
        ],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $data  = json_decode((string) $response->getBody(), TRUE);
      $token = $data['access_token'] ?? NULL;
      if ($token) {
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        // Cache the token 60 seconds before its actual expiry to avoid
        // sending a nearly-expired token on a slow or retried request.
        $this->cache->set($cid, $token, time() + $expiresIn - 60);
      }
      return $token;
    }
    catch (RequestException $e) {
      $this->logger->error('Pega OAuth token request failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Fetches case content from the Pega DX API for a given case ID.
   *
   * Calls the PublicCommentDrupalInfo page view on the case, which exposes
   * only the fields needed for the public comment form (applicant name,
   * location, comment period dates, license type, etc.).
   *
   * @param string $baseUrl
   *   The Pega base URL.
   * @param string $caseId
   *   The short case ID (e.g. "L-14372").
   * @param string $token
   *   A valid OAuth access token.
   *
   * @return array|null
   *   The content array from the Pega case response, or NULL on failure.
   */
  private function fetchContent(string $baseUrl, string $caseId, string $token): ?array {
    $handle = self::CASE_HANDLE_PREFIX . ' ' . $caseId;
    $url    = $baseUrl . str_replace('{caseId}', rawurlencode($handle), self::CASE_API_PATH);

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept'        => 'application/json',
        ],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      return $data['data']['caseInfo']['content'] ?? NULL;
    }
    catch (RequestException $e) {
      $this->logger->error('Pega DX API call failed for case @id: @msg', [
        '@id'  => $caseId,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
