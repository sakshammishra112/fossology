<?php

/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\EnhancedReuser\Ui;

use Fossology\Lib\Plugin\DefaultPlugin;
use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Db\DbManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @class EnhancedReuseDiffViewPlugin
 * @brief Display diff view for file changes in enhanced reuse analysis
 */
class EnhancedReuseDiffViewPlugin extends DefaultPlugin
{
  const NAME = "enhanced_reuse_diff_view";

  /** @var UploadDao */
  private $uploadDao;

  /** @var DbManager */
  private $dbManager;

  public function __construct()
  {
    parent::__construct(self::NAME, array(
      self::TITLE => _("File Diff View"),
      self::PERMISSION => Auth::PERM_READ,
      self::REQUIRES_LOGIN => false,
      self::DEPENDENCIES => array("browse"),
    ));

    $this->uploadDao = $GLOBALS['container']->get('dao.upload');
    $this->dbManager = $GLOBALS['container']->get('db.manager');
  }

  /**
   * @param Request $request
   * @return Response
   */
  protected function handle(Request $request)
  {
    $uploadId = intval($request->get('upload'));
    $currentPfile = intval($request->get('currentPfile'));
    $reusedPfile = intval($request->get('reusedPfile'));

    if ($uploadId <= 0 || $currentPfile <= 0 || $reusedPfile <= 0) {
      return new Response(
        '<div class="alert alert-danger">' . _("Invalid parameters for diff view") . '</div>',
        Response::HTTP_BAD_REQUEST
      );
    }

    // Get file information
    $currentFile = $this->getPfileData($currentPfile);
    $reusedFile = $this->getPfileData($reusedPfile);

    if (!$currentFile || !$reusedFile) {
      return new Response(
        '<div class="alert alert-danger">' . _("File not found") . '</div>',
        Response::HTTP_NOT_FOUND
      );
    }

    // Generate diff
    $diffData = $this->generateDiff($currentPfile, $reusedPfile);

    // Get license information
    $currentLicenses = $this->getFileLicenses($currentPfile);
    $reusedLicenses = $this->getFileLicenses($reusedPfile);
    $currentClearingDecisions = $this->getFileClearingDecisions($currentPfile);
    $reusedClearingDecisions = $this->getFileClearingDecisions($reusedPfile);

    $vars = array(
      "uploadId" => $uploadId,
      "item" => $request->get('item'),
      "currentFile" => $currentFile,
      "reusedFile" => $reusedFile,
      "diffData" => $diffData,
      "currentLicenses" => $currentLicenses,
      "reusedLicenses" => $reusedLicenses,
      "currentClearingDecisions" => $currentClearingDecisions,
      "reusedClearingDecisions" => $reusedClearingDecisions,
      "pageMenu" => "", // No pagination for diff view
      "micromenu" => Dir2Browse("browse", $request->get('item'), null, 0, "View", -1, '', '', $this->uploadDao->getUploadtreeTableName($uploadId)),
    );

    return $this->render("enhanced-reuse-diff-view.html.twig", $this->mergeWithDefault($vars));
  }

  /**
   * Generate diff between two files
   * 
   * @param int $currentPfile
   * @param int $reusedPfile
   * @return array
   */
  private function generateDiff($currentPfile, $reusedPfile)
  {
    $currentContent = $this->getFileContent($currentPfile);
    $reusedContent = $this->getFileContent($reusedPfile);

    $currentLines = explode("\n", $currentContent);
    $reusedLines = explode("\n", $reusedContent);

    // Simple diff implementation
    $diff = array();
    $currentIndex = 0;
    $reusedIndex = 0;

    while ($currentIndex < count($currentLines) || $reusedIndex < count($reusedLines)) {
      if ($currentIndex >= count($currentLines)) {
        // Lines deleted
        $diff[] = array(
          'type' => 'deleted',
          'line_number' => $reusedIndex + 1,
          'content' => $reusedLines[$reusedIndex]
        );
        $reusedIndex++;
      } elseif ($reusedIndex >= count($reusedLines)) {
        // Lines added
        $diff[] = array(
          'type' => 'added',
          'line_number' => $currentIndex + 1,
          'content' => $currentLines[$currentIndex]
        );
        $currentIndex++;
      } elseif ($currentLines[$currentIndex] === $reusedLines[$reusedIndex]) {
        // Lines unchanged
        $diff[] = array(
          'type' => 'unchanged',
          'line_number' => $currentIndex + 1,
          'content' => $currentLines[$currentIndex]
        );
        $currentIndex++;
        $reusedIndex++;
      } else {
        // Look ahead to see if it's an addition or deletion
        $found = false;
        $lookAhead = min(5, count($reusedLines) - $reusedIndex);
        
        for ($i = 1; $i <= $lookAhead; $i++) {
          if ($currentIndex + $i < count($currentLines) && 
              $currentLines[$currentIndex + $i] === $reusedLines[$reusedIndex]) {
            // Lines added
            for ($j = 0; $j < $i; $j++) {
              $diff[] = array(
                'type' => 'added',
                'line_number' => $currentIndex + $j + 1,
                'content' => $currentLines[$currentIndex + $j]
              );
            }
            $currentIndex += $i;
            $found = true;
            break;
          }
        }

        if (!$found) {
          $lookAhead = min(5, count($currentLines) - $currentIndex);
          for ($i = 1; $i <= $lookAhead; $i++) {
            if ($reusedIndex + $i < count($reusedLines) && 
                $currentLines[$currentIndex] === $reusedLines[$reusedIndex + $i]) {
              // Lines deleted
              for ($j = 0; $j < $i; $j++) {
                $diff[] = array(
                  'type' => 'deleted',
                  'line_number' => $reusedIndex + $j + 1,
                  'content' => $reusedLines[$reusedIndex + $j]
                );
              }
              $reusedIndex += $i;
              $found = true;
              break;
            }
          }
        }

        if (!$found) {
          // Modified line
          $diff[] = array(
            'type' => 'modified',
            'line_number' => $currentIndex + 1,
            'content' => $currentLines[$currentIndex]
          );
          $currentIndex++;
          $reusedIndex++;
        }
      }
    }

    return $diff;
  }

  /**
   * Get pfile data from database
   * 
   * @param int $pfileId
   * @return array|null
   */
  private function getPfileData($pfileId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT pfile_pk, pfile_size, pfile_sha1, pfile_md5, pfile_sha256, pfile_mimetypefk
            FROM pfile 
            WHERE pfile_pk = $1";
    return $this->dbManager->getSingleRow($sql, array($pfileId), $stmt);
  }

  /**
   * Get file content from pfile using FOSSology's RepPathItem
   * 
   * @param int $pfileId
   * @return string
   */
  private function getFileContent($pfileId)
  {
    // Get uploadtree_pk for this pfile
    $stmt = __METHOD__ . ".getUploadtree";
    $sql = "SELECT uploadtree_pk FROM uploadtree WHERE pfile_fk = $1 LIMIT 1";
    $result = $this->dbManager->getSingleRow($sql, array($pfileId), $stmt);
    
    if (!$result || empty($result['uploadtree_pk'])) {
      return '';
    }
    
    $uploadtreePk = $result['uploadtree_pk'];
    
    // Use FOSSology's RepPathItem function
    $filepath = RepPathItem($uploadtreePk);
    
    if ($filepath && file_exists($filepath)) {
      return file_get_contents($filepath);
    }

    return '';
  }

  /**
   * Get license file information for a pfile
   * 
   * @param int $pfileId
   * @return array
   */
  private function getFileLicenses($pfileId)
  {
    $stmt = __METHOD__;
    $sql = "SELECT DISTINCT ON (lr.rf_shortname, a.agent_name) 
                   lf.rf_match_pct, lf.fl_start_byte, lf.fl_end_byte, lr.rf_shortname, lr.rf_fullname, lr.rf_spdx_id,
                   a.agent_name, a.agent_desc
            FROM license_file lf
            INNER JOIN license_ref lr ON lr.rf_pk = lf.rf_fk
            INNER JOIN agent a ON a.agent_pk = lf.agent_fk
            WHERE lf.pfile_fk = $1
            ORDER BY lr.rf_shortname, a.agent_name, lf.rf_match_pct DESC";
    
    return $this->dbManager->getRows($sql, array($pfileId), $stmt);
  }

  /**
   * Get clearing decisions for a pfile
   * 
   * @param int $pfileId
   * @return array
   */
  private function getFileClearingDecisions($pfileId)
  {
    $stmt = __METHOD__;
    $sql = "WITH all_decs AS ( 
      SELECT cd.clearing_decision_pk, lr.rf_shortname, ce.removed, cd.decision_type, 
             cd.scope, ce.date_added, ce.comment, cd.group_fk
      FROM clearing_decision cd 
      INNER JOIN clearing_decision_event cde ON cde.clearing_decision_fk = cd.clearing_decision_pk 
      INNER JOIN clearing_event ce ON ce.clearing_event_pk = cde.clearing_event_fk 
      INNER JOIN license_ref lr ON lr.rf_pk = ce.rf_fk 
      WHERE cd.pfile_fk = $1 
      ORDER BY cd.clearing_decision_pk DESC 
    ), ranked AS ( 
      SELECT clearing_decision_pk, rf_shortname, removed, decision_type, 
             scope, date_added, comment, group_fk,
        rank() OVER (PARTITION BY rf_shortname ORDER BY clearing_decision_pk DESC) AS rnk 
      FROM all_decs 
    ) 
    SELECT rf_shortname, decision_type, scope, date_added, comment, group_fk 
    FROM ranked 
    WHERE rnk = 1 AND removed = false";
    
    return $this->dbManager->getRows($sql, array($pfileId), $stmt);
  }

}

register_plugin(new EnhancedReuseDiffViewPlugin());
