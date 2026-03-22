<?php
/*
SPDX-FileCopyrightText: © Fossology contributors

SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @file
 * @brief AJAX endpoints for Enhanced Reuse UI
 */

use Fossology\EnhancedReuser\DiffAnalyzer;
use Fossology\EnhancedReuser\LicenseChangeAnalyzer;
use Fossology\EnhancedReuser\SmartSuggestionEngine;
use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Plugin\DefaultPlugin;

include_once(__DIR__ . "/../agent/DiffAnalyzer.php");
include_once(__DIR__ . "/../agent/LicenseChangeAnalyzer.php");
include_once(__DIR__ . "/../agent/SmartSuggestionEngine.php");

class AjaxEnhancedReuser extends DefaultPlugin
{
  const NAME = 'ajax_enhanced_reuser';
  
  protected $uploadDao;
  protected $clearingDao;
  protected $licenseDao;
  protected $treeDao;
  protected $folderDao;
  
  public function __construct()
  {
    parent::__construct(self::NAME);
    $this->uploadDao = $this->getObject('dao.upload');
    $this->clearingDao = $this->getObject('dao.clearing');
    $this->licenseDao = $this->getObject('dao.license');
    $this->treeDao = $this->getObject('dao.tree');
    $this->folderDao = $this->getObject('dao.folder');
  }
  
  protected function handle($request)
  {
    $action = $request->get('action');
    $uploadId = intval($request->get('upload', 0));
    
    if ($uploadId <= 0) {
      return new JsonResponse(['error' => 'Invalid upload ID'], 400);
    }
    
    $groupId = Auth::getGroupId();
    if (!$this->uploadDao->isAccessible($uploadId, $groupId)) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }
    
    // Get reuse context
    $reusePairs = $this->uploadDao->getReusedUpload($uploadId, $groupId);
    $reusedUploadId = 0;
    if (!empty($reusePairs)) {
      $reusedUploadId = intval($reusePairs[0]['reused_upload_fk']);
    }
    
    switch ($action) {
      case 'stats':
        return $this->getStats($uploadId, $reusedUploadId, $groupId);
        
      case 'license-comparison':
        return $this->getLicenseComparison($uploadId, $reusedUploadId, $groupId);
        
      case 'diff-tree':
        return $this->getDiffTree($uploadId, $reusedUploadId, $groupId);
        
      case 'suggestions':
        return $this->getSuggestions($uploadId, $groupId);
        
      default:
        return new JsonResponse(['error' => 'Unknown action'], 400);
    }
  }
  
  private function getStats($uploadId, $reusedUploadId, $groupId)
  {
    if ($reusedUploadId == 0) {
      return new JsonResponse(['error' => 'No reuse context'], 400);
    }
    
    $diffTree = $this->computeDiffTree($uploadId, $reusedUploadId, $groupId);
    $licenseComparison = $this->computeLicenseComparison($uploadId, $reusedUploadId, $groupId);
    
    return new JsonResponse($this->computeStatsFromTree($diffTree, $licenseComparison));
  }
  
  private function getLicenseComparison($uploadId, $reusedUploadId, $groupId)
  {
    if ($reusedUploadId == 0) {
      return new JsonResponse(['error' => 'No reuse context'], 400);
    }
    
    return new JsonResponse($this->computeLicenseComparison($uploadId, $reusedUploadId, $groupId));
  }
  
  private function getDiffTree($uploadId, $reusedUploadId, $groupId)
  {
    if ($reusedUploadId == 0) {
      return new JsonResponse(['error' => 'No reuse context'], 400);
    }
    
    return new JsonResponse($this->computeDiffTree($uploadId, $reusedUploadId, $groupId));
  }
  
  private function getSuggestions($uploadId, $groupId)
  {
    $suggestionEngine = new SmartSuggestionEngine($this->uploadDao, $this->clearingDao, $this->licenseDao);
    return new JsonResponse($suggestionEngine->getSuggestions($uploadId, $groupId));
  }
  
  // Helper methods (copied from the REST controller)
  private function computeDiffTree($uploadId, $reusedUploadId, $groupId)
  {
    $diffAnalyzer = new DiffAnalyzer($this->uploadDao, $this->treeDao);
    return $diffAnalyzer->analyzeDiff($uploadId, $reusedUploadId, $groupId);
  }
  
  private function computeLicenseComparison($uploadId, $reusedUploadId, $groupId)
  {
    $licenseAnalyzer = new LicenseChangeAnalyzer($this->uploadDao, $this->clearingDao, $this->licenseDao);
    return $licenseAnalyzer->analyzeLicenseChanges($uploadId, $reusedUploadId, $groupId);
  }
  
  private function computeStatsFromTree(array $diffTree, array $licenseComparison)
  {
    $total = count($diffTree);
    $counts = ['identical' => 0, 'modified_minor' => 0, 'modified_major' => 0,
               'modified_comment_only' => 0, 'new' => 0, 'deleted' => 0];
    
    foreach ($diffTree as $item) {
      $status = $item['status'];
      if (isset($counts[$status])) {
        $counts[$status]++;
      }
    }
    
    $linesAdded = array_sum(array_column($diffTree, 'linesAdded'));
    $linesRemoved = array_sum(array_column($diffTree, 'linesRemoved'));
    
    $newLicenseCount = 0;
    foreach ($licenseComparison as $comparison) {
      if ($comparison['status'] === 'license_added') {
        $newLicenseCount++;
      }
    }
    
    return [
      'totalFiles' => $total,
      'identicalFiles' => $counts['identical'],
      'modifiedFiles' => $counts['modified_minor'] + $counts['modified_major'] + $counts['modified_comment_only'],
      'newFiles' => $counts['new'],
      'deletedFiles' => $counts['deleted'],
      'linesAdded' => $linesAdded,
      'linesRemoved' => $linesRemoved,
      'pctIdentical' => $total > 0 ? round(($counts['identical'] / $total) * 100, 1) : 0,
      'pctModified' => $total > 0 ? round((($counts['modified_minor'] + $counts['modified_major'] + $counts['modified_comment_only']) / $total) * 100, 1) : 0,
      'pctNew' => $total > 0 ? round(($counts['new'] / $total) * 100, 1) : 0,
      'pctDeleted' => $total > 0 ? round(($counts['deleted'] / $total) * 100, 1) : 0,
      'pctNewLicenses' => $total > 0 ? round(($newLicenseCount / $total) * 100, 1) : 0,
    ];
  }
}

register_plugin(new AjaxEnhancedReuser());
