<?php

namespace Drupal\dmr_public_comment\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Exposes JSON endpoints for the frontend autofill on the public comment form.
 *
 * Two endpoints are provided — one fetching live data from the Pega API for
 * cases linked to Pega (?case_id=), and one reading from a Drupal
 * public_comment_period node for manually-managed applications (?nid=).
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

  public function __construct(
    private readonly PegaCaseService $pegaCaseService,
  ) {}

  /**
   * Injects the PegaCaseService via Drupal's service container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dmr_public_comment.case_service'),
    );
  }

  /**
   * Returns JSON data for a Pega case to pre-populate the public comment form.
   *
   * Validates the case ID format before making any API call to Pega to prevent
   * malformed requests. Returns a 502 if Pega is unreachable or returns no data.
   *
   * @param string $caseId
   *   The short case ID from the URL (e.g. "L-14372").
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with keys: case_id, applicant, town, location, comment_period,
   *   license_type. Returns 400 for invalid format, 502 if Pega call fails.
   */
  public function getCaseData(string $caseId): JsonResponse {
    if (!preg_match('/^[A-Z]+-\d+$/i', $caseId)) {
      return new JsonResponse(['error' => 'Invalid case ID format'], 400);
    }

    $content = $this->pegaCaseService->getCaseContent($caseId);
    if ($content === NULL) {
      return new JsonResponse(['error' => 'Failed to retrieve case data'], 502);
    }

    $start = $this->getNestedValue($content, self::FIELD_PERIOD_START);
    $end   = $this->getNestedValue($content, self::FIELD_PERIOD_END);

    return new JsonResponse([
      'case_id'        => $caseId,
      'applicant'      => $this->getNestedValue($content, self::FIELD_APPLICANT),
      'town'           => $this->getNestedValue($content, self::FIELD_TOWN),
      'location'       => $this->getNestedValue($content, self::FIELD_LOCATION),
      'comment_period' => $start && $end ? $this->formatPegaDate($start) . ' - ' . $this->formatPegaDate($end) : '',
      'license_type'   => $this->getNestedValue($content, self::FIELD_LICENSE_TYPE),
    ]);
  }

  /**
   * Returns JSON data for a public_comment_period node to pre-populate the form.
   *
   * Used when the form is accessed with a ?nid= query parameter (manual/non-Pega
   * applications). Returns 404 if the node does not exist, is not the right
   * content type, or is unpublished (preventing access to draft applications).
   *
   * @param int $nid
   *   The node ID from the URL.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Same JSON shape as getCaseData(): applicant, town, location,
   *   comment_period, license_type.
   */
  public function getNodeData(int $nid): JsonResponse {
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'public_comment_period' || !$node->isPublished()) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }

    // Drupal datetime fields store values as "YYYY-MM-DDTHH:MM:SS"; take the date portion only.
    $start = substr($node->get('field_comment_start')->value ?? '', 0, 10);
    $end   = substr($node->get('field_comment_end')->value ?? '', 0, 10);

    // The Selection list field stores the machine name (e.g. "experimental_lease")
    // but the webform select expects the display label (e.g. "Experimental Lease")
    // to match Pega's format and trigger conditional logic correctly.
    $licenseTypeKey = $node->get('field_license_type')->value ?? '';
    $allowedValues  = $node->getFieldDefinition('field_license_type')->getSetting('allowed_values');
    $licenseType    = $allowedValues[$licenseTypeKey] ?? $licenseTypeKey;

    return new JsonResponse([
      'applicant'      => $node->get('field_applicant_name')->value ?? '',
      'town'           => $node->get('field_town')->value ?? '',
      'location'       => $node->get('field_location')->value ?? '',
      'comment_period' => $start && $end ? $this->formatPegaDate($start) . ' - ' . $this->formatPegaDate($end) : '',
      'license_type'   => $licenseType,
    ]);
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
   *   The string value at the path, or an empty string if the path does not exist.
   */
  private function getNestedValue(array $data, string $path): string {
    $value = $data;
    foreach (explode('.', $path) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        return '';
      }
      $value = $value[$key];
    }
    return (string) ($value ?? '');
  }

  /**
   * Converts a Pega date string to M/D/YYYY display format.
   *
   * Pega may return dates as "YYYYMMDD" or "YYYY-MM-DD". Both are normalised
   * to "YYYYMMDD" before parsing. Returns the original string unchanged if it
   * cannot be parsed.
   *
   * @param string $date
   *   Date string in "YYYYMMDD" or "YYYY-MM-DD" format.
   *
   * @return string
   *   Date formatted as "M/D/YYYY" (e.g. "6/15/2026"), or the original string
   *   if parsing fails.
   */
  private function formatPegaDate(string $date): string {
    $normalized = str_replace('-', '', $date);
    if (strlen($normalized) !== 8 || !ctype_digit($normalized)) {
      return $date;
    }
    $dt = \DateTime::createFromFormat('Ymd', $normalized);
    return $dt ? $dt->format('n/j/Y') : $date;
  }

}
