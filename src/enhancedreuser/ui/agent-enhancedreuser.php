<?php
/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\EnhancedReuser\Ui;

use Fossology\Lib\Plugin\AgentPlugin;

class EnhancedReuserAgentPlugin extends AgentPlugin
{
  public function __construct()
  {
    $this->Name = "agent_enhancedreuser";
    $this->Title = _("Enhanced Reuse Analysis");
    $this->AgentName = "enhancedreuser";

    parent::__construct();
  }

  /**
   * Queue enhanced reuse after a specific reuser jobqueue row (the one from this job).
   *
   * Do not use doAgentAdd(["agent_reuser"]): resolving that dependency calls
   * ReuserAgentPlugin::AgentAdd(), which can return 0; JobQueueAdd() treats 0 as an
   * empty dependency, so the enhanced job would have no jobdepends row and can get
   * stuck or run at the wrong time.
   *
   * @param int $reuserJqPk jobqueue.jq_pk of the reuser step for this upload/job
   * @return int jq_pk on success, -1 on failure
   */
  public function scheduleAfterReuserJob(int $jobId, int $uploadId, int $reuserJqPk, string &$errorMsg): int
  {
    if ($reuserJqPk <= 0) {
      $errorMsg .= _("Invalid reuser job queue id for enhanced reuse.");
      return -1;
    }

    $jobQueueId = \IsAlreadyScheduled($jobId, $this->AgentName, $uploadId);
    if ($jobQueueId != 0) {
      return $jobQueueId;
    }

    $jobQueueId = \JobQueueAdd($jobId, $this->AgentName, (string) $uploadId, "",
      [$reuserJqPk], null, null);
    if (empty($jobQueueId)) {
      $errorMsg .= _("Failed to insert enhancedreuser into job queue.");
      return -1;
    }
    $success = \fo_communicate_with_scheduler("database", $output, $errorMsg);
    if (!$success) {
      $errorMsg .= "\n" . $output;
    }

    return (int) $jobQueueId;
  }

  /**
   * @copydoc Fossology\Lib\Plugin\AgentPlugin::AgentAdd()
   */
  public function AgentAdd($jobId, $uploadId, &$errorMsg, $dependencies = [],
      $arguments = null, $request = null, $unpackArgs = null)
  {
    $jobQueueId = \IsAlreadyScheduled($jobId, $this->AgentName, $uploadId);
    if ($jobQueueId != 0) {
      return $jobQueueId;
    }

    return $this->doAgentAdd($jobId, $uploadId, $errorMsg,
      ["agent_reuser"], $uploadId, null, $request);
  }
}

register_plugin(new EnhancedReuserAgentPlugin());
