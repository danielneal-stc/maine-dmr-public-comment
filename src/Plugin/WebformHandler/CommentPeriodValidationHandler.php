<?php

namespace Drupal\dmr_public_comment\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\node\Entity\Node;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Blocks form submissions that fall outside the case's open comment period.
 *
 * Supports two modes detected from the request query string:
 *   - Node mode (?nid=): dates read from the node's field_comment_start /
 *     field_comment_end fields.
 *   - Pega mode (?case_id=): dates fetched live from the Pega API.
 *
 * The period closes at 4:00 PM Eastern time on the end date. America/New_York
 * handles EST/EDT transitions automatically.
 *
 * @WebformHandler(
 *   id = "comment_period_validation",
 *   label = @Translation("Comment period validation"),
 *   category = @Translation("Pega"),
 *   description = @Translation("Blocks submissions outside the open comment period."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class CommentPeriodValidationHandler extends WebformHandlerBase {

  protected PegaCaseService $pegaCaseService;

  /**
   * Injects the PegaCaseService via Drupal's service container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pegaCaseService = $container->get('dmr_public_comment.case_service');
    return $instance;
  }

  /**
   * Returns a plain-text summary shown on the webform handlers admin page.
   */
  public function getSummary(): array {
    return ['#markup' => $this->t('Blocks submissions outside the open comment period (closes at 4:00 PM Eastern).')];
  }

  /**
   * Validates that the submission falls within the open comment period.
   *
   * Detects node mode vs Pega mode from the request query string, then
   * delegates to the appropriate date source before applying the same
   * period check.
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): void {
    // Skip partial AJAX file-upload requests (see CommentSubmissionHandler).
    if (\Drupal::request()->query->has('element_parents')) {
      return;
    }

    $nid = dmr_public_comment_resolve_nid(\Drupal::request()->query->get('nid'));
    if ($nid) {
      $this->validateFromNode($nid, $form_state);
    }
    else {
      $this->validateFromPega($form_state);
    }
  }

  /**
   * Validates the comment period using dates stored on a public_comment_form node.
   */
  private function validateFromNode(int $nid, FormStateInterface $form_state): void {
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'public_comment_form' || !$node->isPublished()) {
      $form_state->setErrorByName('', $this->t('This application could not be found.'));
      return;
    }

    // Drupal datetime fields store "YYYY-MM-DDTHH:MM:SS"; take the date portion.
    $startStr = substr($node->get('field_comment_start')->value ?? '', 0, 10);
    $endStr   = substr($node->get('field_comment_end')->value ?? '', 0, 10);

    if (!$startStr || !$endStr) {
      $form_state->setErrorByName('', $this->t('This application is not currently open for public comment.'));
      return;
    }

    $this->checkPeriod($startStr, $endStr, $form_state);
  }

  /**
   * Validates the comment period by fetching dates from the Pega API.
   */
  private function validateFromPega(FormStateInterface $form_state): void {
    $caseId = trim((string) ($form_state->getValue('case_id') ?? ''));

    if (!$caseId || !preg_match('/^[A-Z]+-\d+$/i', $caseId)) {
      $form_state->setErrorByName('', $this->t('A valid case ID is required.'));
      return;
    }

    $content = $this->pegaCaseService->getCaseContent($caseId);
    if ($content === NULL) {
      $form_state->setErrorByName('', $this->t('Unable to verify the comment period. Please try again later.'));
      return;
    }

    $startStr = $content['PublicCommentStartDate'] ?? '';
    $endStr   = $content['PublicCommentEndDate'] ?? '';

    if (!$startStr || !$endStr) {
      $form_state->setErrorByName('', $this->t('This application is not currently open for public comment.'));
      return;
    }

    $this->checkPeriod($startStr, $endStr, $form_state);
  }

  /**
   * Checks whether the current Eastern time falls within the comment period.
   *
   * @param string $startStr
   *   Start date in YYYY-MM-DD format. Period opens at midnight Eastern.
   * @param string $endStr
   *   End date in YYYY-MM-DD format. Period closes at 4:00 PM Eastern.
   */
  private function checkPeriod(string $startStr, string $endStr, FormStateInterface $form_state): void {
    $tz    = new \DateTimeZone('America/New_York');
    $now   = new \DateTime('now', $tz);
    $start = \DateTime::createFromFormat('Y-m-d', $startStr, $tz)->setTime(0, 0, 0);
    $end   = \DateTime::createFromFormat('Y-m-d', $endStr, $tz)->setTime(16, 0, 0);

    if ($now < $start) {
      $form_state->setErrorByName('', $this->t('The comment period for this application has not yet begun.'));
      return;
    }

    if ($now >= $end) {
      $form_state->setErrorByName('', $this->t('The comment period for this application has closed. All comments must be submitted before 4:00 PM Eastern time on the closing date.'));
    }
  }

}
