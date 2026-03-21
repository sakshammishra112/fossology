<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\EnhancedReuser;

use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Data\AgentRef;
use Fossology\Lib\Proxy\ScanJobProxy;

/**
 * @file LicenseChangeAnalyzer.php
 * @brief Compares license findings and decisions between two upload versions
 * @class LicenseChangeAnalyzer
 * @brief Analyses license differences between old (v1) and new (v2) uploads
 *
 * Produces:
 * - Per-upload license histograms (scanner counts + concluded counts)
 * - Delta: licenses added in v2, removed from v2, unchanged
 * - Colour-coded risk classification
 */
class LicenseChangeAnalyzer
{
  /** @var ClearingDao $clearingDao */
  private $clearingDao;

  /** @var LicenseDao $licenseDao */
  private $licenseDao;

  /** @var AgentDao $agentDao */
  private $agentDao;

  public function __construct(ClearingDao $clearingDao, LicenseDao $licenseDao, AgentDao $agentDao)
  {
    $this->clearingDao = $clearingDao;
    $this->licenseDao  = $licenseDao;
    $this->agentDao    = $agentDao;
  }

  /**
   * @brief Build license histogram for a single upload
   *
   * @param \Fossology\Lib\Data\Tree\ItemTreeBounds $itemTreeBounds
   * @param int $groupId
   * @param int $uploadId
   * @return array Associative array: licenseName => ['scannerCount'=>int, 'concludedCount'=>int, 'rf_pk'=>int]
   */
  public function buildHistogram($itemTreeBounds, $groupId, $uploadId)
  {
    $scannerAgents = array_keys(AgentRef::AGENT_LIST);
    $scanJobProxy  = new ScanJobProxy($this->agentDao, $uploadId);
    $scanJobProxy->createAgentStatus($scannerAgents);
    $latestAgentIds = $scanJobProxy->getLatestSuccessfulAgentIds();

    $scannedLicenses  = $this->licenseDao->getLicenseHistogram($itemTreeBounds, $latestAgentIds);
    $editedLicenses   = $this->clearingDao->getClearedLicenseIdAndMultiplicities($itemTreeBounds, $groupId);

    $allNames = array_unique(array_merge(array_keys($scannedLicenses), array_keys($editedLicenses)));
    // Remove the sentinel "no license found" entry
    $allNames = array_filter($allNames, function ($n) {
      return $n !== LicenseDao::NO_LICENSE_FOUND;
    });

    $histogram = [];
    foreach ($allNames as $name) {
      $scannerCount   = isset($scannedLicenses[$name]) ? intval($scannedLicenses[$name]['unique']) : 0;
      $concludedCount = isset($editedLicenses[$name])  ? intval($editedLicenses[$name]['count'])   : 0;
      $rfPk           = isset($scannedLicenses[$name]) ? $scannedLicenses[$name]['rf_pk']
                                                       : (isset($editedLicenses[$name]) ? $editedLicenses[$name]['rf_pk'] : 0);
      $histogram[$name] = [
        'scannerCount'   => $scannerCount,
        'concludedCount' => $concludedCount,
        'rf_pk'          => $rfPk,
      ];
    }
    return $histogram;
  }

  /**
   * @brief Compare two license histograms and produce a delta report
   *
   * @param array $v1Histogram Old upload histogram (from buildHistogram)
   * @param array $v2Histogram New upload histogram (from buildHistogram)
   * @return array{
   *   added: array,
   *   removed: array,
   *   unchanged: array,
   *   changed: array,
   *   riskLevel: string,
   *   v1Total: int,
   *   v2Total: int,
   *   pctNewLicenses: float,
   *   comparison: array
   * }
   */
  public function compare(array $v1Histogram, array $v2Histogram)
  {
    $v1Names = array_keys($v1Histogram);
    $v2Names = array_keys($v2Histogram);

    $added     = array_diff($v2Names, $v1Names);  // in v2 but not v1
    $removed   = array_diff($v1Names, $v2Names);  // in v1 but not v2
    $common    = array_intersect($v1Names, $v2Names);

    $changed   = [];
    $unchanged = [];
    foreach ($common as $name) {
      $v1Count = $v1Histogram[$name]['scannerCount'];
      $v2Count = $v2Histogram[$name]['scannerCount'];
      if ($v1Count !== $v2Count) {
        $changed[] = $name;
      } else {
        $unchanged[] = $name;
      }
    }

    $v1Total = array_sum(array_column($v1Histogram, 'scannerCount'));
    $v2Total = array_sum(array_column($v2Histogram, 'scannerCount'));

    $addedCount   = count($added);
    $removedCount = count($removed);

    $pctNewLicenses = ($v1Total > 0)
      ? round(($addedCount / max($v1Total, 1)) * 100, 2)
      : ($addedCount > 0 ? 100.0 : 0.0);

    // Risk assessment
    if ($addedCount > 0) {
      $riskLevel = 'high';
    } elseif ($removedCount > 0 || count($changed) > 0) {
      $riskLevel = 'medium';
    } else {
      $riskLevel = 'low';
    }

    // Build side-by-side comparison array for the histogram chart
    $allLicenses = array_unique(array_merge($v1Names, $v2Names));
    $comparison  = [];
    foreach ($allLicenses as $name) {
      $v1Count = isset($v1Histogram[$name]) ? $v1Histogram[$name]['scannerCount'] : 0;
      $v2Count = isset($v2Histogram[$name]) ? $v2Histogram[$name]['scannerCount'] : 0;

      if (in_array($name, $added)) {
        $status = 'added';
        $colour = '#28a745'; // green
      } elseif (in_array($name, $removed)) {
        $status = 'removed';
        $colour = '#dc3545'; // red
      } elseif (in_array($name, $changed)) {
        $status = 'changed';
        $colour = '#fd7e14'; // amber
      } else {
        $status = 'unchanged';
        $colour = '#6c757d'; // grey
      }
      $comparison[] = [
        'name'    => $name,
        'v1Count' => $v1Count,
        'v2Count' => $v2Count,
        'status'  => $status,
        'colour'  => $colour,
      ];
    }

    return [
      'added'         => array_values($added),
      'removed'       => array_values($removed),
      'unchanged'     => array_values($unchanged),
      'changed'       => array_values($changed),
      'riskLevel'     => $riskLevel,
      'v1Total'       => $v1Total,
      'v2Total'       => $v2Total,
      'pctNewLicenses'=> $pctNewLicenses,
      'comparison'    => $comparison,
    ];
  }
}
