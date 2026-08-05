<?php

declare(strict_types=1);

namespace Drupal\dmr_public_comment\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires an uploaded file to be the format its extension claims.
 *
 * Core ships nothing that does this. Its file constraints check the extension
 * (FileExtension), the dangerous-extension list (FileExtensionSecure), size,
 * the name length, whether the file is an image at all (FileIsImage, which
 * cannot express "an image OR a PDF"), image dimensions, and text encoding.
 * None of them looks at whether a ".pdf" contains a PDF.
 *
 * The constraint ID is namespaced to this module so it cannot collide with a
 * constraint from core or another project.
 *
 * @see \Drupal\dmr_public_comment\File\FileSignature
 */
#[Constraint(
  id: 'DmrPublicCommentFileContent',
  label: new TranslatableMarkup('DMR public comment file content', [], ['context' => 'Validation']),
  type: 'file',
)]
class FileContentMatchesExtensionConstraint extends SymfonyConstraint {

  /**
   * The message shown to the visitor when a file is refused.
   *
   * Deliberately plain: the visitor is a member of the public who has almost
   * certainly done nothing wrong, most likely renaming a file to get past the
   * accepted-types list or exporting something from an app that wrote a
   * different format than the name suggests. It says what to do next and
   * nothing about signatures, MIME types or validation, and it is the same
   * message whatever the file turned out to be, so it gives no help to anyone
   * probing what does get through.
   *
   * @var string
   */
  public string $message = 'The file %filename could not be accepted. Please attach a photo (JPG, PNG, HEIC or WEBP) or a PDF saved from the program that created it, and do not rename a file to change its type. If you cannot attach it, email it to dmraquaculture@maine.gov instead.';

}
