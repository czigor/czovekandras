<?php

namespace Drupal\image_effects\Plugin\ImageToolkit\Operation\gd;

use Drupal\Component\Utility\Color;
use Drupal\Component\Utility\Unicode;
use Drupal\image_effects\Component\ColorUtility;
use Drupal\image_effects\Component\PositionedRectangle;

/**
 * Trait for GD image toolkit operations.
 */
trait GDOperationTrait {

  /**
   * Allocates a GD color from an RGBA hexadecimal.
   *
   * @param string $rgba_hex
   *   A string specifing an RGBA color in the format '#RRGGBBAA'.
   *
   * @return int
   *   A GD color index.
   */
  protected function allocateColorFromRgba($rgba_hex) {
    list($r, $g, $b, $alpha) = array_values($this->hexToRgba($rgba_hex));
    return imagecolorallocatealpha($this->getToolkit()->getResource(), $r, $g, $b, $alpha);
  }

  /**
   * Convert a RGBA hex to its RGBA integer GD components.
   *
   * GD expects a value between 0 and 127 for alpha, where 0 indicates
   * completely opaque while 127 indicates completely transparent.
   * RGBA hexadecimal notation has #00 for transparent and #FF for
   * fully opaque.
   *
   * @param string $rgba_hex
   *   A string specifing an RGBA color in the format '#RRGGBBAA'.
   *
   * @return array
   *   An array with four elements for red, green, blue, and alpha.
   */
  protected function hexToRgba($rgba_hex) {
    $rgbHex = Unicode::substr($rgba_hex, 0, 7);
    try {
      $rgb = Color::hexToRgb($rgbHex);
      $opacity = ColorUtility::rgbaToOpacity($rgba_hex);
      $alpha = 127 - floor(($opacity / 100) * 127);
      $rgb['alpha'] = $alpha;
      return $rgb;
    }
    catch (\InvalidArgumentException $e) {
      return FALSE;
    }
  }

  /**
   * Convert a rectangle to a sequence of point coordinates.
   *
   * GD requires a simple array of point coordinates in its
   * imagepolygon() function.
   *
   * @param \Drupal\image_effects\Component\PositionedRectangle $rect
   *   A PositionedRectangle object.
   *
   * @return array
   *   A simple array of 8 point coordinates.
   */
  protected function getRectangleCorners(PositionedRectangle $rect) {
    $points = [];
    foreach (array('c_d', 'c_c', 'c_b', 'c_a') as $c) {
      $point = $rect->getPoint($c);
      $points[] = $point[0];
      $points[] = $point[1];
    }
    return $points;
  }

  /**
   * Copy and merge part of an image, preserving alpha.
   *
   * The standard imagecopymerge() function in PHP GD fails to preserve the
   * alpha information of two merged images. This method implements the
   * workaround described in
   * http://php.net/manual/en/function.imagecopymerge.php#92787
   *
   * @param resource $dst_im
   *   Destination image link resource.
   * @param resource $src_im
   *   Source image link resource.
   * @param int $dst_x
   *   X-coordinate of destination point.
   * @param int $dst_y
   *   Y-coordinate of destination point.
   * @param int $src_x
   *   X-coordinate of source point.
   * @param int $src_y
   *   Y-coordinate of source point.
   * @param int $src_w
   *   Source width.
   * @param int $src_h
   *   Source height.
   * @param int $pct
   *   Opacity of the source image in percentage.
   *
   * @return bool
   *   Returns TRUE on success or FALSE on failure.
   *
   * @see http://php.net/manual/en/function.imagecopymerge.php#92787
   */
  protected function imageCopyMergeAlpha($dst_im, $src_im, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h, $pct) {
    if ($pct === 100) {
      // Use imagecopy() if opacity is 100%.
      return imagecopy($dst_im, $src_im, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h);
    }
    else {
      // If opacity is below 100%, use the approach described in
      // http://php.net/manual/it/function.imagecopymerge.php#92787
      // to preserve watermark alpha.

      // Create a cut resource.
      // @todo when #2583041 is committed, add a check for memory
      // availability before creating the resource.
      $cut = imagecreatetruecolor($src_w, $src_h);
      if (!is_resource($cut)) {
        return FALSE;
      }

      // Copy relevant section from destination image to the cut resource.
      if (!imagecopy($cut, $dst_im, 0, 0, $dst_x, $dst_y, $src_w, $src_h)) {
        imagedestroy($cut);
        return FALSE;
      }

      // Copy relevant section from merged image to the cut resource.
      if (!imagecopy($cut, $src_im, 0, 0, $src_x, $src_y, $src_w, $src_h)) {
        imagedestroy($cut);
        return FALSE;
      }

      // Insert cut resource to destination image.
      $success = imagecopymerge($dst_im, $cut, $dst_x, $dst_y, 0, 0, $src_w, $src_h, $pct);
      imagedestroy($cut);
      return $success;
    }
  }

  /**
   * Wrapper of imagettftext().
   *
   * If imagettftext() is missing, throw an exception instead of failing
   * fatally.
   *
   * @param resource $image
   *   An image resource.
   * @param float $size
   *   The font size.
   * @param float $angle
   *  The angle in degrees.
   * @param int $x
   *   The coordinates given by x and y will define the basepoint of the first
   *   character (roughly the lower-left corner of the character).
   * @param int $y
   *   The y-ordinate. This sets the position of the fonts baseline, not the
   *   very bottom of the character.
   * @param int $color
   *   The color index.
   * @param string $fontfile
   *   The path to the TrueType font to use.
   * @param string $text
   *   The text string in UTF-8 encoding.
   *
   * @return int[]
   *   An array with 8 elements representing four points making the bounding
   *   box of the text.
   *
   * @see http://php.net/manual/en/function.imagettftext.php
   */
  protected function _imagettftext($image, $size, $angle, $x, $y, $color, $fontfile, $text) {
    if (function_exists('imagettftext')) {
      return imagettftext($image, $size, $angle, $x, $y, $color, $fontfile, $text);
    }
    else {
      // @todo \InvalidArgumentException is incorrect, but other exceptions
      // would not be managed by toolkits that implement ImageToolkitBase.
      // Change to \RuntimeException when #2583041 is committed.
      throw new \InvalidArgumentException("The imagettftext() PHP function is not available, and image effects using fonts cannot be executed");
    }
  }

  /**
   * Wrapper of imagettfbbox().
   *
   * If imagettfbbox() is missing, throw an exception instead of failing
   * fatally.
   *
   * @param float $size
   *   The font size.
   * @param float $angle
   *  The angle in degrees.
   * @param string $fontfile
   *   The path to the TrueType font to use.
   * @param string $text
   *   The string to be measured.
   *
   * @return int[]|false
   *   Array with 8 elements representing four points making the bounding box
   *   of the text on success and FALSE on error.
   *
   * @see http://php.net/manual/en/function.imagettfbbox.php
   */
  protected function _imagettfbbox($size, $angle, $fontfile, $text) {
    if (function_exists('imagettfbbox')) {
      return imagettfbbox($size, $angle, $fontfile, $text);
    }
    else {
      // @todo \InvalidArgumentException is incorrect, but other exceptions
      // would not be managed by toolkits that implement ImageToolkitBase.
      // Change to \RuntimeException when #2583041 is committed.
      throw new \InvalidArgumentException("The imagettfbbox() PHP function is not available, and image effects using fonts cannot be executed");
    }
  }

}
