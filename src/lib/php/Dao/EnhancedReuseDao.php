<?php
/*
SPDX-FileCopyrightText: 2026 Fossology contributors

SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\Db\DbManager;

// Include RepPathItem function
require_once dirname(__DIR__, 2) . '/php/common-repo.php';

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
   * Get total file counts for upload and reuse packages
   *
   * @param int $analysisId
   * @return array<string, mixed>
   */
  public function getTotalFileCounts($analysisId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT 
              COUNT(CASE WHEN upload_pfile_fk IS NOT NULL THEN 1 END) as total_upload_files,
              COUNT(CASE WHEN reused_pfile_fk IS NOT NULL THEN 1 END) as total_reuse_files
            FROM enhanced_reuse_file_comparison
            WHERE analysis_fk=$1";
    
    $result = $this->dbManager->getSingleRow($sql, array($analysisId), $stmt);
    
    return [
      'total_upload_files' => intval($result['total_upload_files'] ?? 0),
      'total_reuse_files' => intval($result['total_reuse_files'] ?? 0)
    ];
  }

  /**
   * Stored agent rows, or on-the-fly license text from clearing only (same rules as enhancedreuser).
   *
   * @return array<int, array<string, mixed>>
   */
  public function getLicenseComparison($analysisId)
  {
    // Use the same query as getLicenseComparisonStats for consistency
    return $this->getLicenseComparisonStats($analysisId);
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
    $sql = "SELECT file_name, classification, modification_type, changed_lines, risk_level, upload_pfile_fk, reused_pfile_fk
            FROM enhanced_reuse_file_comparison
            WHERE analysis_fk=$1
            ORDER BY file_name";
    $results = $this->dbManager->getRows($sql, array($analysisId), $stmt);
    
    // Calculate proper changed_lines count for files that have both upload_pfile_fk and reused_pfile_fk
    foreach ($results as &$row) {
      if (!empty($row['upload_pfile_fk']) && !empty($row['reused_pfile_fk']) && $row['classification'] === 'MODIFIED') {
        $row['changed_lines'] = $this->calculateDiffLineCount($row['upload_pfile_fk'], $row['reused_pfile_fk']);
      }
    }
    
    return $results;
  }
  
  /**
   * Calculate the actual line count differences between two files
   */
  private function calculateDiffLineCount($uploadPfileId, $reusedPfileId)
  {
    try {
      // Get file contents using RepPathItem
      $uploadtreeStmt = __METHOD__ . ".uploadtree";
      $uploadtreeSql = "SELECT uploadtree_pk FROM uploadtree WHERE pfile_fk = $1 LIMIT 1";
      $uploadResult = $this->dbManager->getSingleRow($uploadtreeSql, array($uploadPfileId), $uploadtreeStmt);
      
      $reusedtreeStmt = __METHOD__ . ".reusedtree";
      $reusedtreeSql = "SELECT uploadtree_pk FROM uploadtree WHERE pfile_fk = $1 LIMIT 1";
      $reusedResult = $this->dbManager->getSingleRow($reusedtreeSql, array($reusedPfileId), $reusedtreeStmt);
      
      if (!$uploadResult || !$reusedResult) {
        return 1; // fallback to current behavior
      }
      
      $uploadPath = RepPathItem($uploadResult['uploadtree_pk']);
      $reusedPath = RepPathItem($reusedResult['uploadtree_pk']);
      
      if (!$uploadPath || !$reusedPath || !file_exists($uploadPath) || !file_exists($reusedPath)) {
        return 1; // fallback to current behavior
      }
      
      $uploadContent = file_get_contents($uploadPath);
      $reusedContent = file_get_contents($reusedPath);
      
      if ($uploadContent === false || $reusedContent === false) {
        return 1; // fallback to current behavior
      }
      
      $uploadLines = explode("\n", $uploadContent);
      $reusedLines = explode("\n", $reusedContent);
      
      // Simple diff algorithm to count changes
      $added = 0;
      $deleted = 0;
      $modified = 0;
      
      $i = 0;
      $j = 0;
      $uploadCount = count($uploadLines);
      $reusedCount = count($reusedLines);
      
      while ($i < $uploadCount || $j < $reusedCount) {
        if ($i >= $uploadCount) {
          $deleted++;
          $j++;
        } elseif ($j >= $reusedCount) {
          $added++;
          $i++;
        } elseif ($uploadLines[$i] === $reusedLines[$j]) {
          $i++;
          $j++;
        } else {
          // Look ahead to see if it's an addition or deletion
          $found = false;
          for ($lookahead = 1; $lookahead <= 5 && ($i + $lookahead) < $uploadCount; $lookahead++) {
            if ($uploadLines[$i + $lookahead] === $reusedLines[$j]) {
              $added += $lookahead;
              $i += $lookahead + 1;
              $j++;
              $found = true;
              break;
            }
          }
          
          if (!$found) {
            for ($lookahead = 1; $lookahead <= 5 && ($j + $lookahead) < $reusedCount; $lookahead++) {
              if ($uploadLines[$i] === $reusedLines[$j + $lookahead]) {
                $deleted += $lookahead;
                $i++;
                $j += $lookahead + 1;
                $found = true;
                break;
              }
            }
          }
          
          if (!$found) {
            $modified++;
            $i++;
            $j++;
          }
        }
      }
      
      return $added + $deleted + $modified;
      
    } catch (Exception $e) {
      return 1; // fallback to current behavior
    }
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
   * License statistics from enhancedreuser analysis.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getLicenseStats($analysisId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT histogram_key AS license_change, histogram_count AS total
            FROM enhanced_reuse_histogram
            WHERE analysis_fk=$1 AND risk_level='LICENSE'
            ORDER BY histogram_key";
    return $this->dbManager->getRows($sql, array($analysisId), $stmt);
  }

  /**
   * Get license comparison between upload and reuse packages
   *
   * @return array<int, array<string, mixed>>
   */
  public function getLicenseComparisonStats($analysisId)
  {
    // Get upload and reuse context
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

    $uploadId = intval($ctx['upload_fk']);
    $reusedUploadId = intval($ctx['reused_upload_fk']);
    $groupId = intval($ctx['group_fk']);
    $reusedGroupId = isset($ctx['reused_group_fk']) && $ctx['reused_group_fk'] !== null && $ctx['reused_group_fk'] !== ''
      ? intval($ctx['reused_group_fk']) : $groupId;

    // Get root uploadtree_pk for each upload (needed for license_list_files)
    $uploadTreeStmt = __METHOD__ . '.upload_tree';
    $uploadTreeSql = "SELECT uploadtree_pk FROM uploadtree WHERE upload_fk = $1 AND parent IS NULL LIMIT 1";
    $uploadTreeResult = $this->dbManager->getSingleRow($uploadTreeSql, array($uploadId), $uploadTreeStmt);
    $uploadTreeId = $uploadTreeResult ? $uploadTreeResult['uploadtree_pk'] : $uploadId;

    $reusedTreeStmt = __METHOD__ . '.reused_tree';
    $reusedTreeSql = "SELECT uploadtree_pk FROM uploadtree WHERE upload_fk = $1 AND parent IS NULL LIMIT 1";
    $reusedTreeResult = $this->dbManager->getSingleRow($reusedTreeSql, array($reusedUploadId), $reusedTreeStmt);
    $reusedTreeId = $reusedTreeResult ? $reusedTreeResult['uploadtree_pk'] : $reusedUploadId;

    // Query that matches license browser exactly
    $uploadLicensesStmt = __METHOD__ . '.upload_licenses';
    $uploadLicensesSql = "SELECT COALESCE(pfile_ref.rf_shortname, 'No_license_found') AS license_name, COUNT(*) AS count
            FROM (SELECT license_ref.rf_shortname, license_ref.rf_pk, license_file.fl_pk, license_file.agent_fk, license_file.pfile_fk
                  FROM license_file
                  JOIN license_ref ON license_file.rf_fk = license_ref.rf_pk) AS pfile_ref
            RIGHT JOIN uploadtree UT ON pfile_ref.pfile_fk = UT.pfile_fk
            WHERE (pfile_ref.rf_shortname IS NULL OR pfile_ref.rf_shortname NOT IN ('Void')) 
              AND UT.upload_fk = $1 AND UT.ufile_mode&(3<<28)=0
            GROUP BY COALESCE(pfile_ref.rf_shortname, 'No_license_found')
            ORDER BY COALESCE(pfile_ref.rf_shortname, 'No_license_found')";
    $uploadLicenses = $this->dbManager->getRows($uploadLicensesSql, array($uploadId), $uploadLicensesStmt);

    // Query for reused upload
    $reusedLicensesStmt = __METHOD__ . '.reused_licenses';
    $reusedLicensesSql = "SELECT COALESCE(pfile_ref.rf_shortname, 'No_license_found') AS license_name, COUNT(*) AS count
            FROM (SELECT license_ref.rf_shortname, license_ref.rf_pk, license_file.fl_pk, license_file.agent_fk, license_file.pfile_fk
                  FROM license_file
                  JOIN license_ref ON license_file.rf_fk = license_ref.rf_pk) AS pfile_ref
            RIGHT JOIN uploadtree UT ON pfile_ref.pfile_fk = UT.pfile_fk
            WHERE (pfile_ref.rf_shortname IS NULL OR pfile_ref.rf_shortname NOT IN ('Void')) 
              AND UT.upload_fk = $1 AND UT.ufile_mode&(3<<28)=0
            GROUP BY COALESCE(pfile_ref.rf_shortname, 'No_license_found')
            ORDER BY COALESCE(pfile_ref.rf_shortname, 'No_license_found')";
    $reusedLicenses = $this->dbManager->getRows($reusedLicensesSql, array($reusedUploadId), $reusedLicensesStmt);
    
    // Merge and compare licenses
    $licenseMap = array();
    
    // Add upload licenses
    foreach ($uploadLicenses as $license) {
      $licenseMap[$license['license_name']] = array(
        'license' => $license['license_name'],
        'upload_count' => intval($license['count']),
        'reuse_count' => 0,
        'upload_files_url' => '?mod=license_list_files&item=' . $uploadTreeId . '&lic=' . urlencode($license['license_name']),
        'reuse_files_url' => ''
      );
    }
    
    // Add reused licenses
    foreach ($reusedLicenses as $license) {
      if (isset($licenseMap[$license['license_name']])) {
        $licenseMap[$license['license_name']]['reuse_count'] = intval($license['count']);
        $licenseMap[$license['license_name']]['reuse_files_url'] = '?mod=license_list_files&item=' . $reusedTreeId . '&lic=' . urlencode($license['license_name']);
      } else {
        $licenseMap[$license['license_name']] = array(
          'license' => $license['license_name'],
          'upload_count' => 0,
          'reuse_count' => intval($license['count']),
          'upload_files_url' => '',
          'reuse_files_url' => '?mod=license_list_files&item=' . $reusedTreeId . '&lic=' . urlencode($license['license_name'])
        );
      }
    }
    
    // Sort by license name
    ksort($licenseMap);
    
    return array_values($licenseMap);
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
