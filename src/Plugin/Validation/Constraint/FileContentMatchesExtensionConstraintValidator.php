<?php

declare(strict_types=1);

namespace Drupal\dmr_public_comment\Plugin\Validation\Constraint;

use Drupal\dmr_public_comment\File\FileSignature;
use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Refuses an uploaded file whose content is not the format its name claims.
 *
 * Applied to the attachments element of the public comment form by
 * dmr_public_comment_webform_element_managed_file_alter(). It runs alongside
 * core's own upload validators, so a file has already been checked for size,
 * extension and dangerous name by the time it gets here.
 *
 * Has no injected services on purpose: the check reads bytes and compares them
 * against a fixed table, and a validator with nothing to resolve cannot fail to
 * be constructed on a site whose container is in an unexpected state.
 */
class FileContentMatchesExtensionConstraintValidator extends BaseFileConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $file = $this->assertValueIsFile($value);
    if (!$constraint instanceof FileContentMatchesExtensionConstraint) {
      throw new UnexpectedTypeException($constraint, FileContentMatchesExtensionConstraint::class);
    }

    // A freshly uploaded file is temporary and is stored under a generated name
    // that does not carry the original extension, so the name the visitor chose
    // has to come from the entity. This is the same rule core's
    // FileExtensionConstraintValidator applies, which matters: if the two
    // constraints judged different names, one of them could be bypassed.
    $filename = $file->isTemporary() ? (string) $file->getFilename() : (string) $file->getFileUri();

    if (!FileSignature::matchesFilename($filename, (string) $file->getFileUri())) {
      $this->context->addViolation($constraint->message, [
        '%filename' => $file->getFilename(),
      ]);
    }
  }

}
