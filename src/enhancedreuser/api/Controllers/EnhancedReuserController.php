<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @file EnhancedReuserController.php
 * @brief REST API controller for Enhanced Reuse analysis endpoints
 */

namespace Fossology\EnhancedReuser\Api\Controllers;

use Fossology\EnhancedReuser\DiffAnalyzer;
use Fossology\EnhancedReuser\LicenseChangeAnalyzer;
use Fossology\EnhancedReuser\SmartSuggestionEngine;
use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\FolderDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\TreeDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\DecisionTypes;
use Fossology\Lib\Data\Clearing\ClearingEventTypes;
use Fossology\UI\Api\Controllers\RestController;
use Fossology\UI\Api\Exceptions\HttpBadRequestException;
use Fossology\UI\Api\Exceptions\HttpNotFoundException;
use Fossology\UI\Api\Helper\ResponseHelper;
use Psr\Http\Message\ServerRequestInterface;

// Include agent helper classes
require_once __DIR__ . '/../../agent/DiffAnalyzer.php';
require_once __DIR__ . '/../../agent/LicenseChangeAnalyzer.php';
require_once __DIR__ . '/../../agent/SmartSuggestionEngine.php';

/**
 * @class EnhancedReuserController
 * @brief REST API controller providing Enhanced Reuse analysis endpoints
 *
 * All endpoints are under `/uploads/{id}/enhanced-reuse/`.
 *
 * The controller first tries to read pre-computed analysis from disk
 * (written by the EnhancedReuserAgent). If no cached data is available,
 * it computes the analysis on-demand.
 */
class EnhancedReuserController extends RestController
{
  /**
   * @var UploadDao $uploadDao
   */
  private $uploadDao;

  /**
   * @var ClearingDao $clearingDao
   */
  private $clearingDao;

  /**
   * @var LicenseDao $licenseDao
   */
  private $licenseDao;

  /**
   * @var AgentDao $agentDao
   */
  private $agentDao;

  /**
   * @var FolderDao $folderDao
   */
  private $folderDao;

  /**
   * @var TreeDao $treeDao
   */
  private $treeDao;

  /**
   * @var LicenseChangeAnalyzer $licenseAnalyzer
   */
  private $licenseAnalyzer;

  /**
   * @var SmartSuggestionEngine $suggestionEngine
   */
  private $suggestionEngine;

  public function __construct($container)
  {
    parent::__construct($container);
    $this->uploadDao        = $this->restHelper->getUploadDao();
    $this->clearingDao      = $this->container->get('dao.clearing');
    $this->licenseDao       = $this->container->get('dao.license');
    $this->agentDao         = $this->container->get('dao.agent');
    $this->folderDao        = $this->container->get('dao.folder');
    $this->treeDao          = $this->container->get('dao.tree');
    $this->licenseAnalyzer  = new LicenseChangeAnalyzer($this->clearingDao, $this->licenseDao, $this->agentDao);
    $this->suggestionEngine = new SmartSuggestionEngine($this->uploadDao, $this->folderDao);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GET /uploads/{id}/enhanced-reuse/diff-tree
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @brief Return per-file diff tree comparing v2 (current) with v1 (reused)
   *
   * Query parameters:
   *   - `reuseUploadId` (required unless cached): the v1 upload to compare against
   *
   * Response: array of file diff entries (see EnhancedReuserAgent::buildDiffTree)
   *
   * @param ServerRequestInterface $request
   * @param ResponseHelper         $response
   * @param array                  $args
   * @return ResponseHelper
   */
  public function getDiffTree($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);

    list($reusedUploadId, $groupId) = $this->resolveReuseContext($request, $uploadId);

    // Try cache first
    $cached = $this->loadCachedAnalysis($uploadId, $reusedUploadId);
    if ($cached !== null) {
      return $response->withJson($cached['diffTree'], 200);
    }

    // On-demand computation
    $diffTree = $this->computeDiffTree($uploadId, $reusedUploadId, $groupId);
    return $response->withJson($diffTree, 200);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GET /uploads/{id}/enhanced-reuse/stats
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @brief Return aggregate statistics comparing v1 and v2
   *
   * @param ServerRequestInterface $request
   * @param ResponseHelper         $response
   * @param array                  $args
   * @return ResponseHelper
   */
  public function getStats($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);

    list($reusedUploadId, $groupId) = $this->resolveReuseContext($request, $uploadId);

    $cached = $this->loadCachedAnalysis($uploadId, $reusedUploadId);
    if ($cached !== null) {
      return $response->withJson($cached['stats'], 200);
    }

    $licenseComparison = $this->computeLicenseComparison($uploadId, $reusedUploadId, $groupId);
    $diffTree          = $this->computeDiffTree($uploadId, $reusedUploadId, $groupId);
    $stats             = $this->computeStatsFromTree($diffTree, $licenseComparison);

    return $response->withJson($stats, 200);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GET /uploads/{id}/enhanced-reuse/license-comparison
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @brief Return side-by-side license histogram comparison (v1 vs v2)
   *
   * Response includes `comparison` array with per-license entries, each
   * tagged with `status` (added/removed/changed/unchanged) and `colour`.
   *
   * @param ServerRequestInterface $request
   * @param ResponseHelper         $response
   * @param array                  $args
   * @return ResponseHelper
   */
  public function getLicenseComparison($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);

    list($reusedUploadId, $groupId) = $this->resolveReuseContext($request, $uploadId);

    $cached = $this->loadCachedAnalysis($uploadId, $reusedUploadId);
    if ($cached !== null) {
      return $response->withJson($cached['licenseComparison'], 200);
    }

    $comparison = $this->computeLicenseComparison($uploadId, $reusedUploadId, $groupId);
    return $response->withJson($comparison, 200);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GET /uploads/{id}/enhanced-reuse/suggestions
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @brief Return smart reuse suggestions for the current upload
   *
   * Suggestions are ranked by filename similarity score (0-100).
   *
   * @param ServerRequestInterface $request
   * @param ResponseHelper         $response
   * @param array                  $args
   * @return ResponseHelper
   */
  public function getSuggestions($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);

    $groupId = $this->restHelper->getGroupId();
    $upload  = $this->uploadDao->getUpload($uploadId);
    if ($upload === null) {
      throw new HttpNotFoundException("Upload not found");
    }

    $suggestions = $this->suggestionEngine->findSuggestions(
      $uploadId, $groupId, $upload->getFilename()
    );

    // Format timestamps for API consumers
    foreach ($suggestions as &$s) {
      $s['uploadedAt'] = date('c', $s['timestamp']);
      unset($s['timestamp']);
    }

    return $response->withJson($suggestions, 200);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // POST /uploads/{id}/enhanced-reuse/decide
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @brief Apply bulk clearing decisions based on file change type
   *
   * Request body (JSON):
   * ```json
   * {
   *   "reuseUploadId": 42,
   *   "scope": "new_files|changed_files|comment_only_files|identical_files",
   *   "action": "reuse|skip|tbd"
   * }
   * ```
   *
   * - `reuse`:  copy the clearing decision from v1 into v2
   * - `skip`:   mark the file as "no license" (will not be auto-decided)
   * - `tbd`:    mark as "To Be Decided" (work-in-progress)
   *
   * @param ServerRequestInterface $request
   * @param ResponseHelper         $response
   * @param array                  $args
   * @return ResponseHelper
   */
  public function postDecide($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);

    $body = $this->getParsedBody($request);
    if (empty($body)) {
      throw new HttpBadRequestException("Request body is required");
    }

    $reuseUploadId = isset($body['reuseUploadId']) ? intval($body['reuseUploadId']) : null;
    $scope         = $body['scope']  ?? null;
    $action        = $body['action'] ?? null;

    $validScopes  = ['new_files', 'changed_files', 'comment_only_files', 'identical_files'];
    $validActions = ['reuse', 'skip', 'tbd'];

    if (empty($reuseUploadId) || $reuseUploadId < 1) {
      throw new HttpBadRequestException("'reuseUploadId' is required");
    }
    if (!in_array($scope, $validScopes)) {
      throw new HttpBadRequestException(
        "'scope' must be one of: " . implode(', ', $validScopes)
      );
    }
    if (!in_array($action, $validActions)) {
      throw new HttpBadRequestException(
        "'action' must be one of: " . implode(', ', $validActions)
      );
    }

    $groupId = $this->restHelper->getGroupId();
    $userId  = $this->restHelper->getUserId();

    // Map scope to file statuses
    $targetStatuses = $this->scopeToFileStatuses($scope);

    // Get diff tree
    $cached   = $this->loadCachedAnalysis($uploadId, $reuseUploadId);
    $diffTree = $cached ? $cached['diffTree'] : $this->computeDiffTree($uploadId, $reuseUploadId, $groupId);

    // Get v1 clearing decisions indexed by pfile
    $reusedTreeTable = $this->uploadDao->getUploadtreeTableName($reuseUploadId);
    $v1Bounds        = $this->uploadDao->getParentItemBounds($reuseUploadId, $reusedTreeTable);
    if (!$v1Bounds) {
      throw new HttpNotFoundException("Reuse upload not found or inaccessible");
    }

    $v1Decisions = $this->clearingDao->getFileClearingsFolder($v1Bounds, $groupId);
    $v1ByPfile   = [];
    foreach ($v1Decisions as $cd) {
      $v1ByPfile[$cd->getPfileId()] = $cd;
    }

    $processed = 0;
    $skipped   = 0;

    foreach ($diffTree as $entry) {
      if (!in_array($entry['fileStatus'], $targetStatuses)) {
        continue;
      }
      if (empty($entry['v2ItemId'])) {
        continue;
      }

      $v2ItemId  = intval($entry['v2ItemId']);
      $v1PfileId = $entry['v1PfileId'] ? intval($entry['v1PfileId']) : null;

      switch ($action) {
        case 'reuse':
          if ($v1PfileId && isset($v1ByPfile[$v1PfileId])) {
            /** @var ClearingDecision $v1Dec */
            $v1Dec = $v1ByPfile[$v1PfileId];
            $this->copyDecisionToItem($v2ItemId, $userId, $groupId, $v1Dec);
            $processed++;
          } else {
            $skipped++;
          }
          break;

        case 'tbd':
          // Create a work-in-progress decision with no license events
          // (the user will need to review and decide)
          $this->clearingDao->createDecisionFromEvents(
            $v2ItemId, $userId, $groupId,
            DecisionTypes::WIP,
            \Fossology\Lib\Data\DecisionScopes::ITEM,
            []
          );
          $processed++;
          break;

        case 'skip':
          // No action needed – do not propagate any decision
          $processed++;
          break;
      }
    }

    return $response->withJson([
      'message'   => "Bulk decision applied",
      'processed' => $processed,
      'skipped'   => $skipped,
      'scope'     => $scope,
      'action'    => $action,
    ], 200);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Helpers
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @brief Resolve the reused upload ID and group from request or DB
   *
   * Checks query param `reuseUploadId` first; falls back to the first
   * reused-upload pair stored for this upload.
   *
   * @param ServerRequestInterface $request
   * @param int $uploadId
   * @return array [reuseUploadId, groupId]
   * @throws HttpBadRequestException
   */
  private function resolveReuseContext($request, $uploadId)
  {
    $groupId = $this->restHelper->getGroupId();
    $query   = $request->getQueryParams();

    if (!empty($query['reuseUploadId'])) {
      $reuseUploadId = intval($query['reuseUploadId']);
      if (!$this->dbHelper->doesIdExist('upload', 'upload_pk', $reuseUploadId)) {
        throw new HttpNotFoundException("Reuse upload does not exist");
      }
      return [$reuseUploadId, $groupId];
    }

    // Fall back to DB-stored reuse pair
    $pairs = $this->uploadDao->getReusedUpload($uploadId, $groupId);
    if (empty($pairs)) {
      throw new HttpBadRequestException(
        "No reuse upload configured. Provide 'reuseUploadId' query parameter."
      );
    }
    return [intval($pairs[0]['reused_upload_fk']), intval($pairs[0]['reused_group_fk'])];
  }

  /**
   * @brief Load pre-computed analysis JSON from disk
   *
   * @param int $uploadId
   * @param int $reusedUploadId
   * @return array|null Decoded analysis array or null if not found
   */
  private function loadCachedAnalysis($uploadId, $reusedUploadId)
  {
    global $SysConf;
    $basePath = rtrim($SysConf['FOSSOLOGY']['path'], '/') . '/enhanced-reuse';
    $file     = "$basePath/$uploadId/$reusedUploadId/analysis.json";
    if (!is_readable($file)) {
      return null;
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
  }

  /**
   * @brief Compute diff tree on-demand (no cached data)
   *
   * @param int $uploadId
   * @param int $reusedUploadId
   * @param int $groupId
   * @return array
   */
  private function computeDiffTree($uploadId, $reusedUploadId, $groupId)
  {
    $uploadTreeTable = $this->uploadDao->getUploadtreeTableName($uploadId);
    $v2Bounds        = $this->uploadDao->getParentItemBounds($uploadId, $uploadTreeTable);
    $reusedTable     = $this->uploadDao->getUploadtreeTableName($reusedUploadId);
    $v1Bounds        = $this->uploadDao->getParentItemBounds($reusedUploadId, $reusedTable);

    if (!$v2Bounds || !$v1Bounds) {
      return [];
    }

    $v1Decisions = $this->clearingDao->getFileClearingsFolder($v1Bounds, $groupId);
    $v1ByPfile   = [];
    foreach ($v1Decisions as $cd) {
      $v1ByPfile[$cd->getPfileId()] = $cd;
    }

    $v2Decisions = $this->clearingDao->getFileClearingsFolder($v2Bounds, $groupId);
    $v2ByPfile   = [];
    foreach ($v2Decisions as $cd) {
      $v2ByPfile[$cd->getPfileId()] = $cd;
    }

    $dbManager = $this->container->get('db.manager');

    // Query v1 files by name
    $sql = "SELECT ut.uploadtree_pk, ut.ufile_name, ut.pfile_fk
              FROM uploadtree ut
             WHERE ut.upload_fk = $1
               AND ut.lft BETWEEN $2 AND $3
               AND ut.pfile_fk != 0
               AND (ut.ufile_mode & (1<<28)) = 0";
    $stmt = 'EnhancedReuserController.v1Files';
    $dbManager->prepare($stmt, $sql);
    $res = $dbManager->execute($stmt, [
      $v1Bounds->getUploadId(),
      $v1Bounds->getLeft(),
      $v1Bounds->getRight(),
    ]);
    $v1FilesByName = [];
    while ($row = $dbManager->fetchArray($res)) {
      $v1FilesByName[$row['ufile_name']][] = $row;
    }
    $dbManager->freeResult($res);

    // Query v2 files
    $stmt2 = 'EnhancedReuserController.v2Files';
    $dbManager->prepare($stmt2, $sql);
    $res2 = $dbManager->execute($stmt2, [
      $v2Bounds->getUploadId(),
      $v2Bounds->getLeft(),
      $v2Bounds->getRight(),
    ]);

    $diffTree    = [];
    $v2FileNames = [];

    while ($row = $dbManager->fetchArray($res2)) {
      $fileName  = $row['ufile_name'];
      $v2PfileId = intval($row['pfile_fk']);
      $v2ItemId  = intval($row['uploadtree_pk']);
      $v2FileNames[] = $fileName;

      $entry = [
        'fileName'            => $fileName,
        'v2ItemId'            => $v2ItemId,
        'v2PfileId'           => $v2PfileId,
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

      if (!empty($v1FilesByName[$fileName])) {
        $v1Match   = $v1FilesByName[$fileName][0];
        $v1PfileId = intval($v1Match['pfile_fk']);
        $v1ItemId  = intval($v1Match['uploadtree_pk']);

        $entry['v1ItemId']        = $v1ItemId;
        $entry['v1PfileId']       = $v1PfileId;
        $entry['hasDecisionInV1'] = isset($v1ByPfile[$v1PfileId]);

        if ($v2PfileId === $v1PfileId) {
          $entry['fileStatus'] = 'identical';
          $entry['diffType']   = 'identical';
        } else {
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
              case 'identical':     $entry['fileStatus'] = 'identical';             break;
              case 'comment_only':  $entry['fileStatus'] = 'modified_comment_only'; break;
              case 'minor':         $entry['fileStatus'] = 'modified_minor';        break;
              default:              $entry['fileStatus'] = 'modified_major';        break;
            }
          }
        }

        $entry['licenseStatus'] = $this->determineLicenseStatus(
          $v1PfileId, $v2PfileId, $v1ByPfile, $v2ByPfile
        );
      }

      $diffTree[] = $entry;
    }
    $dbManager->freeResult($res2);

    // Deleted files
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
   * @brief Compute license comparison on-demand
   *
   * @param int $uploadId
   * @param int $reusedUploadId
   * @param int $groupId
   * @return array
   */
  private function computeLicenseComparison($uploadId, $reusedUploadId, $groupId)
  {
    $uploadTreeTable = $this->uploadDao->getUploadtreeTableName($uploadId);
    $v2Bounds        = $this->uploadDao->getParentItemBounds($uploadId, $uploadTreeTable);
    $reusedTable     = $this->uploadDao->getUploadtreeTableName($reusedUploadId);
    $v1Bounds        = $this->uploadDao->getParentItemBounds($reusedUploadId, $reusedTable);

    if (!$v1Bounds || !$v2Bounds) {
      return [];
    }

    $v1Histogram = $this->licenseAnalyzer->buildHistogram($v1Bounds, $groupId, $reusedUploadId);
    $v2Histogram = $this->licenseAnalyzer->buildHistogram($v2Bounds, $groupId, $uploadId);
    return $this->licenseAnalyzer->compare($v1Histogram, $v2Histogram);
  }

  /**
   * @brief Compute aggregate stats from diff tree and license comparison
   *
   * @param array $diffTree
   * @param array $licenseComparison
   * @return array
   */
  private function computeStatsFromTree(array $diffTree, array $licenseComparison)
  {
    $total  = count($diffTree);
    $counts = ['identical' => 0, 'modified_minor' => 0, 'modified_major' => 0,
               'modified_comment_only' => 0, 'new' => 0, 'deleted' => 0];
    $licCounts = ['license_added' => 0, 'license_removed' => 0, 'license_changed' => 0];
    $linesAdded = $linesRemoved = 0;

    foreach ($diffTree as $e) {
      if (isset($counts[$e['fileStatus']])) {
        $counts[$e['fileStatus']]++;
      }
      if (isset($licCounts[$e['licenseStatus']])) {
        $licCounts[$e['licenseStatus']]++;
      }
      $linesAdded   += $e['linesAdded'];
      $linesRemoved += $e['linesRemoved'];
    }

    $modified = $counts['modified_minor'] + $counts['modified_major'] + $counts['modified_comment_only'];

    return [
      'totalFiles'              => $total,
      'identicalFiles'          => $counts['identical'],
      'modifiedFiles'           => $modified,
      'modifiedMinorFiles'      => $counts['modified_minor'],
      'modifiedMajorFiles'      => $counts['modified_major'],
      'commentOnlyFiles'        => $counts['modified_comment_only'],
      'newFiles'                => $counts['new'],
      'deletedFiles'            => $counts['deleted'],
      'filesWithNewLicense'     => $licCounts['license_added'],
      'filesWithRemovedLicense' => $licCounts['license_removed'],
      'filesWithChangedLicense' => $licCounts['license_changed'],
      'totalLinesAdded'         => $linesAdded,
      'totalLinesRemoved'       => $linesRemoved,
      'pctIdentical'            => $total > 0 ? round(($counts['identical'] / $total) * 100, 1) : 0,
      'pctModified'             => $total > 0 ? round(($modified             / $total) * 100, 1) : 0,
      'pctNew'                  => $total > 0 ? round(($counts['new']        / $total) * 100, 1) : 0,
      'pctDeleted'              => $total > 0 ? round(($counts['deleted']    / $total) * 100, 1) : 0,
      'riskLevel'               => $licenseComparison['riskLevel'] ?? 'low',
      'pctNewLicenses'          => $licenseComparison['pctNewLicenses'] ?? 0,
    ];
  }

  /**
   * @brief Determine license status for two pfile IDs
   *
   * @param int   $v1PfileId
   * @param int   $v2PfileId
   * @param array $v1ByPfile
   * @param array $v2ByPfile
   * @return string
   */
  private function determineLicenseStatus($v1PfileId, $v2PfileId, array $v1ByPfile, array $v2ByPfile)
  {
    $hasV1 = isset($v1ByPfile[$v1PfileId]);
    $hasV2 = isset($v2ByPfile[$v2PfileId]);

    if (!$hasV1 && !$hasV2) return 'unknown';
    if (!$hasV1 && $hasV2)  return 'license_added';
    if ($hasV1  && !$hasV2) return 'license_removed';

    $v1Lic = $this->extractLicenseNames($v1ByPfile[$v1PfileId]);
    $v2Lic = $this->extractLicenseNames($v2ByPfile[$v2PfileId]);
    sort($v1Lic); sort($v2Lic);

    if ($v1Lic === $v2Lic) return 'unchanged';
    $added   = array_diff($v2Lic, $v1Lic);
    $removed = array_diff($v1Lic, $v2Lic);
    if (!empty($added) && !empty($removed)) return 'license_changed';
    if (!empty($added))   return 'license_added';
    return 'license_removed';
  }

  /**
   * @brief Extract non-removed license names from a ClearingDecision
   *
   * @param ClearingDecision $dec
   * @return string[]
   */
  private function extractLicenseNames(ClearingDecision $dec)
  {
    $names = [];
    foreach ($dec->getClearingEvents() as $ev) {
      if (!$ev->isRemoved()) {
        $names[] = $ev->getLicenseShortName();
      }
    }
    return $names;
  }

  /**
   * @brief Copy a v1 ClearingDecision into a v2 upload-tree item
   *
   * @param int              $itemId   Target uploadtree_pk in v2
   * @param int              $userId
   * @param int              $groupId
   * @param ClearingDecision $source   Decision to copy from v1
   */
  private function copyDecisionToItem($itemId, $userId, $groupId, ClearingDecision $source)
  {
    $eventIds = [];
    foreach ($source->getClearingEvents() as $event) {
      $eventIds[] = $this->clearingDao->insertClearingEvent(
        $itemId, $userId, $groupId,
        $event->getLicenseId(),
        $event->isRemoved(),
        ClearingEventTypes::USER,
        $event->getReportinfo(),
        $event->getComment(),
        $event->getAcknowledgement(),
        0 // jobId = 0 for manual REST action
      );
    }
    if (!empty($eventIds)) {
      $this->clearingDao->createDecisionFromEvents(
        $itemId, $userId, $groupId,
        $source->getType(),
        $source->getScope(),
        $eventIds
      );
    }
  }

  /**
   * @brief Map a scope string to an array of fileStatus values
   *
   * @param string $scope
   * @return string[]
   */
  private function scopeToFileStatuses($scope)
  {
    switch ($scope) {
      case 'new_files':          return ['new'];
      case 'changed_files':      return ['modified_minor', 'modified_major', 'modified_comment_only'];
      case 'comment_only_files': return ['modified_comment_only'];
      case 'identical_files':    return ['identical'];
      default:                   return [];
    }
  }
}
