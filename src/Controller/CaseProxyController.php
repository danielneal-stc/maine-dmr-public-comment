<?php

namespace Drupal\dmr_public_comment\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\dmr_public_comment\Service\CommentPeriod;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\dmr_public_comment\Service\PublicCommentNodeSource;
use Drupal\dmr_public_comment\Service\SubmissionModeResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Exposes JSON endpoints for the frontend autofill on the public comment form.
 *
 * Two endpoints are provided: one fetching live data from the Pega API for
 * cases linked to Pega (?case_id=), and one reading from a Drupal
 * public_comment_form node for manually-managed applications (?nid=).
 *
 * Both endpoints are anonymous by design, so they are constrained as follows:
 *   - The Pega endpoint only returns data while the case's comment period is
 *     open. Anything else (closed period, missing dates, unknown case, Pega
 *     unreachable) returns the same generic 404, so the endpoint cannot be used
 *     to enumerate cases or read data from cases that are not open for comment.
 *   - The node endpoint enforces entity view access in addition to the bundle
 *     and published checks, and returns the same generic 404 when access is
 *     denied so a restricted node is indistinguishable from a missing one.
 *   - Successful payloads are cached for a short, configurable TTL so repeat
 *     hits do not re-call Pega.
 *   - Per-IP flood control rejects abusive clients with HTTP 429. Edge WAF /
 *     CDN rate limiting at the hosting layer is the complementary control.
 *
 * @phpstan-consistent-constructor
 *   create() below returns new static(), which is the Drupal convention for a
 *   controller. The annotation records that any subclass is expected to keep
 *   this constructor signature.
 */
class CaseProxyController implements ContainerInjectionInterface {

  // Dot-separated paths into the Pega case content object, matching the
  // property structure returned by the PublicCommentDrupalInfo page view.
  private const FIELD_APPLICANT    = 'Applicant.LegalApplicantName';
  private const FIELD_TOWN         = 'License.ProposalInformation.TownsConcatenated';
  private const FIELD_LOCATION     = 'License.ProposalInformation.Waterbody';
  private const FIELD_PERIOD_START = 'PublicCommentStartDate';
  private const FIELD_PERIOD_END   = 'PublicCommentEndDate';
  private const FIELD_LICENSE_TYPE = 'LicenseType';

  private const CACHE_PREFIX = 'dmr_public_comment:autofill:';

  // Flood control event names. Requests are counted per client IP per endpoint.
  private const FLOOD_EVENT_CASE    = 'dmr_public_comment.case_data';
  private const FLOOD_EVENT_NODE    = 'dmr_public_comment.node_data';
  private const FLOOD_EVENT_INVALID = 'dmr_public_comment.invalid_request';

  // Malformed case IDs per client per window before enumeration is logged.
  private const MALFORMED_THRESHOLD = 5;

  // Used to throttle warning logging so an attacker cannot flood the log.
  private const FLOOD_EVENT_LOG = 'dmr_public_comment.abuse_log';
  private const LOG_THRESHOLD   = 5;

  // Fallbacks used when the corresponding config value is unset.
  private const DEFAULT_CACHE_TTL       = 300;
  private const DEFAULT_FLOOD_THRESHOLD = 60;
  private const DEFAULT_FLOOD_WINDOW    = 3600;

  public function __construct(
    private readonly PegaCaseService $pegaCaseService,
    private readonly CacheBackendInterface $cache,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly CommentPeriod $commentPeriod,
    private readonly PublicCommentNodeSource $nodeSource,
  ) {}

  /**
   * Injects the module's services via Drupal's service container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dmr_public_comment.case_service'),
      $container->get('cache.default'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('logger.channel.dmr_public_comment'),
      $container->get('request_stack'),
      $container->get('dmr_public_comment.comment_period'),
      $container->get('dmr_public_comment.node_source'),
    );
  }

  /**
   * Returns JSON data for a Pega case to pre-populate the public comment form.
   *
   * Data is only returned while the case's comment period is open. Every other
   * outcome (no dates on the case, period not yet open, period closed, unknown
   * case, Pega unreachable) returns the same generic 404 so the endpoint gives
   * away nothing about cases that are not currently open for comment.
   *
   * @param string $caseId
   *   The short case ID from the URL (e.g. "L-14372").
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with keys: case_id, applicant, town, location, comment_period,
   *   license_type. Returns 400 for an invalid format, 429 when rate limited,
   *   and 404 in every other non-success case.
   */
  public function getCaseData(string $caseId): JsonResponse {
    $rateLimited = $this->enforceFloodLimit(self::FLOOD_EVENT_CASE);
    if ($rateLimited) {
      return $rateLimited;
    }

    if (!SubmissionModeResolver::isValidCaseId($caseId)) {
      $this->registerMalformedRequest();
      return $this->jsonResponse(['error' => 'Invalid case ID format'], 400);
    }

    $cid = self::CACHE_PREFIX . 'case:' . strtoupper($caseId);
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $this->jsonResponse($cached->data, 200, TRUE);
    }

    $content = $this->pegaCaseService->getCaseContent($caseId);
    if ($content === NULL) {
      // Either the case does not exist, Pega is unreachable, or credentials
      // are not configured. The service logs the underlying reason; the client
      // gets the same response as a case that is not open for comment.
      return $this->notFound();
    }

    $start = $this->getNestedValue($content, self::FIELD_PERIOD_START);
    $end   = $this->getNestedValue($content, self::FIELD_PERIOD_END);
    if (!$this->commentPeriod->isOpen($start, $end)) {
      return $this->notFound();
    }

    $payload = [
      'case_id'        => $caseId,
      'applicant'      => $this->getNestedValue($content, self::FIELD_APPLICANT),
      'town'           => $this->getNestedValue($content, self::FIELD_TOWN),
      'location'       => $this->getNestedValue($content, self::FIELD_LOCATION),
      'comment_period' => $this->commentPeriod->formatRange($start, $end),
      'license_type'   => $this->getNestedValue($content, self::FIELD_LICENSE_TYPE),
    ];

    $this->cache->set($cid, $payload, time() + $this->cacheTtl());

    return $this->jsonResponse($payload, 200, TRUE);
  }

  /**
   * Returns JSON data for a public_comment_form node to pre-populate the form.
   *
   * Used when the form is accessed with a ?nid= query parameter (non-Pega
   * applications). Returns the same generic 404 when the node does not exist,
   * is the wrong content type, is unpublished, or the current user does not
   * have permission to view it. For the public form to autofill, the node must
   * be published AND viewable by anonymous users.
   *
   * @param int $nid
   *   The node ID from the URL.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Same JSON shape as getCaseData(): applicant, town, location,
   *   comment_period, license_type.
   */
  public function getNodeData(int $nid): JsonResponse {
    $rateLimited = $this->enforceFloodLimit(self::FLOOD_EVENT_NODE);
    if ($rateLimited) {
      return $rateLimited;
    }

    // The bundle, published and view-access checks all live in the node source
    // and all produce the same NULL, and they run before the cache is consulted
    // so a cached payload can never be served to a user who may not see the
    // node.
    $node = $this->nodeSource->load($nid);
    if ($node === NULL) {
      return $this->notFound();
    }

    $cid = self::CACHE_PREFIX . 'node:' . $nid;
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $this->jsonResponse($cached->data, 200, TRUE);
    }

    // A missing field means the content type this module installed has been
    // altered. Fail with a clean response and a log entry, not a fatal error.
    $missingField = $this->nodeSource->firstMissingField($node);
    if ($missingField !== NULL) {
      $this->logger->warning('The @field field is missing from the @bundle content type, so autofill data cannot be returned for node @nid. This field is installed with the DMR Public Comment module; restore it by reinstalling the module on a site with no comment periods, or recreate it with exactly that machine name.', [
        '@field'  => $missingField,
        '@bundle' => PublicCommentNodeSource::BUNDLE,
        '@nid'    => $nid,
      ]);
      return $this->jsonResponse(['error' => 'Application data unavailable'], 500);
    }

    $start = $this->nodeSource->dateValue($node, PublicCommentNodeSource::FIELD_PERIOD_START);
    $end   = $this->nodeSource->dateValue($node, PublicCommentNodeSource::FIELD_PERIOD_END);

    $payload = [
      'applicant'      => $this->nodeSource->value($node, PublicCommentNodeSource::FIELD_APPLICANT_NAME),
      'town'           => $this->nodeSource->value($node, PublicCommentNodeSource::FIELD_TOWN),
      'location'       => $this->nodeSource->value($node, PublicCommentNodeSource::FIELD_LOCATION),
      'comment_period' => $start && $end ? $this->commentPeriod->formatRange($start, $end) : '',
      'license_type'   => $this->nodeSource->licenseTypeLabel($node),
    ];

    // Cache tags mean editing the node clears this entry immediately.
    $this->cache->set($cid, $payload, time() + $this->cacheTtl(), $node->getCacheTags());

    return $this->jsonResponse($payload, 200, TRUE);
  }

  /**
   * Rejects the request when the client has exceeded the per-IP request limit.
   *
   * Counts one event per request per endpoint. A threshold of 0 or less
   * disables flood control for the endpoint.
   *
   * @param string $event
   *   The flood event name for the endpoint being called.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   A 429 response when the client is over the limit, NULL to continue.
   */
  private function enforceFloodLimit(string $event): ?JsonResponse {
    $config    = $this->configFactory->get('dmr_public_comment.settings');
    $threshold = (int) ($config->get('flood_threshold') ?? self::DEFAULT_FLOOD_THRESHOLD);
    $window    = (int) ($config->get('flood_window') ?? self::DEFAULT_FLOOD_WINDOW);

    if ($threshold <= 0) {
      return NULL;
    }
    if ($window <= 0) {
      $window = self::DEFAULT_FLOOD_WINDOW;
    }

    $identifier = $this->clientIp();
    if (!$this->flood->isAllowed($event, $threshold, $window, $identifier)) {
      $this->logThrottled('rate_limit', 'Public comment autofill rate limit exceeded on @event by client @ip. Requests are being rejected with HTTP 429.', ['@event' => $event]);
      return $this->jsonResponse(['error' => 'Too many requests'], 429);
    }
    $this->flood->register($event, $window, $identifier);

    return NULL;
  }

  /**
   * Counts a malformed request and logs once a client makes a run of them.
   *
   * A single mistyped link is not interesting, so nothing is logged until the
   * same client has sent more than MALFORMED_THRESHOLD malformed case IDs in
   * the window, which is the shape of an enumeration attempt. The case ID
   * itself is never logged, since it is attacker-controlled.
   */
  private function registerMalformedRequest(): void {
    $identifier = $this->clientIp();
    $this->flood->register(self::FLOOD_EVENT_INVALID, self::DEFAULT_FLOOD_WINDOW, $identifier);

    if (!$this->flood->isAllowed(self::FLOOD_EVENT_INVALID, self::MALFORMED_THRESHOLD, self::DEFAULT_FLOOD_WINDOW, $identifier)) {
      $this->logThrottled('malformed', 'Repeated malformed case IDs requested on the public comment autofill endpoint by client @ip. This looks like case enumeration.');
    }
  }

  /**
   * Logs a suspected abuse pattern at warning level, a few times per hour.
   *
   * Logging is throttled per client IP so that a flood of malformed or
   * over-threshold requests cannot itself be used to fill the log table.
   *
   * @param string $type
   *   Short pattern name, used to keep the throttles independent.
   * @param string $message
   *   Log message. Must not contain client-supplied values.
   * @param array $context
   *   Extra placeholder values to merge into the log context.
   */
  private function logThrottled(string $type, string $message, array $context = []): void {
    $identifier = $this->clientIp();
    $logEvent   = self::FLOOD_EVENT_LOG . '.' . $type;

    if (!$this->flood->isAllowed($logEvent, self::LOG_THRESHOLD, self::DEFAULT_FLOOD_WINDOW, $identifier)) {
      return;
    }
    $this->flood->register($logEvent, self::DEFAULT_FLOOD_WINDOW, $identifier);

    $this->logger->warning($message, $context + ['@ip' => $identifier]);
  }

  /**
   * Returns the client IP used as the flood identifier.
   *
   * NOTE: behind a CDN or reverse proxy this is the proxy's address unless
   * $settings['reverse_proxy'] is configured in settings.php, which would make
   * the limit global rather than per client.
   */
  private function clientIp(): string {
    return $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
  }

  /**
   * Returns the configured cache lifetime for autofill payloads, in seconds.
   */
  private function cacheTtl(): int {
    $ttl = (int) ($this->configFactory->get('dmr_public_comment.settings')->get('cache_ttl') ?? self::DEFAULT_CACHE_TTL);
    return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
  }

  /**
   * Returns the generic "not found" response.
   *
   * Deliberately identical for a missing case, a case whose comment period is
   * not open, a restricted node, and a failed Pega call, so that the response
   * reveals nothing about which of those applies.
   */
  private function notFound(): JsonResponse {
    return $this->jsonResponse(['error' => 'Not found'], 404);
  }

  /**
   * Builds the JSON response with the module's standard security headers.
   *
   * @param array $data
   *   The response payload.
   * @param int $status
   *   HTTP status code.
   * @param bool $cacheable
   *   TRUE for successful public payloads, which may be cached for the
   *   configured TTL. Everything else is marked no-store.
   */
  private function jsonResponse(array $data, int $status = 200, bool $cacheable = FALSE): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set(
      'Cache-Control',
      $cacheable ? 'public, max-age=' . $this->cacheTtl() : 'private, no-store, max-age=0'
    );
    return $response;
  }

  /**
   * Traverses a nested array using a dot-separated key path.
   *
   * For example, getNestedValue($data, 'Applicant.LegalApplicantName') is
   * equivalent to $data['Applicant']['LegalApplicantName'].
   *
   * @param array $data
   *   The array to traverse.
   * @param string $path
   *   Dot-separated sequence of array keys.
   *
   * @return string
   *   The string value at the path, or an empty string if it does not exist.
   */
  private function getNestedValue(array $data, string $path): string {
    $value = $data;
    foreach (explode('.', $path) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        return '';
      }
      $value = $value[$key];
    }
    return is_scalar($value) ? (string) $value : '';
  }

}
