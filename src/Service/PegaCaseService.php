<?php

namespace Drupal\dmr_public_comment\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Handles all communication with the Pega DX API v2.
 *
 * Responsible for fetching case data, submitting public comments, uploading
 * attachments, and linking those attachments to the correct data record in
 * Pega. All API calls use OAuth 2.0 client credentials for authentication.
 *
 * Security notes:
 *   - The OAuth client secret is never stored in configuration. It is resolved
 *     at request time from a Key entity whose provider keeps the value outside
 *     Drupal config (Environment or File). Key entities using a configuration
 *     based provider are refused.
 *   - The configured base URL is re-validated here, not just in the settings
 *     form, so a stored or overridden value that points somewhere other than an
 *     approved Pega host can never be used for a server-side request.
 *   - Pega response bodies are never written to the log. Failures are logged
 *     with the operation, case ID, HTTP status, and a short correlation ID.
 */
class PegaCaseService {

  // Pega DX API v2 endpoint paths.
  private const TOKEN_ENDPOINT         = '/prweb/PRRestService/oauth2/v1/token';
  private const CASE_API_PATH          = '/prweb/app/Licensing/api/application/v2/cases/{caseId}?viewType=page&pageName=PublicCommentDrupalInfo';
  private const COMMENT_API_PATH       = '/prweb/app/Licensing/api/application/v2/data/D_PublicComment';
  private const ATTACHMENT_UPLOAD_PATH = '/prweb/app/Licensing/api/application/v2/attachments/upload';
  private const ATTACHMENT_API_PATH    = '/prweb/app/Licensing/api/application/v2/cases/{caseId}/attachments';

  // Custom REST endpoint built in Pega to link attachments to a comment record.
  private const LINK_ATTACHMENTS_PATH = '/prweb/app/Licensing/api/drupal_public_comment/v1/comment/attachments';

  // Only attachments in this category are considered when linking to a comment.
  private const ATTACHMENT_CATEGORY = 'PublicCommentDocuments';

  // Keys the Pega DX API v2 case-attachment list may use for a file's name. The
  // first non-empty one wins. Verified against the dev tenant on 2026-08-04:
  // every attachment carries both, "fileName" being the full name as uploaded
  // ("site_plan__1a2b3c4d5e6f7a8b.pdf") and "name" the same without the
  // extension. Both still contain the correlation token, but "fileName" is the
  // value we actually set, so it is preferred. Correlation depends entirely
  // on one of these being present, so getCaseAttachments() reports it loudly
  // rather than guessing if a tenant ever returns neither.
  private const ATTACHMENT_NAME_FIELDS = ['fileName', 'name'];

  // Prefix for the full Pega case handle (e.g. "SOM-DMR-BPH-WORK L-14372").
  private const CASE_HANDLE_PREFIX = 'SOM-DMR-BPH-WORK';

  private const REQUEST_TIMEOUT = 10;

  // Key provider plugin IDs that keep the secret inside Drupal itself: "config"
  // writes it back into exportable configuration, "state" into the database.
  // Both defeat the purpose of using Key, so the secret is refused rather than
  // read. External providers (Environment, File, and contrib providers such as
  // Vault or a cloud secrets manager) remain usable.
  //
  // Note: Key 1.x provider annotations do not expose a "storage_method", so
  // this list is the effective control; the storage_method check below is
  // forward compatibility only.
  private const REJECTED_KEY_PROVIDERS = ['config', 'state'];

  /**
   * Correlation reference logged for the most recent failure, if any.
   *
   * Reset at the start of every public method, so a caller that has just had a
   * FALSE/NULL back can quote the same reference the log entry carries without
   * picking up a stale one from an earlier call in the same request. Empty when
   * the failure was a configuration problem, which is logged without a
   * reference because there is no HTTP exchange to correlate it to.
   */
  private string $lastFailureReference = '';

  /**
   * Case content already fetched during this request, keyed by case ID.
   *
   * One public comment submission asks Pega for the same case twice: the
   * validation handler reads it to check the comment period, and the submission
   * handler reads it again to take the licence type and applicant details from
   * an authoritative source rather than from the browser. Both happen in the
   * same request, milliseconds apart, so the second read was pure latency for
   * the visitor.
   *
   * This is deliberately NOT a persistent cache. It lives on the service, which
   * Drupal builds fresh for each request, so it never outlives the request that
   * filled it and cannot serve one visitor data fetched for another. The
   * security property the second read exists for is preserved: the value still
   * comes from Pega and never from anything the browser sent.
   *
   * @var array<string, array>
   */
  private array $caseContentCache = [];

  public function __construct(
    // Typed as Guzzle's Client rather than ClientInterface because every call
    // below uses the shorthand verbs (->post(), ->get()), which Client provides
    // through __call() and the interface does not declare. Drupal's http_client
    // service is always a Client, so this states a dependency that was already
    // there rather than adding one.
    private readonly Client $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
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
    $this->lastFailureReference = '';

    // Case IDs are treated case-insensitively everywhere else, so the key is
    // normalised to stop "L-14372" and "l-14372" being fetched separately.
    $key = strtoupper(trim($caseId));
    if (isset($this->caseContentCache[$key])) {
      return $this->caseContentCache[$key];
    }

    $credentials = $this->getCredentials();
    if ($credentials === NULL) {
      return NULL;
    }

    $token = $this->getAccessToken($credentials);
    if (!$token) {
      return NULL;
    }

    $content = $this->fetchContent($credentials['base_url'], $caseId, $token);

    // Only successes are remembered. A failure is often transient, and a caller
    // that retries after one should get a real attempt rather than the previous
    // disappointment, with its failure reference already cleared above.
    if ($content !== NULL) {
      $this->caseContentCache[$key] = $content;
    }

    return $content;
  }

  /**
   * Submits a public comment to Pega as a new SOM-DMR-Data-Comment record.
   *
   * On success, returns the pzInsKey of the newly created record. This key is
   * needed afterwards to link any uploaded attachments to the correct comment.
   *
   * @param array $fields
   *   Associative array of comment field values (CaseID, FullName, Email, etc.)
   *   matching the properties the D_PublicComment data page in Pega expects.
   *
   * @return string|null
   *   The Pega pzInsKey of the created comment record (e.g.
   *   "SOM-DMR-DATA-COMMENT ABC123"), or NULL if the submission fails.
   */
  public function submitComment(array $fields): ?string {
    $this->lastFailureReference = '';

    $credentials = $this->getCredentials();
    if ($credentials === NULL) {
      return NULL;
    }

    $token = $this->getAccessToken($credentials);
    if (!$token) {
      return NULL;
    }

    try {
      $response = $this->httpClient->post($credentials['base_url'] . self::COMMENT_API_PATH, [
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
    catch (GuzzleException $e) {
      $this->logRequestFailure('comment submission', $e, (string) ($fields['CaseID'] ?? ''));
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
   *   The filename to send to Pega, with the submission's correlation token
   *   already embedded in it; see getCaseAttachments().
   * @param resource|mixed $file
   *   An open, readable stream positioned at the start of the file. The stream
   *   is read by Guzzle as the request body rather than being loaded into a
   *   string, so a large upload does not have to fit in PHP's memory limit.
   *   Ownership stays with the caller, which must close it on every path.
   *   Typed loosely because the caller's fopen() can fail and hand back FALSE,
   *   and because this is the boundary where that is checked: anything that is
   *   not a usable stream is refused below, before any request is made.
   *
   * @return bool
   *   TRUE if both steps succeed, FALSE otherwise.
   */
  public function submitAttachment(string $caseId, string $filename, $file): bool {
    $this->lastFailureReference = '';

    if (!is_resource($file)) {
      $this->logger->error('Pega attachment upload for case @id was given an unusable file handle and was skipped.', ['@id' => $caseId]);
      return FALSE;
    }

    $credentials = $this->getCredentials();
    if ($credentials === NULL) {
      return FALSE;
    }

    $token = $this->getAccessToken($credentials);
    if (!$token) {
      return FALSE;
    }

    $baseUrl = $credentials['base_url'];

    // Step 1: upload file to Pega temp storage, get back an attachment ID.
    try {
      $uploadResponse = $this->httpClient->post($baseUrl . self::ATTACHMENT_UPLOAD_PATH, [
        'headers'   => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'multipart' => [
          ['name' => 'appendUniqueIdToFileName', 'contents' => 'true'],
          // Guzzle streams the resource straight into the multipart body.
          ['name' => 'file', 'contents' => $file, 'filename' => $filename],
        ],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $attachmentId = json_decode((string) $uploadResponse->getBody(), TRUE)['ID'] ?? NULL;
      if (!$attachmentId) {
        $this->lastFailureReference = $this->newFailureReference();
        $this->logger->error('Pega attachment upload for case @id returned no attachment ID, so the file was not stored (ref @ref). Response body omitted from log.', [
          '@id'  => $caseId,
          '@ref' => $this->lastFailureReference,
        ]);
        return FALSE;
      }
    }
    catch (GuzzleException $e) {
      $this->logRequestFailure('attachment upload', $e, $caseId);
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
    catch (GuzzleException $e) {
      $this->logRequestFailure('attachment association', $e, $caseId);
      return FALSE;
    }
  }

  /**
   * Retrieves the attachment keys for one submission's uploads on a case.
   *
   * After uploading attachments, we need their Pega pzInsKey values to link
   * them to the comment record. Attachments are correlated by a token that the
   * caller generates per submission and embeds in each uploaded filename, just
   * before the extension. Matching is on the filename CONTAINING the token, so
   * anything Pega adds around it leaves the correlation intact and the token
   * identifies exactly this submission's files.
   *
   * This replaces the previous "created since I started uploading" heuristic,
   * which scanned the whole case and could cross-link files when two people
   * commented on the same case at the same time.
   *
   * @param string $caseId
   *   The short case ID (e.g. "L-14372").
   * @param string $token
   *   The submission's correlation token, as embedded in the filenames passed
   *   to submitAttachment().
   *
   * @return array
   *   Array of LINK-ATTACHMENT pzInsKey strings for this submission's
   *   attachments, or an empty array if none match or the request fails. The
   *   caller compares the count against the number of files it uploaded, so an
   *   empty or short result is treated as a failure rather than as "nothing to
   *   link".
   */
  public function getCaseAttachments(string $caseId, string $token): array {
    $this->lastFailureReference = '';

    if ($token === '') {
      return [];
    }

    $credentials = $this->getCredentials();
    if ($credentials === NULL) {
      return [];
    }

    $accessToken = $this->getAccessToken($credentials);
    if (!$accessToken) {
      return [];
    }

    $handle = self::CASE_HANDLE_PREFIX . ' ' . $caseId;
    $url    = $credentials['base_url'] . str_replace('{caseId}', rawurlencode($handle), self::ATTACHMENT_API_PATH);

    try {
      $response = $this->httpClient->get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);

      $keys       = [];
      $inCategory = 0;
      $named      = 0;

      foreach ($data['attachments'] ?? [] as $attachment) {
        if (!is_array($attachment) || ($attachment['category'] ?? '') !== self::ATTACHMENT_CATEGORY) {
          continue;
        }
        $inCategory++;

        $name = $this->attachmentName($attachment);
        if ($name === '') {
          continue;
        }
        $named++;

        if (!str_contains($name, $token)) {
          continue;
        }

        $id = $attachment['ID'] ?? '';
        if (is_scalar($id) && (string) $id !== '') {
          $keys[] = (string) $id;
        }
      }

      // Correlation depends entirely on Pega returning a filename. If the case
      // has attachments in our category but none of them carry a name, the
      // response contract has changed and nothing can be linked. Say so
      // explicitly rather than falling back to a heuristic that can attach one
      // person's files to another person's comment.
      if ($inCategory > 0 && $named === 0) {
        $this->lastFailureReference = $this->newFailureReference();
        $this->logger->error("Pega's case attachment list for case @case returned no filename on any @category attachment (looked for: @fields), so this submission's uploads could not be identified and were left unlinked (ref @ref). The files are on the case but not on the comment record. The Pega team needs to expose the attachment name on GET .../cases/{caseId}/attachments.", [
          '@case'     => $caseId,
          '@category' => self::ATTACHMENT_CATEGORY,
          '@fields'   => implode(', ', self::ATTACHMENT_NAME_FIELDS),
          '@ref'      => $this->lastFailureReference,
        ]);
      }

      return $keys;
    }
    catch (GuzzleException $e) {
      $this->logRequestFailure('case attachment lookup', $e, $caseId);
      return [];
    }
  }

  /**
   * Reads an attachment's filename from a Pega case-attachment list entry.
   *
   * @param array $attachment
   *   One entry from the attachments array in Pega's response.
   *
   * @return string
   *   The filename, or an empty string if the entry carries none.
   */
  private function attachmentName(array $attachment): string {
    foreach (self::ATTACHMENT_NAME_FIELDS as $field) {
      $value = $attachment[$field] ?? NULL;
      if (is_scalar($value) && (string) $value !== '') {
        return (string) $value;
      }
    }

    return '';
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
    $this->lastFailureReference = '';

    $credentials = $this->getCredentials();
    if ($credentials === NULL) {
      return FALSE;
    }

    $token = $this->getAccessToken($credentials);
    if (!$token) {
      return FALSE;
    }

    // Each key is individually URL-encoded to handle spaces and special
    // characters. Keys are joined with a literal comma (not passed through
    // http_build_query) because Pega's expression parser misinterprets the
    // pipe character even when percent-encoded.
    $url = $credentials['base_url'] . self::LINK_ATTACHMENTS_PATH
      . '?pzInsKey=' . rawurlencode($pzInsKey)
      . '&attachmentKeys=' . implode(',', array_map('rawurlencode', $attachmentKeys));

    try {
      $this->httpClient->post($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      return TRUE;
    }
    catch (GuzzleException $e) {
      $this->logRequestFailure('link attachments to comment', $e, $caseId);
      return FALSE;
    }
  }

  /**
   * Loads and validates the Pega connection settings.
   *
   * Returns NULL (so callers degrade gracefully) when the connection is not
   * fully configured, when the base URL is not an approved HTTPS Pega host, or
   * when the client secret cannot be resolved from its Key entity.
   *
   * @return array|null
   *   Array with keys base_url, client_id, client_secret, or NULL.
   */
  private function getCredentials(): ?array {
    $config   = $this->configFactory->get('dmr_public_comment.settings');
    $baseUrl  = rtrim(trim((string) $config->get('base_url')), '/');
    $clientId = trim((string) $config->get('client_id'));

    if ($baseUrl === '' || $clientId === '') {
      return NULL;
    }

    $allowlist = $config->get('base_url_allowlist');
    if (!is_array($allowlist)) {
      $allowlist = [];
    }

    // Defence in depth: the settings form applies the same check, but a value
    // written directly to config, imported, or overridden in settings.php must
    // not be able to turn this service into a server-side request forgery tool.
    if (!self::isAllowedBaseUrl($baseUrl, $allowlist)) {
      $this->logger->error('Refusing to call Pega: the configured base URL host (@host) is not an approved HTTPS Pega host. Check base_url and base_url_allowlist in dmr_public_comment.settings.', [
        '@host' => (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'none'),
      ]);
      return NULL;
    }

    $clientSecret = $this->getClientSecret();
    if ($clientSecret === NULL) {
      return NULL;
    }

    return [
      'base_url'      => $baseUrl,
      'client_id'     => $clientId,
      'client_secret' => $clientSecret,
    ];
  }

  /**
   * Resolves the OAuth client secret from its Key entity.
   *
   * The secret is never stored in this module's configuration. Configuration
   * only holds the machine name of a Key entity, and the Key entity must use a
   * provider that keeps the value outside config (Environment or File).
   *
   * @return string|null
   *   The client secret, or NULL if it is not configured or not usable. NULL
   *   makes the calling method degrade gracefully instead of fatalling.
   */
  private function getClientSecret(): ?string {
    $keyId = trim((string) $this->configFactory->get('dmr_public_comment.settings')->get('client_secret_key'));
    if ($keyId === '') {
      return NULL;
    }

    $key = $this->keyRepository->getKey($keyId);
    if ($key === NULL) {
      $this->logger->error('The configured OAuth client secret key (@key) does not exist. Create it at /admin/config/system/keys and re-select it in the DMR Public Comment settings.', [
        '@key' => $keyId,
      ]);
      return NULL;
    }

    if (self::keyStoresSecretInConfig($key)) {
      $this->logger->error('Refusing to read the OAuth client secret: key @key uses a key provider that stores the value inside Drupal (configuration or state). Switch the key to the Environment or File provider.', [
        '@key' => $keyId,
      ]);
      return NULL;
    }

    $value = $key->getKeyValue();
    if (!is_string($value) || $value === '') {
      $this->logger->error('The OAuth client secret key (@key) resolved to an empty value. Check that the environment variable or key file is present and readable by the web server.', [
        '@key' => $keyId,
      ]);
      return NULL;
    }

    return $value;
  }

  /**
   * Determines whether a Key entity keeps its value in Drupal configuration.
   *
   * Checks both the provider plugin ID and the provider's declared storage
   * method, so any config-backed provider is caught, not just Key's own
   * Configuration provider. Static so the settings form can refuse such a key
   * at validation time using exactly the same rule.
   *
   * @param \Drupal\key\KeyInterface $key
   *   A Key config entity.
   *
   * @return bool
   *   TRUE if the key's value would be stored in exportable configuration.
   */
  public static function keyStoresSecretInConfig(KeyInterface $key): bool {
    $provider = $key->getKeyProvider();
    if ($provider === NULL) {
      return TRUE;
    }

    // Every key provider in practice extends PluginBase and so can be asked
    // which plugin it is. KeyProviderInterface does not itself promise that,
    // and this decides whether a secret is safe to use, so a provider that
    // cannot be identified is refused rather than assumed to be external.
    if (!$provider instanceof PluginInspectionInterface) {
      return TRUE;
    }

    if (in_array($provider->getPluginId(), self::REJECTED_KEY_PROVIDERS, TRUE)) {
      return TRUE;
    }

    $definition = $provider->getPluginDefinition();
    $storageMethod = is_array($definition) ? ($definition['storage_method'] ?? '') : '';

    return $storageMethod === 'config';
  }

  /**
   * Checks that a Pega base URL is an approved HTTPS host.
   *
   * Static so the settings form can apply exactly the same rule at validation
   * time without duplicating it. Rules:
   *   - https only, no credentials in the URL, no port other than 443, and no
   *     path, query, or fragment component.
   *   - IP literals are rejected outright, which covers loopback (127.0.0.0/8),
   *     link-local (169.254.0.0/16 and IPv6 fe80::/10), and the private ranges
   *     10/8, 172.16/12 and 192.168/16.
   *   - Single-label and obvious internal host names are rejected.
   *   - The host must then match the allowlist, either exactly or via a single
   *     leading "*." wildcard. An empty allowlist allows nothing.
   *
   * @param string $url
   *   The configured base URL.
   * @param array $allowlist
   *   Allowed host names or "*.domain" patterns.
   *
   * @return bool
   *   TRUE if the URL may be used for outbound requests.
   */
  public static function isAllowedBaseUrl(string $url, array $allowlist): bool {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) {
      return FALSE;
    }

    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
      return FALSE;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
      return FALSE;
    }
    if (!empty($parts['query']) || !empty($parts['fragment'])) {
      return FALSE;
    }
    if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
      return FALSE;
    }

    $host = strtolower(rtrim($parts['host'], '.'));
    if ($host === '') {
      return FALSE;
    }

    // Reject IP literals; approved Pega hosts are always DNS names.
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== FALSE) {
      return FALSE;
    }

    // Reject single-label and well-known internal names.
    if (!str_contains($host, '.')) {
      return FALSE;
    }
    foreach (['.localhost', '.local', '.localdomain', '.internal', '.intranet'] as $suffix) {
      if (str_ends_with($host, $suffix)) {
        return FALSE;
      }
    }

    foreach ($allowlist as $pattern) {
      $pattern = strtolower(trim((string) $pattern));
      if ($pattern === '') {
        continue;
      }
      if (str_starts_with($pattern, '*.')) {
        $suffix = substr($pattern, 1);
        if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
          return TRUE;
        }
        continue;
      }
      if ($host === $pattern) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Obtains a Pega OAuth 2.0 access token using client credentials.
   *
   * Tokens are cached in Drupal's cache backend to avoid requesting a new
   * token on every API call. The cache entry expires 60 seconds before the
   * token's actual expiry to avoid sending a nearly-expired token.
   *
   * @param array $credentials
   *   Validated credentials from getCredentials().
   *
   * @return string|null
   *   The access token string, or NULL if the request fails.
   */
  private function getAccessToken(array $credentials): ?string {
    $cid = 'dmr_public_comment:token:' . md5($credentials['client_id']);
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    try {
      $response = $this->httpClient->post($credentials['base_url'] . self::TOKEN_ENDPOINT, [
        'form_params' => [
          'grant_type'    => 'client_credentials',
          'client_id'     => $credentials['client_id'],
          'client_secret' => $credentials['client_secret'],
        ],
        'timeout' => self::REQUEST_TIMEOUT,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $token = $data['access_token'] ?? NULL;
      if ($token) {
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        // Cache the token 60 seconds before its actual expiry to avoid
        // sending a nearly-expired token on a slow or retried request.
        $this->cache->set($cid, $token, time() + $expiresIn - 60);
      }
      return $token;
    }
    catch (GuzzleException $e) {
      // Only the status and reason phrase are logged. The exception message and
      // response body are deliberately omitted: the request body of this call
      // contains the client secret and must never reach the log.
      $this->logRequestFailure('OAuth token request', $e);
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
   *   The validated Pega base URL.
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
    catch (GuzzleException $e) {
      $this->logRequestFailure('case lookup', $e, $caseId);
      return NULL;
    }
  }

  /**
   * Logs a failed Pega request without exposing response bodies or secrets.
   *
   * Records the operation, case ID, HTTP status, reason phrase, exception type,
   * and a short correlation ID that can be quoted when comparing Drupal logs
   * against Pega's own tracer output. Response and request bodies are never
   * logged: they can contain applicant PII and, for the token call, the OAuth
   * client secret.
   *
   * Takes any GuzzleException, not only a RequestException. The failures that
   * matter most here carry no response at all: a connection refused, a DNS or
   * TLS failure, and, most importantly, the REQUEST_TIMEOUT expiring against an
   * unresponsive Pega are all raised as a ConnectException, a sibling of
   * RequestException rather than a subclass of it. Catching only that left
   * the commonest Pega outage escaping as an uncaught exception, which the
   * visitor saw as a broken page instead of the wording below.
   *
   * @param string $operation
   *   Short description of the call that failed.
   * @param \GuzzleHttp\Exception\GuzzleException $e
   *   The exception thrown by Guzzle.
   * @param string $caseId
   *   The case ID involved, if any.
   *
   * @return string
   *   The correlation ID that was logged.
   */
  private function logRequestFailure(string $operation, GuzzleException $e, string $caseId = ''): string {
    $reference = $this->newFailureReference();
    // Only a RequestException carries a response, and even then it may be NULL.
    $response = $e instanceof RequestException ? $e->getResponse() : NULL;

    $this->lastFailureReference = $reference;

    $this->logger->error('Pega @operation failed (case @case, HTTP @status @reason, type @type, ref @ref). Response body omitted from log.', [
      '@operation' => $operation,
      '@case'      => $caseId !== '' ? $caseId : 'n/a',
      '@status'    => $response ? $response->getStatusCode() : 'no response',
      '@reason'    => $response ? ($response->getReasonPhrase() ?: 'no reason phrase') : 'connection failed',
      '@type'      => (new \ReflectionClass($e))->getShortName(),
      '@ref'       => $reference,
    ]);

    return $reference;
  }

  /**
   * Generates a short, non-guessable correlation reference for a log entry.
   *
   * The reference identifies one failure across the Drupal log, the message
   * shown to the submitter, and Pega's own tracer output. It carries no
   * information about the request, so it is safe to show to the public.
   */
  private function newFailureReference(): string {
    return substr(hash('sha256', uniqid('', TRUE)), 0, 8);
  }

  /**
   * Returns the correlation reference logged by the last call that failed.
   *
   * Lets a caller quote, in the message it shows the submitter, exactly the
   * reference that appears in the log entry describing the failure. The value
   * is reset at the start of every public method, so it always belongs to the
   * call that just returned. It is a reference only: it exposes no case data,
   * no response body and no credentials.
   *
   * @return string
   *   The reference, or an empty string when the last call succeeded or failed
   *   for a configuration reason that produced no reference.
   */
  public function getLastFailureReference(): string {
    return $this->lastFailureReference;
  }

}
