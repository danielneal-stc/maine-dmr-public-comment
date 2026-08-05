<?php

declare(strict_types=1);

namespace Drupal\dmr_public_comment\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Reads comment period details from a public_comment_form node.
 *
 * Node mode covers applications that are not tracked in Pega, where a member of
 * DMR staff types the details into Drupal instead. The content type and its six
 * fields are created by this module's hook_install() from config/content_type,
 * so their machine names are part of the module rather than something a site
 * builder chose, and they are declared here once. Both webform handlers and the
 * autofill endpoint read them through this service, which means a rename or a
 * deletion on the site produces the same clean refusal everywhere instead of a
 * fatal error in whichever code path happened to run first.
 *
 * Access is part of this service's job, not the caller's. load() applies the
 * bundle, published and view-access checks together, and gives back NULL if any
 * of them fails, so a restricted application cannot be distinguished from one
 * that does not exist by the response it produces.
 */
class PublicCommentNodeSource {

  /**
   * The content type holding a comment period.
   */
  public const BUNDLE = 'public_comment_form';

  /**
   * The applicant's legal name, matching Pega's LegalApplicantName.
   */
  public const FIELD_APPLICANT_NAME = 'field_dmr_pc_applicant_name';

  /**
   * The town or towns the proposal concerns.
   */
  public const FIELD_TOWN = 'field_dmr_pc_town';

  /**
   * The waterbody or location of the proposal.
   */
  public const FIELD_LOCATION = 'field_dmr_pc_location';

  /**
   * The date the comment period opens.
   */
  public const FIELD_PERIOD_START = 'field_dmr_pc_period_start';

  /**
   * The date the comment period closes.
   */
  public const FIELD_PERIOD_END = 'field_dmr_pc_period_end';

  /**
   * The license type, stored as a machine key from an options list.
   */
  public const FIELD_LICENSE_TYPE = 'field_dmr_pc_license_type';

  /**
   * Every field the module installs and requires on the bundle.
   *
   * Kept in step with config/content_type. A field missing from a node is
   * reported by firstMissingField() rather than read blindly.
   */
  public const FIELDS = [
    self::FIELD_APPLICANT_NAME,
    self::FIELD_TOWN,
    self::FIELD_LOCATION,
    self::FIELD_PERIOD_START,
    self::FIELD_PERIOD_END,
    self::FIELD_LICENSE_TYPE,
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads a comment period node that the current user is allowed to see.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL when it does not exist, is the wrong content type, is
   *   unpublished, or the current user has no view access to it. Callers must
   *   give the same answer for all four, so that the endpoint and the form
   *   cannot be used to find out which applies.
   */
  public function load(int $nid): ?NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node instanceof NodeInterface
      || $node->bundle() !== self::BUNDLE
      || !$node->isPublished()
      || !$node->access('view')) {
      return NULL;
    }

    return $node;
  }

  /**
   * Returns the first field this module needs that the node does not have.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to inspect.
   *
   * @return string|null
   *   The machine name of the first missing field, or NULL when the node has
   *   all of them. A missing field means the installed content type has been
   *   altered on the site; callers log the name and refuse the request.
   */
  public function firstMissingField(NodeInterface $node): ?string {
    foreach (self::FIELDS as $fieldName) {
      if (!$node->hasField($fieldName)) {
        return $fieldName;
      }
    }

    return NULL;
  }

  /**
   * Reads a field value as a string, tolerating an empty field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to read.
   * @param string $fieldName
   *   One of the FIELD_* constants.
   *
   * @return string
   *   The value, or an empty string.
   */
  public function value(NodeInterface $node, string $fieldName): string {
    return (string) ($node->get($fieldName)->value ?? '');
  }

  /**
   * Reads a date field as the plain date CommentPeriod parses.
   *
   * Drupal datetime fields store "YYYY-MM-DDTHH:MM:SS". Only the date part is
   * meaningful here: the time of day a period opens and closes is fixed by
   * CommentPeriod, not stored per application.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to read.
   * @param string $fieldName
   *   FIELD_PERIOD_START or FIELD_PERIOD_END.
   *
   * @return string
   *   The date as "YYYY-MM-DD", or an empty string if the field is empty.
   */
  public function dateValue(NodeInterface $node, string $fieldName): string {
    return substr($this->value($node, $fieldName), 0, 10);
  }

  /**
   * Resolves the License Type field to the label the rest of the form uses.
   *
   * The field stores a machine key ("experimental_lease") but everything
   * downstream works in Pega's labels ("Experimental Lease"): the hidden
   * license_type element is compared against the label to decide whether the
   * hearing-request checkbox is shown, and the label is what staff read on the
   * comment. Falls back to the stored key if the allowed-values setting cannot
   * be read, keeping a recognisable value on the record rather than an empty
   * one.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to read.
   *
   * @return string
   *   The display label, or the stored key, or an empty string.
   */
  public function licenseTypeLabel(NodeInterface $node): string {
    if (!$node->hasField(self::FIELD_LICENSE_TYPE)) {
      return '';
    }

    $key = $this->value($node, self::FIELD_LICENSE_TYPE);
    $definition = $node->getFieldDefinition(self::FIELD_LICENSE_TYPE);
    if ($definition === NULL) {
      return $key;
    }

    // getSetting('allowed_values') returns the simplified key => label array,
    // not the list-of-maps shape the field storage config file is written in.
    $allowedValues = $definition->getSetting('allowed_values');

    return is_array($allowedValues) && isset($allowedValues[$key])
      ? (string) $allowedValues[$key]
      : $key;
  }

}
