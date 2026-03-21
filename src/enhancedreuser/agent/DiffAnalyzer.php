<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\EnhancedReuser;

/**
 * @file DiffAnalyzer.php
 * @brief Utility to compare two files and extract diff statistics
 * @class DiffAnalyzer
 * @brief Analyses differences between two file revisions
 *
 * Uses the system `diff` tool to determine:
 * - Number of lines added and removed
 * - Whether changes are in comment-only regions or source-code regions
 * - Overall diff level (total changed lines)
 */
class DiffAnalyzer
{
  /** @var string[] $commentPrefixes
   * Common single-line comment prefixes across languages
   */
  private static $commentPrefixes = ['//', '#', '*', ';', '--', '%'];

  /** @var string[] $commentBlockStart
   * Block-comment open markers
   */
  private static $commentBlockStart = ['/*', '<!--', '"""', "'''"];

  /** @var string[] $commentBlockEnd
   * Block-comment close markers
   */
  private static $commentBlockEnd = ['*/', '-->', '"""', "'''"];

  /**
   * @brief Analyse differences between two file paths
   *
   * @param string $oldPath  Absolute path to the old (v1) file
   * @param string $newPath  Absolute path to the new (v2) file
   * @return array{
   *   linesAdded: int,
   *   linesRemoved: int,
   *   commentLinesChanged: int,
   *   codeLinesChanged: int,
   *   diffLevel: int,
   *   diffType: string
   * }
   */
  public static function analyze($oldPath, $newPath)
  {
    $result = [
      'linesAdded'          => 0,
      'linesRemoved'        => 0,
      'commentLinesChanged' => 0,
      'codeLinesChanged'    => 0,
      'diffLevel'           => 0,
      'diffType'            => 'identical',
    ];

    if (!is_readable($oldPath) || !is_readable($newPath)) {
      $result['diffType'] = 'unreadable';
      return $result;
    }

    // Get unified diff output
    $escapedOld = escapeshellarg($oldPath);
    $escapedNew = escapeshellarg($newPath);
    $diffOutput = [];
    exec("diff -u $escapedOld $escapedNew 2>/dev/null", $diffOutput, $exitCode);

    // Exit code 0 = identical, 1 = different, 2 = error
    if ($exitCode === 0) {
      $result['diffType'] = 'identical';
      return $result;
    }
    if ($exitCode === 2) {
      $result['diffType'] = 'error';
      return $result;
    }

    $addedLines   = [];
    $removedLines = [];

    foreach ($diffOutput as $line) {
      if (strlen($line) === 0) {
        continue;
      }
      $firstChar = $line[0];
      // Skip diff headers (--- +++ @@)
      if ($firstChar === '-' && isset($line[1]) && $line[1] === '-') {
        continue;
      }
      if ($firstChar === '+' && isset($line[1]) && $line[1] === '+') {
        continue;
      }
      if ($firstChar === '@') {
        continue;
      }
      if ($firstChar === '+') {
        $addedLines[] = substr($line, 1);
      } elseif ($firstChar === '-') {
        $removedLines[] = substr($line, 1);
      }
    }

    $result['linesAdded']   = count($addedLines);
    $result['linesRemoved'] = count($removedLines);
    $result['diffLevel']    = $result['linesAdded'] + $result['linesRemoved'];

    // Classify each changed line as comment or code
    $allChanged = array_merge($addedLines, $removedLines);
    $commentCount = 0;
    foreach ($allChanged as $changedLine) {
      if (self::isCommentLine(trim($changedLine))) {
        $commentCount++;
      }
    }

    $result['commentLinesChanged'] = $commentCount;
    $result['codeLinesChanged']    = $result['diffLevel'] - $commentCount;

    // Classify diff type
    if ($result['diffLevel'] === 0) {
      $result['diffType'] = 'identical';
    } elseif ($result['codeLinesChanged'] === 0) {
      $result['diffType'] = 'comment_only';
    } elseif ($result['diffLevel'] < 10) {
      $result['diffType'] = 'minor';
    } else {
      $result['diffType'] = 'major';
    }

    return $result;
  }

  /**
   * @brief Determine whether a trimmed source line looks like a comment
   *
   * @param string $line Trimmed source line
   * @return bool True if the line is a comment line
   */
  private static function isCommentLine($line)
  {
    if (empty($line)) {
      return true; // blank lines in diff are comment-adjacent
    }
    foreach (self::$commentPrefixes as $prefix) {
      if (strncmp($line, $prefix, strlen($prefix)) === 0) {
        return true;
      }
    }
    foreach (self::$commentBlockStart as $marker) {
      if (strncmp($line, $marker, strlen($marker)) === 0) {
        return true;
      }
    }
    foreach (self::$commentBlockEnd as $marker) {
      if (strncmp($line, $marker, strlen($marker)) === 0) {
        return true;
      }
    }
    return false;
  }
}
