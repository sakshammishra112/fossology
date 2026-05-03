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
      self::TITLE => _("Enhanced Reuse Analysis"),
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

    /*
     * Render rows in Twig (server-side). Embedding large JSON inside <script> is fragile
     * and can fail silently; the REST API also requires a Bearer JWT for browser fetches.
     */
    $stats = $dao->getStats($analysisId);
    if (empty($stats)) {
      $stats = $dao->getHistogramStats($analysisId);
    }

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

    $vars = array(
      "micromenu" => Dir2Browse(self::NAME, $item, null, 0, "Browse",
        -1, '', '', $uploadTreeTable),
      "uploadId" => $upload,
      "itemId" => $item,
      "enhancedReuseStats" => $stats,
      "enhancedReuseDiffTree" => $dao->getDiffTree($analysisId),
      "enhancedReuseLicenses" => $licenses,
    );

    return $this->render("enhanced-reuse-analysis-page.html.twig", $this->mergeWithDefault($vars));
  }
}

register_plugin(new EnhancedReuseAnalysisPlugin());
