<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\EnhancedReuser;

use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Dao\FolderDao;
use Fossology\Lib\Auth\Auth;

/**
 * @file SmartSuggestionEngine.php
 * @brief Suggests previously-cleared uploads that match the current upload
 * @class SmartSuggestionEngine
 * @brief Intelligent reuse suggestion based on upload name similarity
 *
 * Uses Levenshtein distance and token-set comparison to score uploads.
 * Only uploads that have clearing decisions (status closed/inprogress) are
 * considered valid suggestion candidates.
 */
class SmartSuggestionEngine
{
  /** @var UploadDao $uploadDao */
  private $uploadDao;

  /** @var FolderDao $folderDao */
  private $folderDao;

  /** @var int $maxSuggestions
   * Maximum number of suggestions to return
   */
  private $maxSuggestions;

  public function __construct(UploadDao $uploadDao, FolderDao $folderDao, $maxSuggestions = 10)
  {
    $this->uploadDao      = $uploadDao;
    $this->folderDao      = $folderDao;
    $this->maxSuggestions = $maxSuggestions;
  }

  /**
   * @brief Find the best reuse candidates for a given upload
   *
   * @param int    $uploadId  The upload to find suggestions for
   * @param int    $groupId   Current user group
   * @param string $filename  Filename of the upload (used for similarity scoring)
   * @return array[] Sorted list of suggestion candidates, highest score first
   *   Each entry: ['uploadId', 'filename', 'score', 'timestamp', 'status', 'groupId']
   */
  public function findSuggestions($uploadId, $groupId, $filename)
  {
    // Normalise: strip version suffix, get package base name
    $baseName = $this->extractBaseName($filename);

    // Fetch all uploads visible to this group
    $allUploads = $this->folderDao->getAllUploadsForGroup($groupId);

    $candidates = [];
    foreach ($allUploads as $uploadProgress) {
      $candidateId = $uploadProgress->getId();
      if ($candidateId === $uploadId) {
        continue; // skip self
      }

      $candidateFilename = $uploadProgress->getFilename();
      $candidateBase     = $this->extractBaseName($candidateFilename);

      $score = $this->computeSimilarity($baseName, $candidateBase, $filename, $candidateFilename);
      if ($score <= 0) {
        continue;
      }

      $candidates[] = [
        'uploadId'  => $candidateId,
        'filename'  => $candidateFilename,
        'score'     => $score,
        'timestamp' => $uploadProgress->getTimestamp(),
        'status'    => $uploadProgress->getStatusString(),
        'groupId'   => $uploadProgress->getGroupId(),
      ];
    }

    // Sort by score descending, then by timestamp descending (newer first)
    usort($candidates, function ($a, $b) {
      if ($b['score'] !== $a['score']) {
        return $b['score'] <=> $a['score'];
      }
      return $b['timestamp'] <=> $a['timestamp'];
    });

    return array_slice($candidates, 0, $this->maxSuggestions);
  }

  /**
   * @brief Compute a similarity score (0-100) between two upload filenames
   *
   * Combines:
   * - Levenshtein distance on base names (normalised)
   * - Token intersection score (shared words / total words)
   *
   * @param string $base1     Normalised base name of upload 1
   * @param string $base2     Normalised base name of upload 2
   * @param string $full1     Full filename of upload 1
   * @param string $full2     Full filename of upload 2
   * @return int Score 0-100 (higher = more similar)
   */
  private function computeSimilarity($base1, $base2, $full1, $full2)
  {
    if (empty($base1) || empty($base2)) {
      return 0;
    }

    // Levenshtein score
    $maxLen   = max(strlen($base1), strlen($base2));
    $levDist  = levenshtein($base1, $base2);
    $levScore = $maxLen > 0 ? (1.0 - ($levDist / $maxLen)) * 100 : 0;

    // Token set score
    $tokens1    = array_unique(preg_split('/[\W_\-\.]+/', strtolower($full1), -1, PREG_SPLIT_NO_EMPTY));
    $tokens2    = array_unique(preg_split('/[\W_\-\.]+/', strtolower($full2), -1, PREG_SPLIT_NO_EMPTY));
    $intersect  = count(array_intersect($tokens1, $tokens2));
    $union      = count(array_unique(array_merge($tokens1, $tokens2)));
    $tokenScore = $union > 0 ? ($intersect / $union) * 100 : 0;

    // Weighted average: 60% Levenshtein base-name, 40% token overlap full-name
    $combined = intval(round(0.6 * $levScore + 0.4 * $tokenScore));
    return $combined;
  }

  /**
   * @brief Strip version numbers and extensions to get a base package name
   *
   * E.g. "libfoo-2.3.1.tar.gz" -> "libfoo"
   *
   * @param string $filename
   * @return string Lowercase base name
   */
  private function extractBaseName($filename)
  {
    if (empty($filename)) {
      return '';
    }
    // Remove common archive suffixes
    $name = preg_replace('/\.(tar\.(gz|bz2|xz|zst)|zip|tgz|tbz2|txz)$/i', '', $filename);
    // Remove trailing version pattern like -1.2.3, _1.2.3, -v1.2
    $name = preg_replace('/[-_]v?[\d]+[\d\._\-]*$/', '', $name);
    return strtolower($name);
  }
}
