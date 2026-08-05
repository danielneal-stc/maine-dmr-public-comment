<?php

declare(strict_types=1);

namespace Drupal\dmr_public_comment\Service;

use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides, once per request, which application a comment belongs to.
 *
 * The public comment form runs in one of two modes: Pega mode (a Pega case ID)
 * or node mode (a Drupal public_comment_form node). The mode decides whether a
 * comment is sent to Pega or kept in Drupal, and the reference it resolves to
 * decides which application the comment is recorded against, so it must never
 * be inferred from a value the browser could have altered without being
 * checked.
 *
 * Both sources of that reference are client supplied: the query string, and the
 * form's hidden case_id / nid elements that the autofill script fills in. This
 * resolver therefore fails closed:
 *
 *   - A request presenting BOTH a case reference and a node reference is
 *     rejected outright. Picking a winner would let a crafted request send a
 *     comment to a case the visitor was never looking at.
 *   - A query parameter that disagrees with its matching hidden field is
 *     rejected for the same reason.
 *   - A case ID that is not in Pega's short-case-ID format, and a node
 *     reference that does not resolve to a node, are rejected.
 *
 * When both sources agree, the posted value is returned. That is the value the
 * rest of the submission was built around, and by this point it is known to
 * match the query string.
 */
class SubmissionModeResolver {

  /**
   * Pattern a Pega short case ID must match (e.g. "L-14372").
   */
  public const CASE_ID_PATTERN = '/^[A-Z]+-\d+$/i';

  /**
   * The comment belongs to a Pega case and is sent to Pega.
   */
  public const MODE_PEGA = 'pega';

  /**
   * The comment belongs to a Drupal node and stays in Drupal.
   */
  public const MODE_NODE = 'node';

  /**
   * The request could not be tied to exactly one application.
   */
  public const MODE_INVALID = 'invalid';

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Resolves a nid parameter to a numeric node ID.
   *
   * Accepts either a numeric ID ("5") or a URL alias ("musky-oysters-2026"),
   * because the links Maine publishes use the alias.
   *
   * @param string|null $param
   *   The raw value of the nid query parameter or hidden field.
   *
   * @return int|null
   *   The numeric node ID, or NULL if the value is empty or does not resolve to
   *   a node path. Note that a numeric value is returned as-is: whether it
   *   exists, is published and is viewable is checked by
   *   PublicCommentNodeSource, which is what every caller loads it through.
   */
  public function resolveNid(?string $param): ?int {
    if ($param === NULL || $param === '') {
      return NULL;
    }

    if (ctype_digit($param)) {
      return (int) $param;
    }

    $path = $this->aliasManager->getPathByAlias('/' . ltrim($param, '/'));
    if (preg_match('/^\/node\/(\d+)$/', $path, $matches)) {
      return (int) $matches[1];
    }

    return NULL;
  }

  /**
   * Decides whether a form request is in Pega mode or node mode.
   *
   * @param mixed $postedCaseId
   *   The submitted case_id element value, from the form state during
   *   validation or from the submission entity afterwards. Any type is
   *   tolerated, because this is client input.
   * @param mixed $postedNid
   *   The submitted nid element value, on the same terms.
   *
   * @return array
   *   Associative array with keys:
   *     - mode: one of the MODE_* constants.
   *     - case_id: the validated short case ID in Pega mode, otherwise ''.
   *     - nid: the resolved node ID in node mode, otherwise NULL.
   *     - reason: in invalid mode, one of 'mixed' (both a case and a node),
   *       'conflict' (query string disagrees with the hidden field), 'missing'
   *       (neither was supplied), 'bad_case_id', or 'bad_nid'. Otherwise ''.
   */
  public function resolve(mixed $postedCaseId = NULL, mixed $postedNid = NULL): array {
    $query = $this->requestStack->getCurrentRequest()?->query;

    $case = $this->agreedValue($query?->get('case_id'), $postedCaseId);
    $node = $this->agreedValue($query?->get('nid'), $postedNid);

    if ($case === FALSE || $node === FALSE) {
      return $this->invalid('conflict');
    }
    if ($case !== '' && $node !== '') {
      return $this->invalid('mixed');
    }

    if ($node !== '') {
      $nid = $this->resolveNid($node);
      return $nid === NULL
        ? $this->invalid('bad_nid')
        : ['mode' => self::MODE_NODE, 'case_id' => '', 'nid' => $nid, 'reason' => ''];
    }

    if ($case !== '') {
      return self::isValidCaseId($case)
        ? ['mode' => self::MODE_PEGA, 'case_id' => $case, 'nid' => NULL, 'reason' => '']
        : $this->invalid('bad_case_id');
    }

    return $this->invalid('missing');
  }

  /**
   * Checks a string against Pega's short-case-ID format.
   *
   * The one place this pattern lives. The autofill endpoint applies it to the
   * case ID in its URL before doing anything else with it, and resolve()
   * applies it to a submission's case reference, so a value that reaches Pega
   * has passed exactly the same test either way.
   *
   * @param string $caseId
   *   The candidate case ID.
   *
   * @return bool
   *   TRUE if the value has the shape of a Pega short case ID.
   */
  public static function isValidCaseId(string $caseId): bool {
    return (bool) preg_match(self::CASE_ID_PATTERN, $caseId);
  }

  /**
   * Builds the rejection result for a request that could not be trusted.
   *
   * @param string $reason
   *   The machine reason, for the log only.
   *
   * @return array
   *   An invalid-mode result.
   */
  private function invalid(string $reason): array {
    return ['mode' => self::MODE_INVALID, 'case_id' => '', 'nid' => NULL, 'reason' => $reason];
  }

  /**
   * Reconciles the query-string and posted copies of one client value.
   *
   * Both copies come from the browser, so a disagreement between them is
   * treated as tampering rather than resolved in favour of one of them.
   *
   * @param mixed $queryValue
   *   The value from the query string, if any.
   * @param mixed $postedValue
   *   The value from the submitted form, if any.
   *
   * @return string|false
   *   The agreed value ('' if neither was supplied), or FALSE if the two were
   *   both supplied and differ.
   */
  private function agreedValue(mixed $queryValue, mixed $postedValue): string|false {
    $fromQuery = is_scalar($queryValue) ? trim((string) $queryValue) : '';
    $fromPost  = is_scalar($postedValue) ? trim((string) $postedValue) : '';

    if ($fromQuery !== '' && $fromPost !== '' && strcasecmp($fromQuery, $fromPost) !== 0) {
      return FALSE;
    }

    return $fromPost !== '' ? $fromPost : $fromQuery;
  }

}
