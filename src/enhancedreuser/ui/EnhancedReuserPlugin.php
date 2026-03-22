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

  /** @var TreeDao $treeDao */
  private $treeDao;

  /** @var ClearingDao $clearingDao */
  private $clearingDao;

  /** @var LicenseDao $licenseDao */
  private $licenseDao;

  public function __construct()
  {
    parent::__construct(self::NAME, [
      self::TITLE      => _("Enhanced Reuse Analysis"),
      self::PERMISSION => Auth::PERM_READ,
      self::REQUIRES_LOGIN => false,
    ]);
    $this->uploadDao = $this->getObject('dao.upload');
    $this->folderDao = $this->getObject('dao.folder');
    $this->treeDao = $this->getObject('dao.tree');
    $this->clearingDao = $this->getObject('dao.clearing');
    $this->licenseDao = $this->getObject('dao.license');
  }

  /**
   * @copydoc Fossology::Lib::Plugin::DefaultPlugin::preInstall()
   * @see Fossology::Lib::Plugin::DefaultPlugin::preInstall()
   */
  function preInstall()
  {
    $text = _("Enhanced Reuse Analysis");
    menu_insert("Browse-Pfile::Enhanced Reuse Analysis", 0, self::NAME, $text);
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

    // Handle AJAX requests for data
    $action = $request->get('action');
    if ($action) {
      return $this->handleAjax($request, $uploadId);
    }

    $groupId = Auth::getGroupId();
    $userId = Auth::getUserId();
    
    // Check if user is authenticated
    if (!$userId || !$groupId) {
      $vars = [
        'uploadId' => $uploadId,
        'uploadFilename' => 'Unknown',
        'reusedUploadId' => 0,
        'reusedFilename' => '',
        'hasReuseContext' => false,
        'stats' => null,
        'licenseComparison' => null,
        'diffTree' => null,
        'suggestions' => null,
        'authRequired' => true,
        'authMessage' => _('Please log in to view Enhanced Reuse Analysis.')
      ];
      
      $renderer = $this->getObject('twig.environment');
      $content = $renderer->load('enhanced_reuser.html.twig')->render($vars);
      return new Response($content, Response::HTTP_OK);
    }
    
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
    $stats = null;
    $licenseComparison = null;
    $diffTree = null;
    $suggestions = null;
    
    // Debug output
    error_log("Enhanced Reuser: Processing upload $uploadId for group $groupId");
    error_log("Enhanced Reuser: Found " . count($reusePairs) . " reuse pairs");
    
    if (!empty($reusePairs)) {
      $reusedUploadId = intval($reusePairs[0]['reused_upload_fk']);
      $reusedUpload   = $this->uploadDao->getUpload($reusedUploadId);
      $reusedFilename = $reusedUpload ? $reusedUpload->getFilename() : '';
      
      error_log("Enhanced Reuser: Reused upload ID: $reusedUploadId, filename: $reusedFilename");
      
      // Load cached analysis data from the agent's JSON file
      $analysisFile = "/srv/fossology/repository/enhanced-reuse/{$uploadId}/{$reusedUploadId}/analysis.json";
      error_log("Enhanced Reuser: Looking for analysis file: $analysisFile");
      
      if (file_exists($analysisFile)) {
        $cachedData = json_decode(file_get_contents($analysisFile), true);
        if ($cachedData) {
          $stats = $cachedData['stats'] ?? null;
          $licenseComparison = $cachedData['licenseComparison'] ?? null;
          $diffTree = $cachedData['diffTree'] ?? null;
          $suggestions = $cachedData['suggestions'] ?? null;
          
          // Debug output
          error_log("Enhanced Reuser: Loaded data for upload $uploadId, reused $reusedUploadId");
          error_log("Enhanced Reuser: Stats loaded: " . ($stats ? 'YES' : 'NO'));
          error_log("Enhanced Reuser: License comparison loaded: " . ($licenseComparison ? 'YES' : 'NO'));
          error_log("Enhanced Reuser: Diff tree loaded: " . ($diffTree ? 'YES' : 'NO'));
          error_log("Enhanced Reuser: Suggestions loaded: " . ($suggestions ? 'YES' : 'NO'));
        } else {
          error_log("Enhanced Reuser: Failed to decode JSON from $analysisFile");
        }
      } else {
        error_log("Enhanced Reuser: Analysis file not found: $analysisFile");
      }
    } else {
      error_log("Enhanced Reuser: No reuse context found for upload $uploadId");
    }

    $vars = [
      'uploadId'        => $uploadId,
      'uploadFilename'  => $upload->getFilename(),
      'reusedUploadId'  => $reusedUploadId,
      'reusedFilename'  => $reusedFilename,
      'hasReuseContext' => ($reusedUploadId > 0),
      'stats'           => $stats,
      'licenseComparison'=> $licenseComparison,
      'diffTree'        => $diffTree,
      'suggestions'     => $suggestions,
    ];
    
    // Debug output
    error_log("Enhanced Reuser: About to render template with " . count($vars) . " variables");
    error_log("Enhanced Reuser: hasReuseContext: " . ($vars['hasReuseContext'] ? 'true' : 'false'));
    error_log("Enhanced Reuser: stats: " . ($vars['stats'] ? 'SET' : 'NULL'));
    error_log("Enhanced Reuser: licenseComparison: " . ($vars['licenseComparison'] ? 'SET' : 'NULL'));
    error_log("Enhanced Reuser: diffTree: " . ($vars['diffTree'] ? 'SET' : 'NULL'));
    error_log("Enhanced Reuser: suggestions: " . ($vars['suggestions'] ? 'SET' : 'NULL'));

    $renderer = $this->getObject('twig.environment');
    $content   = $renderer->load('enhanced_reuser.html.twig')->render($vars);
    
    error_log("Enhanced Reuser: Template rendered successfully, content length: " . strlen($content));
    
    return new Response($content, Response::HTTP_OK);
  }
}
