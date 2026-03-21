<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @dir
 * @brief Source for Enhanced Reuser agent
 * @page enhancedreuser Enhanced Reuser Agent
 * @tableofcontents
 * @section enhancedreuser_about About Enhanced Reuser Agent
 *
 * The Enhanced Reuser Agent analyses the differences between a previously
 * cleared upload (v1) and a new version of the same package (v2). It runs
 * automatically after all scanner agents have finished and produces:
 *
 * - **Diff tree view**: per-file change status (new, deleted, identical,
 *   minor, major, comment-only)
 * - **License histogram comparison**: side-by-side counts of licenses found
 *   in v1 versus v2, colour-coded by change type
 * - **Statistics**: % of files identical, modified, or new; overall risk level
 * - **Smart suggestions**: ranked list of previously-cleared uploads suitable
 *   for reuse, based on filename similarity
 *
 * Results are stored as JSON files under:
 *   `$FOSSOLOGY_PATH/enhanced-reuse/{uploadId}/analysis.json`
 *
 * @section enhancedreuser_source Agent source
 *   - @link src/enhancedreuser/agent @endlink
 *   - @link src/enhancedreuser/ui @endlink
 */

/**
 * @namespace Fossology::EnhancedReuser
 * @brief Namespace for Enhanced Reuser agent
 */
namespace Fossology\EnhancedReuser;

use Fossology\Lib\Agent\Agent;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Dao\FolderDao;
use Fossology\Lib\Dao\TreeDao;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Util\ArrayOperation;

include_once(__DIR__ . "/version.php");
include_once(__DIR__ . "/DiffAnalyzer.php");
include_once(__DIR__ . "/LicenseChangeAnalyzer.php");
include_once(__DIR__ . "/SmartSuggestionEngine.php");

/**
 * @class EnhancedReuserAgent
 * @brief Orchestrates the full enhanced-reuse analysis pipeline
 */
class EnhancedReuserAgent extends Agent
{
  /** @var UploadDao $uploadDao */
  private $uploadDao;

  /** @var ClearingDao $clearingDao */
  private $clearingDao;

  /** @var LicenseDao $licenseDao */
  private $licenseDao;

  /** @var AgentDao $agentDao */
  private $agentDao;

  /** @var FolderDao $folderDao */
  private $folderDao;

  /** @var TreeDao $treeDao */
  private $treeDao;

  /** @var LicenseChangeAnalyzer $licenseAnalyzer */
  private $licenseAnalyzer;

  /** @var SmartSuggestionEngine $suggestionEngine */
  private $suggestionEngine;

  public function __construct()
  {
    parent::__construct(ENHANCED_REUSER_AGENT_NAME, AGENT_VERSION, AGENT_REV);

    $this->uploadDao        = $this->container->get('dao.upload');
    $this->clearingDao      = $this->container->get('dao.clearing');
    $this->licenseDao       = $this->container->get('dao.license');
    $this->agentDao         = $this->container->get('dao.agent');
    $this->folderDao        = $this->container->get('dao.folder');
    $this->treeDao          = $this->container->get('dao.tree');
    $this->licenseAnalyzer  = new LicenseChangeAnalyzer($this->clearingDao, $this->licenseDao, $this->agentDao);
    $this->suggestionEngine = new SmartSuggestionEngine($this->uploadDao, $this->folderDao);
  }

  /**
   * @brief Main entry point: analyse upload and its reused counterpart
   *
   * @param int $uploadId Upload ID to process
   * @return bool True on success
   */
  public function processUploadId($uploadId)
  {
    $reusedPairs = $this->uploadDao->getReusedUpload($uploadId, $this->groupId);
    if (empty($reusedPairs)) {
      $this->heartbeat(0);
      return true;
    }

    $upload  = $this->uploadDao->getUpload($uploadId);
    $v2Filename = $upload ? $upload->getFilename() : '';

    $uploadTreeTableName = $this->uploadDao->getUploadtreeTableName($uploadId);
    $v2Bounds = $this->uploadDao->getParentItemBounds($uploadId, $uploadTreeTableName);

    foreach ($reusedPairs as $pair) {
      $reusedUploadId = intval($pair['reused_upload_fk']);
      $reusedGroupId  = intval($pair['reused_group_fk']);

      $reusedTreeTable = $this->uploadDao->getUploadtreeTableName($reusedUploadId);
      $v1Bounds = $this->uploadDao->getParentItemBounds($reusedUploadId, $reusedTreeTable);
      if ($v1Bounds === false) {
        continue;
      }

      // Build license histograms for both versions
      $v1Histogram = $this->licenseAnalyzer->buildHistogram($v1Bounds, $reusedGroupId, $reusedUploadId);
      $v2Histogram = $this->licenseAnalyzer->buildHistogram($v2Bounds, $this->groupId, $uploadId);

      // Compare histograms
      $licenseComparison = $this->licenseAnalyzer->compare($v1Histogram, $v2Histogram);

      // Build per-file diff tree
      $diffTree = $this->buildDiffTree($v2Bounds, $v1Bounds, $reusedGroupId);

      // Aggregate statistics
      $stats = $this->computeStats($diffTree, $licenseComparison);

      // Smart suggestions
      $suggestions = $this->suggestionEngine->findSuggestions($uploadId, $this->groupId, $v2Filename);

      $analysis = [
        'uploadId'          => $uploadId,
        'reusedUploadId'    => $reusedUploadId,
        'generatedAt'       => date('c'),
        'stats'             => $stats,
        'licenseComparison' => $licenseComparison,
        'diffTree'          => $diffTree,
        'suggestions'       => $suggestions,
      ];

      $this->storeAnalysis($uploadId, $reusedUploadId, $analysis);
      $this->heartbeat(1);
    }
    return true;
  }

  /**
   * @brief Build file-level diff tree by comparing files in v2 vs v1
   *
   * Files are matched by name within the same path. For each matched pair,
   * DiffAnalyzer computes the detailed diff stats.
   *
   * @param ItemTreeBounds $v2Bounds  New upload tree bounds
   * @param ItemTreeBounds $v1Bounds  Reused upload tree bounds
   * @param int $reusedGroupId
   * @return array[] Per-file diff entries
   */
  private function buildDiffTree(ItemTreeBounds $v2Bounds, ItemTreeBounds $v1Bounds, $reusedGroupId)
  {
    $uploadDao = $this->uploadDao;

    // Get clearing decisions indexed by pfile for v1
    $v1Decisions = $this->clearingDao->getFileClearingsFolder($v1Bounds, $reusedGroupId);
    $v1ByPfile   = [];
    foreach ($v1Decisions as $cd) {
      $v1ByPfile[$cd->getPfileId()] = $cd;
    }

    // Get clearing decisions indexed by pfile for v2
    $v2Decisions = $this->clearingDao->getFileClearingsFolder($v2Bounds, $this->groupId);
    $v2ByPfile   = [];
    foreach ($v2Decisions as $cd) {
      $v2ByPfile[$cd->getPfileId()] = $cd;
    }

    // Build a name-keyed index for v1 files so we can match by filename
    $sql = "SELECT ut.uploadtree_pk, ut.ufile_name, ut.pfile_fk
              FROM uploadtree ut
             WHERE ut.upload_fk = $1
               AND ut.lft BETWEEN $2 AND $3
               AND ut.pfile_fk != 0
               AND (ut.ufile_mode & (1<<28)) = 0"; // exclude containers
    $stmt = __METHOD__ . '.v1Files';
    $this->dbManager->prepare($stmt, $sql);
    $res = $this->dbManager->execute($stmt, [
      $v1Bounds->getUploadId(),
      $v1Bounds->getLeft(),
      $v1Bounds->getRight(),
    ]);
    $v1FilesByName = [];
    while ($row = $this->dbManager->fetchArray($res)) {
      $v1FilesByName[$row['ufile_name']][] = $row;
    }
    $this->dbManager->freeResult($res);

    // Enumerate v2 files and match against v1
    $sql2 = "SELECT ut.uploadtree_pk, ut.ufile_name, ut.pfile_fk
               FROM uploadtree ut
              WHERE ut.upload_fk = $1
                AND ut.lft BETWEEN $2 AND $3
                AND ut.pfile_fk != 0
                AND (ut.ufile_mode & (1<<28)) = 0";
    $stmt2 = __METHOD__ . '.v2Files';
    $this->dbManager->prepare($stmt2, $sql2);
    $res2 = $this->dbManager->execute($stmt2, [
      $v2Bounds->getUploadId(),
      $v2Bounds->getLeft(),
      $v2Bounds->getRight(),
    ]);

    $diffTree = [];
    $v2FileNames = [];

    while ($row = $this->dbManager->fetchArray($res2)) {
      $fileName  = $row['ufile_name'];
      $v2PfileId = $row['pfile_fk'];
      $v2ItemId  = $row['uploadtree_pk'];
      $v2FileNames[] = $fileName;

      $entry = [
        'fileName'            => $fileName,
        'v2ItemId'            => intval($v2ItemId),
        'v2PfileId'           => intval($v2PfileId),
        'v1ItemId'            => null,
        'v1PfileId'           => null,
        'fileStatus'          => 'new',
        'linesAdded'          => 0,
        'linesRemoved'        => 0,
        'commentLinesChanged' => 0,
        'codeLinesChanged'    => 0,
        'diffLevel'           => 0,
        'diffType'            => 'new',
        'licenseStatus'       => 'unknown',
        'hasDecisionInV1'     => false,
      ];

      if (isset($v1FilesByName[$fileName]) && !empty($v1FilesByName[$fileName])) {
        $v1Match   = $v1FilesByName[$fileName][0];
        $v1PfileId = $v1Match['pfile_fk'];
        $v1ItemId  = $v1Match['uploadtree_pk'];

        $entry['v1ItemId']  = intval($v1ItemId);
        $entry['v1PfileId'] = intval($v1PfileId);
        $entry['hasDecisionInV1'] = isset($v1ByPfile[$v1PfileId]);

        // Check hash equality first (fast path)
        if ($v2PfileId === $v1PfileId) {
          $entry['fileStatus'] = 'identical';
          $entry['diffType']   = 'identical';
        } else {
          // Different pfile — run diff
          $v1Path = $this->treeDao->getRepoPathOfPfile($v1PfileId);
          $v2Path = $this->treeDao->getRepoPathOfPfile($v2PfileId);

          if (!empty($v1Path) && !empty($v2Path)) {
            $diff = DiffAnalyzer::analyze($v1Path, $v2Path);
            $entry['linesAdded']          = $diff['linesAdded'];
            $entry['linesRemoved']        = $diff['linesRemoved'];
            $entry['commentLinesChanged'] = $diff['commentLinesChanged'];
            $entry['codeLinesChanged']    = $diff['codeLinesChanged'];
            $entry['diffLevel']           = $diff['diffLevel'];
            $entry['diffType']            = $diff['diffType'];

            switch ($diff['diffType']) {
              case 'identical':
                $entry['fileStatus'] = 'identical';
                break;
              case 'comment_only':
                $entry['fileStatus'] = 'modified_comment_only';
                break;
              case 'minor':
                $entry['fileStatus'] = 'modified_minor';
                break;
              default:
                $entry['fileStatus'] = 'modified_major';
                break;
            }
          }
        }

        // License status
        $entry['licenseStatus'] = $this->determineLicenseStatus(
          $v1PfileId, $v2PfileId, $v1ByPfile, $v2ByPfile
        );
      }

      $diffTree[] = $entry;
      $this->heartbeat(0);
    }
    $this->dbManager->freeResult($res2);

    // Add deleted files (in v1 but not found in v2)
    foreach ($v1FilesByName as $fileName => $v1Rows) {
      if (!in_array($fileName, $v2FileNames)) {
        $v1Row = $v1Rows[0];
        $diffTree[] = [
          'fileName'            => $fileName,
          'v2ItemId'            => null,
          'v2PfileId'           => null,
          'v1ItemId'            => intval($v1Row['uploadtree_pk']),
          'v1PfileId'           => intval($v1Row['pfile_fk']),
          'fileStatus'          => 'deleted',
          'linesAdded'          => 0,
          'linesRemoved'        => 0,
          'commentLinesChanged' => 0,
          'codeLinesChanged'    => 0,
          'diffLevel'           => 0,
          'diffType'            => 'deleted',
          'licenseStatus'       => 'deleted',
          'hasDecisionInV1'     => isset($v1ByPfile[$v1Row['pfile_fk']]),
        ];
      }
    }

    return $diffTree;
  }

  /**
   * @brief Determine license status for a file by comparing v1 and v2 decisions
   *
   * @param int   $v1PfileId
   * @param int   $v2PfileId
   * @param array $v1ByPfile  ClearingDecision[] indexed by pfileId
   * @param array $v2ByPfile  ClearingDecision[] indexed by pfileId
   * @return string One of: 'new', 'deleted', 'unchanged', 'license_added',
   *                        'license_removed', 'license_changed', 'unknown'
   */
  private function determineLicenseStatus($v1PfileId, $v2PfileId, array $v1ByPfile, array $v2ByPfile)
  {
    $hasV1 = isset($v1ByPfile[$v1PfileId]);
    $hasV2 = isset($v2ByPfile[$v2PfileId]);

    if (!$hasV1 && !$hasV2) {
      return 'unknown';
    }
    if (!$hasV1 && $hasV2) {
      return 'license_added';
    }
    if ($hasV1 && !$hasV2) {
      return 'license_removed';
    }

    /** @var ClearingDecision $v1Dec */
    $v1Dec = $v1ByPfile[$v1PfileId];
    /** @var ClearingDecision $v2Dec */
    $v2Dec = $v2ByPfile[$v2PfileId];

    $v1Licenses = $this->getLicenseNamesFromDecision($v1Dec);
    $v2Licenses = $this->getLicenseNamesFromDecision($v2Dec);

    sort($v1Licenses);
    sort($v2Licenses);

    if ($v1Licenses === $v2Licenses) {
      return 'unchanged';
    }
    $added   = array_diff($v2Licenses, $v1Licenses);
    $removed = array_diff($v1Licenses, $v2Licenses);

    if (!empty($added) && !empty($removed)) {
      return 'license_changed';
    }
    if (!empty($added)) {
      return 'license_added';
    }
    return 'license_removed';
  }

  /**
   * @brief Extract license short names from a ClearingDecision
   *
   * @param ClearingDecision $decision
   * @return string[]
   */
  private function getLicenseNamesFromDecision(ClearingDecision $decision)
  {
    $names = [];
    foreach ($decision->getClearingEvents() as $event) {
      if (!$event->isRemoved()) {
        $names[] = $event->getLicenseShortName();
      }
    }
    return $names;
  }

  /**
   * @brief Compute aggregate statistics from the diff tree and license comparison
   *
   * @param array $diffTree         Output from buildDiffTree()
   * @param array $licenseComparison Output from LicenseChangeAnalyzer::compare()
   * @return array
   */
  private function computeStats(array $diffTree, array $licenseComparison)
  {
    $totalFiles           = count($diffTree);
    $identicalFiles       = 0;
    $modifiedMinorFiles   = 0;
    $modifiedMajorFiles   = 0;
    $commentOnlyFiles     = 0;
    $newFiles             = 0;
    $deletedFiles         = 0;
    $filesWithLicenseNew  = 0;
    $filesWithLicenseRm   = 0;
    $filesWithLicenseCh   = 0;
    $totalLinesAdded      = 0;
    $totalLinesRemoved    = 0;

    foreach ($diffTree as $entry) {
      switch ($entry['fileStatus']) {
        case 'identical':            $identicalFiles++;     break;
        case 'modified_minor':       $modifiedMinorFiles++; break;
        case 'modified_major':       $modifiedMajorFiles++; break;
        case 'modified_comment_only':$commentOnlyFiles++;   break;
        case 'new':                  $newFiles++;           break;
        case 'deleted':              $deletedFiles++;       break;
      }
      switch ($entry['licenseStatus']) {
        case 'license_added':   $filesWithLicenseNew++; break;
        case 'license_removed': $filesWithLicenseRm++;  break;
        case 'license_changed': $filesWithLicenseCh++;  break;
      }
      $totalLinesAdded   += intval($entry['linesAdded']);
      $totalLinesRemoved += intval($entry['linesRemoved']);
    }

    $modifiedFiles = $modifiedMinorFiles + $modifiedMajorFiles + $commentOnlyFiles;
    $pctIdentical  = $totalFiles > 0 ? round(($identicalFiles / $totalFiles) * 100, 1) : 0;
    $pctModified   = $totalFiles > 0 ? round(($modifiedFiles  / $totalFiles) * 100, 1) : 0;
    $pctNew        = $totalFiles > 0 ? round(($newFiles       / $totalFiles) * 100, 1) : 0;
    $pctDeleted    = $totalFiles > 0 ? round(($deletedFiles   / $totalFiles) * 100, 1) : 0;

    return [
      'totalFiles'             => $totalFiles,
      'identicalFiles'         => $identicalFiles,
      'modifiedFiles'          => $modifiedFiles,
      'modifiedMinorFiles'     => $modifiedMinorFiles,
      'modifiedMajorFiles'     => $modifiedMajorFiles,
      'commentOnlyFiles'       => $commentOnlyFiles,
      'newFiles'               => $newFiles,
      'deletedFiles'           => $deletedFiles,
      'filesWithNewLicense'    => $filesWithLicenseNew,
      'filesWithRemovedLicense'=> $filesWithLicenseRm,
      'filesWithChangedLicense'=> $filesWithLicenseCh,
      'totalLinesAdded'        => $totalLinesAdded,
      'totalLinesRemoved'      => $totalLinesRemoved,
      'pctIdentical'           => $pctIdentical,
      'pctModified'            => $pctModified,
      'pctNew'                 => $pctNew,
      'pctDeleted'             => $pctDeleted,
      'riskLevel'              => $licenseComparison['riskLevel'],
      'pctNewLicenses'         => $licenseComparison['pctNewLicenses'],
    ];
  }

  /**
   * @brief Persist analysis result as a JSON file in the FOSSology data directory
   *
   * Path: <FOSSOLOGY_PATH>/enhanced-reuse/<uploadId>/<reusedUploadId>/analysis.json
   *
   * @param int   $uploadId
   * @param int   $reusedUploadId
   * @param array $analysis
   */
  private function storeAnalysis($uploadId, $reusedUploadId, array $analysis)
  {
    global $SysConf;
    $basePath = rtrim($SysConf['FOSSOLOGY']['path'], '/') . '/enhanced-reuse';
    $dir      = "$basePath/$uploadId/$reusedUploadId";
    if (!is_dir($dir)) {
      mkdir($dir, 0750, true);
    }
    $file = "$dir/analysis.json";
    file_put_contents($file, json_encode($analysis, JSON_PRETTY_PRINT));
  }
}
