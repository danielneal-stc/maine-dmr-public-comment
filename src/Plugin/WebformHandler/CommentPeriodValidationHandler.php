<?php

namespace Drupal\dmr_public_comment\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\dmr_public_comment\Service\CommentPeriod;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\dmr_public_comment\Service\PublicCommentNodeSource;
use Drupal\dmr_public_comment\Service\SubmissionModeResolver;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Blocks form submissions that fall outside the case's open comment period.
 *
 * Supports two modes, resolved by dmr_public_comment.mode_resolver from the
 * query string and the form's hidden fields together:
 *   - Node mode: dates read from the period fields on a public_comment_form
 *     node.
 *   - Pega mode: dates fetched live from the Pega API.
 *
 * A request that presents both, or whose query string disagrees with its hidden
 * fields, is rejected rather than resolved to one of the two.
 *
 * Whether a period is open is decided by dmr_public_comment.comment_period, the
 * same service the autofill endpoints ask, so a case that can populate the form
 * is exactly a case that can receive a comment.
 *
 * This handler is also the Pega pre-flight for CommentSubmissionHandler: the
 * live getCaseContent() call below runs during validation, so a submission is
 * blocked here when Pega is unreachable. That is what makes it safe for the
 * submission handler to defer its Pega writes until after the submission is
 * saved.
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

  /**
   * Fetches case content from Pega in Pega mode.
   *
   * The call it makes during validation is also the pre-flight that blocks a
   * submission when Pega is unreachable.
   *
   * @var \Drupal\dmr_public_comment\Service\PegaCaseService
   */
  protected PegaCaseService $pegaCaseService;

  /**
   * The module's own log channel.
   *
   * Named moduleLogger because WebformHandlerBase already has a $logger, which
   * writes to the webform channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $moduleLogger;

  /**
   * Decides whether the request is a Pega case or a Drupal node.
   *
   * @var \Drupal\dmr_public_comment\Service\SubmissionModeResolver
   */
  protected SubmissionModeResolver $modeResolver;

  /**
   * Decides whether a comment period is open.
   *
   * The same service the autofill endpoints ask, so a case that can populate
   * the form is exactly a case that can receive a comment.
   *
   * @var \Drupal\dmr_public_comment\Service\CommentPeriod
   */
  protected CommentPeriod $commentPeriod;

  /**
   * Reads comment period details from a public_comment_form node.
   *
   * @var \Drupal\dmr_public_comment\Service\PublicCommentNodeSource
   */
  protected PublicCommentNodeSource $nodeSource;

  /**
   * Injects the module's services via Drupal's service container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance                  = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pegaCaseService = $container->get('dmr_public_comment.case_service');
    $instance->moduleLogger    = $container->get('logger.channel.dmr_public_comment');
    $instance->modeResolver    = $container->get('dmr_public_comment.mode_resolver');
    $instance->commentPeriod   = $container->get('dmr_public_comment.comment_period');
    $instance->nodeSource      = $container->get('dmr_public_comment.node_source');
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
   * Resolves the submission mode first, refusing ambiguous or tampered
   * requests, then delegates to the appropriate date source before applying the
   * same period check.
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): void {
    // Drupal's AJAX file upload widget fires validateForm for each file upload
    // with an element_parents query parameter. Skip all processing for those.
    if (\Drupal::request()->query->has('element_parents')) {
      return;
    }

    $mode = $this->modeResolver->resolve(
      $form_state->getValue('case_id'),
      $form_state->getValue('nid')
    );

    switch ($mode['mode']) {
      case SubmissionModeResolver::MODE_NODE:
        $this->validateFromNode($mode['nid'], $form_state);
        return;

      case SubmissionModeResolver::MODE_PEGA:
        $this->validateFromPega($mode['case_id'], $form_state);
        return;

      default:
        $this->rejectMode($mode['reason'], $form_state);
    }
  }

  /**
   * Rejects a request whose submission mode could not be trusted.
   *
   * The visitor gets a short, non-technical message in every case; the specific
   * reason goes to the log only, so a crafted request learns nothing about
   * which check it tripped.
   *
   * @param string $reason
   *   The machine reason from the mode resolver.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to attach the error to.
   */
  private function rejectMode(string $reason, FormStateInterface $form_state): void {
    // 'mixed' and 'conflict' cannot happen through the published links, so they
    // are worth a log entry. A bad or missing reference is usually just a
    // mistyped or truncated link and is not logged.
    if ($reason === 'mixed' || $reason === 'conflict') {
      $this->moduleLogger->warning('A public comment submission was refused because its application reference was inconsistent (@reason): the request carried both a case ID and a node reference, or its query string disagreed with its hidden fields. No comment was created.', [
        '@reason' => $reason,
      ]);
    }

    $form_state->setErrorByName('', $this->t('This form could not be matched to a single application. Please reopen the link you were given and try again.'));
  }

  /**
   * Validates the comment period using dates stored on a node.
   *
   * @param int $nid
   *   The node ID already resolved by the mode resolver.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to attach any error to.
   */
  private function validateFromNode(int $nid, FormStateInterface $form_state): void {
    // The node source applies the same conditions as the node autofill
    // endpoint, so an application that will not populate the form cannot
    // receive comments either, and all of them produce the same message, so a
    // restricted node is indistinguishable from a missing one.
    $node = $this->nodeSource->load($nid);
    if ($node === NULL) {
      $form_state->setErrorByName('', $this->t('This application could not be found.'));
      return;
    }

    // A missing field means the content type this module installed has been
    // altered. Fail with a clean form error and a log entry, not a fatal error.
    $missingField = $this->nodeSource->firstMissingField($node);
    if ($missingField !== NULL) {
      $this->moduleLogger->warning('The @field field is missing from the @bundle content type, so the comment period for node @nid cannot be checked and the submission was refused. This field is installed with the DMR Public Comment module; restore it by reinstalling the module on a site with no comment periods, or recreate it with exactly that machine name.', [
        '@field'  => $missingField,
        '@bundle' => PublicCommentNodeSource::BUNDLE,
        '@nid'    => $nid,
      ]);
      $form_state->setErrorByName('', $this->t('This application is not currently open for public comment.'));
      return;
    }

    $startStr = $this->nodeSource->dateValue($node, PublicCommentNodeSource::FIELD_PERIOD_START);
    $endStr   = $this->nodeSource->dateValue($node, PublicCommentNodeSource::FIELD_PERIOD_END);

    if ($startStr === '' || $endStr === '') {
      $form_state->setErrorByName('', $this->t('This application is not currently open for public comment.'));
      return;
    }

    $this->checkPeriod($startStr, $endStr, $form_state);
  }

  /**
   * Validates the comment period by fetching dates from the Pega API.
   *
   * This is also the point at which an unreachable Pega blocks the submission,
   * before CommentSubmissionHandler writes anything.
   *
   * @param string $caseId
   *   The short case ID already validated by the mode resolver.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to attach any error to.
   */
  private function validateFromPega(string $caseId, FormStateInterface $form_state): void {
    $content = $this->pegaCaseService->getCaseContent($caseId);
    if ($content === NULL) {
      $form_state->setErrorByName('', $this->t('Unable to verify the comment period. Please try again later.'));
      return;
    }

    $startStr = is_scalar($content['PublicCommentStartDate'] ?? NULL) ? (string) $content['PublicCommentStartDate'] : '';
    $endStr   = is_scalar($content['PublicCommentEndDate'] ?? NULL) ? (string) $content['PublicCommentEndDate'] : '';

    if ($startStr === '' || $endStr === '') {
      $form_state->setErrorByName('', $this->t('This application is not currently open for public comment.'));
      return;
    }

    $this->checkPeriod($startStr, $endStr, $form_state);
  }

  /**
   * Turns the comment period's status into the message the visitor sees.
   *
   * The comparison itself, the timezone and the 4:00 PM close all belong to the
   * comment period service. What belongs here is the wording: a period that has
   * not started and one that has finished get different, specific messages,
   * while dates that cannot be read are logged for an operator and produce the
   * same neutral message as an application with no dates at all. Before that
   * service existed, an unexpected date format reached setTime() on the FALSE
   * that createFromFormat() returns and raised a TypeError.
   *
   * @param string $startStr
   *   Start date in "YYYYMMDD" or "YYYY-MM-DD" format.
   * @param string $endStr
   *   End date in the same formats.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to attach any error to.
   */
  private function checkPeriod(string $startStr, string $endStr, FormStateInterface $form_state): void {
    switch ($this->commentPeriod->status($startStr, $endStr)) {
      case CommentPeriod::STATUS_OPEN:
        return;

      case CommentPeriod::STATUS_NOT_YET_OPEN:
        $form_state->setErrorByName('', $this->t('The comment period for this application has not yet begun.'));
        return;

      case CommentPeriod::STATUS_CLOSED:
        $form_state->setErrorByName('', $this->t('The comment period for this application has closed. All comments must be submitted before 4:00 PM Eastern time on the closing date.'));
        return;

      default:
        // The dates are set on the case or node but are not dates we can read.
        // Log it so an operator can fix the source data; the visitor gets the
        // same "not open" message as a case with no dates at all.
        $this->moduleLogger->warning('A comment period could not be checked because its start or end date was not in a recognised format ("YYYYMMDD" or "YYYY-MM-DD"). The submission was refused. Check the comment period dates on the application.');
        $form_state->setErrorByName('', $this->t('This application is not currently open for public comment.'));
    }
  }

}
