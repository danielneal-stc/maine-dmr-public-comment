<?php

namespace Drupal\dmr_public_comment\Plugin\WebformHandler;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\dmr_public_comment\Service\PegaCaseService;
use Drupal\dmr_public_comment\Service\PublicCommentNodeSource;
use Drupal\dmr_public_comment\Service\SubmissionModeResolver;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a completed public comment and any attachments to Pega.
 *
 * Supports two modes, resolved by dmr_public_comment.mode_resolver from the
 * query string and the form's hidden fields together:
 *   - Node mode (?nid=): no Pega involvement. Drupal stores the submission and
 *     the webform's own email handler notifies staff.
 *   - Pega mode (?case_id=): the comment and its attachments are sent to Pega
 *     and linked to the case.
 *
 * Ordering. Everything that writes to Pega happens in postSave(), after the
 * submission has been accepted and stored, and only on the initial save. It
 * used to run in validateForm(), which Drupal may call more than once for one
 * visitor: a validator failing after this handler had already run left a Pega
 * comment record, and possibly orphaned attachments, for a submission that was
 * never completed. Deferring the writes is safe because
 * CommentPeriodValidationHandler still reads the case from Pega during
 * validation, so a submission is already blocked when Pega is unreachable.
 *
 * Trust. Nothing that decides where a comment goes, or what it is recorded
 * against, is taken from the browser. The case ID is reconciled against the
 * query string, the case content is re-read from Pega in the submit path, and
 * the license type is taken from that content rather than from the hidden field
 * the autofill script populates.
 *
 * @WebformHandler(
 *   id = "comment_submission",
 *   label = @Translation("Comment submission"),
 *   category = @Translation("Pega"),
 *   description = @Translation("Submits the completed public comment to Pega and reports any failure to the submitter."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class CommentSubmissionHandler extends WebformHandlerBase {

  // Where a submitter is told to go when their comment could not reach Pega.
  // Matches the address the webform copies on every confirmation email.
  private const SUPPORT_EMAIL = 'dmraquaculture@maine.gov';

  // Length, in hex characters, of the per-submission correlation token that is
  // embedded in every uploaded filename. 16 characters is 64 bits, far more
  // than is needed to keep two people commenting on the same case at the same
  // moment apart, and short enough to stay tolerable in the filename Pega
  // shows to staff. See pegaFilename() for where it sits in the name.
  private const TOKEN_LENGTH    = 16;
  private const TOKEN_SEPARATOR = '__';

  // Longest filename sent to Pega, before Pega appends its own unique ID.
  private const MAX_FILENAME_LENGTH = 150;

  // How long the "this submission already has a Pega comment record" marker is
  // kept. It only has to outlive any retry of the same submission, so it is
  // deliberately short lived and expires itself rather than accumulating.
  private const IDEMPOTENCY_TTL = 2592000;

  /**
   * Creates the Pega comment record and uploads its attachments.
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
   * Generates the per-submission token embedded in each uploaded filename.
   *
   * That token is what ties an attachment in Pega to the comment record it
   * arrived with, so two people commenting on the same case at the same moment
   * cannot have their files cross-linked.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuidGenerator;

  /**
   * Decides whether the request is a Pega case or a Drupal node.
   *
   * @var \Drupal\dmr_public_comment\Service\SubmissionModeResolver
   */
  protected SubmissionModeResolver $modeResolver;

  /**
   * Reads the application details from a public_comment_form node.
   *
   * @var \Drupal\dmr_public_comment\Service\PublicCommentNodeSource
   */
  protected PublicCommentNodeSource $nodeSource;

  /**
   * File usage tracking, or NULL when the File module is not installed.
   *
   * The module's info.yml declares drupal:file, so on a correctly installed
   * site this is always present. It is still resolved defensively rather than
   * assumed: a site that force-uninstalled the File module would otherwise take
   * the whole form down at container build time, and without that module there
   * are no uploads to track anyway.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface|null
   */
  protected ?FileUsageInterface $fileUsage;

  /**
   * Records which submissions already have a Pega comment record.
   *
   * Keyed by Drupal submission ID, holding the pzInsKey that was created. See
   * sendToPega() for how it is used.
   */
  protected KeyValueStoreExpirableInterface $createdComments;

  /**
   * Injects the module's services via Drupal's service container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance                  = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pegaCaseService = $container->get('dmr_public_comment.case_service');
    $instance->moduleLogger    = $container->get('logger.channel.dmr_public_comment');
    $instance->uuidGenerator   = $container->get('uuid');
    $instance->fileUsage       = $container->has('file.usage') ? $container->get('file.usage') : NULL;
    $instance->createdComments = self::expirableStore($container);
    $instance->modeResolver    = $container->get('dmr_public_comment.mode_resolver');
    $instance->nodeSource      = $container->get('dmr_public_comment.node_source');
    // $messenger is declared (untyped) on the base class by MessengerTrait, so
    // it is set rather than redeclared here. $entityTypeManager, which this
    // handler uses to load files, is already set by the parent.
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  /**
   * Returns the expiring store that records which submissions reached Pega.
   *
   * The store has to be an EXPIRING one. Its entries are written with a TTL so
   * that the idempotency markers clear themselves instead of growing without
   * limit, and setWithExpire() exists only on the expirable interface.
   *
   * The check is here because core's KeyValueExpirableFactory inherits its
   * get() from KeyValueFactory, whose declared return type is the non-expiring
   * KeyValueStoreInterface, even though the object it returns is expirable. The
   * narrowing is therefore established rather than assumed, and a container
   * that ever returned a plain store is refused instead of quietly producing
   * markers that never expire.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   *   The collection holding this module's idempotency markers.
   */
  private static function expirableStore(ContainerInterface $container): KeyValueStoreExpirableInterface {
    $store = $container->get('keyvalue.expirable')->get('dmr_public_comment.pega_comment');

    if (!$store instanceof KeyValueStoreExpirableInterface) {
      throw new \LogicException("The 'keyvalue.expirable' service returned a store that cannot expire its entries, so the DMR Public Comment module cannot record which submissions have already been sent to Pega.");
    }

    return $store;
  }

  /**
   * Returns a plain-text summary shown on the webform handlers admin page.
   */
  public function getSummary(): array {
    return ['#markup' => $this->t('Submits the completed public comment and attachments to Pega.')];
  }

  /**
   * Replaces the client-supplied license type with the authoritative value.
   *
   * License_type is a hidden element the autofill script fills in from the
   * browser, so its posted value proves nothing. It is re-derived here, before
   * the submission is written, so that the stored record, the confirmation
   * email and the Pega record all carry the same value from the same source:
   * Pega's copy of the case, or the node, read through the same access rules
   * the rest of the module applies. Anything that cannot be re-derived is
   * recorded as empty rather than as whatever the browser claimed.
   */
  public function preSave(WebformSubmissionInterface $webform_submission): void {
    if (!$webform_submission->isNew()) {
      return;
    }

    $mode = $this->resolveMode($webform_submission);

    $licenseType = '';
    if ($mode['mode'] === SubmissionModeResolver::MODE_PEGA) {
      $content     = $this->caseContent($mode['case_id']);
      $licenseType = $content === NULL ? '' : $this->plainField($content['LicenseType'] ?? '');
    }
    elseif ($mode['mode'] === SubmissionModeResolver::MODE_NODE) {
      $node        = $this->nodeSource->load($mode['nid']);
      $licenseType = $node === NULL ? '' : $this->nodeSource->licenseTypeLabel($node);
    }

    $webform_submission->setElementData('license_type', $licenseType);
  }

  /**
   * Sends the completed submission to Pega.
   *
   * Runs only on the initial save, so an administrator re-saving a submission
   * later never produces a second comment record in Pega.
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE): void {
    if ($update) {
      return;
    }

    $mode = $this->resolveMode($webform_submission);
    if ($mode['mode'] !== SubmissionModeResolver::MODE_PEGA) {
      // Node mode keeps the comment and its files in Drupal. An ambiguous
      // request never reaches this point: CommentPeriodValidationHandler
      // refuses it during validation.
      return;
    }

    $this->sendToPega($webform_submission, $mode['case_id']);
  }

  /**
   * Creates the Pega comment record and sends any attachments.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The saved submission.
   * @param string $caseId
   *   The short case ID, already reconciled against the query string.
   */
  private function sendToPega(WebformSubmissionInterface $webform_submission, string $caseId): void {
    $sid = (string) $webform_submission->id();

    // Idempotency. postSave() with $update === FALSE fires once per submission,
    // so this only matters if something re-runs the insert path for a
    // submission that has already been through it. The marker is written the
    // instant the Pega record exists, so a second run stops here instead of
    // creating a duplicate comment for the same person.
    if ($sid !== '' && $this->createdComments->get($sid) !== NULL) {
      $this->moduleLogger->notice('Public comment submission @sid was not sent to Pega again: a comment record already exists for it.', ['@sid' => $sid]);
      return;
    }

    // Re-read the case from Pega rather than trusting anything the browser
    // sent about it. This is also what the license type comes from.
    $content = $this->caseContent($caseId);
    if ($content === NULL) {
      $this->reportCommentFailure($caseId, $sid);
      return;
    }

    $fields = [
      'CaseID'              => $caseId,
      'FullName'            => $this->plainField($webform_submission->getElementData('full_name')),
      'Email'               => $this->plainField($webform_submission->getElementData('email')),
      'PhoneNumber'         => self::formatPhone($this->plainField($webform_submission->getElementData('phone_number'))),
      'Comments'            => $this->sanitizeComment($webform_submission->getElementData('comments')),
      'LicenseType'         => $this->plainField($content['LicenseType'] ?? ''),
      'HasRequestedHearing' => (bool) $webform_submission->getElementData('hearing_requested'),
      'IsHonest'            => (bool) $webform_submission->getElementData('confirmtrue'),
    ];

    $pzInsKey = $this->pegaCaseService->submitComment($fields);
    if (!$pzInsKey) {
      $this->reportCommentFailure($caseId, $sid);
      return;
    }

    // Recorded before anything else is attempted, so a repeat run can never
    // add a second comment record even if the attachment steps below fail.
    if ($sid !== '') {
      $this->createdComments->setWithExpire($sid, $pzInsKey, self::IDEMPOTENCY_TTL);
    }

    $this->sendAttachments($webform_submission, $caseId, $pzInsKey, $sid);
  }

  /**
   * Uploads the submission's files to the case and links them to the comment.
   *
   * Files are only removed from Drupal once both the upload and the link have
   * been confirmed, so a failure anywhere leaves the originals in place for
   * staff to recover.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The saved submission.
   * @param string $caseId
   *   The short case ID.
   * @param string $pzInsKey
   *   The Pega handle of the comment record just created.
   * @param string $sid
   *   The Drupal submission ID, for logging.
   */
  private function sendAttachments(WebformSubmissionInterface $webform_submission, string $caseId, string $pzInsKey, string $sid): void {
    $fids = array_filter((array) ($webform_submission->getElementData('attachments') ?? []));
    if (!$fids) {
      return;
    }

    $token   = $this->correlationToken();
    $storage = $this->entityTypeManager->getStorage('file');

    $uploaded   = [];
    $incomplete = FALSE;

    foreach ($fids as $fid) {
      $file = $storage->load($fid);
      if ($file instanceof FileInterface && $this->uploadFile($caseId, $token, $file)) {
        $uploaded[] = $file;
        continue;
      }

      $incomplete = TRUE;
      $this->moduleLogger->error('Attachment @fid on public comment submission @sid (case @case) was not stored in Pega (ref @ref). The file is still in the Drupal file storage.', [
        '@fid'  => $fid,
        '@sid'  => $sid,
        '@case' => $caseId,
        '@ref'  => $this->failureReference(),
      ]);
    }

    if ($uploaded) {
      if ($this->linkUploads($caseId, $pzInsKey, $token, $uploaded, $sid)) {
        $this->deleteFiles($uploaded, $sid);
      }
      else {
        $incomplete = TRUE;
      }
    }

    if ($incomplete) {
      $this->reportAttachmentFailure($caseId, $sid);
    }
  }

  /**
   * Streams one file to Pega and associates it with the case.
   *
   * The file is passed to the API client as an open stream, not as a string, so
   * the whole of a large upload never has to fit in PHP's memory limit. The
   * handle is closed on every path, including a failure or an exception from
   * the HTTP client.
   *
   * @param string $caseId
   *   The short case ID.
   * @param string $token
   *   This submission's correlation token.
   * @param \Drupal\file\FileInterface $file
   *   The managed file to send.
   *
   * @return bool
   *   TRUE if Pega stored the file on the case.
   */
  private function uploadFile(string $caseId, string $token, FileInterface $file): bool {
    $stream = @fopen((string) $file->getFileUri(), 'rb');
    if ($stream === FALSE) {
      $this->moduleLogger->error('Attachment @fid could not be opened for upload to Pega case @case, so it was skipped.', [
        '@fid'  => $file->id(),
        '@case' => $caseId,
      ]);
      return FALSE;
    }

    try {
      return $this->pegaCaseService->submitAttachment(
        $caseId,
        $this->pegaFilename($token, (string) $file->getFilename()),
        $stream
      );
    }
    finally {
      // Guzzle closes the stream itself once the request body has been
      // consumed, so the handle may already be gone by the time we get here.
      if (is_resource($stream)) {
        fclose($stream);
      }
    }
  }

  /**
   * Links this submission's uploads to its comment record.
   *
   * Every uploaded file must come back from the attachment list, otherwise the
   * comment record would end up with only some of the submitter's files and
   * nobody would know which were missing.
   *
   * @param string $caseId
   *   The short case ID.
   * @param string $pzInsKey
   *   The Pega handle of the comment record.
   * @param string $token
   *   This submission's correlation token.
   * @param array $uploaded
   *   The files Pega confirmed it stored.
   * @param string $sid
   *   The Drupal submission ID, for logging.
   *
   * @return bool
   *   TRUE only if every uploaded file was found and linked.
   */
  private function linkUploads(string $caseId, string $pzInsKey, string $token, array $uploaded, string $sid): bool {
    $attachmentKeys = $this->pegaCaseService->getCaseAttachments($caseId, $token);

    if (count($attachmentKeys) !== count($uploaded)) {
      $this->moduleLogger->error('Pega returned @found of the @expected attachments uploaded for public comment submission @sid on case @case, so none were linked to the comment record. The files are on the case but not on the comment. Check the preceding Pega log entry.', [
        '@found'    => count($attachmentKeys),
        '@expected' => count($uploaded),
        '@sid'      => $sid,
        '@case'     => $caseId,
      ]);
      return FALSE;
    }

    if (!$this->pegaCaseService->linkAttachmentsToComment($caseId, $pzInsKey, $attachmentKeys)) {
      $this->moduleLogger->error('The attachments for public comment submission @sid could not be linked to their comment record on case @case. The files are on the case but not on the comment.', [
        '@sid'  => $sid,
        '@case' => $caseId,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Deletes the Drupal copies of files that are now stored in Pega.
   *
   * Only ever called for Pega-mode submissions whose upload and link both
   * succeeded. Node-mode files belong to the Drupal submission and are never
   * touched.
   *
   * @param array $files
   *   The files to remove.
   * @param string $sid
   *   The Drupal submission ID, for logging.
   */
  private function deleteFiles(array $files, string $sid): void {
    foreach ($files as $file) {
      try {
        // Webform registers a file usage record for every attachment during
        // the element's own postSave, which runs just before this handler.
        // Releasing it first stops a row pointing at a file that no longer
        // exists from being left behind.
        $this->fileUsage?->delete($file, 'webform', 'webform_submission', $sid);
        $file->delete();
      }
      catch (\Exception $e) {
        $this->moduleLogger->warning('The Drupal copy of attachment @fid from public comment submission @sid could not be removed after Pega stored it, so it is still in the site file storage.', [
          '@fid' => $file->id(),
          '@sid' => $sid,
        ]);
      }
    }
  }

  /**
   * Tells the submitter, and the log, that the comment never reached Pega.
   *
   * The submission itself is stored in Drupal and the webform still sends its
   * confirmation email, so the comment is not lost; what failed is the delivery
   * into the licensing system, and the submitter is given a reference they can
   * quote so staff can find the matching log entry.
   */
  private function reportCommentFailure(string $caseId, string $sid): void {
    $reference = $this->failureReference();

    $this->moduleLogger->error('A public comment could not be recorded in Pega for case @case (Drupal submission @sid, ref @ref). The submission is stored in Drupal and the submitter was shown an error. The Pega log entry immediately before this one carries the HTTP status.', [
      '@case' => $caseId,
      '@sid'  => $sid,
      '@ref'  => $reference,
    ]);

    $this->messenger()->addError($this->t('Your comment was saved, but it could not be delivered to the Department of Marine Resources licensing system. Please email @email and quote reference @ref so that your comment can be recorded against this application.', [
      '@email' => self::SUPPORT_EMAIL,
      '@ref'   => $reference,
    ]));
  }

  /**
   * Tells the submitter, and the log, that some files never reached Pega.
   *
   * The comment itself was recorded, so this is deliberately a separate,
   * narrower message: only the files need re-sending.
   */
  private function reportAttachmentFailure(string $caseId, string $sid): void {
    $reference = $this->failureReference();

    $this->moduleLogger->error('One or more attachments on public comment submission @sid (case @case) did not reach the comment record in Pega (ref @ref). The submitter was asked to email the files. The Drupal copies were kept.', [
      '@sid'  => $sid,
      '@case' => $caseId,
      '@ref'  => $reference,
    ]);

    $this->messenger()->addError($this->t('Your comment was received, but one or more of the files you attached could not be delivered. Please email your files to @email and quote reference @ref.', [
      '@email' => self::SUPPORT_EMAIL,
      '@ref'   => $reference,
    ]));
  }

  /**
   * Returns the correlation reference to show the submitter and log.
   *
   * Prefers the reference the API client already logged for the failure, so the
   * two entries line up. Falls back to a fresh one when the failure had no HTTP
   * exchange behind it, such as a missing key or an unapproved base URL.
   */
  private function failureReference(): string {
    $reference = $this->pegaCaseService->getLastFailureReference();

    return $reference !== '' ? $reference : substr(hash('sha256', uniqid('', TRUE)), 0, 8);
  }

  /**
   * Resolves the submission mode from the request and the stored values.
   */
  private function resolveMode(WebformSubmissionInterface $webform_submission): array {
    return $this->modeResolver->resolve(
      $webform_submission->getElementData('case_id'),
      $webform_submission->getElementData('nid')
    );
  }

  /**
   * Fetches case content from Pega, reusing this request's earlier fetch.
   *
   * This used to memoise the result on the handler itself, which meant it was
   * only ever shared between preSave() and postSave(). The comment period
   * validation handler is a DIFFERENT object, so its earlier read of the same
   * case in the same request was never reused and every submission asked Pega
   * for the case twice. The reuse now lives on the Pega service, which is
   * shared for the request, so one submission means one read.
   *
   * @param string $caseId
   *   The short case ID.
   *
   * @return array|null
   *   The case content, or NULL if Pega did not return it.
   */
  private function caseContent(string $caseId): ?array {
    return $this->pegaCaseService->getCaseContent($caseId);
  }

  /**
   * Generates this submission's attachment correlation token.
   */
  private function correlationToken(): string {
    return substr(str_replace('-', '', $this->uuidGenerator->generate()), 0, self::TOKEN_LENGTH);
  }

  /**
   * Builds the filename an attachment is uploaded to Pega under.
   *
   * The token goes between the submitter's filename and its extension, so staff
   * see the original name first: "site_plan__1a2b3c4d5e6f7a8b.pdf".
   * getCaseAttachments() matches on the filename merely CONTAINING the token,
   * so its position does not matter, and anything Pega appends of its own
   * leaves the token intact. What would break matching is the token being cut
   * in half, so only the submitter's part of the name is ever shortened; the
   * token and the extension are always kept whole.
   *
   * @param string $token
   *   This submission's correlation token.
   * @param string $filename
   *   The file's name as Drupal sanitised it on upload.
   *
   * @return string
   *   The tokenised filename, within MAX_FILENAME_LENGTH.
   */
  private function pegaFilename(string $token, string $filename): string {
    $filename  = trim($filename);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $suffix    = $extension === '' ? '' : '.' . $extension;
    $base      = $suffix === '' ? $filename : substr($filename, 0, -strlen($suffix));

    if ($base === '') {
      $base = 'attachment';
    }

    $budget = self::MAX_FILENAME_LENGTH - strlen($token) - strlen(self::TOKEN_SEPARATOR) - strlen($suffix);
    if (strlen($base) > $budget) {
      $base = substr($base, 0, max(1, $budget));
    }

    return $base . self::TOKEN_SEPARATOR . $token . $suffix;
  }

  /**
   * Reduces the submitted comment to plain text before it leaves Drupal.
   *
   * The comment arrives from a rich-text element, and Pega, the case worker
   * portal and the notification emails downstream of it all treat what they are
   * given as content to render. Nothing but text is sent, so none of them can
   * be handed markup or a script by a submitter. This stays in place after the
   * element itself becomes a plain textarea: it is the server-side boundary,
   * not a substitute for the element's own configuration.
   *
   * @param mixed $value
   *   The raw element value. A text_format element supplies an array with a
   *   'value' key.
   *
   * @return string
   *   The comment as plain text, with its paragraph breaks preserved.
   */
  private function sanitizeComment($value): string {
    if (is_array($value)) {
      $value = $value['value'] ?? '';
    }
    $text = is_scalar($value) ? (string) $value : '';

    // Turn the block-level markup into line breaks before the tags go, so the
    // plain text keeps the shape the submitter typed.
    $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
    $text = (string) preg_replace('#</(?:p|div|li|tr|h[1-6])\s*>#i', "\n", $text);

    return $this->normalize($this->stripMarkup($text), TRUE);
  }

  /**
   * Reduces a single-line submitted value to plain text.
   *
   * @param mixed $value
   *   The raw element or Pega value.
   *
   * @return string
   *   The value as a single line of plain text.
   */
  private function plainField($value): string {
    $text = is_scalar($value) ? (string) $value : '';

    return $this->normalize($this->stripMarkup($text), FALSE);
  }

  /**
   * Removes markup from a string, including markup hidden behind entities.
   *
   * Tags are stripped, entities are decoded, and tags are stripped again: the
   * decode step can turn an escaped "&lt;script&gt;" back into real markup, so
   * the second pass is what stops it leaving Drupal as a tag.
   */
  private function stripMarkup(string $text): string {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return strip_tags($text);
  }

  /**
   * Normalises whitespace and removes control characters.
   *
   * Control characters are dropped so a submitted value cannot inject
   * line-based structure into anything that renders it downstream.
   *
   * @param string $text
   *   The text to normalise.
   * @param bool $allowLineBreaks
   *   TRUE to keep paragraph breaks, FALSE to collapse the value to one line.
   *
   * @return string
   *   The normalised text.
   */
  private function normalize(string $text, bool $allowLineBreaks): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // Byte-wise on purpose: this range cannot appear inside a multi-byte UTF-8
    // sequence, and matching bytes avoids failing on malformed input.
    $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

    if ($allowLineBreaks) {
      $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
    }
    else {
      $text = str_replace("\n", ' ', $text);
    }

    $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

    return trim($text);
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
