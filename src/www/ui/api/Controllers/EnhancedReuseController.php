<?php
/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\UI\Api\Controllers;

use Fossology\UI\Api\Exceptions\HttpNotFoundException;

class EnhancedReuseController extends RestController
{
  private function getAnalysisIdOrFail($uploadId)
  {
    $groupId = $this->restHelper->getGroupId();
    $analysisId = $this->container->get('dao.enhanced_reuse')
      ->getLatestAnalysisId($uploadId, $groupId);
    if ($analysisId === null) {
      throw new HttpNotFoundException("No enhanced reuse analysis found for this upload.");
    }
    return $analysisId;
  }

  public function getStats($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);
    $analysisId = $this->getAnalysisIdOrFail($uploadId);
    $result = $this->container->get('dao.enhanced_reuse')->getStats($analysisId);
    return $response->withJson($result, 200);
  }

  public function getLicenseComparison($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);
    $analysisId = $this->getAnalysisIdOrFail($uploadId);
    $result = $this->container->get('dao.enhanced_reuse')->getLicenseComparison($analysisId);
    return $response->withJson($result, 200);
  }

  public function getSuggestions($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);
    $analysisId = $this->getAnalysisIdOrFail($uploadId);
    $result = $this->container->get('dao.enhanced_reuse')->getSuggestions($analysisId);
    return $response->withJson($result, 200);
  }

  public function getDiffTree($request, $response, $args)
  {
    $uploadId = intval($args['id']);
    $this->uploadAccessible($uploadId);
    $analysisId = $this->getAnalysisIdOrFail($uploadId);
    $result = $this->container->get('dao.enhanced_reuse')->getDiffTree($analysisId);
    return $response->withJson($result, 200);
  }
}
