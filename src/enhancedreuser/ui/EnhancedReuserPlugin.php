<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @file EnhancedReuserPlugin.php
 * @brief UI plugin for the Enhanced Reuse Dashboard
 */

namespace Fossology\EnhancedReuser\Ui;

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\FolderDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Plugin\DefaultPlugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @class EnhancedReuserPlugin
 * @brief Renders the Enhanced Reuse Analysis dashboard
 *
 * Accessible at: `?mod=enhanced_reuser&upload=<uploadId>`
 *
 * The page shows:
 * - Statistics cards (identical / modified / new / deleted)
 * - Risk level badge
 * - License histogram chart (v1 vs v2) powered by Chart.js
 * - Diff tree table with colour-coded rows
 * - Smart suggestions panel
 * - Bulk decision form
 */
class EnhancedReuserPlugin extends DefaultPlugin
{
  const NAME = 'enhanced_reuser'; ///< Module name

  /** @var UploadDao $uploadDao */
  private $uploadDao;

  /** @var FolderDao $folderDao */
  private $folderDao;

  public function __construct()
  {
    parent::__construct(self::NAME, [
      self::TITLE      => _("Enhanced Reuse Analysis"),
      self::PERMISSION => Auth::PERM_READ,
    ]);
    $this->uploadDao = $this->getObject('dao.upload');
    $this->folderDao = $this->getObject('dao.folder');
  }

  /**
   * @copydoc Fossology::Lib::Plugin::DefaultPlugin::handle()
   */
  protected function handle(Request $request)
  {
    $uploadId = intval($request->get('upload', 0));
    if ($uploadId <= 0) {
      return new Response(
        _("No upload selected. Please provide ?upload=<id>."),
        Response::HTTP_BAD_REQUEST
      );
    }

    $groupId = Auth::getGroupId();
    if (!$this->uploadDao->isAccessible($uploadId, $groupId)) {
      return new Response(_("Upload is not accessible."), Response::HTTP_FORBIDDEN);
    }

    $upload = $this->uploadDao->getUpload($uploadId);
    if ($upload === null) {
      return new Response(_("Upload not found."), Response::HTTP_NOT_FOUND);
    }

    // Determine reuse context (which v1 upload is paired with this one)
    $reusePairs    = $this->uploadDao->getReusedUpload($uploadId, $groupId);
    $reusedUploadId = 0;
    $reusedFilename = '';
    if (!empty($reusePairs)) {
      $reusedUploadId = intval($reusePairs[0]['reused_upload_fk']);
      $reusedUpload   = $this->uploadDao->getUpload($reusedUploadId);
      $reusedFilename = $reusedUpload ? $reusedUpload->getFilename() : '';
    }

    $vars = [
      'uploadId'        => $uploadId,
      'uploadFilename'  => $upload->getFilename(),
      'reusedUploadId'  => $reusedUploadId,
      'reusedFilename'  => $reusedFilename,
      'hasReuseContext' => ($reusedUploadId > 0),
    ];

    $renderer = $this->getObject('twig.environment');
    $content   = $renderer->load('enhanced_reuser.html.twig')->render($vars);
    return new Response($content, Response::HTTP_OK);
  }
}

register_plugin(new EnhancedReuserPlugin());
