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
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @class ReuseFileDiffViewPlugin
 * @brief Display a two-column file diff view for reuse comparison,
 *        matching the EnhancedReuser diff view structure.
 */
class ReuseFileDiffViewPlugin extends DefaultPlugin
{
  const NAME = "reusediffview";

  /** @var DbManager */
  private $dbManager;

  /** @var UploadDao */
  private $uploadDao;

  function __construct()
  {
    parent::__construct(self::NAME, [
      self::TITLE => _("File Diff View"),
      self::PERMISSION => Auth::PERM_READ,
      self::DEPENDENCIES => ["browse"],
    ]);
    $this->dbManager = $this->getObject('db.manager');
    $this->uploadDao = $this->getObject('dao.upload');
  }

  /**
   * @param Request $request
   * @return Response
   */
  protected function handle(Request $request)
  {
    $uploadId = intval($request->get('upload'));
    $item = intval($request->get('item'));
    $currentPfile = intval($request->get('currentPfile'));
    $reusedPfile = intval($request->get('reusedPfile'));
    $reuseId = intval($request->get('reuse', 0));

    if ($uploadId <= 0) {
      return new Response(
        '<div class="alert alert-danger">' . _("Invalid parameters for diff view") . '</div>',
        Response::HTTP_BAD_REQUEST
      );
    }

    if ($currentPfile <= 0 || $reusedPfile <= 0) {
      $reuseParam = $reuseId > 0 ? '&reuse=' . $reuseId : '';
      return new RedirectResponse(
        Traceback_uri() . "?mod=reusecompare&upload=$uploadId&item=$item$reuseParam"
      );
    }

    $groupId = Auth::getGroupId();
    if (!$this->uploadDao->isAccessible($uploadId, $groupId)) {
      return new Response(
        '<div class="alert alert-danger">' . _("Permission Denied") . '</div>',
        Response::HTTP_FORBIDDEN
      );
    }

    /* Get pfile data */
    $currentFile = $this->getPfileData($currentPfile);
    $reusedFile = $this->getPfileData($reusedPfile);

    if (!$currentFile || !$reusedFile) {
      return new Response(
        '<div class="alert alert-danger">' . _("File not found") . '</div>',
        Response::HTTP_NOT_FOUND
      );
    }

    /* Generate diff */
    $diffData = $this->generateDiff($currentPfile, $reusedPfile);

    /* Get scanner findings and clearing-decision license sets */
    $curScanner = $this->getScannerFindings($currentPfile);
    $reScanner = $this->getScannerFindings($reusedPfile);
    list($curAdded, $curRemoved) = $this->getClearingDecisionSets($currentPfile, $uploadId);
    list($reAdded, $reRemoved) = $this->getClearingDecisionSets($reusedPfile, $reuseId);

    $curScannerF = array_flip($curScanner);
    $reScannerF = array_flip($reScanner);
    $curAddedF = array_flip($curAdded);
    $reAddedF = array_flip($reAdded);
    $curRemovedF = array_flip($curRemoved);
    $reRemovedF = array_flip($reRemoved);

    /* Collect all unique license names from every source */
    $allNames = array_unique(array_merge(
      $curScanner, $reScanner, $curAdded, $reAdded, $curRemoved, $reRemoved
    ));
    sort($allNames);

    /* Determine source for each license:
       Priority: removed > both scanners > from-reuse > added-on-target */
    $allLicenses = [];
    foreach ($allNames as $name) {
      $inCurScanner  = isset($curScannerF[$name]);
      $inReScanner   = isset($reScannerF[$name]);
      $inCurAdded    = isset($curAddedF[$name]);
      $inReAdded     = isset($reAddedF[$name]);
      $inCurRemoved  = isset($curRemovedF[$name]);
      $inReRemoved   = isset($reRemovedF[$name]);

      $inReEffective = $inReScanner || $inReAdded;

      if ($inReRemoved) {
        $status = 'removed-in-reuse';
      } elseif ($inCurRemoved) {
        $status = 'removed-in-current';
      } elseif ($inCurScanner && $inReScanner) {
        $status = 'both';
      } elseif ($inReEffective && !$inCurScanner) {
        $status = 'added-in-reuse';
      } elseif ($inCurAdded && !$inReEffective) {
        $status = 'added-in-current';
      } else {
        continue;
      }
      $allLicenses[$name] = ['status' => $status];
    }
    ksort($allLicenses);

    $tableName = $this->uploadDao->getUploadtreeTableName($uploadId);

    /* Look up the actual file's uploadtree_pk for the breadcrumb path */
    $fileItem = $this->uploadDao->getUploadtreeIdFromPfile($uploadId, $currentPfile);
    if (empty($fileItem)) {
      $fileItem = $item;
    }

    $micromenu = Dir2Browse("license", $fileItem, null, 0, "View",
      -1, '', '', $tableName);

    $stepper = $this->buildStepper($uploadId, $item, $reuseId, $currentPfile, $reusedPfile);

    $vars = [
      "uploadId" => $uploadId,
      "item" => $item,
      "reuse" => $reuseId,
      "currentFile" => $currentFile,
      "reusedFile" => $reusedFile,
      "diffData" => $diffData,
      "allLicenses" => $allLicenses,
      "pageMenu" => $micromenu,
      "micromenu" => $micromenu,
      "stepperPrev" => $stepper['prev'],
      "stepperNext" => $stepper['next'],
      "stepperPosition" => $stepper['position'],
      "stepperTotal" => $stepper['total'],
    ];

    $defaultVars = $this->mergeWithDefault([]);
    $defaultVars['styles'] .= "<link rel='stylesheet' href='css/highlights.css'>\n";
    $vars = array_merge($defaultVars, $vars);

    return $this->render("reusediffview.html.twig", $vars);
  }

  /**
   * Generate diff between two pfiles using system `diff -u`.
   * Handles arbitrarily large files by delegating to C.
   * @return array diff lines
   */
  private function generateDiff($pfileA, $pfileB)
  {
    $pathReused = RepPath($pfileB);
    $pathCurrent = RepPath($pfileA);

    if (!$pathReused || !file_exists($pathReused) ||
        !$pathCurrent || !file_exists($pathCurrent)) {
      return [['type' => 'unchanged', 'line_number' => 1,
        'content' => _('Unable to load files for diff.')]];
    }

    $tmpReused = tempnam(sys_get_temp_dir(), 'fosdiff_');
    $tmpCurrent = tempnam(sys_get_temp_dir(), 'fosdiff_');
    if ($tmpReused === false || $tmpCurrent === false) {
      return [['type' => 'unchanged', 'line_number' => 1,
        'content' => _('Unable to create temp files for diff.')]];
    }
    if (!copy($pathReused, $tmpReused) || !copy($pathCurrent, $tmpCurrent)) {
      @unlink($tmpReused);
      @unlink($tmpCurrent);
      return [['type' => 'unchanged', 'line_number' => 1,
        'content' => _('Unable to copy files for diff.')]];
    }

    $diffOutput = null;
    $exitCode = -1;
    $cmd = "diff -u " . escapeshellarg($tmpReused) . " " . escapeshellarg($tmpCurrent);
    exec($cmd, $diffOutput, $exitCode);

    $contentCurrent = file_get_contents($pathCurrent);
    $linesCurrent = explode("\n", $contentCurrent);

    unlink($tmpReused);
    unlink($tmpCurrent);

    if ($exitCode === 0) {
      $diff = [];
      foreach ($linesCurrent as $i => $line) {
        $diff[] = ['type' => 'unchanged', 'line_number' => $i + 1, 'content' => $line];
      }
      return $diff;
    }

    if ($exitCode === 2 || empty($diffOutput)) {
      return [['type' => 'unchanged', 'line_number' => 1,
        'content' => _('Unable to compute diff for this file.')]];
    }

    return $this->parseUnifiedDiff($diffOutput, $linesCurrent);
  }

  /**
   * Parse `diff -u` output into a complete per-line diff,
   * filling in all unchanged lines from the current file content.
   */
  private function parseUnifiedDiff(array $diffLines, array $linesCurrent)
  {
    /* Parse hunk headers to get line number offsets */
    $hunks = [];
    foreach ($diffLines as $raw) {
      if (preg_match('/^@@ -(\d+),?\d* \+(\d+),?\d* @@/', $raw, $m)) {
        $hunks[] = ['old_start' => (int)$m[1], 'new_start' => (int)$m[2]];
      }
    }

    if (empty($hunks)) {
      return [];
    }

    /* Build the per-hunk change data */
    $hunkData = [];
    $hunkIdx = -1;
    $inHunk = false;

    foreach ($diffLines as $raw) {
      if (preg_match('/^@@ -(\d+),?\d* \+(\d+),?\d* @@/', $raw)) {
        $hunkIdx++;
        $hunkData[$hunkIdx] = ['changes' => []];
        $inHunk = true;
        continue;
      }
      if (!$inHunk || strlen($raw) === 0) {
        continue;
      }
      $prefix = $raw[0];
      if ($prefix === ' ' || $prefix === '-' || $prefix === '+') {
        $hunkData[$hunkIdx]['changes'][] = $raw;
      }
    }

    /* Build the complete diff by walking line-by-line through the current file */
    $diff = [];
    $currentLineIdx = 1;

    foreach ($hunkData as $hIdx => $hunk) {
      $newStart = $hunks[$hIdx]['new_start'];

      /* Fill in unchanged lines before this hunk */
      while ($currentLineIdx < $newStart) {
        $diff[] = ['type' => 'unchanged', 'line_number' => $currentLineIdx,
          'content' => $linesCurrent[$currentLineIdx - 1]];
        $currentLineIdx++;
      }

      /* Process the hunk changes */
      foreach ($hunk['changes'] as $raw) {
        $prefix = $raw[0];
        $content = substr($raw, 1);
        if ($prefix === ' ') {
          $diff[] = ['type' => 'unchanged', 'line_number' => $currentLineIdx,
            'content' => $content];
          $currentLineIdx++;
        } elseif ($prefix === '-') {
          $diff[] = ['type' => 'deleted', 'line_number' => $currentLineIdx,
            'content' => $content];
        } else {
          $diff[] = ['type' => 'added', 'line_number' => $currentLineIdx,
            'content' => $content];
          $currentLineIdx++;
        }
      }
    }

    /* Fill in remaining unchanged lines after last hunk */
    while ($currentLineIdx <= count($linesCurrent)) {
      $diff[] = ['type' => 'unchanged', 'line_number' => $currentLineIdx,
        'content' => $linesCurrent[$currentLineIdx - 1]];
      $currentLineIdx++;
    }

    return $diff;
  }

  /**
   * Get pfile data from DB.
   */
  private function getPfileData($pfileId)
  {
    $sql = "SELECT pfile_pk, pfile_size, pfile_sha1, pfile_md5, pfile_sha256, pfile_mimetypefk
            FROM pfile WHERE pfile_pk = $1";
    $this->dbManager->prepare($stmt = __METHOD__ . '_pfile', $sql);
    $row = $this->dbManager->getSingleRow($sql, [$pfileId], $stmt);
    return $row ?: null;
  }

  /**
   * Get scanner-agent license findings for a pfile.
   *
   * @param int $pfileId
   * @return string[] License shortnames found by scanners
   */
  private function getScannerFindings($pfileId)
  {
    if (empty($pfileId)) {
      return [];
    }

    $sql = "SELECT DISTINCT lr.rf_shortname
            FROM license_file lf
            JOIN license_ref lr ON lr.rf_pk = lf.rf_fk
            WHERE lf.pfile_fk = $1
              AND lr.rf_shortname IS NOT NULL
              AND lr.rf_shortname != 'Void'";
    $this->dbManager->prepare($stmt = __METHOD__ . '_scan', $sql);
    $res = $this->dbManager->execute($stmt, [$pfileId]);
    $findings = [];
    while ($row = $this->dbManager->fetchArray($res)) {
      $findings[$row['rf_shortname']] = true;
    }
    $this->dbManager->freeResult($res);
    return array_keys($findings);
  }

  /**
   * Get clearing-decision license sets for a pfile in a specific upload.
   * Returns two arrays: user-added (type_fk=1 USER, removed=false/null)
   * and user-removed (removed=true) from clearing events.
   *
   * @param int $pfileId
   * @param int $uploadId
   * @return array [added[], removed[]]
   */
  private function getClearingDecisionSets($pfileId, $uploadId)
  {
    $added = [];
    $removed = [];

    if (empty($pfileId) || empty($uploadId)) {
      return [$added, $removed];
    }

    $uploadtreePk = $this->uploadDao->getUploadtreeIdFromPfile($uploadId, $pfileId);
    if (empty($uploadtreePk)) {
      return [$added, $removed];
    }

    /* User-added licenses (type_fk=1 USER, removed=false or null) */
    $sql = "SELECT DISTINCT lr.rf_shortname
            FROM clearing_decision cd
            JOIN clearing_decision_event cde ON cde.clearing_decision_fk = cd.clearing_decision_pk
            JOIN clearing_event ce ON ce.clearing_event_pk = cde.clearing_event_fk
            JOIN license_ref lr ON lr.rf_pk = ce.rf_fk
            WHERE cd.uploadtree_fk = $1
              AND (ce.removed IS NULL OR ce.removed = false)
              AND ce.type_fk = 1
              AND lr.rf_shortname IS NOT NULL
              AND lr.rf_shortname != 'Void'";
    $this->dbManager->prepare($stmt = __METHOD__ . '_add', $sql);
    $res = $this->dbManager->execute($stmt, [$uploadtreePk]);
    while ($row = $this->dbManager->fetchArray($res)) {
      $added[$row['rf_shortname']] = true;
    }
    $this->dbManager->freeResult($res);

    /* User-removed licenses (removed = true) */
    $sql = "SELECT DISTINCT lr.rf_shortname
            FROM clearing_decision cd
            JOIN clearing_decision_event cde ON cde.clearing_decision_fk = cd.clearing_decision_pk
            JOIN clearing_event ce ON ce.clearing_event_pk = cde.clearing_event_fk
            JOIN license_ref lr ON lr.rf_pk = ce.rf_fk
            WHERE cd.uploadtree_fk = $1
              AND ce.removed = true
              AND lr.rf_shortname IS NOT NULL
              AND lr.rf_shortname != 'Void'";
    $this->dbManager->prepare($stmt = __METHOD__ . '_rem', $sql);
    $res = $this->dbManager->execute($stmt, [$uploadtreePk]);
    while ($row = $this->dbManager->fetchArray($res)) {
      $removed[$row['rf_shortname']] = true;
    }
    $this->dbManager->freeResult($res);

    return [array_keys($added), array_keys($removed)];
  }

  /**
   * Build the prev/next navigation links to step through all MODIFIED
   * file pairs (same ordering as the Reuse Compare list) without going
   * back to the list view.
   *
   * @param int $uploadId
   * @param int $item Root item id of the compared subtree
   * @param int $reuseId Reused upload id
   * @param int $currentPfile pfile_fk of the current file being viewed
   * @param int $reusedPfile pfile_fk of the reused file being viewed
   * @return array{prev:?string,next:?string,position:int,total:int}
   */
  private function buildStepper($uploadId, $item, $reuseId, $currentPfile, $reusedPfile)
  {
    $result = ['prev' => null, 'next' => null, 'position' => 0, 'total' => 0];
    $pairs = $this->getOrderedModifiedPairs($uploadId, $item, $reuseId);
    $result['total'] = count($pairs);

    $currentIndex = -1;
    foreach ($pairs as $idx => $pair) {
      if ($pair['currentPfile'] == $currentPfile && $pair['reusedPfile'] == $reusedPfile) {
        $currentIndex = $idx;
        break;
      }
    }
    if ($currentIndex === -1) {
      return $result;
    }
    $result['position'] = $currentIndex + 1;

    $baseUri = Traceback_uri() . "?mod=reusediffview&upload=$uploadId&item=$item&reuse=$reuseId";
    if ($currentIndex > 0) {
      $prevPair = $pairs[$currentIndex - 1];
      $result['prev'] = $baseUri . "&currentPfile={$prevPair['currentPfile']}&reusedPfile={$prevPair['reusedPfile']}";
    }
    if ($currentIndex < count($pairs) - 1) {
      $nextPair = $pairs[$currentIndex + 1];
      $result['next'] = $baseUri . "&currentPfile={$nextPair['currentPfile']}&reusedPfile={$nextPair['reusedPfile']}";
    }
    return $result;
  }

  /**
   * Recompute the MODIFIED file pairs (upload pfile vs. reused pfile) for a
   * compared subtree, in the same order shown by ReuseComparePlugin.
   *
   * @param int $uploadId
   * @param int $item Root item id of the compared subtree
   * @param int $reuseUploadId Reused upload id
   * @return array[] List of ['currentPfile' => int, 'reusedPfile' => int]
   */
  private function getOrderedModifiedPairs($uploadId, $item, $reuseUploadId)
  {
    if (empty($uploadId) || empty($item) || empty($reuseUploadId)) {
      return [];
    }

    $tableName = $this->uploadDao->getUploadtreeTableName($uploadId);
    $itemRow = $this->uploadDao->getUploadEntry($item, $tableName);
    if (empty($itemRow)) {
      return [];
    }

    $reuseTableName = $this->uploadDao->getUploadtreeTableName($reuseUploadId);
    $reuseRootPk = $this->uploadDao->getUploadParent($reuseUploadId);
    $reuseRootRow = $this->uploadDao->getUploadEntry($reuseRootPk, $reuseTableName);

    $children1 = $this->getAllNonArtifactDescendants($itemRow, $tableName);
    $children2 = !empty($reuseRootRow) ?
      $this->getAllNonArtifactDescendants($reuseRootRow, $reuseTableName) : [];
    FuzzyName($children1);
    FuzzyName($children2);
    $master = MakeMaster($children1, $children2);

    $pairs = [];
    foreach ($master as $pair) {
      $child1 = !empty($pair[1]) ? $pair[1] : null;
      $child2 = !empty($pair[2]) ? $pair[2] : null;
      if ($child1 && $child2 && $child1['pfile_fk'] != $child2['pfile_fk']) {
        $pairs[] = [
          'currentPfile' => (int)$child1['pfile_fk'],
          'reusedPfile' => (int)$child2['pfile_fk'],
        ];
      }
    }
    return $pairs;
  }

  /**
   * Get all non-artifact descendant files under an upload tree node using
   * the lft/rgt nested-set range (mirrors ReuseComparePlugin's helper).
   *
   * @param array $itemRow Row from uploadtree table
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

register_plugin(new ReuseFileDiffViewPlugin());
