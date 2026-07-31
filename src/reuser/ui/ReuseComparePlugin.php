<?php
/*
 SPDX-FileCopyrightText: © 2026 Siemens AG

 SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @dir
 * @brief UI element of reuser agent
 * @file
 */

namespace Fossology\Reuser;

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Plugin\DefaultPlugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @class ReuseComparePlugin
 * @brief Compare files in the current upload against their counterparts
 *        from the reused upload source. Shows histogram, diff tree, and
 *        license comparison similar to the Enhanced Reuse view.
 */
class ReuseComparePlugin extends DefaultPlugin
{
  const NAME = "reusecompare";

  /** @var DbManager */
  private $dbManager;

  /** @var UploadDao */
  private $uploadDao;

  function __construct()
  {
    parent::__construct(self::NAME, [
      self::TITLE => _("Reuse Compare"),
      self::DEPENDENCIES => ["browse", "view"],
      self::PERMISSION => Auth::PERM_READ,
      self::REQUIRES_LOGIN => true,
    ]);
    $this->dbManager = $this->getObject('db.manager');
    $this->uploadDao = $this->getObject('dao.upload');
  }

  function preInstall()
  {
    menu_insert("Browse-Pfile::Reuse Compare", 3, self::NAME,
      _("Compare files with reused upload source."));
  }

  /**
   * @param Request $request
   * @return Response
   */
  protected function handle(Request $request)
  {
    $uploadId = (int)$request->get('upload', 0);
    $item = (int)$request->get('item', 0);

    if (empty($uploadId) || empty($item)) {
      return $this->flushContent(
        "<h3>" . _("Missing upload or item parameter.") . "</h3>");
    }

    $groupId = Auth::getGroupId();
    if (!$this->uploadDao->isAccessible($uploadId, $groupId)) {
      return $this->flushContent(
        "<h2>" . _("Permission Denied") . "</h2>");
    }

    $tableName = $this->uploadDao->getUploadtreeTableName($uploadId);

    /* Get reuse sources */
    $reusedUploads = $this->uploadDao->getReusedUpload($uploadId, $groupId);
    if (empty($reusedUploads)) {
      return $this->flushContent(
        "<h3>" . _("No reused upload sources found for this upload.") . "</h3>");
    }

    $selectedReuseId = (int)$request->get('reuse', 0);
    $reuseUploadId = 0;
    $reuseSources = [];

    foreach ($reusedUploads as $ru) {
      $rid = intval($ru['reused_upload_fk']);
      $ruUpload = $this->uploadDao->getUpload($rid);
      $ruName = $ruUpload ? $ruUpload->getFilename() : "unknown";
      $ruMode = intval($ru['reuse_mode']);
      $reuseSources[] = [
        'id'   => $rid,
        'name' => $ruName,
        'mode' => $ruMode,
      ];
      if ($reuseUploadId === 0 ||
          ($selectedReuseId > 0 && $rid === $selectedReuseId) ||
          ($selectedReuseId === 0 && $reuseUploadId === 0)) {
        $reuseUploadId = $rid;
      }
    }

    $reuseTableName = $this->uploadDao->getUploadtreeTableName($reuseUploadId);
    $reuseRootPk = $this->uploadDao->getUploadParent($reuseUploadId);

    /* Determine which node to list children from */
    $itemRow = $this->uploadDao->getUploadEntry($item, $tableName);
    if (empty($itemRow)) {
      return $this->flushContent("<h3>" . _("Item not found.") . "</h3>");
    }

    /* Get all descendant files (not just one level) using lft/rgt range */
    $children1 = $this->getAllNonArtifactDescendants($itemRow, $tableName);
    $reuseRootRow = $this->uploadDao->getUploadEntry($reuseRootPk, $reuseTableName);
    $children2 = [];
    if (!empty($reuseRootRow)) {
      $children2 = $this->getAllNonArtifactDescendants($reuseRootRow, $reuseTableName);
    }
    FuzzyName($children1);
    FuzzyName($children2);
    $master = MakeMaster($children1, $children2);

    /* Build diff tree, stats, and license data */
    $diffTree = [];
    $stats = [
      'IDENTICAL' => 0, 'MODIFIED' => 0, 'NEW' => 0, 'REMOVED' => 0
    ];

    $licenseMapUploadPfiles = $this->batchGetLicensesForUpload($uploadId, $itemRow['lft'], $itemRow['rgt'], $tableName);
    $reuseRootLft = $reuseRootRow['lft'] ?? 0;
    $reuseRootRgt = $reuseRootRow['rgt'] ?? 0;
    $licenseMapReusePfiles = !empty($reuseRootRow) ?
      $this->batchGetLicensesForUpload($reuseUploadId, $reuseRootLft, $reuseRootRgt, $reuseTableName) : [];
    $riskMap = $this->getLicenseRiskMap();

    $uploadPathUri = Traceback_uri();
    $licenseMapUpload = [];
    $licenseMapReuse = [];

    foreach ($master as $idx => $pair) {
      $child1 = !empty($pair[1]) ? $pair[1] : null;
      $child2 = !empty($pair[2]) ? $pair[2] : null;

      $row = $this->buildDiffRow($child1, $child2, $uploadId, $reuseUploadId,
        $tableName, $reuseTableName, $uploadPathUri);

      /* Collect licenses for comparison (from pre-fetched batch) */
      $lics1 = [];
      $lics2 = [];
      if ($child1 && !empty($child1['pfile_fk'])) {
        $lics1 = $licenseMapUploadPfiles[(int)$child1['pfile_fk']] ?? [];
        foreach ($lics1 as $lic) {
          $licenseMapUpload[$lic] = isset($licenseMapUpload[$lic]) ?
            $licenseMapUpload[$lic] + 1 : 1;
        }
      }
      if ($child2 && !empty($child2['pfile_fk'])) {
        $lics2 = $licenseMapReusePfiles[(int)$child2['pfile_fk']] ?? [];
        foreach ($lics2 as $lic) {
          $licenseMapReuse[$lic] = isset($licenseMapReuse[$lic]) ?
            $licenseMapReuse[$lic] + 1 : 1;
        }
      }

      if ($row['classification'] === 'MODIFIED') {
        $row = array_merge($row, $this->buildRiskInfo($lics1, $lics2, $riskMap));
      }

      $stats[$row['classification']]++;
      $diffTree[] = $row;
    }

    /* Build license comparison stats */
    $allLicenses = array_unique(array_merge(
      array_keys($licenseMapUpload), array_keys($licenseMapReuse)));
    sort($allLicenses);

    $licenseSummary = ['new' => 0, 'deleted' => 0, 'modified' => 0, 'unchanged' => 0];
    $licenseCategories = ['new' => [], 'deleted' => [], 'modified' => [], 'unchanged' => []];

    foreach ($allLicenses as $lic) {
      $upCount = $licenseMapUpload[$lic] ?? 0;
      $reCount = $licenseMapReuse[$lic] ?? 0;
      if ($upCount > 0 && $reCount == 0) {
        $licenseSummary['new']++;
        $licenseCategories['new'][] = $lic;
      } elseif ($upCount == 0 && $reCount > 0) {
        $licenseSummary['deleted']++;
        $licenseCategories['deleted'][] = $lic;
      } elseif ($upCount != $reCount) {
        $licenseSummary['modified']++;
        $licenseCategories['modified'][] = $lic;
      } else {
        $licenseSummary['unchanged']++;
        $licenseCategories['unchanged'][] = $lic;
      }
    }
    $licenseFilter = $request->get('license_filter', '');
    if (!in_array($licenseFilter, ['new', 'deleted', 'modified', 'unchanged', ''])) {
      $licenseFilter = '';
    }

    /* Apply search and status filter server-side before pagination */
    $searchQuery = $request->get('search', '');
    $statusFilterQuery = $request->get('statusFilter', '');
    $queryParams = $request->query->all();
    $queryParams['search'] = $searchQuery;
    $queryParams['statusFilter'] = $statusFilterQuery;
    unset($queryParams['page']);

    $filteredDiffTree = [];
    foreach ($diffTree as $row) {
      if (!empty($statusFilterQuery) && $row['classification'] !== $statusFilterQuery) {
        continue;
      }
      if (!empty($searchQuery)) {
        $match = false;
        if (stripos($row['upload_file_name'], $searchQuery) !== false ||
            stripos($row['reuse_file_name'], $searchQuery) !== false) {
          $match = true;
        }
        if (!$match) {
          continue;
        }
      }
      $filteredDiffTree[] = $row;
    }
    $diffTree = $filteredDiffTree;

    /* Paginate the diff tree */
    $page = (int)$request->get('page', 0);
    if ($page < 0) {
      $page = 0;
    }
    $perPage = 25;
    $totalDiffRows = count($diffTree);
    $totalPages = $totalDiffRows > 0 ? (int)ceil($totalDiffRows / $perPage) - 1 : 0;
    if ($page > $totalPages) {
      $page = 0;
    }
    $offset = $page * $perPage;
    $pagedDiffTree = array_slice($diffTree, $offset, $perPage);
    $pageUri = '?' . http_build_query($queryParams);

    $vars = [
      'uploadId'         => $uploadId,
      'itemId'           => $item,
      'reuseUploadId'    => $reuseUploadId,
      'reuseSources'     => $reuseSources,
      'stats'            => $stats,
      'diffTree'         => $pagedDiffTree,
      'pageUri'          => $pageUri,
      'currentPage'      => $page + 1,
      'totalPagesView'   => $totalPages + 1,
      'perPage'          => $perPage,
      'totalDiffRows'    => $totalDiffRows,
      'licenseSummary'   => $licenseSummary,
      'licenseCategories'=> $licenseCategories,
      'licenseFilter'    => $licenseFilter,
      'totalUpload'      => count($children1),
      'totalReuse'       => count($children2),
      'searchQuery'      => $searchQuery,
      'statusFilterQuery'=> $statusFilterQuery,
    ];

    $defaultVars = $this->mergeWithDefault([]);
    $defaultVars['styles'] .= "<link rel='stylesheet' href='css/highlights.css'>\n";
    $vars = array_merge($defaultVars, $vars);

    return $this->render('reusecompare.html.twig', $vars);
  }

  /**
   * Build a diff tree row for a matched/unmatched file pair.
   */
  private function buildDiffRow($child1, $child2, $uploadId, $reuseUploadId,
                                $tableName, $reuseTableName, $uri)
  {
    $row = [
      'upload_file_name' => '',
      'reuse_file_name'  => '',
      'classification'   => '',
      'upload_pfile_fk'  => 0,
      'reused_pfile_fk'  => 0,
      'upload_href'      => '',
      'reuse_href'       => '',
    ];

    if ($child1 && $child2) {
      $row['upload_file_name'] = $child1['ufile_name'];
      $row['reuse_file_name'] = $child2['ufile_name'];
      $row['upload_pfile_fk'] = intval($child1['pfile_fk']);
      $row['reused_pfile_fk'] = intval($child2['pfile_fk']);

      $upPk = $child1['uploadtree_pk'];
      $rePk = $child2['uploadtree_pk'];
      $row['upload_href'] = $uri . "?mod=view-license&upload=$uploadId&item=$upPk";
      $row['reuse_href'] = $uri . "?mod=view-license&upload=$reuseUploadId&item=$rePk";

      if ($child1['pfile_fk'] == $child2['pfile_fk']) {
        $row['classification'] = 'IDENTICAL';
      } else {
        $row['classification'] = 'MODIFIED';
      }
    } elseif ($child1) {
      $row['upload_file_name'] = $child1['ufile_name'];
      $row['upload_pfile_fk'] = intval($child1['pfile_fk']);
      $row['classification'] = 'NEW';
      $upPk = $child1['uploadtree_pk'];
      $row['upload_href'] = $uri . "?mod=view-license&upload=$uploadId&item=$upPk";
    } elseif ($child2) {
      $row['reuse_file_name'] = $child2['ufile_name'];
      $row['reused_pfile_fk'] = intval($child2['pfile_fk']);
      $row['classification'] = 'REMOVED';
      $rePk = $child2['uploadtree_pk'];
      $row['reuse_href'] = $uri . "?mod=view-license&upload=$reuseUploadId&item=$rePk";
    }

    return $row;
  }

  /**
   * Get licenses for all files within an upload tree boundary via subquery.
   */
  private function batchGetLicensesForUpload($uploadFk, $lft, $rgt, $tableName)
  {
    $sql = "SELECT ut.pfile_fk, lr.rf_shortname
            FROM $tableName ut
            JOIN license_file lf ON lf.pfile_fk = ut.pfile_fk
            JOIN license_ref lr ON lf.rf_fk = lr.rf_pk
            WHERE ut.upload_fk = $1
              AND ut.lft BETWEEN $2 AND $3
              AND ut.ufile_mode & (3<<28) = 0
              AND ut.pfile_fk IS NOT NULL
              AND lr.rf_shortname IS NOT NULL
              AND lr.rf_shortname NOT IN ('Void')
            ORDER BY lr.rf_shortname";
    $stmtName = __METHOD__ . '_' . $tableName;
    $this->dbManager->prepare($stmtName, $sql);
    $res = $this->dbManager->execute($stmtName, [$uploadFk, $lft, $rgt]);
    $map = [];
    while ($row = $this->dbManager->fetchArray($res)) {
      $pid = (int)$row['pfile_fk'];
      if (!isset($map[$pid])) {
        $map[$pid] = [];
      }
      $map[$pid][] = $row['rf_shortname'];
    }
    $this->dbManager->freeResult($res);
    return $map;
  }

  /**
   * Get the risk level (rf_risk, 0-5) of every known license, keyed by
   * shortname, to allow flagging risk changes on MODIFIED files.
   *
   * @return array<string,int> Map of rf_shortname => rf_risk
   */
  private function getLicenseRiskMap()
  {
    $sql = "SELECT rf_shortname, rf_risk FROM license_ref
            WHERE rf_shortname IS NOT NULL";
    $map = [];
    foreach ($this->dbManager->getRows($sql, [], __METHOD__) as $row) {
      $map[$row['rf_shortname']] = (int)$row['rf_risk'];
    }
    return $map;
  }

  /**
   * Compare the highest license risk level on each side of a MODIFIED file
   * pair to determine whether the change made the file riskier.
   *
   * @param string[] $lics1 License shortnames found in the upload file
   * @param string[] $lics2 License shortnames found in the reused file
   * @param array<string,int> $riskMap Map of rf_shortname => rf_risk
   * @return array{riskLevel:int,reuseRiskLevel:int,riskDirection:string}
   */
  private function buildRiskInfo($lics1, $lics2, $riskMap)
  {
    $riskLevel = 0;
    foreach ($lics1 as $lic) {
      $riskLevel = max($riskLevel, $riskMap[$lic] ?? 0);
    }
    $reuseRiskLevel = 0;
    foreach ($lics2 as $lic) {
      $reuseRiskLevel = max($reuseRiskLevel, $riskMap[$lic] ?? 0);
    }

    if ($riskLevel > $reuseRiskLevel) {
      $riskDirection = 'up';
    } elseif ($riskLevel < $reuseRiskLevel) {
      $riskDirection = 'down';
    } else {
      $riskDirection = 'same';
    }

    return [
      'riskLevel' => $riskLevel,
      'reuseRiskLevel' => $reuseRiskLevel,
      'riskDirection' => $riskDirection,
    ];
  }

  /**
   * Recursively get all non-artifact descendant files under an upload tree node
   * using the lft/rgt nested-set range. Returns rows in the same format as
   * GetNonArtifactChildren (uploadtree + pfile columns).
   *
   * @param array $itemRow  Row from uploadtree table (must have lft, rgt, uploadtree_pk, upload_fk)
   * @param string $tableName
   * @return array
   */
  private function getAllNonArtifactDescendants($itemRow, $tableName)
  {
    $lft = (int)$itemRow['lft'];
    $rgt = (int)$itemRow['rgt'];
    $pk = (int)$itemRow['uploadtree_pk'];
    $uploadFk = (int)$itemRow['upload_fk'];

    $sql = "SELECT ut.*, pfile_size, pfile_mimetypefk
            FROM $tableName ut
            LEFT JOIN pfile ON (pfile_pk = ut.pfile_fk)
            WHERE ut.upload_fk = $1
              AND ut.lft BETWEEN $2 AND $3
              AND ut.uploadtree_pk != $4
              AND ut.ufile_mode & (3<<28) = 0
            ORDER BY ut.lft";
    $this->dbManager->prepare($stmt = __METHOD__ . ".$tableName", $sql);
    $res = $this->dbManager->execute($stmt, [$uploadFk, $lft, $rgt, $pk]);
    $children = [];
    while ($row = $this->dbManager->fetchArray($res)) {
      $children[] = $row;
    }
    $this->dbManager->freeResult($res);
    return $children;
  }
}

register_plugin(new ReuseComparePlugin());
