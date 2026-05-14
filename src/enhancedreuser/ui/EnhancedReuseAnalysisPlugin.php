<?php
/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\EnhancedReuser\Ui;

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\EnhancedReuseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Plugin\DefaultPlugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EnhancedReuseAnalysisPlugin extends DefaultPlugin
{
  const NAME = "enhanced_reuse_analysis";

  /** @var UploadDao */
  private $uploadDao;

  public function __construct()
  {
    parent::__construct(self::NAME, array(
      self::TITLE => _("Reuse Analysis"),
      self::PERMISSION => Auth::PERM_READ,
      self::REQUIRES_LOGIN => false,
      self::DEPENDENCIES => array("browse"),
    ));

    $this->uploadDao = $this->getObject('dao.upload');
  }

  /**
   * Add this view to the Browse / View “select action” menus when upload and item are known.
   */
  public function RegisterMenus()
  {
    $URI = $this->getName() . Traceback_parm_keep(array("show", "upload", "item", "folder"));
    $item = GetParm("item", PARM_INTEGER);
    $upload = GetParm("upload", PARM_INTEGER);
    if (empty($item) || empty($upload)) {
      return;
    }

    $menuName = $this->getTitle();
    if (GetParm("mod", PARM_STRING) == self::NAME) {
      menu_insert("Browse::$menuName", 102);
      menu_insert("Browse::[BREAK]", 100);
      menu_insert("View::$menuName", 102);
      menu_insert("View-Meta::$menuName", 102);
    } else {
      $text = _("Compare reused upload, histogram, and file-level diffs");
      menu_insert("Browse::$menuName", 102, $URI, $text);
      menu_insert("Browse::[BREAK]", 100);
      menu_insert("View::$menuName", 102, $URI, $text);
      menu_insert("View-Meta::$menuName", 102, $URI, $text);
    }
  }

  public function preInstall()
  {
    $this->RegisterMenus();
  }

  /**
   * @param Request $request
   * @return Response
   */
  protected function handle(Request $request)
  {
    $upload = intval($request->get("upload"));
    $item = intval($request->get("item"));
    $groupId = Auth::getGroupId();

    if (empty($upload) || empty($item)) {
      return $this->flushContent(
        _("Open this page from browse or the license view: pick an upload and folder, then choose Enhanced Reuse Analysis from the action menu."));
    }

    if (!$this->uploadDao->isAccessible($upload, $groupId)) {
      return $this->flushContent(_("Permission denied."));
    }

    $uploadTreeTable = $this->uploadDao->getUploadtreeTableName($upload);
    $itemBounds = $this->uploadDao->getItemTreeBounds($item, $uploadTreeTable);
    if (empty($itemBounds->getLeft())) {
      return $this->flushContent(_("Unpack/adj2nest has not completed for this upload."));
    }

    /** @var EnhancedReuseDao $dao */
    $dao = $this->getObject('dao.enhanced_reuse');
    $analysisId = $dao->getLatestAnalysisId($upload, $groupId);
    if ($analysisId === null) {
      return $this->flushContent(
        _("No enhanced reuse results for this upload yet. Schedule reuse with enhanced reuse enabled and wait for the enhancedreuser agent to finish."));
    }

    // Handle license filtering from URL parameters
    $licenseFilter = $request->get("license_filter");
    $filterLicenses = $request->get("licenses");
    if (!empty($licenseFilter) && !empty($filterLicenses)) {
      try {
        $filterLicenses = explode(',', $filterLicenses);
        return $this->handleLicenseFilter($upload, $item, $licenseFilter, $filterLicenses, $groupId);
      } catch (Exception $e) {
        error_log("License filter error: " . $e->getMessage());
        return $this->flushContent("Error in license filtering: " . $e->getMessage());
      }
    }

    /*
     * Render rows in Twig (server-side). Embedding large JSON inside <script> is fragile
     * and can fail silently; the REST API also requires a Bearer JWT for browser fetches.
     */
    $stats = $dao->getStats($analysisId);
    if (empty($stats)) {
      $stats = $dao->getHistogramStats($analysisId);
    }
    
    // Add total file counts to the stats
    $totalFileCounts = $dao->getTotalFileCounts($analysisId);
    
    // Add total file counts as additional rows to stats
    $stats[] = [
      'classification' => 'Total files (upload)',
      'total' => $totalFileCounts['total_upload_files']
    ];
    
    $stats[] = [
      'classification' => 'Total files (reuse)',
      'total' => $totalFileCounts['total_reuse_files']
    ];
    
    $licenseStats = $dao->getLicenseComparisonStats($analysisId);

    $licenses = $dao->getLicenseComparison($analysisId);
    foreach ($licenses as &$licRow) {
      $licRow['license_file_href'] = '';
      $pfileFk = isset($licRow['upload_pfile_fk']) ? intval($licRow['upload_pfile_fk']) : 0;
      if ($pfileFk > 0) {
        $licenseItem = $this->uploadDao->getUploadtreeIdFromPfile($upload, $pfileFk);
        if ($licenseItem > 0) {
          $licRow['license_file_href'] = Traceback_uri()
            . '?mod=view-license&upload=' . $upload . '&item=' . $licenseItem;
        }
      }
    }
    unset($licRow);

    $diffTree = $dao->getDiffTree($analysisId);
    foreach ($diffTree as &$diffRow) {
      $diffRow['file_href'] = '';
      $pfileFk = isset($diffRow['upload_pfile_fk']) ? intval($diffRow['upload_pfile_fk']) : 0;
      if ($pfileFk > 0) {
        $diffItem = $this->uploadDao->getUploadtreeIdFromPfile($upload, $pfileFk);
        if ($diffItem > 0) {
          $diffRow['file_href'] = Traceback_uri()
            . '?mod=view-license&upload=' . $upload . '&item=' . $diffItem;
        }
      }
      // Add change type to original diff tree as well
      $diffRow['change_type'] = $this->detectChangeType($diffRow);
      
      // Add license information for new columns
      $licenseInfo = $this->getLicenseInfoForFile($diffRow['upload_pfile_fk'], $diffRow['reused_pfile_fk'], $upload, $reusedUpload, $groupId);
      $diffRow['upload_license'] = $licenseInfo['upload_license'];
      $diffRow['reuse_license'] = $licenseInfo['reuse_license'];
      $diffRow['license_status'] = $licenseInfo['status'];
    }
    unset($diffRow);

    $vars = array(
      "micromenu" => Dir2Browse(self::NAME, $item, null, 0, "Browse",
        -1, '', '', $uploadTreeTable),
      "uploadId" => $upload,
      "itemId" => $item,
      "enhancedReuseStats" => $stats,
      "enhancedReuseLicenseStats" => $licenseStats,
      "enhancedReuseDiffTree" => $diffTree,
      "enhancedReuseLicenses" => $licenses,
      // License statistics for histogram
      "licenseHistogramStats" => $this->prepareLicenseHistogramStats($licenseStats, $diffTree),
    );

    return $this->render("enhanced-reuse-analysis-page.html.twig", $this->mergeWithDefault($vars));
  }

  
  /**
   * Detect file type based on extension
   */
  private function detectFileType($fileName)
  {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    if (in_array($ext, ['c', 'cpp', 'h', 'hpp', 'cc', 'cxx'])) {
      return 'source_c';
    } elseif (in_array($ext, ['py', 'js', 'java', 'cs', 'rb', 'php'])) {
      return 'source_script';
    } elseif (in_array($ext, ['txt', 'md', 'rst', 'doc', 'docx'])) {
      return 'doc';
    } elseif (in_array($ext, ['cmake', 'mk', 'am', 'in', 'makefile'])) {
      return 'config';
    } else {
      return 'other';
    }
  }

  /**
   * Get file type badge class
   */
  private function getFileTypeBadge($fileType)
  {
    switch ($fileType) {
      case 'source_c':
      case 'source_script':
        return 'primary';
      case 'doc':
        return 'secondary';
      case 'config':
        return 'warning';
      default:
        return 'light';
    }
  }

  /**
   * Get enhanced status display
   */
  private function getEnhancedStatus($classification)
  {
    switch ($classification) {
      case 'IDENTICAL':
        return 'Unchanged';
      case 'MODIFIED':
        return 'Modified';
      case 'NEW':
        return 'New';
      case 'REMOVED':
        return 'Removed';
      default:
        return $classification;
    }
  }

  /**
   * Get signed lines display
   */
  private function getSignedLines($lines)
  {
    if ($lines > 0) {
      return '+' . $lines;
    } elseif ($lines < 0) {
      return (string)$lines;
    }
    return '0';
  }

  /**
   * Get change kind display
   */
  private function getChangeKindDisplay($modificationType)
  {
    switch ($modificationType) {
      case 'MINOR':
        return 'Minor';
      case 'MAJOR':
        return 'Major';
      case 'LICENSE_CHANGED':
        return 'License';
      case 'CONFLICT':
        return 'Conflict';
      default:
        return 'None';
    }
  }

  /**
   * Get risk display
   */
  private function getRiskDisplay($riskLevel)
  {
    return $riskLevel ?: 'LOW';
  }

  /**
   * Get license status
   */
  private function getLicenseStatus($diff, $row)
  {
    if ($diff == 0) {
      return 'Perfect match';
    } elseif (abs($diff) <= 5) {
      return 'Minor difference';
    } elseif ($diff > 0) {
      return 'Increased in reuse';
    } else {
      return 'Decreased in reuse';
    }
  }

  /**
   * Get license action
   */
  private function getLicenseAction($diff, $row)
  {
    if ($diff == 0) {
      return 'OK';
    } elseif (abs($diff) > 5) {
      return 'Review';
    } elseif ($row['upload_count'] == 0) {
      return 'Added';
    } elseif ($row['reuse_count'] == 0) {
      return 'Missing';
    } else {
      return 'OK';
    }
  }

  /**
   * Get status icon
   */
  private function getStatusIcon($diff)
  {
    if ($diff == 0) {
      return '✅';
    } elseif (abs($diff) <= 5) {
      return '✅';
    } else {
      return '⚠️';
    }
  }

  /**
   * Get status CSS class
   */
  private function getStatusClass($diff)
  {
    if ($diff == 0) {
      return 'text-success';
    } elseif (abs($diff) <= 5) {
      return 'text-success';
    } else {
      return 'text-warning';
    }
  }

  /**
   * Get action CSS class
   */
  private function getActionClass($action)
  {
    switch ($action) {
      case 'OK':
        return 'success';
      case 'Review':
        return 'warning';
      case 'Added':
        return 'info';
      case 'Missing':
        return 'danger';
      default:
        return 'secondary';
    }
  }

  /**
   * Detect change type based on modification type and file characteristics
   * Priority: License text > Copyright > code > comment > config > mixed
   */
  private function detectChangeType($row)
  {
    $modificationType = $row['modification_type'] ?? '';
    $fileName = $row['file_name'] ?? '';
    $classification = $row['classification'] ?? '';
    $changedLines = abs($row['changed_lines'] ?? 0);
    
    // Priority 1: License text changes (only for modified files)
    if (($classification === 'MODIFIED' || $classification === 'LICENSE_CHANGED') && 
        ($modificationType === 'LICENSE_CHANGED' || $modificationType === 'CONFLICT')) {
      return 'License text';
    }
    
    // Priority 2: Copyright files (only for NEW files or modified files with copyright indicators)
    $fileNameLower = strtolower($fileName);
    if (($classification === 'NEW' || $classification === 'MODIFIED') &&
        (strpos($fileNameLower, 'copyright') !== false || 
         strpos($fileNameLower, 'copying') !== false ||
         strpos($fileNameLower, 'licence') !== false)) {
      return 'Copyright';
    }
    
    // Priority 3: License files (NEW files with license in name)
    if ($classification === 'NEW' && 
        (strpos($fileNameLower, 'license') !== false)) {
      return 'License text';
    }
    
    // For IDENTICAL files, determine type based on file content/purpose
    if ($classification === 'IDENTICAL') {
      // Don't classify identical files as change types - they're unchanged
      // Classify based on file purpose instead
      $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
      
      // License/copyright files
      if (strpos($fileNameLower, 'license') !== false || 
          strpos($fileNameLower, 'copyright') !== false ||
          strpos($fileNameLower, 'copying') !== false ||
          strpos($fileNameLower, 'licence') !== false) {
        return 'License text';
      }
      
      // Config files
      if (in_array($extension, ['conf', 'cfg', 'ini', 'yaml', 'yml', 'json', 'xml', 'cmake', 'mk', 'am', 'in', 
          'toml', 'properties', 'env', 'dockerfile'])) {
        return 'Config';
      }
      
      // Source files
      if (in_array($extension, ['c', 'cpp', 'h', 'hpp', 'cc', 'cxx', 'py', 'js', 'java', 'cs', 'rb', 'php', 'sh'])) {
        return 'code';
      }
      
      return 'mixed';
    }
    
    // For modified files, check file extension and change magnitude
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Priority 4: Code changes (source files with significant changes)
    if (in_array($extension, ['c', 'cpp', 'h', 'hpp', 'cc', 'cxx', 'py', 'js', 'java', 'cs', 'rb', 'php', 'sh'])) {
      // If significant changes, likely code changes
      if ($changedLines > 5) {
        return 'code';
      }
    }
    
    // Priority 5: Comment changes (source files with minimal changes)
    if (in_array($extension, ['c', 'cpp', 'h', 'hpp', 'cc', 'cxx', 'py', 'js', 'java', 'cs', 'rb', 'php', 'sh'])) {
      // If few lines changed, likely comment changes
      if ($changedLines > 0 && $changedLines <= 5) {
        return 'comment';
      }
    }
    
    // Priority 6: Config changes
    if (in_array($extension, ['conf', 'cfg', 'ini', 'yaml', 'yml', 'json', 'xml', 'cmake', 'mk', 'am', 'in', 
        'toml', 'properties', 'env', 'dockerfile'])) {
      return 'Config';
    }
    
    // Priority 7: Mixed changes (default fallback)
    return 'mixed';
  }

  /**
   * Prepare license statistics for histogram section
   */
  private function prepareLicenseHistogramStats($licenseStats, $diffTree)
  {
    $stats = [
      'new_licenses' => 0,
      'deleted_licenses' => 0,
      'modified_licenses' => 0,
      'unchanged_licenses' => 0,
      'total_licenses_upload' => 0,
      'total_licenses_reuse' => 0,
      'license_conflicts' => 0,
      'license_changes' => 0
    ];

    // Count license changes from diff tree
    foreach ($diffTree as $row) {
      if ($row['modification_type'] === 'LICENSE_CHANGED') {
        $stats['license_changes']++;
      }
      if ($row['modification_type'] === 'CONFLICT') {
        $stats['license_conflicts']++;
      }
    }

    // Analyze license stats for new/deleted/modified licenses
    $newLicenses = [];
    $deletedLicenses = [];
    $modifiedLicenses = [];
    $unchangedLicenses = [];
    
    foreach ($licenseStats as $license) {
      $uploadCount = $license['upload_count'] ?? 0;
      $reuseCount = $license['reuse_count'] ?? 0;
      $licenseName = $license['license'] ?? '';
      
      $stats['total_licenses_upload'] += $uploadCount;
      $stats['total_licenses_reuse'] += $reuseCount;
      
      if ($uploadCount > 0 && $reuseCount == 0) {
        $stats['new_licenses']++;
        $newLicenses[] = $licenseName;
      } elseif ($uploadCount == 0 && $reuseCount > 0) {
        $stats['deleted_licenses']++;
        $deletedLicenses[] = $licenseName;
      } elseif ($uploadCount > 0 && $reuseCount > 0 && $uploadCount != $reuseCount) {
        $stats['modified_licenses']++;
        $modifiedLicenses[] = $licenseName;
      } elseif ($uploadCount > 0 && $reuseCount > 0 && $uploadCount == $reuseCount) {
        $stats['unchanged_licenses']++;
        $unchangedLicenses[] = $licenseName;
      }
    }
    
    // Generate URLs for clickable counts
    $stats['new_licenses_url'] = $this->generateLicenseFilterUrl($newLicenses, 'new');
    $stats['deleted_licenses_url'] = $this->generateLicenseFilterUrl($deletedLicenses, 'deleted');
    $stats['modified_licenses_url'] = $this->generateLicenseFilterUrl($modifiedLicenses, 'modified');
    $stats['unchanged_licenses_url'] = $this->generateLicenseFilterUrl($unchangedLicenses, 'unchanged');
    $stats['total_licenses_upload_url'] = $this->generateLicenseFilterUrl(array_column($licenseStats, 'license'), 'upload');
    $stats['total_licenses_reuse_url'] = $this->generateLicenseFilterUrl(array_column($licenseStats, 'license'), 'reuse');
    $stats['license_changes_url'] = $this->generateLicenseFilterUrl(array_merge($newLicenses, $deletedLicenses, $modifiedLicenses), 'changes');
    
    if ($stats['license_conflicts'] > 0) {
      $conflictLicenses = array_filter($licenseStats, function($license) {
        return !empty($license['conflict']) && $license['conflict'] !== 'f' && $license['conflict'] !== false;
      });
      $stats['license_conflicts_url'] = $this->generateLicenseFilterUrl(array_column($conflictLicenses, 'license'), 'conflicts');
    }

    return $stats;
  }

  /**
   * Get license information for a file pair
   */
  private function getLicenseInfoForFile($uploadPfileId, $reusedPfileId, $uploadId, $reusedUploadId, $groupId)
  {
    $result = [
      'upload_license' => '',
      'reuse_license' => '',
      'status' => 'Unchanged'
    ];

    // Get license for upload file using direct database query
    if ($uploadPfileId > 0) {
      $uploadLicenses = $this->getLicensesForPfile($uploadPfileId, $uploadId);
      $result['upload_license'] = !empty($uploadLicenses) ? implode(', ', array_unique($uploadLicenses)) : 'No license';
    }

    // Get license for reused file using direct database query
    if ($reusedPfileId > 0) {
      $reuseLicenses = $this->getLicensesForPfile($reusedPfileId, $reusedUploadId);
      $result['reuse_license'] = !empty($reuseLicenses) ? implode(', ', array_unique($reuseLicenses)) : 'No license';
    }

    // Determine status
    if ($uploadPfileId > 0 && $reusedPfileId > 0) {
      if ($result['upload_license'] === $result['reuse_license']) {
        $result['status'] = 'Unchanged';
      } elseif (empty($uploadLicenses) && !empty($reuseLicenses)) {
        $result['status'] = 'Deleted';
      } elseif (!empty($uploadLicenses) && empty($reuseLicenses)) {
        $result['status'] = 'Added';
      } else {
        $result['status'] = 'Modified';
      }
    } elseif ($uploadPfileId > 0) {
      $result['status'] = 'Added';
    } elseif ($reusedPfileId > 0) {
      $result['status'] = 'Deleted';
    }

    return $result;
  }

  /**
   * Get licenses by type (new, deleted, modified, unchanged) across all files
   */
  private function getLicensesByType($uploadId, $reusedUploadId, $groupId, $type)
  {
    global $container;
    $dbManager = $container->get('db.manager');
    
    try {
      switch ($type) {
        case 'new':
          // Licenses that exist in upload but not in reuse
          $sql = "SELECT DISTINCT lr.rf_shortname 
                  FROM license_file lf_upload
                  JOIN license_ref lr ON lf_upload.rf_fk = lr.rf_pk
                  WHERE lf_upload.pfile_fk IN (
                    SELECT pfile_fk FROM uploadtree WHERE upload_fk = $1
                  )
                  AND lr.rf_shortname NOT IN (
                    SELECT DISTINCT lr2.rf_shortname 
                    FROM license_file lf_reuse
                    JOIN license_ref lr2 ON lf_reuse.rf_fk = lr2.rf_pk
                    WHERE lf_reuse.pfile_fk IN (
                      SELECT pfile_fk FROM uploadtree WHERE upload_fk = $2
                    )
                  )";
          $results = $dbManager->getRows($sql, array($uploadId, $reusedUploadId));
          break;
          
        case 'deleted':
          // Licenses that exist in reuse but not in upload
          $sql = "SELECT DISTINCT lr.rf_shortname 
                  FROM license_file lf_reuse
                  JOIN license_ref lr ON lf_reuse.rf_fk = lr.rf_pk
                  WHERE lf_reuse.pfile_fk IN (
                    SELECT pfile_fk FROM uploadtree WHERE upload_fk = $2
                  )
                  AND lr.rf_shortname NOT IN (
                    SELECT DISTINCT lr2.rf_shortname 
                    FROM license_file lf_upload
                    JOIN license_ref lr2 ON lf_upload.rf_fk = lr2.rf_pk
                    WHERE lf_upload.pfile_fk IN (
                      SELECT pfile_fk FROM uploadtree WHERE upload_fk = $1
                    )
                  )";
          $results = $dbManager->getRows($sql, array($uploadId, $reusedUploadId));
          break;
          
        case 'modified':
          // Licenses that exist in both but might have different content/matches
          $sql = "SELECT DISTINCT lr.rf_shortname 
                  FROM license_file lf_upload
                  JOIN license_ref lr ON lf_upload.rf_fk = lr.rf_pk
                  WHERE lf_upload.pfile_fk IN (
                    SELECT pfile_fk FROM uploadtree WHERE upload_fk = $1
                  )
                  AND lr.rf_shortname IN (
                    SELECT DISTINCT lr2.rf_shortname 
                    FROM license_file lf_reuse
                    JOIN license_ref lr2 ON lf_reuse.rf_fk = lr2.rf_pk
                    WHERE lf_reuse.pfile_fk IN (
                      SELECT pfile_fk FROM uploadtree WHERE upload_fk = $2
                    )
                  )";
          $results = $dbManager->getRows($sql, array($uploadId, $reusedUploadId));
          break;
          
        case 'unchanged':
          // Licenses that exist in both (same as modified for now)
          $sql = "SELECT DISTINCT lr.rf_shortname 
                  FROM license_file lf_upload
                  JOIN license_ref lr ON lf_upload.rf_fk = lr.rf_pk
                  WHERE lf_upload.pfile_fk IN (
                    SELECT pfile_fk FROM uploadtree WHERE upload_fk = $1
                  )
                  AND lr.rf_shortname IN (
                    SELECT DISTINCT lr2.rf_shortname 
                    FROM license_file lf_reuse
                    JOIN license_ref lr2 ON lf_reuse.rf_fk = lr2.rf_pk
                    WHERE lf_reuse.pfile_fk IN (
                      SELECT pfile_fk FROM uploadtree WHERE upload_fk = $2
                    )
                  )";
          $results = $dbManager->getRows($sql, array($uploadId, $reusedUploadId));
          break;
          
        default:
          return [];
      }
      
      $licenses = [];
      foreach ($results as $row) {
        $licenses[] = $row['rf_shortname'];
      }
      
      return $licenses;
    } catch (Exception $e) {
      error_log("Error getting licenses by type $type: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Check if a file's license string contains any of the target licenses
   */
  private function fileContainsLicenses($fileLicenses, $targetLicenses)
  {
    if (empty($fileLicenses) || empty($targetLicenses)) {
      return false;
    }
    
    $fileLicenseArray = array_map('trim', explode(',', $fileLicenses));
    
    foreach ($targetLicenses as $targetLicense) {
      if (in_array($targetLicense, $fileLicenseArray)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Get licenses for a pfile using direct database query
   */
  private function getLicensesForPfile($pfileId, $uploadId)
  {
    global $container;
    $dbManager = $container->get('db.manager');
    
    try {
      // Query license_file table directly for this pfile
      $sql = "SELECT DISTINCT lr.rf_shortname 
               FROM license_file lf 
               JOIN license_ref lr ON lf.rf_fk = lr.rf_pk 
               WHERE lf.pfile_fk = $1 
               AND lr.rf_shortname IS NOT NULL 
               AND lr.rf_shortname NOT IN ('Void')
               ORDER BY lr.rf_shortname";
      
      $results = $dbManager->getRows($sql, array($pfileId));
      $licenses = [];
      
      foreach ($results as $row) {
        $licenses[] = $row['rf_shortname'];
      }
      
      return $licenses;
    } catch (Exception $e) {
      error_log("Error getting licenses for pfile $pfileId: " . $e->getMessage());
      return [];
    }
  }

  /**
   * Generate URL for filtering by license type
   */
  private function generateLicenseFilterUrl($licenses, $type)
  {
    if (empty($licenses)) {
      return '';
    }

    $baseUri = Traceback_uri();
    $uploadId = GetParm("upload", PARM_INTEGER);
    $item = GetParm("item", PARM_INTEGER);
    
    // Create URL parameters for license filtering
    $params = [
      'mod' => 'enhanced_reuse_analysis',
      'upload' => $uploadId,
      'item' => $item,
      'license_filter' => $type,
      'licenses' => implode(',', array_unique($licenses))
    ];
    
    return $baseUri . '?' . http_build_query($params);
  }

  /**
   * Handle license filtering and display filtered results
   */
  private function handleLicenseFilter($upload, $item, $filterType, $filterLicenses, $groupId)
  {
    try {
      /** @var EnhancedReuseDao $dao */
      $dao = $this->getObject('dao.enhanced_reuse');
      $analysisId = $dao->getLatestAnalysisId($upload, $groupId);
      
      if ($analysisId === null) {
        return $this->flushContent(_("No enhanced reuse results for this upload yet."));
      }
      
      $uploadTreeTable = $this->uploadDao->getUploadtreeTableName($upload);
      $itemBounds = $this->uploadDao->getItemTreeBounds($item, $uploadTreeTable);
    
    // Get diff tree and filter by license status
    $diffTree = $dao->getDiffTree($analysisId);
    
    // Get reused upload ID from analysis context
    global $container;
    $dbManager = $container->get('db.manager');
    $ctxStmt = __METHOD__ . '.ctx';
    $ctxSql = "SELECT era.upload_fk, era.reused_upload_fk, era.group_fk
      FROM enhanced_reuse_analysis era WHERE era.analysis_pk = $1";
    $ctx = $dbManager->getSingleRow($ctxSql, array($analysisId), $ctxStmt);
    $reusedUpload = isset($ctx['reused_upload_fk']) ? intval($ctx['reused_upload_fk']) : 0;
    
    // Get specific licenses that match the filter type
    $targetLicenses = $this->getLicensesByType($upload, $reusedUpload, $groupId, $filterType);
    
    // Filter diff tree based on actual license statistics
    $filteredDiffTree = [];
    foreach ($diffTree as $row) {
      $licenseInfo = $this->getLicenseInfoForFile($row['upload_pfile_fk'], $row['reused_pfile_fk'], $upload, $reusedUpload, $groupId);
      
      $shouldInclude = false;
      switch ($filterType) {
        case 'new':
          // Show files that contain any of the new licenses
          $shouldInclude = $this->fileContainsLicenses($licenseInfo['upload_license'], $targetLicenses);
          break;
        case 'deleted':
          // Show files that contain any of the deleted licenses
          $shouldInclude = $this->fileContainsLicenses($licenseInfo['reuse_license'], $targetLicenses);
          break;
        case 'modified':
          // Show files that contain any of the modified licenses
          $shouldInclude = $this->fileContainsLicenses($licenseInfo['upload_license'], $targetLicenses) && 
                          $this->fileContainsLicenses($licenseInfo['reuse_license'], $targetLicenses);
          break;
        case 'unchanged':
          // Show files that contain any of the unchanged licenses
          $shouldInclude = $this->fileContainsLicenses($licenseInfo['upload_license'], $targetLicenses) && 
                          $this->fileContainsLicenses($licenseInfo['reuse_license'], $targetLicenses);
          break;
        case 'changes':
          // Show files with any license changes
          $shouldInclude = in_array($licenseInfo['status'], ['Added', 'Deleted', 'Modified']);
          break;
        case 'conflicts':
          // Show files with license conflicts
          $shouldInclude = !empty($row['modification_type']) && $row['modification_type'] === 'CONFLICT';
          break;
        case 'upload':
          // Show files that have any license in upload
          $shouldInclude = !empty($licenseInfo['upload_license']) && $licenseInfo['upload_license'] !== 'No license';
          break;
        case 'reuse':
          // Show files that have any license in reuse
          $shouldInclude = !empty($licenseInfo['reuse_license']) && $licenseInfo['reuse_license'] !== 'No license';
          break;
      }
      
      if ($shouldInclude) {
        $row['upload_license'] = $licenseInfo['upload_license'];
        $row['reuse_license'] = $licenseInfo['reuse_license'];
        $row['license_status'] = $licenseInfo['status'];
        $row['change_type'] = $this->detectChangeType($row);
        
        // Add file href for clickable files
        $pfileFk = isset($row['upload_pfile_fk']) ? intval($row['upload_pfile_fk']) : 0;
        if ($pfileFk > 0) {
          $diffItem = $this->uploadDao->getUploadtreeIdFromPfile($upload, $pfileFk);
          if ($diffItem > 0) {
            $row['file_href'] = Traceback_uri() . '?mod=view-license&upload=' . $upload . '&item=' . $diffItem;
          }
        }
        
        $filteredDiffTree[] = $row;
      }
    }
    
    // Prepare template variables
    $vars = array(
      "micromenu" => Dir2Browse(self::NAME, $item, null, 0, "Browse", -1, '', '', $uploadTreeTable),
      "uploadId" => $upload,
      "itemId" => $item,
      "enhancedReuseDiffTree" => $filteredDiffTree,
      "filterType" => $filterType,
      "filterLicenses" => $filterLicenses,
      "isFilteredView" => true,
    );

    return $this->render("enhanced-reuse-analysis-filtered.html.twig", $this->mergeWithDefault($vars));
    } catch (Exception $e) {
      error_log("Error in handleLicenseFilter: " . $e->getMessage());
      return $this->flushContent("Error processing license filter: " . $e->getMessage());
    }
  }
}

register_plugin(new EnhancedReuseAnalysisPlugin());
