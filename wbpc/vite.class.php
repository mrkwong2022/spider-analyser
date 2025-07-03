<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class WB_Vite
{
  const VITE_HOST = 'http://localhost:5173';
  public static $output_uri = '';

  public static function vite(string $entry, string $output_dir = 'assets/wbp/', string $output_uri = 'assets/wbp/'): string
  {
    self::$output_uri = $output_uri;

    return "\n" . self::jsTag($entry, $output_dir)
      . "\n" . self::jsPreloadImports($entry, $output_dir)
      . "\n" . self::cssTag($entry, $output_dir);
  }

  public static function isDev(string $entry): bool
  {
    static $exists = null;
    if ($exists !== null) {
      return $exists;
    }

    $handle = curl_init(self::VITE_HOST . '/' . $entry);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_NOBODY, true);

    curl_exec($handle);
    $error = curl_errno($handle);
    curl_close($handle);

    return $exists = !$error;
  }

  public static function jsTag(string $entry, string $output_dir): string
  {
    $url = self::isDev($entry) ?
      self::VITE_HOST . '/' . $entry :
      self::assetUrl($entry, $output_dir);

    if (!$url) {
      return '';
    }

    if (static::isDev($entry)) { // Changed self:: to static::
      return '<script type="module" src="' . esc_url( static::VITE_HOST . '/@vite/client' ) . '"></script>' . "\n"
        . '<script type="module" src="' . esc_url( $url ) . '"></script>';
    }

    return '<script type="module" src="' . esc_url( $url ) . '"></script>';
  }

  public static function jsPreloadImports(string $entry, string $output_dir): string
  {
    if (static::isDev($entry)) { // Changed self:: to static::
      return '';
    }

    $res = '';
    foreach (static::importsUrls($entry, $output_dir) as $url) { // Changed self:: to static::
      $res .= '<link rel="modulepreload" href="' . esc_url( $url ) . '">';
    }
    return $res;
  }

  public static function cssTag(string $entry, string $output_dir): string
  {
    if (static::isDev($entry)) { // Changed self:: to static::
      return '';
    }

    $tags = '';
    foreach (static::cssUrls($entry, $output_dir) as $url) { // Changed self:: to static::
      $tags .= '<link rel="stylesheet" href="' . esc_url( $url ) . '">';
    }
    return $tags;
  }

  public static function getManifest(string $output_dir): array
  {
    $manifest_path = $output_dir . '.vite/manifest.json';
    $content = file_get_contents($manifest_path);

    return json_decode($content, true);
  }

  public static function assetUrl(string $entry, string $output_dir): string
  {
    $manifest = self::getManifest($output_dir);

    return isset($manifest[$entry]) ? self::out_uri($manifest[$entry]['file']) : '';
  }

  public static function importsUrls(string $entry, string $output_dir): array
  {
    $urls = [];
    $manifest = self::getManifest($output_dir);

    if (!empty($manifest[$entry]['imports'])) {
      foreach ($manifest[$entry]['imports'] as $imports) {
        $urls[] = self::out_uri($manifest[$imports]['file']);
      }
    }
    return $urls;
  }

  public static function cssUrls(string $entry, string $output_dir): array
  {
    $urls = [];
    $manifest = self::getManifest($output_dir);

    if (!empty($manifest[$entry]['css'])) {
      foreach ($manifest[$entry]['css'] as $file) {
        $urls[] = self::out_uri($file);
      }
    } else {
      $imports = $manifest[$entry]['imports'];

      foreach ($imports as $import) {
        if (!empty($manifest[$import]['css'])) {
          foreach ($manifest[$import]['css'] as $file) {
            $urls[] = self::out_uri($file);
          }
        }
      }
    }
    return $urls;
  }

  public static function out_uri($file_path)
  {
    return self::$output_uri . $file_path;
  }
}
