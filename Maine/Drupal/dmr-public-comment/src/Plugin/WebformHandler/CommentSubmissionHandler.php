<?php

namespace Drupal\dmr_public_comment\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a submitted public comment and any attachments to Pega.
 *
 * Supports two modes detected from the request query string:
 *   - Node mode (?nid=): no Pega involvement. The handler returns immediately
 *     and Drupal stores the submission in the database. Email notification is
 *     configured separately on the webform.
 *   - Pega mode (?case_id=): comment and attachments are sent to Pega and
 *     linked to the case. The submission is blocked on error.
 *
 * Runs during form validation so errors can be shown before the submission
 * is saved.
 *
 * @WebformHandler(
 *   id = "comment_submission",
 *   label = @Translation("Comment submission"),
 *   category = @Translation("Pega"),
 *   description = @Translation("Submits the public comment to Pega. Blocks the submission if Pega is unreachable."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class CommentSubmissionHandler extends WebformHandlerBase {

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
    return ['#markup' => $this->t('Submits the public comment and attachments to Pega.')];
  }

  /**
   * Submits the comment to Pega, or defers to Drupal storage for node-based forms.
   *
   * In node mode (?nid=), returns immediately so Drupal handles saving the
   * submission. In Pega mode (?case_id=), the processing order is:
   *   1. POST comment text and metadata to Pega's D_PublicComment data page.
   *      Blocks the submission with an error if this fails.
   *   2. Upload each attached file to the Pega case (two-step process).
   *   3. Retrieve attachment keys for the files just uploaded.
   *   4. Link those attachments to the comment record created in step 1.
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): void {
    // Drupal's AJAX file upload widget fires validateForm for each file upload
    // with an element_parents query parameter. Skip all processing for those.
    if (\Drupal::request()->query->has('element_parents')) {
      return;
    }

    // Node mode: no Pega involvement. Drupal saves the submission to the
    // database and handles the confirmation email.
    if (dmr_public_comment_resolve_nid(\Drupal::request()->query->get('nid'))) {
      return;
    }

    $caseId   = (string) ($form_state->getValue('case_id') ?? '');
    $comments = $form_state->getValue('comments');

    $fields = [
      'CaseID'              => $caseId,
      'FullName'            => (string) ($form_state->getValue('full_name') ?? ''),
      'Email'               => (string) ($form_state->getValue('email') ?? ''),
      'PhoneNumber'         => self::formatPhone((string) ($form_state->getValue('phone_number') ?? '')),
      // The comments field is a rich-text element and may be an array with a 'value' key.
      'Comments'            => is_array($comments) ? (string) ($comments['value'] ?? '') : (string) $comments,
      'LicenseType'         => (string) ($form_state->getValue('license_type') ?? ''),
      'HasRequestedHearing' => (bool) $form_state->getValue('hearing_requested'),
      'IsHonest'            => (bool) $form_state->getValue('confirmtrue'),
    ];

    // Submit the comment. The returned pzInsKey is the Pega handle for the
    // newly created SOM-DMR-Data-Comment record; it is needed to link files.
    $pzInsKey = $this->pegaCaseService->submitComment($fields);
    if (!$pzInsKey) {
      $form_state->setErrorByName('', $this->t('Your comment could not be submitted at this time. Please try again later.'));
      return;
    }

    $fids = array_filter((array) ($form_state->getValue('attachments') ?? []));
    if (empty($fids)) {
      return;
    }

    // Capture the current time before uploading so getCaseAttachments can
    // filter out any files that already existed on the case before this request.
    $startTime  = microtime(TRUE);
    $fileSystem = \Drupal::service('file_system');

    foreach ($fids as $fid) {
      $file = File::load($fid);
      if (!$file) {
        continue;
      }
      $contents = file_get_contents($fileSystem->realpath($file->getFileUri()));
      if ($contents === FALSE) {
        continue;
      }
      $this->pegaCaseService->submitAttachment($caseId, $file->getFilename(), $file->getMimeType(), $contents);
    }

    // Fetch the attachment keys for files uploaded in this request, then link
    // them to the comment record so they appear on the SOM-DMR-Data-Comment
    // page in Pega rather than just on the case.
    $attachmentKeys = $this->pegaCaseService->getCaseAttachments($caseId, $startTime);
    if (!empty($attachmentKeys)) {
      $this->pegaCaseService->linkAttachmentsToComment($caseId, $pzInsKey, $attachmentKeys);
    }
  }

  /**
   * Normalizes a phone number to E.164 format with a +1 country code.
   *
   * Strips all non-digit characters, takes the last 10 digits (to discard any
   * leading country code the user may have typed), and prepends "+1".
   *
   * @param string $phone
   *   Raw phone number as entered by the user.
   *
   * @return string
   *   Formatted phone number (e.g. "+12075551234"), or empty string if the
   *   input contained no digits.
   */
  private static function formatPhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    return $digits ? '+1' . substr($digits, -10) : '';
  }

}
