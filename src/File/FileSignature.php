<?php

declare(strict_types=1);

namespace Drupal\dmr_public_comment\File;

/**
 * Identifies an uploaded file from its own bytes rather than from its name.
 *
 * The public comment form accepts photographs and PDFs. Checking the extension
 * alone, which is all the webform element itself does, accepts a text file, a
 * script or an executable that has simply been renamed to ".pdf", and Drupal
 * then forwards it to Pega where a member of staff opens it. This class reads
 * the start of the file and answers what it actually is.
 *
 * Why this is done with explicit signatures rather than through PHP's fileinfo
 * extension or Symfony's MIME guessers:
 *   - fileinfo is not present on every PHP build. The local Drupal CMS launcher
 *     build (PHP 8.4, static-php-cli) does not have it, and a check that
 *     silently stops checking on a host without an optional extension is worse
 *     than no check at all, because nobody notices.
 *   - The allowlist here is five formats, all of which have short, stable,
 *     well-documented signatures. Reading them gives the same answer on
 *     every host, which also means the behaviour a test proves is the behaviour
 *     Maine's server has.
 *   - The alternative, mapping a guesser's MIME string onto an allowlist, drags
 *     in per-host differences (image/heic against image/heif being the obvious
 *     one) that would show up as a visitor's photo being refused on one server
 *     and accepted on another.
 *
 * This is type validation, not malware scanning. A real PDF with something
 * malicious is a valid PDF and is accepted here; scanning for that is the
 * hosting layer's job (ClamAV or equivalent), documented in Part 2 of the
 * installation guide.
 */
final class FileSignature {

  /**
   * Maps an accepted file extension to the format its content must be.
   *
   * The keys are exactly the extensions the attachments element allows, and the
   * values group the extensions that are the same format, which is why "jpg"
   * and "jpeg" share one. A file must match the format its extension claims:
   * a PNG named ".pdf" is refused even though PNG is itself an accepted format,
   * because a name that misdescribes its content is either a mistake worth
   * telling the visitor about or an attempt at something.
   */
  private const EXTENSION_FORMATS = [
    'jpg'  => 'jpeg',
    'jpeg' => 'jpeg',
    'png'  => 'png',
    'webp' => 'webp',
    'heic' => 'heic',
    'pdf'  => 'pdf',
  ];

  /**
   * Bytes read from the start of a file to identify it.
   *
   * Twelve bytes would settle every format below except HEIC, whose brand list
   * can run on; 64 covers a normal ISO base media file type box in full.
   */
  private const HEADER_BYTES = 64;

  /**
   * ISO base media file brands that mean "a still image in HEIF form".
   *
   * A HEIC file is an ISO base media file (the same container family as MP4)
   * whose brand says it holds HEVC-coded still images. Apple writes "heic" for
   * a photograph and "heix" or "hevc" for some bursts and Live Photos; Android
   * devices and desktop converters also write the generic HEIF brands "mif1"
   * and "msf1". Anything outside this list is refused, so an MP4 or a MOV
   * renamed to ".heic" does not get through on the strength of sharing a
   * container: their brands ("isom", "mp41", "qt  ") are not here.
   */
  private const HEIF_BRANDS = [
    'heic', 'heix', 'heim', 'heis',
    'hevc', 'hevx', 'hevm', 'hevs',
    'mif1', 'msf1',
  ];

  /**
   * Returns the format a file's own bytes say it is.
   *
   * @param string $uri
   *   The file URI, including a stream wrapper such as "private://".
   *
   * @return string|null
   *   One of the format names used in EXTENSION_FORMATS, or NULL when the file
   *   cannot be read or its content is not one of the accepted formats. NULL is
   *   the only answer for an unreadable file: every caller refuses on NULL, so
   *   an unidentifiable upload fails closed.
   */
  public static function detect(string $uri): ?string {
    $handle = @fopen($uri, 'rb');
    if ($handle === FALSE) {
      return NULL;
    }

    try {
      $header = @fread($handle, self::HEADER_BYTES);
    }
    finally {
      fclose($handle);
    }

    return is_string($header) ? self::detectFromHeader($header) : NULL;
  }

  /**
   * Returns the format the start of a file describes.
   *
   * Separate from detect() so the signature rules can be exercised directly.
   *
   * @param string $header
   *   The first bytes of a file, at least HEADER_BYTES of it where the file is
   *   that long.
   *
   * @return string|null
   *   The format name, or NULL if the bytes are not a format this module
   *   accepts.
   */
  public static function detectFromHeader(string $header): ?string {
    // JPEG: Start of Image marker followed by the first marker of the frame.
    if (str_starts_with($header, "\xFF\xD8\xFF")) {
      return 'jpeg';
    }

    // PNG: the fixed 8-byte signature from the PNG specification.
    if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
      return 'png';
    }

    // PDF: the version header. Required at the very start of the file. Some
    // readers tolerate leading rubbish before it; accepting that here would
    // also accept a file that begins as something else entirely and has a PDF
    // bolted on, which is the case this check exists to catch.
    if (str_starts_with($header, '%PDF-')) {
      return 'pdf';
    }

    // WebP: a RIFF container whose form type is WEBP. The four bytes between
    // them are the file length and are not checked, because a truncated
    // download is not what this is guarding against.
    if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
      return 'webp';
    }

    if (self::isHeifStillImage($header)) {
      return 'heic';
    }

    return NULL;
  }

  /**
   * Returns the format an extension claims the file is.
   *
   * @param string $filename
   *   A filename or URI. Only the part after the last dot is looked at, so
   *   "report.php.pdf" claims to be a PDF; refusing that name outright is
   *   core's FileExtensionSecure constraint, not this one.
   *
   * @return string|null
   *   The claimed format, or NULL when the extension is not one the form
   *   accepts.
   */
  public static function formatForFilename(string $filename): ?string {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return self::EXTENSION_FORMATS[$extension] ?? NULL;
  }

  /**
   * Determines whether a file's content matches the name it was uploaded under.
   *
   * @param string $filename
   *   The filename the visitor uploaded.
   * @param string $uri
   *   The URI the file is stored at.
   *
   * @return bool
   *   TRUE only when the extension is one the form accepts AND the file's own
   *   bytes are that same format.
   */
  public static function matchesFilename(string $filename, string $uri): bool {
    $claimed = self::formatForFilename($filename);
    if ($claimed === NULL) {
      return FALSE;
    }

    return self::detect($uri) === $claimed;
  }

  /**
   * Determines whether a header describes an ISO base media still image.
   *
   * The file type box is the first box in the file: a four-byte size, the tag
   * "ftyp", a four-byte major brand, a four-byte minor version, then any number
   * of compatible brands. The major brand is checked first. A file whose major
   * brand is something else but which lists an accepted brand as compatible is
   * also accepted, because converters do produce that, and the compatible list
   * is bounded by the box size so a crafted size cannot make this read beyond
   * the header it was given.
   *
   * @param string $header
   *   The first bytes of the file.
   *
   * @return bool
   *   TRUE if the header describes a HEIF still image.
   */
  private static function isHeifStillImage(string $header): bool {
    if (strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
      return FALSE;
    }

    if (in_array(substr($header, 8, 4), self::HEIF_BRANDS, TRUE)) {
      return TRUE;
    }

    // Compatible brands begin after the major brand and minor version.
    $boxSize = unpack('N', substr($header, 0, 4))[1] ?? 0;
    $limit   = min($boxSize, strlen($header));

    for ($offset = 16; $offset + 4 <= $limit; $offset += 4) {
      if (in_array(substr($header, $offset, 4), self::HEIF_BRANDS, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
