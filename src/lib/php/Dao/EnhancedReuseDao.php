<?php
/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\Db\DbManager;

class EnhancedReuseDao
{
  /** @var DbManager */
  private $dbManager;

  /** @var PfileDao */
  private $pfileDao;

  public function __construct(DbManager $dbManager, PfileDao $pfileDao)
  {
    $this->dbManager = $dbManager;
    $this->pfileDao = $pfileDao;
  }

  public function getLatestAnalysisId($uploadId, $groupId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT analysis_pk FROM enhanced_reuse_analysis
            WHERE upload_fk=$1 AND group_fk=$2
            ORDER BY created_at DESC LIMIT 1";
    $row = $this->dbManager->getSingleRow($sql, array($uploadId, $groupId), $stmt);
    return empty($row) ? null : intval($row['analysis_pk']);
  }

  public function getStats($analysisId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT classification, count(*) AS total
            FROM enhanced_reuse_file_comparison
            WHERE analysis_fk=$1
            GROUP BY classification";
    return $this->dbManager->getRows($sql, array($analysisId), $stmt);
  }

  /**
   * Stored agent rows, or on-the-fly license text from clearing only (same rules as enhancedreuser).
   *
   * @return array<int, array<string, mixed>>
   */
  public function getLicenseComparison($analysisId)
  {
    $stmt = __METHOD__ . '.stored';
    $sql = "SELECT upload_pfile_fk, reused_pfile_fk, current_decision, reused_decision, conflict
            FROM enhanced_reuse_license_comparison
            WHERE analysis_fk=$1
            ORDER BY comparison_pk";
    $stored = $this->dbManager->getRows($sql, array($analysisId), $stmt);
    if (!empty($stored)) {
      return $stored;
    }

    return $this->computeLicenseComparisonFromPairs($analysisId);
  }

  /**
   * Files sorted by risk (CRITICAL first); same data as diff tree, different order.
   * Kept for REST compatibility; the web UI no longer exposes a separate “Suggestions” tab.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getSuggestions($analysisId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT file_name, classification, modification_type, risk_level
            FROM enhanced_reuse_file_comparison
            WHERE analysis_fk=$1
            ORDER BY
              CASE risk_level
                WHEN 'CRITICAL' THEN 1
                WHEN 'HIGH' THEN 2
                WHEN 'MEDIUM' THEN 3
                ELSE 4
              END, file_name";
    return $this->dbManager->getRows($sql, array($analysisId), $stmt);
  }

  public function getDiffTree($analysisId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT file_name, classification, modification_type, changed_lines, risk_level
            FROM enhanced_reuse_file_comparison
            WHERE analysis_fk=$1
            ORDER BY file_name";
    return $this->dbManager->getRows($sql, array($analysisId), $stmt);
  }

  /**
   * Histogram buckets written by enhancedreuser (fallback if file-level aggregation is empty).
   *
   * @return array<int, array<string, mixed>>
   */
  public function getHistogramStats($analysisId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT histogram_key AS classification, histogram_count AS total
            FROM enhanced_reuse_histogram
            WHERE analysis_fk=$1
            ORDER BY histogram_key";
    return $this->dbManager->getRows($sql, array($analysisId), $stmt);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function computeLicenseComparisonFromPairs($analysisId)
  {
    $ctxStmt = __METHOD__ . '.ctx';
    $ctxSql = "SELECT era.upload_fk, era.reused_upload_fk, era.group_fk,
      (SELECT ur.reused_group_fk FROM upload_reuse ur
        WHERE ur.upload_fk = era.upload_fk AND ur.group_fk = era.group_fk
        ORDER BY ur.date_added DESC LIMIT 1) AS reused_group_fk
      FROM enhanced_reuse_analysis era WHERE era.analysis_pk = $1";
    $ctx = $this->dbManager->getSingleRow($ctxSql, array($analysisId), $ctxStmt);
    if (empty($ctx)) {
      return array();
    }

    $groupId = intval($ctx['group_fk']);
    $reusedGroupId = isset($ctx['reused_group_fk']) && $ctx['reused_group_fk'] !== null && $ctx['reused_group_fk'] !== ''
      ? intval($ctx['reused_group_fk']) : $groupId;

    $pairStmt = __METHOD__ . '.pairs';
    $pairSql = "SELECT DISTINCT ON (upload_pfile_fk, reused_pfile_fk)
                  upload_pfile_fk, reused_pfile_fk, file_name
                FROM enhanced_reuse_file_comparison
                WHERE analysis_fk=$1
                  AND upload_pfile_fk IS NOT NULL
                  AND reused_pfile_fk IS NOT NULL
                ORDER BY upload_pfile_fk, reused_pfile_fk, file_name
                LIMIT 3000";
    $pairs = $this->dbManager->getRows($pairSql, array($analysisId), $pairStmt);

    $out = array();
    foreach ($pairs as $pair) {
      $up = intval($pair['upload_pfile_fk']);
      $rp = intval($pair['reused_pfile_fk']);
      if ($up === $rp && $groupId === $reusedGroupId) {
        $currentText = $this->effectiveLicenseText($groupId, $up);
        $reusedText = $currentText;
      } else {
        $currentText = $this->effectiveLicenseText($groupId, $up);
        $reusedText = $this->effectiveLicenseText($reusedGroupId, $rp);
      }
      $out[] = array(
        'file_name' => isset($pair['file_name']) ? $pair['file_name'] : '',
        'upload_pfile_fk' => $up,
        'reused_pfile_fk' => $rp,
        'current_decision' => $currentText,
        'reused_decision' => $reusedText,
        'conflict' => ($currentText !== $reusedText),
      );
    }

    return $out;
  }

  /**
   * Effective license text from clearing conclusions only (no scanner license_file fallback).
   */
  private function effectiveLicenseText(int $groupId, int $pfileId): string
  {
    $conclusions = $this->pfileDao->getConclusions($groupId, $pfileId);
    if ($conclusions === array('NOASSERTION')) {
      return '';
    }
    if ($conclusions === array('NONE')) {
      return 'NONE';
    }
    return implode(', ', $conclusions);
  }
}
